<?php
declare(strict_types=1);

/**
 * Runs steps in order. No agentic loop — a fixed, deterministic sequence where
 * each step is one shot. Callers can stop after a given step id and observe
 * per-step timing via the optional reporter.
 */
final class Pipeline
{
    /** @param Step[] $steps */
    public function __construct(private array $steps) {}

    /** @return string[] ordered step ids */
    public function stepIds(): array
    {
        return array_map(static fn (Step $s) => $s->id(), $this->steps);
    }

    /**
     * Run every step up to and including $untilId (or all steps if null).
     *
     * @param callable(Step,float):void|null $reporter called after each step with (step, seconds)
     * @param callable(Step):void|null       $onStart  called before each step->run() with (step),
     *                                                  so a "starting" line can be shown for long steps
     */
    public function runThrough(
        Project $project,
        ?string $untilId = null,
        ?callable $reporter = null,
        ?callable $onStart = null,
    ): void {
        foreach ($this->steps as $step) {
            if ($onStart !== null) {
                $onStart($step);
            }
            $start = microtime(true);
            $step->run($project);
            $elapsed = microtime(true) - $start;
            if ($reporter !== null) {
                $reporter($step, $elapsed);
            }
            if ($untilId !== null && $step->id() === $untilId) {
                return;
            }
        }
    }
}
