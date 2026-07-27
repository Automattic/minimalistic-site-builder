<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Attributes;

/**
 * Maps an unregistered comment-attribute key onto the registered attribute it
 * was probably meant to be.
 *
 * Generated markup misses attribute names in two predictable ways: it varies
 * the shape of a real name (`vertical_alignment`, `VerticalAlignment`) or it
 * misspells one (`verticleAlignment`, `textColour`). Both carry authored
 * intent that the block can still honour, so renaming beats dropping.
 *
 * Everything here is a guess, so each guess has to be unambiguous before it is
 * applied. A rename must win outright — a tie between two candidates is
 * rejected rather than broken arbitrarily — and the authored value must fit the
 * target's declared type, because renaming a value the block cannot store just
 * moves the failure downstream. A key that clears none of that is left for the
 * caller to drop.
 *
 * Pure and side-effect free.
 */
final class AttributeNameResolver
{
    /**
     * Maximum edit distance between two normalized names that still reads as a
     * typo. Two covers the observed cases (a transposition, a dropped letter, a
     * British spelling) without letting genuinely different short names collide.
     */
    private const MAX_DISTANCE = 2;

    /**
     * Shortest normalized key eligible for distance matching. Below this, two
     * unrelated names are routinely within MAX_DISTANCE of each other (`top`
     * and `tag`, `id` and `url`), so only exact shape matches are trusted.
     */
    private const MIN_TYPO_LENGTH = 4;

    /**
     * The registered attribute $key was probably meant to name, or null when
     * there is no unambiguous answer.
     *
     * @param mixed                             $value   the authored value, natively typed
     * @param array<string,array<string,mixed>> $schemas the block's registered attribute schemas
     * @param array<string,mixed>               $taken   keys the comment already carries; never overwritten
     */
    public static function resolve(string $key, mixed $value, array $schemas, array $taken = []): ?string
    {
        $normalized = self::normalize($key);
        if ($normalized === '') {
            return null;
        }

        $candidates = [];
        foreach ($schemas as $name => $schema) {
            // A key the comment already carries is an explicit authorial
            // choice. Renaming onto it would silently overwrite that value
            // with a guess, which is worse than dropping the stray key.
            if (array_key_exists($name, $taken) || !is_array($schema)) {
                continue;
            }
            $candidates[$name] = self::normalize((string) $name);
        }

        $exact = array_keys($candidates, $normalized, true);
        if (count($exact) === 1) {
            return self::typeMatches($value, $schemas[$exact[0]]) ? $exact[0] : null;
        }
        // Two registered names that differ only in shape cannot be told apart
        // from the authored key alone.
        if ($exact !== []) {
            return null;
        }

        if (strlen($normalized) < self::MIN_TYPO_LENGTH) {
            return null;
        }

        $best = null;
        $bestDistance = self::MAX_DISTANCE + 1;
        $tied = false;
        foreach ($candidates as $name => $candidate) {
            // levenshtein() is byte-oriented and returns -1 past 255 bytes;
            // attribute names never approach that, and a negative would
            // otherwise read as a perfect match.
            $distance = levenshtein($normalized, $candidate);
            if ($distance < 0 || $distance > self::MAX_DISTANCE) {
                continue;
            }
            if ($distance < $bestDistance) {
                $best = $name;
                $bestDistance = $distance;
                $tied = false;
            } elseif ($distance === $bestDistance) {
                $tied = true;
            }
        }

        if ($best === null || $tied) {
            return null;
        }
        return self::typeMatches($value, $schemas[$best]) ? $best : null;
    }

    /**
     * Case- and separator-insensitive form, so `vertical_alignment`,
     * `Vertical-Alignment` and `verticalAlignment` compare equal.
     */
    private static function normalize(string $name): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', $name));
    }

    /**
     * Whether the authored value can be stored under the target schema. A
     * schema with no declared type accepts anything. JSON objects arrive as
     * either stdClass or an associative array depending on the decode path, so
     * both are treated as `object`.
     *
     * @param array<string,mixed> $schema
     */
    private static function typeMatches(mixed $value, array $schema): bool
    {
        $types = $schema['type'] ?? null;
        if ($types === null) {
            return true;
        }
        foreach ((array) $types as $type) {
            $ok = match ((string) $type) {
                'string'  => is_string($value),
                'number'  => is_int($value) || is_float($value),
                'integer' => is_int($value),
                'boolean' => is_bool($value),
                'null'    => $value === null,
                'array'   => is_array($value) && array_is_list($value),
                'object'  => $value instanceof \stdClass || (is_array($value) && !array_is_list($value)),
                default   => true,
            };
            if ($ok) {
                return true;
            }
        }
        return false;
    }
}
