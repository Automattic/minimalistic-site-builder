<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Accumulates the per-step accounting of a build (wall time, token spend and
 * the model each step ran on) plus the image-generation tally, and renders it
 * two ways: the run overview printed on the console AND written to
 * projects/<slug>/logs/project.log (render), and the machine-readable
 * projects/<slug>/build-stats.json (stats).
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
    /** Width of the step-id column, shared by every row formatter below. */
    private const ID_WIDTH = 24;

    /** @var array<int,array{id:string,secs:float,in:int,out:int,model:?string}> */
    private array $rows = [];
    private float $totalSecs = 0.0;
    private float $wallSecs = 0.0;

    private bool $hasImages = false;
    private int $imagesGenerated = 0;
    private int $imagesFailed = 0;
    private int $imagesTotal = 0;

    private int $requests = 0;

    /** @var array<string,int> step id => count of delivered-through defects */
    private array $warningCounts = [];

    public function __construct(
        private string $prompt,
        private string $slug,
        private string $outputPath,
        private string $generatedAt,
    ) {}

    /**
     * Record one completed step: its wall time, the LLM tokens it spent (0/0
     * for the deterministic steps, which make no model calls) and the model it
     * ran on (null for those same steps — see formatRow).
     */
    public function addStep(string $id, float $secs, int $inTokens, int $outTokens, ?string $model = null): void
    {
        $this->rows[] = ['id' => $id, 'secs' => $secs, 'in' => $inTokens, 'out' => $outTokens, 'model' => $model];
        $this->totalSecs += $secs;
    }

    /**
     * Record the run's wall-clock duration — distinct from the sum of the step
     * times, which misses the work between steps.
     */
    public function setWallSeconds(float $secs): void
    {
        $this->wallSecs = $secs;
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

    /**
     * Record the project's warnings.json content — the defects the build
     * delivered through instead of failing on — so the run overview surfaces
     * them instead of a warned build looking identical to a clean one.
     *
     * @param array<mixed> $warningsByStep decoded warnings.json (step id => messages)
     */
    public function setWarnings(array $warningsByStep): void
    {
        $this->warningCounts = [];
        foreach ($warningsByStep as $stepId => $messages) {
            $count = is_array($messages) ? count($messages) : 0;
            if ($count > 0) {
                $this->warningCounts[(string) $stepId] = $count;
            }
        }
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
     * The model label for one step id, looked up in the step => model map.
     *
     * A ConcurrentGroup's id is its members joined together and never appears
     * in the map itself, so resolve each member and collapse the distinct
     * models (usually one — a group's members tend to share a tier). Without
     * this a group would report no model despite having spent tokens. Null when
     * no member ran on a model, i.e. a deterministic step. Pure.
     *
     * @param array<string,string> $stepModels step id => model id
     */
    public static function modelLabel(string $stepId, array $stepModels): ?string
    {
        $used = [];
        foreach (ConcurrentGroup::memberIds($stepId) as $member) {
            if (isset($stepModels[$member])) {
                $used[$stepModels[$member]] = true;
            }
        }
        return $used === [] ? null : implode(', ', array_keys($used));
    }

    /**
     * One formatted table row: id, wall time, total tokens, and the model the
     * step ran on ("—" for a deterministic step, which ran on none). Shared by
     * the live console output and the log file so they never drift. Pure.
     */
    public static function formatRow(string $id, float $secs, int $inTokens, int $outTokens, ?string $model = null): string
    {
        return sprintf(
            '  %-' . self::ID_WIDTH . 's %7.1fs  %11s tok  %s',
            $id,
            $secs,
            number_format($inTokens + $outTokens),
            $model ?? '—'
        );
    }

    /**
     * The "starting" line shown before a step runs, so a long step never leaves
     * the build looking frozen. Lives next to formatRow because it is column-
     * coupled to it: the "→ " marker is two columns wider than formatRow's
     * indent, so the id column is two narrower and the two line up. Pure.
     */
    public static function formatStartRow(string $id, string $label): string
    {
        return sprintf('  → %-' . (self::ID_WIDTH - 2) . 's %s…', $id, $label);
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

    /**
     * The warnings summary line, or null when the build delivered clean.
     * One line, per-step counts — the details live in warnings.json. Pure.
     */
    public function warningsLine(): ?string
    {
        if ($this->warningCounts === []) {
            return null;
        }
        $parts = [];
        foreach ($this->warningCounts as $stepId => $count) {
            $parts[] = "{$stepId}: {$count}";
        }
        return sprintf(
            'Warnings: %d defect(s) delivered through — see warnings.json (%s)',
            array_sum($this->warningCounts),
            implode(', ', $parts)
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
            $lines[] = self::formatRow($r['id'], $r['secs'], $r['in'], $r['out'], $r['model']);
        }

        $lines[] = $sub;
        $lines[] = $this->totalLine();
        $lines[] = '';
        $lines[] = 'LLM requests : ' . $this->requests;
        if (($img = $this->imagesLine()) !== null) {
            $lines[] = $img;
        }
        if (($warnings = $this->warningsLine()) !== null) {
            $lines[] = $warnings;
        }
        $lines[] = $rule;
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * The same accounting as a machine-readable record, for
     * projects/<slug>/build-stats.json: totals plus the per-step breakdown, so
     * a run's cost and exact model mix stay comparable across builds after the
     * fact. The default model and the step => model map are passed in because
     * they come from the CLI's environment config, not from the run. Pure.
     *
     * @param array<string,string> $stepModels step id => model id
     * @return array<string,mixed>
     */
    public function stats(string $defaultModel, array $stepModels): array
    {
        return [
            'prompt'        => $this->prompt,
            'wall_seconds'  => round($this->wallSecs, 1),
            'requests'      => $this->requests,
            'input_tokens'  => $this->totalInputTokens(),
            'output_tokens' => $this->totalOutputTokens(),
            'total_tokens'  => $this->totalTokens(),
            'model'         => $defaultModel,
            'step_models'   => $stepModels,
            'built_at'      => $this->generatedAt,
            'steps'         => array_map(
                static fn (array $r): array => [
                    'id'            => $r['id'],
                    'seconds'       => round($r['secs'], 1),
                    'input_tokens'  => $r['in'],
                    'output_tokens' => $r['out'],
                    'total_tokens'  => $r['in'] + $r['out'],
                    'model'         => $r['model'],
                ],
                $this->rows
            ),
        ];
    }
}
