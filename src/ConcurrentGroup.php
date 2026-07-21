<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

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
            throw new \InvalidArgumentException('ConcurrentGroup needs at least one step');
        }
        $this->steps = array_values($steps);
        $this->validateMembers();
    }

    public function id(): string
    {
        return implode('+', array_map(static fn (Step $s) => $s->id(), $this->steps));
    }

    public function label(): string
    {
        return 'Concurrently: ' . implode(', ', array_map(static fn (Step $s) => $s->label(), $this->steps));
    }

    /**
     * Member steps in construction order (for graph export).
     *
     * @return ConcurrentStep[]
     */
    public function members(): array
    {
        return $this->steps;
    }

    public function declaration(): StepDeclaration
    {
        $reads = [];
        $writes = [];
        foreach ($this->steps as $step) {
            $d = $step->declaration();
            foreach ($d->reads as $path) {
                $reads[self::pathSetKey($path)] = $path;
            }
            foreach ($d->writes as $path) {
                $writes[self::pathSetKey($path)] = $path;
            }
        }
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: array_values($reads),
            writes: array_values($writes),
            concurrent: true,
        );
    }

    /** Keep numeric-string paths as strings while de-duplicating the union. */
    private static function pathSetKey(string $path): string
    {
        return "\0path:" . $path;
    }

    /**
     * Concurrent members may share inputs that already exist, but they cannot
     * exchange outputs or write to the same project path while running.
     */
    private function validateMembers(): void
    {
        $seenIds = [];
        $declarations = [];
        foreach ($this->steps as $i => $step) {
            $id = $step->id();
            if (isset($seenIds[$id])) {
                throw new \InvalidArgumentException("ConcurrentGroup has duplicate member id '{$id}'");
            }
            $seenIds[$id] = true;
            $declarations[$i] = $step->declaration();
        }

        $count = count($this->steps);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $left = $this->steps[$i];
                $right = $this->steps[$j];
                $leftDeclaration = $declarations[$i];
                $rightDeclaration = $declarations[$j];

                $overlap = self::firstOverlap($leftDeclaration->writes, $rightDeclaration->writes);
                if ($overlap !== null) {
                    [$leftPath, $rightPath] = $overlap;
                    throw new \InvalidArgumentException(
                        "ConcurrentGroup members '{$left->id()}' and '{$right->id()}' "
                        . "write overlapping paths '{$leftPath}' and '{$rightPath}'"
                    );
                }

                self::rejectReadFromMemberWrite(
                    $left->id(),
                    $leftDeclaration->reads,
                    $right->id(),
                    $rightDeclaration->writes,
                );
                self::rejectReadFromMemberWrite(
                    $right->id(),
                    $rightDeclaration->reads,
                    $left->id(),
                    $leftDeclaration->writes,
                );
            }
        }
    }

    /** @param list<string> $reads @param list<string> $writes */
    private static function rejectReadFromMemberWrite(
        string $readerId,
        array $reads,
        string $writerId,
        array $writes,
    ): void {
        $overlap = self::firstOverlap($reads, $writes);
        if ($overlap === null) {
            return;
        }

        [$read, $write] = $overlap;
        throw new \InvalidArgumentException(
            "ConcurrentGroup member '{$readerId}' reads '{$read}', which overlaps "
            . "member '{$writerId}' write '{$write}'"
        );
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     * @return array{string, string}|null
     */
    private static function firstOverlap(array $left, array $right): ?array
    {
        foreach ($left as $leftPath) {
            foreach ($right as $rightPath) {
                if (self::pathsOverlap($leftPath, $rightPath)) {
                    return [$leftPath, $rightPath];
                }
            }
        }
        return null;
    }

    private static function pathsOverlap(string $left, string $right): bool
    {
        if ($left === $right) {
            return true;
        }

        if (str_ends_with($left, '/*') && str_starts_with($right, substr($left, 0, -1))) {
            return true;
        }

        return str_ends_with($right, '/*') && str_starts_with($left, substr($right, 0, -1));
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
