<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Accumulates the per-step accounting of a build (wall time, token spend and
 * the configured model(s) attributable to that spend) plus image-generation and
 * reusable-pattern tallies, and renders it two ways: the run overview printed on
 * the console AND written to projects/<slug>/logs/project.log (render), and the
 * machine-readable projects/<slug>/build-stats.json (stats).
 *
 * It is a passive ledger: bin/build.php feeds it the LLM client's cumulative
 * token totals after each step, and it attributes the delta to the step that
 * just ran. Pure apart from holding state — no I/O — so both accounting and
 * rendering are unit-testable.
 *
 * The console prints rows live as they complete (formatRow), so the same row
 * formatter is reused here to keep those rows byte-identical to the final recap.
 */
final class BuildReport
{
    /** Width of the step-id column, shared by every row formatter below. */
    private const ID_WIDTH = 24;
    private const REPORT_WIDTH = 96;

    /** @var array<string,list<string>> report row id => configured model keys */
    private const MODEL_KEYS_BY_STEP = [
        'design-direction' => ['design-direction-seeds', 'design-direction-judge', 'design-direction'],
        'generate-images'  => ['image-prompt-repair'],
    ];

    /** @var array<int,array{id:string,secs:float,in:int,out:int,model:?string}> */
    private array $rows = [];
    private float $totalSecs = 0.0;
    private float $wallSecs = 0.0;
    private int $lastInputTokens = 0;
    private int $lastOutputTokens = 0;
    private ?int $llmInputTokens = null;
    private ?int $llmOutputTokens = null;

    private bool $hasImages = false;
    private int $imagesGenerated = 0;
    private int $imagesFailed = 0;
    private int $imagesTotal = 0;

    private bool $hasPatterns = false;
    private int $sectionPatternsWritten = 0;
    private int $componentPatternsWritten = 0;
    private int $patternsDropped = 0;

    private int $requests = 0;

    /** @var array<string,int> step id => count of delivered-through defects */
    private array $warningCounts = [];

    public function __construct(
        private string $prompt,
        private string $slug,
        private string $outputPath,
        private string $builtAt,
    ) {}

    /**
     * Record one completed step from the LLM client's cumulative usage totals.
     * The shared cursor keeps pipeline and out-of-pipeline steps on the same
     * accounting path, so conditional calls cannot disappear from the report.
     * A zero-token step records no model even if it had one configured.
     *
     * @return array{id:string,secs:float,in:int,out:int,model:?string}
     */
    public function recordStep(
        string $id,
        float $secs,
        int $inputTokensTotal,
        int $outputTokensTotal,
        ?string $configuredModels = null,
    ): array
    {
        if ($inputTokensTotal < $this->lastInputTokens || $outputTokensTotal < $this->lastOutputTokens) {
            throw new \LogicException('LLM usage totals cannot decrease between build steps');
        }

        $inTokens = $inputTokensTotal - $this->lastInputTokens;
        $outTokens = $outputTokensTotal - $this->lastOutputTokens;
        $this->lastInputTokens = $inputTokensTotal;
        $this->lastOutputTokens = $outputTokensTotal;
        $model = $inTokens + $outTokens > 0 ? $configuredModels : null;
        $row = ['id' => $id, 'secs' => $secs, 'in' => $inTokens, 'out' => $outTokens, 'model' => $model];
        $this->rows[] = $row;
        $this->totalSecs += $secs;
        return $row;
    }

    /**
     * Record the run's wall-clock duration — distinct from the sum of the step
     * times, which misses the work between steps.
     */
    public function setWallSeconds(float $secs): void
    {
        $this->wallSecs = $secs;
    }

    /** Record the completion timestamp used by both the report and build-stats.json. */
    public function setBuiltAt(string $builtAt): void
    {
        $this->builtAt = $builtAt;
    }

    /** Record the image-generation tally (only when --with-images ran). */
    public function setImages(int $generated, int $failed, int $total): void
    {
        $this->hasImages = true;
        $this->imagesGenerated = $generated;
        $this->imagesFailed = $failed;
        $this->imagesTotal = $total;
    }

    /** Record the reusable-pattern tally (only when patterns.json exists). */
    public function setPatterns(int $sections, int $components, int $dropped): void
    {
        $this->hasPatterns = true;
        $this->sectionPatternsWritten = $sections;
        $this->componentPatternsWritten = $components;
        $this->patternsDropped = $dropped;
    }

    /**
     * Preserve the client's authoritative final totals for aggregate reporting.
     * Rows remain the per-step breakdown; these totals also cover any future
     * call that cannot be attributed to a row.
     */
    public function setLlmTotals(int $requests, int $inputTokens, int $outputTokens): void
    {
        if ($inputTokens < $this->lastInputTokens || $outputTokens < $this->lastOutputTokens) {
            throw new \LogicException('Final LLM usage totals cannot be smaller than recorded step totals');
        }
        $this->requests = $requests;
        $this->llmInputTokens = $inputTokens;
        $this->llmOutputTokens = $outputTokens;
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
        return $this->llmInputTokens ?? array_sum(array_column($this->rows, 'in'));
    }

    public function totalOutputTokens(): int
    {
        return $this->llmOutputTokens ?? array_sum(array_column($this->rows, 'out'));
    }

    public function totalTokens(): int
    {
        return $this->totalInputTokens() + $this->totalOutputTokens();
    }

    /** @return list<array{id:string,secs:float,in:int,out:int}> */
    public function steps(): array
    {
        return $this->rows;
    }

    /**
     * The model label for one step id, looked up in the step => model map.
     *
     * A ConcurrentGroup's id is its members joined together and never appears
     * in the map itself, so resolve each member and collapse the distinct
     * models (usually one — a group's members tend to share a tier). Without
     * this a group would report no configured model despite having spent
     * tokens. Rows that aggregate differently named phases (design direction,
     * conditional image-prompt repair) resolve their explicit config keys too.
     * Null when none of the resolved keys has a configured model. Pure.
     *
     * @param array<string,string> $stepModels step id => model id
     */
    public static function modelLabel(string $stepId, array $stepModels): ?string
    {
        $used = [];
        $members = self::MODEL_KEYS_BY_STEP[$stepId] ?? ConcurrentGroup::memberIds($stepId);
        foreach ($members as $member) {
            if (isset($stepModels[$member])) {
                $used[$stepModels[$member]] = true;
            }
        }
        return $used === [] ? null : implode(', ', array_keys($used));
    }

    /**
     * The per-step table header. Shared by live output and the final report.
     */
    public static function formatHeader(): string
    {
        return sprintf(
            '  %-' . self::ID_WIDTH . 's %8s %10s %10s %10s  %s',
            'step',
            'time',
            'in-tok',
            'out-tok',
            'total',
            'configured LLM model(s)',
        );
    }

    /**
     * One formatted table row: id, wall time, input/output/total tokens, and
     * the configured model(s) attributable to non-zero LLM spend. Shared by the
     * live console output and the log file so they never drift. Pure.
     */
    public static function formatRow(string $id, float $secs, int $inTokens, int $outTokens, ?string $model = null): string
    {
        return sprintf(
            '  %-' . self::ID_WIDTH . 's %7.1fs %10s %10s %10s  %s',
            $id,
            $secs,
            number_format($inTokens),
            number_format($outTokens),
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
            '  %-' . self::ID_WIDTH . 's %7.1fs %10s %10s %10s',
            'TOTAL',
            $this->totalSecs,
            number_format($this->totalInputTokens()),
            number_format($this->totalOutputTokens()),
            number_format($this->totalTokens()),
        );
    }

    /** The measured wall-clock duration, including work between step callbacks. */
    public function wallLine(): string
    {
        return sprintf('Wall time    : %.1fs', $this->wallSecs);
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

    /** The patterns summary line, or null when no pattern manifest exists. Pure. */
    public function patternsLine(): ?string
    {
        if (!$this->hasPatterns) {
            return null;
        }
        return sprintf(
            'Patterns: %d sections, %d components, %d dropped',
            $this->sectionPatternsWritten,
            $this->componentPatternsWritten,
            $this->patternsDropped
        );
    }

    /**
     * Render the full project.log document: header, the per-step table, totals,
     * and the image/pattern/request summary. Pure — unit-testable.
     */
    public function render(): string
    {
        $rule = str_repeat('=', self::REPORT_WIDTH);
        $sub = str_repeat('-', self::REPORT_WIDTH);

        $lines = [
            $rule,
            'BUILD REPORT — ' . $this->slug,
            $rule,
            'Prompt       : ' . $this->prompt,
            'Built at     : ' . $this->builtAt,
            'Output       : ' . $this->outputPath,
            '',
            'Steps',
            $sub,
            self::formatHeader(),
        ];

        foreach ($this->rows as $r) {
            $lines[] = self::formatRow($r['id'], $r['secs'], $r['in'], $r['out'], $r['model']);
        }

        $lines[] = $sub;
        $lines[] = $this->totalLine();
        $lines[] = $this->wallLine();
        $lines[] = '';
        $lines[] = 'LLM requests : ' . $this->requests;
        if (($img = $this->imagesLine()) !== null) {
            $lines[] = $img;
        }
        if (($patterns = $this->patternsLine()) !== null) {
            $lines[] = $patterns;
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
            'built_at'      => $this->builtAt,
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
