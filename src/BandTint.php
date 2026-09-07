<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * A band colour the brief states (frm PR-2ag).
 *
 * zova's brief states "a pale blue gradient panel hero", and six cohorts
 * shipped the panel in the neutral grey band that BandColor derives from a
 * white page. The page tint reader (GroundTint) reads the page and the accent
 * reader (AccentHue) reads the buttons; nothing read the panel. A colour word
 * beside a surface noun ("pale blue panel", "lavender band", "soft blue
 * gradient panel") names the band's tint family, and the band moves into that
 * family at the lightness BandColor gives it, with enough chroma to read as
 * the colour rather than as a whisper of it.
 */
final class BandTint
{
    /** Colour words a brief uses for a surface, each to its GroundTint family. */
    private const WORDS = [
        'sky blue' => 'cool', 'ice blue' => 'cool', 'powder blue' => 'cool', 'baby blue' => 'cool',
        'steel blue' => 'cool', 'blue' => 'cool', 'sky' => 'cool', 'azure' => 'cool', 'cool grey' => 'cool', 'cool gray' => 'cool',
        'lavender' => 'violet', 'lilac' => 'violet', 'violet' => 'violet', 'purple' => 'violet', 'mauve' => 'violet',
        'sage' => 'green', 'mint' => 'green', 'green' => 'green', 'eucalyptus' => 'green', 'pistachio' => 'green',
        'blush' => 'blush', 'pink' => 'blush', 'rose' => 'blush',
        'cream' => 'warm', 'sand' => 'warm', 'beige' => 'warm', 'peach' => 'warm', 'ivory' => 'warm',
        'apricot' => 'warm', 'butter' => 'warm', 'warm grey' => 'warm', 'warm gray' => 'warm',
    ];

    /** The surface nouns that make a colour word a band colour, not an accent or a page. */
    private const SURFACES = 'panels?|bands?|plates?|hero panel|gradient panels?|gradient';

    /** The optional lightness words a brief puts before the colour. */
    private const SHADES = 'pale|soft|light|faint|muted|powder|pastel|dusty|misty|washed|tinted|gentle';

    /** The chroma a stated band carries, so the colour reads at a glance (BandColor's grey band has none). */
    public const CHROMA = 0.10;

    /**
     * The band tint a brief names beside a surface noun, or null.
     *
     * @return array{word:string,tint:string}|null
     */
    public static function statedInBrief(string $brief): ?array
    {
        $text = mb_strtolower(preg_replace('/\s+/u', ' ', $brief) ?? $brief, 'UTF-8');
        $words = implode('|', array_map(
            static fn (string $word): string => preg_quote($word, '/'),
            array_keys(self::WORDS),
        ));
        // "pale blue gradient panel hero", "a lavender band", "soft sage panels"
        $pattern = '/(?<![\p{L}-])(?:(?:' . self::SHADES . ') )?(' . $words . ')(?:-tinted|-washed)? (?:' . self::SURFACES . ')(?![\p{L}-])/u';
        if (preg_match($pattern, $text, $m) === 1) {
            return ['word' => $m[1], 'tint' => self::WORDS[$m[1]]];
        }
        return null;
    }

    /**
     * The stated band tint from a build's meta, the user's own words first
     * and the refined brief second.
     *
     * @param array<string,mixed> $meta
     * @return array{word:string,tint:string}|null
     */
    public static function statedFor(array $meta): ?array
    {
        foreach (['original_prompt', 'prompt'] as $key) {
            $text = $meta[$key] ?? null;
            if (is_string($text) && trim($text) !== '') {
                $stated = self::statedInBrief($text);
                if ($stated !== null) {
                    return $stated;
                }
            }
        }
        return null;
    }

    /**
     * The band for `$base` inside the stated family: BandColor's lightness
     * (ten points from the base, on the base's side of the light/dark key)
     * at the family's centre hue with CHROMA, or GroundTint's own retint of
     * that band when the chroma target does not satisfy the band contract.
     * Null when no band in the family satisfies it.
     */
    public static function apply(string $base, string $tint): ?string
    {
        $grey = BandColor::fromBase($base);
        if ($grey === null || !in_array($tint, GroundTint::ALL, true) || $tint === 'neutral') {
            return null;
        }
        $lightness = BandColor::lightness($grey);
        if ($lightness !== null) {
            $span = max(1e-6, 1.0 - abs(2.0 * $lightness - 1.0));
            $saturation = min(1.0, self::CHROMA / $span);
            $candidate = self::hex(GroundTint::centerOf($tint), $saturation, $lightness);
            if (GroundTint::classify($candidate) === $tint && BandColor::valid($base, $candidate, $tint)) {
                return $candidate;
            }
        }
        $moved = GroundTint::retint($grey, $tint);
        if ($moved !== null && GroundTint::classify($moved) === $tint && BandColor::valid($base, $moved, $tint)) {
            return $moved;
        }
        return null;
    }

    private static function hex(float $hue, float $saturation, float $lightness): string
    {
        $chroma = (1 - abs(2 * $lightness - 1)) * $saturation;
        $second = $chroma * (1 - abs(fmod($hue / 60, 2) - 1));
        $m = $lightness - $chroma / 2;
        [$r, $g, $b] = match ((int) floor($hue / 60) % 6) {
            0       => [$chroma, $second, 0.0],
            1       => [$second, $chroma, 0.0],
            2       => [0.0, $chroma, $second],
            3       => [0.0, $second, $chroma],
            4       => [$second, 0.0, $chroma],
            default => [$chroma, 0.0, $second],
        };
        return sprintf(
            '#%02X%02X%02X',
            (int) round(($r + $m) * 255),
            (int) round(($g + $m) * 255),
            (int) round(($b + $m) * 255),
        );
    }
}
