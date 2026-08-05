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

    /** Prefix keeps numeric-string paths from being coerced to integer array keys. */
    public const PATH_SET_PREFIX = "\0path:";

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
            StepDeclaration::assertValidProjectPath($seed, 'StepGraph: seed paths');
            $available[self::pathSetKey($seed)] = true;
        }

        /** @var array<string, string> $seenIds id => first owner */
        $seenIds = [];
        foreach ($steps as $i => $step) {
            if (!$step instanceof Step) {
                throw new \InvalidArgumentException("StepGraph: entry {$i} is not a Step");
            }
            $decl = self::declarationOf($step);
            $id = $decl->id;
            self::claimId($seenIds, $id, "top-level step '{$id}'");
            if ($step instanceof ConcurrentGroup) {
                $members = $step->members();
                foreach ($members as $member) {
                    $memberId = $member->id();
                    // A one-member group has the same composite and member id;
                    // those are two views of one addressable node, not a clash.
                    if (count($members) === 1 && $memberId === $id) {
                        continue;
                    }
                    self::claimId(
                        $seenIds,
                        $memberId,
                        "member '{$memberId}' of group '{$id}'",
                    );
                }
            }

            foreach ($decl->reads as $path) {
                if (!self::covers($available, $path)) {
                    $have = $available === [] ? '(none)' : implode(', ', self::pathsInSet($available));
                    throw new \InvalidArgumentException(
                        "step \"{$id}\" reads \"{$path}\" but nothing earlier writes it (available: {$have})"
                    );
                }
            }
            foreach ($decl->writes as $path) {
                $available[self::pathSetKey($path)] = true;
            }
        }
    }

    /**
     * Keep every pipeline-addressable identity in one namespace. Top-level
     * steps are addressed by stepIds(), while concurrent members are accepted
     * by stopIds()/runThrough(); allowing either kind to collide makes the
     * requested checkpoint ambiguous.
     *
     * @param array<string, string> $seenIds id => first owner
     */
    private static function claimId(array &$seenIds, string $id, string $owner): void
    {
        if (isset($seenIds[$id])) {
            throw new \InvalidArgumentException(
                "StepGraph: duplicate addressable step id '{$id}' ({$owner} conflicts with {$seenIds[$id]})"
            );
        }
        $seenIds[$id] = $owner;
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
     * @param array<string|int, true> $available
     */
    public static function covers(array $available, string $needed): bool
    {
        foreach (self::pathsInSet($available) as $have) {
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

    private static function pathSetKey(string $path): string
    {
        return self::PATH_SET_PREFIX . $path;
    }

    /**
     * Decode internal path-set keys. Unprefixed keys remain supported for
     * direct covers() callers, including PHP-coerced numeric-string keys.
     *
     * @param array<string|int, true> $paths
     * @return list<string>
     */
    private static function pathsInSet(array $paths): array
    {
        $decoded = [];
        foreach (array_keys($paths) as $key) {
            if (is_string($key) && str_starts_with($key, self::PATH_SET_PREFIX)) {
                $decoded[] = substr($key, strlen(self::PATH_SET_PREFIX));
            } else {
                $decoded[] = (string) $key;
            }
        }
        return $decoded;
    }
}
