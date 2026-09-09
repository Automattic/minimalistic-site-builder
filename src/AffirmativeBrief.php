<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Exclude direct negative clauses from optional design choices. */
final class AffirmativeBrief
{
    public static function text(string $brief): string
    {
        return preg_replace(
            '/(?<![\p{L}-])(?:avoid|without|not|no|never|omit|exclude|don[\'’]t)\b[^,.;:]*?(?=[,.;:]|\bbut\b|\binstead\b|$)/iu',
            ' ',
            $brief,
        ) ?? $brief;
    }
}
