<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Exclude direct negative clauses from optional design choices.
 *
 * A negative clause runs to the next clause break. A dash is one of those:
 * "not black and white — colour photography please" states a real request
 * after the dash, and swallowing it would turn an affirmative brief into
 * silence. A hyphen only breaks a clause when it is spaced, so a hyphenated
 * word ("black-and-white") stays inside the clause it belongs to.
 */
final class AffirmativeBrief
{
    private const BREAK = '[,.;:—–]|\s-\s|\bbut\b|\binstead\b|$';

    public static function text(string $brief): string
    {
        return preg_replace(
            '/(?<![\p{L}-])(?:avoid|without|not|no|never|omit|exclude|don[\'’]t)\b(?:(?!' . self::BREAK . ').)*?(?=' . self::BREAK . ')/iu',
            ' ',
            $brief,
        ) ?? $brief;
    }
}
