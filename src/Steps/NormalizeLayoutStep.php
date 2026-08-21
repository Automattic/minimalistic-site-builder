<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\StorefrontDegrade;
use Automattic\SiteBuild\Units\GeneratedMarkup;

/**
 * Deterministic: run the LayoutFixer attribute repair + width/rhythm
 * normalization BEFORE the contrast and motion policy passes.
 *
 * LayoutFixer can activate attributes that were previously inert — it
 * repairs unparseable comment JSON and mirrors HTML-only declarations into
 * attributes. If that first happened inside fix-blocks (as it used to), a
 * malformed {"backgroundColor":...} or className:"ken-burns" would be
 * invisible to ContrastFixStep and MotionSanityStep, then become active
 * afterwards with no policy recheck (PR #109 review, finding 1). Running
 * the normalization here keeps those passes fail-closed: everything the
 * repair can activate already exists when they inspect the markup.
 *
 * FixBlocksStep still reruns the same (idempotent) normalization right
 * before re-serialization, as a sync point for anything later steps edit.
 */
final class NormalizeLayoutStep implements Step
{
    private const LOG_FILE = 'normalize-layout.log';

    public function __construct(private bool $htmlFirst = false) {}

    public function id(): string
    {
        return 'normalize-layout';
    }

    public function label(): string
    {
        return 'Normalize layout attributes';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            // Templates are only scanned when they exist; in the default graph
            // they are written by assemble-pages, which runs after this step.
            reads: [
                ...($this->htmlFirst ? ['design/site.css'] : []),
                'meta.json',
                'siteSpec.json',
                'theme/theme.json',
                'theme/parts/*',
                // Read only when it exists: a relabelled purchase CTA needs
                // somewhere to send an enquiry, and pages.json is where the
                // build's routes live. Absent, the CTA's destination is
                // reported instead of invented.
                'pages.json',
            ],
            writes: ['theme/parts/*', 'warnings.json'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        // fix-blocks computes these same tokens, but by the time it runs every
        // root already carries a layout and the injection is a no-op — the
        // stamp this signal would have prevented was committed one step
        // earlier, here. Load it before the damage, not after.
        $wideMeasureRootClasses = $this->htmlFirst && $project->exists('design/site.css')
            ? GeneratedMarkup::wideMeasureSubjectClasses($project->readText('design/site.css'))
            : [];
        $notes = FixBlocksStep::normalizeLayouts(
            $project,
            [],
            $this->htmlFirst,
            $wideMeasureRootClasses,
            // No carrier classes: align:wide promotion stays fix-blocks' alone.
            widePartCarrierClasses: [],
        );
        // Where a relabelled purchase CTA should send an enquiry, when this
        // build has a contact page at all.
        $contactRoute = StorefrontDegrade::contactRouteFromPages(
            $project->exists('pages.json')
                ? (array) ($project->readJson('pages.json')['pages'] ?? [])
                : [],
        );
        $cartWarnings = [];
        foreach ($project->themeFiles() as $rel) {
            $path = 'theme/' . $rel;
            if (!$project->exists($path)) {
                continue;
            }
            [$markup, $degraded] = StorefrontDegrade::markup(
                $project->readText($path),
                $path,
                $contactRoute,
            );
            if ($degraded === []) {
                continue;
            }
            $project->writeText($path, $markup);
            array_push($notes, ...$degraded);
            array_push($cartWarnings, ...$degraded);
        }
        if ($cartWarnings !== []) {
            $project->addWarnings($this->id(), $cartWarnings);
        }
        $report = $notes === []
            ? "No layout/rhythm normalization needed.\n"
            : '- ' . implode("\n- ", $notes) . "\n";
        $project->writeText('logs/' . self::LOG_FILE, $report);
        echo '  layout: ' . count($notes) . ' attribute fix(es) before policy passes'
            . ($notes === [] ? '' : ' (details: logs/' . self::LOG_FILE . ')') . "\n";
    }
}
