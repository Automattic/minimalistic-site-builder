<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\BlockSerializer\FileReport;
use Automattic\SiteBuild\BlockSerializer\FixerReport;

/**
 * One fixer invocation, retaining both the human-readable log and (when the
 * implementation supports it) the typed per-file transaction results.
 */
final class BlockFixerOutcome
{
    private function __construct(
        public readonly string $formatted,
        public readonly ?FixerReport $typed,
    ) {}

    public static function run(BlockFixer $fixer, string $themeDir): self
    {
        if ($fixer instanceof ReportingBlockFixer) {
            $report = $fixer->fixReport($themeDir);
            return new self($report->format(), $report);
        }

        return new self($fixer->fix($themeDir), null);
    }

    /** @return list<FileReport> */
    public function failures(): array
    {
        return $this->typed?->failures() ?? [];
    }

    /**
     * Format only files whose changes survived the enclosing step transaction.
     * Legacy BlockFixers have no typed per-file status, so their complete human
     * report is retained.
     *
     * @param list<string> $excludedPaths fixer-relative paths
     */
    public function formattedExcluding(array $excludedPaths): string
    {
        if ($this->typed === null || $excludedPaths === []) {
            return $this->formatted;
        }

        $excluded = array_fill_keys($excludedPaths, true);
        return (new FixerReport(
            array_values(array_filter(
                $this->typed->files,
                static fn (FileReport $file): bool => !isset($excluded[$file->path]),
            )),
            $this->typed->themes,
        ))->format();
    }
}
