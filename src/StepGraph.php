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
    /**
     * @param Step[]   $steps
     * @param string[] $seeds Project paths available before any step (default meta.json).
     */
    public static function validate(array $steps, array $seeds = ['meta.json']): void
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
            $decl = $step->declaration();
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
            $decl = $step->declaration();
            $row = [
                'id'         => $decl->id,
                'label'      => $decl->label,
                'reads'      => $decl->reads,
                'writes'     => $decl->writes,
                'concurrent' => $decl->concurrent,
            ];
            if ($step instanceof ConcurrentGroup) {
                $row['members'] = array_map(
                    static fn (Step $s) => $s->declaration()->id,
                    $step->members(),
                );
            }
            $rows[] = $row;
        }
        return $rows;
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
