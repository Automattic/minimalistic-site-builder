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

    /**
     * Bounded phrases a brief uses to name its hue budget (frm PR-4v):
     * calderr's "every letter and line in one cobalt blue" met three seeds
     * that all committed single-accent, and the palette floor then rotated
     * the accent to a violet the brief never asked for. Read as whole words,
     * first match wins, the way GroundTint reads a stated page tint. An
     * accent named in so many words is checked before the one-colour
     * phrases so "one accent colour" keeps its accent.
     *
     * @var array<string, list<string>>
     */
    private const STATED_PHRASES = [
        'single-accent' => ['one accent', 'single accent', 'a single accent', 'one interaction colour', 'one interaction color'],
        'multicolor' => ['multicolour', 'multicolor', 'multi-colour', 'multi-color', 'many colours', 'many colors', 'rainbow palette'],
        'monochrome' => [
            'one colour', 'one color', 'single colour', 'single color', 'one-colour', 'one-color', 'one ink', 'single ink',
            'one hue', 'single hue', 'monochrome', 'monochromatic', 'one-ink',
        ],
    ];

    /** Colour words that complete "in one <colour>" (frm PR-4v). */
    private const COLOUR_WORDS = 'blue|red|green|yellow|orange|violet|purple|pink|black|brown|grey|gray|teal|navy|cobalt|crimson|olive|ochre|ocher|indigo|magenta|cyan|ink|rust|terracotta|burgundy|maroon|lime|mustard|amber|coral|emerald|sage';

    /** The hue budget a brief names in so many words, or null. */
    public static function statedInBrief(string $brief): ?string
    {
        $text = mb_strtolower(preg_replace('/\s+/u', ' ', $brief) ?? $brief, 'UTF-8');
        foreach (self::STATED_PHRASES as $economy => $phrases) {
            foreach ($phrases as $phrase) {
                if (preg_match('/(?<![\p{L}-])' . preg_quote($phrase, '/') . '(?![\p{L}-])/u', $text) === 1) {
                    return $economy;
                }
            }
        }
        // "one orange accent" keeps its accent; "in one cobalt blue" or
        // "in one ink" names a single colour for everything.
        if (preg_match('/(?<![\p{L}-])one (?:[\p{L}-]+ )?(?:' . self::COLOUR_WORDS . ') accent(?![\p{L}-])/u', $text) === 1) {
            return 'single-accent';
        }
        if (preg_match('/(?<![\p{L}-])(?:in|of|with) one (?:[\p{L}-]+ )?(?:' . self::COLOUR_WORDS . ')(?![\p{L}-])/u', $text) === 1) {
            return 'monochrome';
        }
        return null;
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
