<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\TreeGraph;

/**
 * The metered call budget, ported from x-pipeline's BudgetMeter. Consulted
 * BEFORE every generative call; a breach is a thrown structured error, never
 * a warning. Before the brief fixes the ceiling, only the pre-ceiling
 * allowance (the brief call plus its one schema-retry) may spend.
 */
final class BudgetMeter
{
    /** S1 + its one schema-retry; nothing else may run before the ceiling exists. */
    private const PRE_CEILING_ALLOWANCE = 2;

    private ?int $ceiling = null;
    private int $spent = 0;

    /** @var list<array{task_type:string,label:string}> */
    private array $calls = [];

    public function __construct(private readonly int $hardCap = 120)
    {
    }

    public function setCeiling(int $ceiling): void
    {
        if ($ceiling > $this->hardCap) {
            throw new TreeGraphException(
                'budget_exceeded',
                "this brief costs up to {$ceiling} calls; the budget hard cap is {$this->hardCap}",
                'Raise SITE_BUILD_TREE_BUDGET_CAP or narrow the prompt.',
                ['ceiling' => $ceiling, 'hard_cap' => $this->hardCap],
            );
        }
        $this->ceiling = $ceiling;
    }

    public function spend(string $taskType, string $label): void
    {
        $limit = $this->ceiling ?? self::PRE_CEILING_ALLOWANCE;
        if ($this->spent + 1 > $limit) {
            throw new TreeGraphException(
                'budget_exceeded',
                'call ' . ($this->spent + 1) . " ({$taskType}:{$label}) would exceed the ceiling of {$limit}",
                'The run ends with a report, never with silent extra spending.',
                ['spent' => $this->spent, 'ceiling' => $limit, 'task_type' => $taskType, 'label' => $label],
            );
        }
        $this->spent++;
        $this->calls[] = ['task_type' => $taskType, 'label' => $label];
    }

    /**
     * A resumed run is the SAME bill, continued. The meter lives in memory,
     * so without this a resume restarts at zero: the ceiling stops binding
     * and the report claims a spend of 0 against a ledger holding every real
     * call. $spentCalls is the resumed ledger's attempt count.
     */
    public function rehydrate(int $spentCalls): void
    {
        $this->spent += max(0, $spentCalls);
    }

    public function spent(): int
    {
        return $this->spent;
    }

    public function ceiling(): ?int
    {
        return $this->ceiling;
    }

    /** @return list<array{task_type:string,label:string}> */
    public function calls(): array
    {
        return $this->calls;
    }
}
