<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\TreeGraph\Gates;
use Automattic\SiteBuild\TreeGraph\Harness;
use Automattic\SiteBuild\TreeGraph\SandboxClient;
use Automattic\SiteBuild\TreeGraph\TreeReport;

/**
 * Tree graph step 9: measured verification (port of x-pipeline S9).
 *
 * Verifies the SITE ROOT — what a visitor actually sees — in a headless
 * browser: heading outline (exactly one h1, no level jumps), every
 * top-level band spanning the viewport, no daylight between bands, rendered
 * text contrast, and every image actually loaded. One deviation from the
 * source pipeline, deliberate and in line with this package's "fix,
 * degrade, warn — never crash" contract: findings land in warnings.json
 * with loud narration instead of failing the build — the site is already
 * published and inspectable, and report.md carries the same findings.
 *
 * Exactly ONE screenshot in the whole run — terminal evidence, never a
 * loop input.
 */
final class TreeVerifyStep implements Step
{
    public function id(): string
    {
        return 'verify';
    }

    public function label(): string
    {
        return 'Verify the live site';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: ['sandbox.json', 'instance.json', 'published.json', 'meta.json'],
            writes: ['verify.json', 'screenshot.png', 'report.md', 'warnings.json'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $sandbox = $project->readJson('sandbox.json');
        $client = new SandboxClient((string) $sandbox['url']);
        $harness = new Harness(\repo_path());
        $url = rtrim((string) $sandbox['url'], '/') . '/';

        $blockNames = array_keys((array) ($client->manifest()['blocks'] ?? []));
        Narrator::write("  checking the finished site at {$url} — headings, bands, contrast, images\n");

        // Single-worker sandboxes can abort one navigation right after the
        // publish mutations, measuring an empty page: a transient, not a
        // verdict — retry once after a beat.
        $verify = [];
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $verify = $harness->verify($project, $url, $blockNames, [
                'wait'           => 'domcontentloaded',
                'nav_timeout_ms' => 120000,
            ]);
            $empty = (array) ($verify['a11y_outline'] ?? []) === [] && (array) ($verify['box_tree'] ?? []) === [];
            if (!$empty || $attempt === 2) {
                break;
            }
            Narrator::write("  measured an empty page (a transient right after publishing) — retrying once\n");
            sleep(3);
        }
        $project->writeJson('verify.json', $verify);

        $failures = [];
        $failures = array_merge($failures, Gates::screenOutline((array) ($verify['a11y_outline'] ?? [])));
        // The width audit: the Layout Cascade measured in the rendered DOM,
        // where a clamped band shows as a number no markup screen can produce.
        $viewportWidth = $verify['measured']['viewport']['width'] ?? null;
        $failures = array_merge($failures, Gates::screenBandWidths(
            (array) ($verify['box_tree'] ?? []),
            is_numeric($viewportWidth) ? (float) $viewportWidth : null,
        ));
        // …and no daylight between bands: the tokens step's seam reset holds.
        $failures = array_merge($failures, Gates::screenBandSeams((array) ($verify['box_tree'] ?? [])));
        // The measured ink audit: unreadable text fails whatever layer painted it.
        $failures = array_merge($failures, Gates::screenTextContrast((array) ($verify['text_contrast'] ?? [])));
        foreach ((array) ($verify['images'] ?? []) as $image) {
            if (($image['loaded'] ?? true) !== true || (int) ($image['natural_w'] ?? 1) === 0) {
                $failures[] = [
                    'code'    => 'image',
                    'message' => 'image not loaded: ' . (string) ($image['selector_path'] ?? '?'),
                ];
            }
        }
        $muddy = count(array_filter(
            (array) ($verify['text_contrast'] ?? []),
            static fn (array $f): bool => (float) ($f['ratio'] ?? 0) >= 3,
        ));
        if ($muddy > 0) {
            Narrator::write("  advisory: {$muddy} text element(s) read between 3:1 and 4.5:1 — legible but muddy (details in verify.json)\n");
        }

        if ($failures !== []) {
            $messages = array_map(static fn (array $f): string => "[{$f['code']}] {$f['message']}", $failures);
            $project->replaceWarnings($this->id(), $messages);
            Narrator::write('  ✗ verification found ' . count($failures) . " measured problem(s) — recorded in warnings.json:\n");
            foreach (array_slice($messages, 0, 5) as $message) {
                Narrator::write("    - {$message}\n");
            }
            if (count($messages) > 5) {
                Narrator::write('    … and ' . (count($messages) - 5) . " more\n");
            }
        } else {
            $project->replaceWarnings($this->id(), []);
            Narrator::write("  verified {$url} — heading structure sane, bands flush and full-width, text readable, images loaded\n");
        }

        // Terminal evidence: the one screenshot of the run.
        $screenshot = \repo_path('bin/screenshot/screenshot.js');
        $out = $project->path('screenshot.png');
        $command = sprintf('node %s %s %s 2>&1', escapeshellarg($screenshot), escapeshellarg($url), escapeshellarg($out));
        @exec($command, $output, $status);
        if ($status !== 0 || !is_file($out)) {
            $project->addWarnings($this->id(), ['screenshot capture failed: ' . implode(' ', array_slice($output, -3))]);
        }

        TreeReport::write($project);
        Narrator::write("  report: {$project->path('report.md')}\n");
    }
}
