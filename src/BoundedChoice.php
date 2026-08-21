<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Reading one value off a fixed vocabulary.
 *
 * Every bounded commitment in the design direction — the corner language, the
 * card construction, and the CSS kits that followed them — needs the same two
 * readings: "is this an explicit valid commitment" (null otherwise, so an
 * accidental value cannot ship), and "give me the delivered value" (the default
 * otherwise, with a durable warning when authored intent was lost).
 *
 * Those two were being copied per vocabulary, and each copy drifted a little.
 * They live here once instead.
 */
final class BoundedChoice
{
    /**
     * The commitment when the raw value is explicitly one of the allowed
     * values, null otherwise. Null means "nothing was committed", which is what
     * callers gate an opt-in behavior on.
     *
     * @param list<string> $allowed
     */
    public static function explicit(mixed $raw, array $allowed): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $value = strtolower(trim($raw));
        return in_array($value, $allowed, true) ? $value : null;
    }

    /**
     * The delivered value, falling back to the default.
     *
     * Absent, null, or blank is the documented default and says nothing. A
     * non-empty value outside the vocabulary lost authored intent, so it is
     * durable-warning material.
     *
     * @param list<string> $allowed
     * @param string       $disposition the clause naming what was replaced, so
     *        each field keeps the vocabulary its own readers already know
     * @param list<string> $warnings
     */
    public static function normalize(
        mixed $raw,
        array $allowed,
        string $default,
        string $field,
        array &$warnings = [],
        string $disposition = 'unsupported generated value replaced by default',
    ): string {
        $explicit = self::explicit($raw, $allowed);
        if ($explicit !== null) {
            return $explicit;
        }
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return $default;
        }
        $warnings[] = "designDirection.json: field {$field} authored "
            . Warnings::value($raw)
            . "; delivered \"{$default}\"; disposition {$disposition}";
        return $default;
    }
}
