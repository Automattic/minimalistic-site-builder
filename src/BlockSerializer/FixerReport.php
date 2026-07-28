<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer;

/** Deterministic report DTO and production formatter. */
final class FixerReport
{
    /** @param list<FileReport> $files */
    public function __construct(public readonly array $files, public readonly int $themes = 1)
    {
        if ($themes < 1) {
            throw new \InvalidArgumentException('A valid PHP fixer call covers one theme');
        }
    }

    public function changedCount(): int
    {
        return count(array_filter($this->files, static fn (FileReport $file): bool => $file->status === 'fixed'));
    }

    public function eligibleCount(): int
    {
        return count(array_filter($this->files, static fn (FileReport $file): bool => $file->status !== 'skip'));
    }

    public function droppedCount(): int
    {
        return array_sum(array_map(static fn (FileReport $file): int => count($file->dropped), $this->files));
    }

    public function repairCount(): int
    {
        return array_sum(array_map(static fn (FileReport $file): int => count($file->repairs), $this->files));
    }

    /** Stable labels in the human-readable report. */
    public const FAILED_TAG = 'FAILED';
    public const FAILED_DETAIL = '! left unmodified:';

    public function failedCount(): int
    {
        return count(array_filter($this->files, static fn (FileReport $file): bool => $file->status === 'failed'));
    }

    /** @return list<FileReport> the files delivered untouched after an abandoned transformation */
    public function failures(): array
    {
        return array_values(array_filter(
            $this->files,
            static fn (FileReport $file): bool => $file->status === 'failed',
        ));
    }

    public function summary(): string
    {
        $summary = sprintf(
            '[fix-templates] %d/%d file(s) re-serialized, %d issue(s) fixed, %d style/class value(s) dropped across %d theme(s).',
            $this->changedCount(),
            $this->eligibleCount(),
            $this->repairCount(),
            $this->droppedCount(),
            $this->themes,
        );
        if (($failed = $this->failedCount()) > 0) {
            $summary .= sprintf(' %d file(s) left unmodified after a failed transformation.', $failed);
        }
        return $summary;
    }

    public function format(): string
    {
        $lines = [$this->summary()];
        foreach ($this->files as $file) {
            $tag = match ($file->status) {
                'fixed' => 'FIXED ',
                'ok' => 'ok    ',
                'skip' => 'skip  ',
                'failed' => self::FAILED_TAG,
            };
            $lines[] = '  ' . $tag . ' ' . $file->path;
            if ($file->error !== null) {
                $lines[] = '         ' . self::FAILED_DETAIL . ' ' . str_replace(["\r", "\n"], ' ', $file->error);
            }
            foreach ($file->dropped as $drop) {
                $lines[] = '         ! ' . $drop->line();
            }
            foreach ($file->repairs as $repair) {
                $lines[] = '         - REPAIR ' . $repair->code . ' at ' . $repair->blockPath;
            }
        }
        return implode("\n", $lines);
    }

    /** @return array<string,mixed> */
    public function normalized(): array
    {
        return [
            'schemaVersion' => 1,
            'totals' => [
                'N' => $this->changedCount(),
                'M' => $this->eligibleCount(),
                'D' => $this->droppedCount(),
                'T' => $this->themes,
            ],
            'files' => array_map(static fn (FileReport $file): array => $file->normalized(), $this->files),
        ];
    }
}
