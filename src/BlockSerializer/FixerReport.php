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

    public function summary(): string
    {
        return sprintf(
            '[fix-templates] %d/%d file(s) re-serialized, %d issue(s) fixed, %d style/class value(s) dropped across %d theme(s).',
            $this->changedCount(),
            $this->eligibleCount(),
            $this->repairCount(),
            $this->droppedCount(),
            $this->themes,
        );
    }

    public function format(): string
    {
        $lines = [$this->summary()];
        foreach ($this->files as $file) {
            $tag = match ($file->status) {
                'fixed' => 'FIXED ',
                'ok' => 'ok    ',
                'skip' => 'skip  ',
            };
            $lines[] = '  ' . $tag . ' ' . $file->path;
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
