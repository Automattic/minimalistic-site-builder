<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * The number of independent hue families a direction intentionally uses.
 *
 * Semantic palette roles remain available in every mode. The economy decides
 * whether those roles are tonal relatives or deliberately different hues; it
 * does not remove any theme.json slug.
 */
final class ColorEconomy
{
    public const ALL = ['monochrome', 'single-accent', 'multicolor'];

    /** Missing directions receive the restrained general-purpose policy. */
    public const DEFAULT = 'single-accent';

    public static function explicit(mixed $value): ?string
    {
        return BoundedChoice::explicit($value, self::ALL);
    }

    /**
     * Normalize generated input while recording the same actionable warning
     * shape as the other bounded design-direction fields.
     *
     * @param list<string> $warnings
     */
    public static function normalize(mixed $value, array &$warnings): string
    {
        return BoundedChoice::normalize(
            $value,
            self::ALL,
            self::DEFAULT,
            'color_economy',
            $warnings,
            'invalid palette economy replaced by deterministic single-accent fallback',
        );
    }

    /** Whether primary and accent are expected to be independent hue families. */
    public static function requiresAccentHueSeparation(string $economy): bool
    {
        return $economy !== 'monochrome';
    }

    public static function meaning(string $economy): string
    {
        return match ($economy) {
            'monochrome' => 'one hue family or a neutral scale; semantic roles vary by tone, never by a forced counter-hue',
            'multicolor' => 'multiple purposeful hue families with a defined role for each',
            default => 'a neutral or tonal foundation with one independent interaction hue',
        };
    }
}
