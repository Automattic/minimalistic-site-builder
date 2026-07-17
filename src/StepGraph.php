<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Pure operations over an ordered list of Steps: assembly-time validation
 * (every read covered by an earlier write or seed) and portable describe()
 * for hosts that compose their own graphs.
 */
final class StepGraph
{
    /** Project paths available before any step runs: meta.json from createProject(). */
    public const DEFAULT_SEEDS = ['meta.json'];

    /**
     * @param Step[]   $steps
     * @param string[] $seeds Project paths available before any step (default meta.json).
     */
    public static function validate(array $steps, array $seeds = self::DEFAULT_SEEDS): void
    {
        if ($steps === []) {
            throw new \InvalidArgumentException('StepGraph: step list must not be empty');
        }

        $available = [];
        foreach ($seeds as $seed) {
            if (!is_string($seed) || $seed === '') {
                throw new \InvalidArgumentException('StepGraph: seed paths must be non-empty strings');
            }
            $available[$seed] = true;
        }

        $seenIds = [];
        foreach ($steps as $i => $step) {
            if (!$step instanceof Step) {
                throw new \InvalidArgumentException("StepGraph: entry {$i} is not a Step");
            }
            $decl = self::declarationOf($step);
            $id = $decl->id;
            if (isset($seenIds[$id])) {
                throw new \InvalidArgumentException("StepGraph: duplicate step id '{$id}'");
            }
            $seenIds[$id] = true;

            foreach ($decl->reads as $path) {
                if (!self::covers($available, $path)) {
                    $have = $available === [] ? '(none)' : implode(', ', array_keys($available));
                    throw new \InvalidArgumentException(
                        "step \"{$id}\" reads \"{$path}\" but nothing earlier writes it (available: {$have})"
                    );
                }
            }
            foreach ($decl->writes as $path) {
                $available[$path] = true;
            }
        }
    }

    /**
     * Portable export of the graph. No host-specific tool names or YAML.
     *
     * @param Step[] $steps
     * @return list<array{id: string, label: string, reads: list<string>, writes: list<string>, concurrent: bool, members?: list<string>}>
     */
    public static function describe(array $steps): array
    {
        $rows = [];
        foreach ($steps as $step) {
            $decl = self::declarationOf($step);
            $row = [
                'id'         => $decl->id,
                'label'      => $decl->label,
                'reads'      => $decl->reads,
                'writes'     => $decl->writes,
                'concurrent' => $decl->concurrent,
            ];
            if ($step instanceof ConcurrentGroup) {
                $row['members'] = array_map(
                    static fn (Step $s) => self::declarationOf($s)->id,
                    $step->members(),
                );
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Return a step's declaration after checking the duplicated identity fields.
     * Pipeline controls use id()/label(); graph export uses the declaration, so
     * allowing them to drift would give one step two different identities.
     */
    private static function declarationOf(Step $step): StepDeclaration
    {
        $decl = $step->declaration();
        $id = $step->id();
        if ($decl->id !== $id) {
            throw new \InvalidArgumentException(
                "StepGraph: step id '{$id}' does not match declaration id '{$decl->id}'"
            );
        }

        $label = $step->label();
        if ($decl->label !== $label) {
            throw new \InvalidArgumentException(
                "StepGraph: step '{$id}' label '{$label}' does not match declaration label '{$decl->label}'"
            );
        }

        if ($step instanceof ConcurrentGroup) {
            foreach ($step->members() as $member) {
                self::declarationOf($member);
            }
        }

        return $decl;
    }

    /**
     * Whether $needed is covered by any path in $available (exact or directory glob).
     *
     * @param array<string, true> $available
     */
    public static function covers(array $available, string $needed): bool
    {
        foreach (array_keys($available) as $have) {
            if ($have === $needed) {
                return true;
            }
            // available "theme/parts/*" covers "theme/parts/*" and "theme/parts/header.html"
            if (str_ends_with($have, '/*')) {
                $prefix = substr($have, 0, -1);
                if ($needed === $have || str_starts_with($needed, $prefix)) {
                    return true;
                }
            }
            // available concrete under a dir covers a later directory read "theme/parts/*"
            if (str_ends_with($needed, '/*')) {
                $prefix = substr($needed, 0, -1);
                if (str_starts_with($have, $prefix)) {
                    return true;
                }
            }
        }
        return false;
    }
}
