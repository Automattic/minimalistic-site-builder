<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\TypographicHygiene;

/**
 * Step (deterministic): bind heading widows, normalize punctuation, and drop
 * justified alignment across every delivered part (BIGR-1012).
 *
 * AFTER header-hero, so the header and hero parts are in their final phase and
 * their copy is covered too — the hero standfirst is as visible as any other
 * line on the page. BEFORE fix-blocks, matching contrast-fix and motion-sanity:
 * this pass edits RichText content and one `align` attribute, and the
 * re-serialization below regenerates the saved HTML around both.
 *
 * Per-file isolation mirrors the other markup policies: a part whose block
 * delimiters cannot be parsed safely is delivered exactly as authored under a
 * durable warning while every other part is still set properly.
 */
final class TypographicHygieneStep implements Step
{
    private const LOG_FILE = 'typographic-hygiene.log';

    public function id(): string
    {
        return 'typographic-hygiene';
    }

    public function label(): string
    {
        return 'Set headings and punctuation';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: ['theme/parts/*'],
            writes: ['theme/parts/*', 'warnings.json'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $warnings = [];
        $log = [];
        $widows = 0;
        $punctuation = 0;
        $justify = 0;

        foreach ($project->themeFiles() as $rel) {
            // parts/ only: templates/ does not exist yet — assemble-pages
            // composes it downstream out of the very parts set here.
            if (!str_starts_with($rel, 'parts/')) {
                continue;
            }
            $markup = $project->readText('theme/' . $rel);
            try {
                $result = TypographicHygiene::apply($markup);
            } catch (\RuntimeException $e) {
                $warnings[] = "file='theme/{$rel}'; block='part'; authored typography delivered unchanged; "
                    . 'disposition=typographic hygiene skipped (' . $e->getMessage() . ')';
                continue;
            }
            if ($result['markup'] === $markup) {
                continue;
            }
            $project->writeText('theme/' . $rel, $result['markup']);
            $widows += $result['widows'];
            $punctuation += $result['punctuation'];
            $justify += $result['justify'];
            $log[] = "theme/{$rel}: {$result['widows']} widow(s) bound, "
                . "{$result['punctuation']} text run(s) repunctuated, "
                . "{$result['justify']} justified block(s) unset";
        }

        if ($log !== []) {
            $project->writeText('logs/' . self::LOG_FILE, implode("\n", $log) . "\n");
        }
        $project->addWarnings($this->id(), $warnings);

        Narrator::write("  typography: {$widows} heading widow(s) bound, "
            . "{$punctuation} text run(s) repunctuated, {$justify} justified block(s) unset"
            . ($log !== [] ? ' (details: logs/' . self::LOG_FILE . ')' : '') . "\n");
        if ($warnings !== []) {
            Narrator::write('  typography: ' . count($warnings) . " part(s) delivered as authored\n");
        }
    }
}
