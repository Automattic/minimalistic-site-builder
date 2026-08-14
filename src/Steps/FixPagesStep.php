<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\BlockFixer;
use Automattic\SiteBuild\BlockFixerOutcome;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

/**
 * Deterministic: re-run the block fixer over the fully assembled pages.
 *
 * fix-blocks repairs each section part in isolation; assemble-pages then
 * concatenates header + sections + footer into plugin/pages/<slug>.html. Block
 * problems that only emerge at document scope (structural adjacency across
 * section boundaries, duplicate anchors, top-level wrapper issues) therefore
 * ship unrepaired. The fixer's discover() already scans a `pages` subdirectory,
 * so pointing it at the plugin directory re-serializes exactly those pages.
 *
 * These are the final shippable pages, so this step degrades rather than fails:
 * a per-page transformation failure, a re-serialization that would drop authored
 * content, or an operational fixer crash all keep the pre-fix page and warn —
 * discarding the assembled page would cost the user the whole build.
 */
final class FixPagesStep implements Step
{
    private const LOG_FILE = 'fix-pages.log';

    public function __construct(private BlockFixer $fixer) {}

    public function id(): string
    {
        return 'fix-pages';
    }

    public function label(): string
    {
        return 'Fix assembled page blocks';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: ['plugin/pages/*'],
            writes: ['plugin/pages/*', 'warnings.json'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $before = self::snapshotPages($project);
        if ($before === []) {
            return;
        }

        try {
            $outcome = BlockFixerOutcome::run($this->fixer, $project->pluginPath());
        } catch (\RuntimeException $e) {
            // Operational crash over the final pages: the assembled markup is
            // already shippable, so restore every page and degrade.
            self::restorePages($project, $before, array_keys($before));
            $message = 'page block re-serialization crashed ('
                . str_replace(["\r", "\n"], ' ', $e->getMessage())
                . '); every assembled page kept as-is';
            $project->writeText('logs/' . self::LOG_FILE, $message . "\n");
            $project->addWarnings($this->id(), [$message . ' — see logs/' . self::LOG_FILE]);
            echo '  [fix-pages] warning: ' . $message . "\n";
            return;
        }

        // A failed file already keeps its pre-fixer bytes; a fixed file that
        // dropped authored class/style content is a document-scope regression
        // on a final page. Keep the pre-fix page in both cases and warn.
        $warnings = [];
        $restore = [];
        foreach ($outcome->typed?->files ?? [] as $file) {
            if (!array_key_exists($file->path, $before)) {
                continue;
            }
            if ($file->status === 'failed') {
                $restore[$file->path] = true;
                $why = str_replace(["\r", "\n"], ' ', $file->error ?? 'unknown transformation failure');
                $warnings[] = "page block re-serialization left {$file->path} unmodified ({$why}); "
                    . 'pre-fix page delivered byte-for-byte — see logs/' . self::LOG_FILE;
                continue;
            }
            if ($file->status === 'fixed' && $file->dropped !== []) {
                $restore[$file->path] = true;
                foreach ($file->dropped as $drop) {
                    $warnings[] = "page block re-serialization would drop {$drop->kind} `{$drop->value}` in "
                        . "{$file->path}; pre-fix page kept — see logs/" . self::LOG_FILE;
                }
            }
        }
        self::restorePages($project, $before, array_keys($restore));

        if ($warnings !== []) {
            $project->addWarnings($this->id(), $warnings);
            echo '  [fix-pages] warning: ' . count($warnings)
                . " page defect(s) kept pre-fix; see warnings.json\n";
        }
        $project->writeText('logs/' . self::LOG_FILE, $outcome->formatted . "\n");
        echo '  ' . (string) strtok($outcome->formatted, "\n")
            . ' (details: logs/' . self::LOG_FILE . ")\n";
    }

    /** @return array<string,string> 'pages/<name>.html' => exact bytes */
    private static function snapshotPages(Project $project): array
    {
        $snapshot = [];
        foreach (glob($project->pluginPath('pages') . '/*.html') ?: [] as $file) {
            $snapshot['pages/' . basename($file)] = (string) file_get_contents($file);
        }
        return $snapshot;
    }

    /**
     * @param array<string,string> $snapshot 'pages/<name>.html' => bytes
     * @param list<string>          $paths    snapshot keys to restore
     */
    private static function restorePages(Project $project, array $snapshot, array $paths): void
    {
        foreach ($paths as $rel) {
            if (array_key_exists($rel, $snapshot)) {
                $project->writeText('plugin/' . $rel, $snapshot[$rel]);
            }
        }
    }
}
