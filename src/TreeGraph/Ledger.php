<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\TreeGraph;

use Automattic\SiteBuild\Project;

/**
 * Append-only record of every metered call, ported from x-pipeline's Ledger.
 * logs/tree-ledger.jsonl survives resumes, so the FILE — not this process —
 * is the record of what the run has spent. Read it back in, or every derived
 * number (report totals, per-task actuals) counts only the calls this
 * process happened to make.
 */
final class Ledger
{
    private const JSONL = 'logs/tree-ledger.jsonl';
    private const FLUSHED = 'logs/tree-ledger.json';

    /** @var list<array<string,mixed>> */
    private array $entries = [];

    public function __construct(private readonly Project $project)
    {
        if (!$project->exists(self::JSONL)) {
            return; // no prior ledger — a fresh run
        }
        foreach (explode("\n", $project->readText(self::JSONL)) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $this->entries[] = $decoded;
            }
        }
    }

    /** @param array<string,mixed> $entry */
    public function record(array $entry): void
    {
        $this->entries[] = $entry;
        // logPath() creates logs/ on demand.
        $file = $this->project->logPath(basename(self::JSONL));
        if (file_put_contents($file, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND) === false) {
            throw new \RuntimeException("Could not append to ledger: {$file}");
        }
    }

    /** @return list<array<string,mixed>> */
    public function entries(): array
    {
        return $this->entries;
    }

    /** Attempts recorded so far — what BudgetMeter::rehydrate() wants on resume. */
    public function count(): int
    {
        return count($this->entries);
    }

    /** Sorted flush of the same record, for reading next to the report. */
    public function flush(): void
    {
        $sorted = $this->entries;
        usort($sorted, static function (array $a, array $b): int {
            return strcmp((string) ($a['task_type'] ?? ''), (string) ($b['task_type'] ?? ''))
                ?: strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''))
                ?: (int) ($a['attempt'] ?? 0) <=> (int) ($b['attempt'] ?? 0);
        });
        $this->project->writeJson(self::FLUSHED, $sorted);
    }
}
