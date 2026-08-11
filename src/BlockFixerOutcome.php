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
     * Overlay caller-owned per-file abandonments onto a typed fixer result so
     * its report describes the bytes the enclosing transaction will deliver.
     * Legacy string-only fixers retain their report and let the caller track
     * those paths separately.
     *
     * @param array<string,string> $failures fixer-relative path => reason
     */
    public function withFailures(array $failures): self
    {
        if ($this->typed === null || $failures === []) {
            return $this;
        }

        $seen = [];
        $files = array_map(static function (FileReport $file) use ($failures, &$seen): FileReport {
            if (!isset($failures[$file->path])) {
                return $file;
            }
            $seen[$file->path] = true;
            $reason = $file->error === null
                ? $failures[$file->path]
                : $file->error . '; ' . $failures[$file->path];
            return new FileReport($file->path, 'failed', error: $reason);
        }, $this->typed->files);
        foreach ($failures as $path => $reason) {
            if (!isset($seen[$path])) {
                $files[] = new FileReport($path, 'failed', error: $reason);
            }
        }

        $typed = new FixerReport($files, $this->typed->themes);
        return new self($typed->format(), $typed);
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
