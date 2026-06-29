<?php
declare(strict_types=1);

/**
 * Runs several ConcurrentSteps as ONE batched LLM call, so independent steps
 * that only share an upstream input (e.g. theme.json and the section plan, both
 * derived from siteSpec) overlap instead of running back to back.
 *
 * It is itself a Step, so the Pipeline stays a flat, ordered list and needs no
 * concept of parallelism: gather every member's requests(), fire one
 * completeJsonBatch(), route the results back, then let each member consume()
 * its own. Member request keys are namespaced by position so two steps can use
 * the same local key without colliding.
 */
final class ConcurrentGroup implements Step
{
    /** @var ConcurrentStep[] */
    private array $steps;

    /** @param ConcurrentStep[] $steps */
    public function __construct(private Llm $llm, array $steps)
    {
        if ($steps === []) {
            throw new InvalidArgumentException('ConcurrentGroup needs at least one step');
        }
        $this->steps = array_values($steps);
    }

    public function id(): string
    {
        return implode('+', array_map(static fn (Step $s) => $s->id(), $this->steps));
    }

    public function label(): string
    {
        return 'Concurrently: ' . implode(', ', array_map(static fn (Step $s) => $s->label(), $this->steps));
    }

    public function run(Project $project): void
    {
        // Collect every member's prompts, tagging each key with the member index
        // so identical local keys from different steps never collide.
        $merged = [];
        $owner = [];
        foreach ($this->steps as $i => $step) {
            foreach ($step->requests($project) as $key => $req) {
                $globalKey = "{$i}:{$key}";
                $merged[$globalKey] = $req;
                $owner[$globalKey] = [$i, $key];
            }
        }

        $results = $this->llm->completeJsonBatch($merged);

        // Route results back to the step that asked for them.
        $byStep = array_fill_keys(array_keys($this->steps), []);
        foreach ($results as $globalKey => $data) {
            [$i, $key] = $owner[$globalKey];
            $byStep[$i][$key] = $data;
        }

        foreach ($this->steps as $i => $step) {
            $step->consume($project, $byStep[$i]);
        }
    }
}
