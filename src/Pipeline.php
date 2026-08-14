<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Runs steps in order. No agentic loop — a fixed, deterministic sequence where
 * each step is one shot. Callers can stop after a given step id and observe
 * per-step timing via the optional reporter.
 */
final class Pipeline implements BuildPipeline
{
    /**
     * @param Step[]       $steps Validated immediately via StepGraph.
     * @param list<string> $seeds Project paths available before any step.
     */
    public function __construct(private array $steps, array $seeds = StepGraph::DEFAULT_SEEDS)
    {
        StepGraph::validate($this->steps, $seeds);
    }

    /** @return string[] ordered step ids */
    public function stepIds(): array
    {
        return array_map(static fn (Step $s) => $s->id(), $this->steps);
    }

    /**
     * Every id `--until` accepts: each step id, plus each member id of a
     * concurrent group (whose own id is its members joined by '+'). So
     * `--until=theme-json` is a valid stop even though it runs inside the
     * `theme-json+page-plan` group.
     *
     * @return string[]
     */
    public function stopIds(): array
    {
        $ids = [];
        foreach ($this->steps as $step) {
            foreach (ConcurrentGroup::memberIds($step->id()) as $part) {
                $ids[] = $part;
            }
        }
        return $ids;
    }

    /**
     * Run every step up to and including $untilId (or all steps if null).
     *
     * $fromId (or null to start at the first step) skips every step that
     * order-precedes it — those artifacts are assumed already materialized on
     * disk. Group members match the same way $untilId does: naming a member
     * starts (like it stops) at the whole group, since a group runs as a unit.
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
        ?string $fromId = null,
    ): void {
        // Route every LLM transcript for this run into the project's own
        // logs/llms/ directory (projects/<slug>/logs/llms/), not the repo root.
        LlmLogger::setDir($project->path('logs/llms'));

        // Null $fromId runs from the first step; otherwise skip until the step
        // whose id (or group member) matches, then run from there onward.
        $started = $fromId === null;

        foreach ($this->steps as $step) {
            if (!$started) {
                if (!in_array($fromId, explode('+', $step->id()), true)) {
                    continue;
                }
                $started = true;
            }
            if ($onStart !== null) {
                $onStart($step);
            }
            $start = microtime(true);
            $step->run($project);
            $elapsed = microtime(true) - $start;
            if ($reporter !== null) {
                $reporter($step, $elapsed);
            }
            // Stop after this step if its id matches — or, for a concurrent
            // group, if $untilId names one of its members (you can't stop
            // mid-group, so it stops once the whole group has run).
            if ($untilId !== null && in_array($untilId, ConcurrentGroup::memberIds($step->id()), true)) {
                return;
            }
        }
    }
}
