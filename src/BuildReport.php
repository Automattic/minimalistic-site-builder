<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Accumulates the per-step accounting of a build (wall time + token spend) plus
 * the image-generation tally, and renders the run overview shown on the console
 * AND written to projects/<slug>/logs/project.log.
 *
 * It is a passive ledger: bin/build.php feeds it one row per step as the
 * pipeline reports them (diffing the LLM client's cumulative token totals to
 * attribute spend to the step that just ran), then asks it to render. Pure apart
 * from holding state — no I/O — so the rendering is unit-testable.
 *
 * The console prints rows live as they complete (formatRow), so the same row
 * formatter is reused here to keep the file and the terminal byte-for-byte
 * consistent — the log is exactly "what we showed in the terminal", in full.
 */
final class BuildReport
{
    /** @var array<int,array{id:string,secs:float,in:int,out:int}> */
    private array $rows = [];
    private float $totalSecs = 0.0;

    private bool $hasImages = false;
    private int $imagesGenerated = 0;
    private int $imagesFailed = 0;
    private int $imagesTotal = 0;

    private int $requests = 0;

    public function __construct(
        private string $prompt,
        private string $slug,
        private string $outputPath,
        private string $generatedAt,
    ) {}

    /**
     * Record one completed step: its wall time and the LLM tokens it spent
     * (0/0 for the deterministic steps, which make no model calls).
     */
    public function addStep(string $id, float $secs, int $inTokens, int $outTokens): void
    {
        $this->rows[] = ['id' => $id, 'secs' => $secs, 'in' => $inTokens, 'out' => $outTokens];
        $this->totalSecs += $secs;
    }

    /** Record the image-generation tally (only when --with-images ran). */
    public function setImages(int $generated, int $failed, int $total): void
    {
        $this->hasImages = true;
        $this->imagesGenerated = $generated;
        $this->imagesFailed = $failed;
        $this->imagesTotal = $total;
    }

    /** Total LLM requests made across the run (from the client's usage totals). */
    public function setRequestCount(int $requests): void
    {
        $this->requests = $requests;
    }

    public function totalSecs(): float
    {
        return $this->totalSecs;
    }

    public function totalInputTokens(): int
    {
        return array_sum(array_column($this->rows, 'in'));
    }

    public function totalOutputTokens(): int
    {
        return array_sum(array_column($this->rows, 'out'));
    }

    public function totalTokens(): int
    {
        return $this->totalInputTokens() + $this->totalOutputTokens();
    }

    /**
     * One formatted table row: id, wall time, total tokens. Shared by the live
     * console output and the log file so they never drift. Pure.
     */
    public static function formatRow(string $id, float $secs, int $inTokens, int $outTokens): string
    {
        return sprintf(
            '  %-24s %7.1fs  %11s tok',
            $id,
            $secs,
            number_format($inTokens + $outTokens)
        );
    }

    /** The TOTAL line, with the in/out token split. Pure. */
    public function totalLine(): string
    {
        return sprintf(
            '  %-24s %7.1fs  %11s tok  (%s in + %s out)',
            'TOTAL',
            $this->totalSecs,
            number_format($this->totalTokens()),
            number_format($this->totalInputTokens()),
            number_format($this->totalOutputTokens())
        );
    }

    /** The images summary line, or null when no image step ran. Pure. */
    public function imagesLine(): ?string
    {
        if (!$this->hasImages) {
            return null;
        }
        return sprintf(
            'Images: %d generated, %d failed (%d total)',
            $this->imagesGenerated,
            $this->imagesFailed,
            $this->imagesTotal
        );
    }

    /**
     * Render the full project.log document: header, the per-step table, totals,
     * and the image/request summary. Pure — unit-testable.
     */
    public function render(): string
    {
        $rule = str_repeat('=', 80);
        $sub = str_repeat('-', 80);

        $lines = [
            $rule,
            'BUILD REPORT — ' . $this->slug,
            $rule,
            'Prompt       : ' . $this->prompt,
            'Generated at : ' . $this->generatedAt,
            'Output       : ' . $this->outputPath,
            '',
            'Steps',
            $sub,
        ];

        foreach ($this->rows as $r) {
            $lines[] = self::formatRow($r['id'], $r['secs'], $r['in'], $r['out']);
        }

        $lines[] = $sub;
        $lines[] = $this->totalLine();
        $lines[] = '';
        $lines[] = 'LLM requests : ' . $this->requests;
        if (($img = $this->imagesLine()) !== null) {
            $lines[] = $img;
        }
        $lines[] = $rule;
        $lines[] = '';

        return implode("\n", $lines);
    }
}
