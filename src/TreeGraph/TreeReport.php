<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\TreeGraph;

use Automattic\SiteBuild\Project;

/**
 * report.md for a tree-graph build: modes, the predicted-vs-spent budget, the
 * per-artifact gate table, dead/substituted artifacts, and the ledger. The
 * whole document is derived from on-disk artifacts so it can be (re)written
 * at any point after the brief exists.
 */
final class TreeReport
{
    public static function write(Project $project): void
    {
        $budget = $project->exists('budget.json') ? $project->readJson('budget.json') : [];
        $ledger = new Ledger($project);
        $entries = $ledger->entries();
        $ledger->flush();

        $lines = ['# Tree graph build report', ''];
        $meta = $project->exists('meta.json') ? $project->readJson('meta.json') : [];
        $lines[] = '- prompt: ' . (string) ($meta['prompt'] ?? '');
        $lines[] = '- graph: tree (brochure — composition only)';
        $lines[] = '- images: ' . (!empty($meta['tree_images']) ? 'generated' : 'skipped — placeholder pixels stay');
        if ($project->exists('sandbox.json')) {
            $lines[] = '- site: ' . (string) ($project->readJson('sandbox.json')['url'] ?? '');
        }
        $lines[] = '';

        if ($budget !== []) {
            $lines[] = '## Budget';
            $lines[] = '';
            $lines[] = sprintf(
                '- sections S=%d, blocks B=%d, packages P=%d, images I=%d, furniture F=%d',
                (int) ($budget['S'] ?? 0),
                (int) ($budget['B'] ?? 0),
                (int) ($budget['P'] ?? 0),
                (int) ($budget['I'] ?? 0),
                (int) ($budget['F'] ?? 0),
            );
            $lines[] = sprintf('- ceiling %d call(s); spent %d', (int) ($budget['ceiling'] ?? 0), count($entries));
            $lines[] = '';
        }

        $gateRows = [];
        foreach (glob($project->path('trees') . '/*.json') ?: [] as $file) {
            $key = basename($file, '.json');
            if (str_starts_with($key, 'page--')) {
                continue;
            }
            $record = json_decode((string) file_get_contents($file), true);
            $gate = is_array($record) ? ($record['gate'] ?? []) : [];
            $status = (string) ($gate['status'] ?? 'unknown');
            $notes = [];
            if (!empty($gate['repaired'])) {
                $notes[] = 'repaired';
            }
            if (!empty($gate['ink_substituted'])) {
                $notes[] = 'ink substituted';
            }
            if ($status === 'baseline') {
                $notes[] = 'stock pattern substituted';
            }
            $gateRows[] = '| ' . $key . ' | ' . $status . ' | ' . implode(', ', $notes) . ' |';
        }
        if ($gateRows !== []) {
            $lines[] = '## Artifacts';
            $lines[] = '';
            $lines[] = '| artifact | gate | notes |';
            $lines[] = '| --- | --- | --- |';
            array_push($lines, ...$gateRows);
            $lines[] = '';
        }

        if ($project->exists('dead.json')) {
            $dead = $project->readJson('dead.json');
            if ($dead !== []) {
                $lines[] = '## Dead artifacts';
                $lines[] = '';
                foreach ($dead as $entry) {
                    $diagnostics = array_map(
                        static fn ($d): string => (string) (is_array($d) ? ($d['message'] ?? $d['code'] ?? '') : $d),
                        (array) ($entry['diagnostics'] ?? []),
                    );
                    $lines[] = '- ' . (string) ($entry['key'] ?? '?') . ': ' . implode(' | ', array_slice($diagnostics, 0, 3));
                }
                $lines[] = '';
            }
        }

        if ($entries !== []) {
            $lines[] = '## Ledger';
            $lines[] = '';
            $lines[] = '| task | label | attempt | outcome | ms |';
            $lines[] = '| --- | --- | --- | --- | --- |';
            foreach ($entries as $entry) {
                $lines[] = sprintf(
                    '| %s | %s | %d | %s | %d |',
                    (string) ($entry['task_type'] ?? ''),
                    (string) ($entry['label'] ?? ''),
                    (int) ($entry['attempt'] ?? 0),
                    (string) ($entry['outcome'] ?? ''),
                    (int) ($entry['ms'] ?? 0),
                );
            }
            $lines[] = '';
        }

        $project->writeText('report.md', implode("\n", $lines) . "\n");
    }
}
