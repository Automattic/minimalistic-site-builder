<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * A stated accent hue (frm PR-4y): the colour family a brief names for its
 * accent in so many words ("a single orange accent on the buttons", "one
 * cobalt accent", "red buttons"). parley's brief names orange; the seed
 * authored yellow in three cohorts, and when the model authored orange the
 * palette floor's hue-separation repair rotated it to yellow because the
 * primary is an orange-brown. The stated hue is the seventh stated-axis
 * reader: the direction's accent is repaired into the family, and the floor
 * moves the primary away from a stated accent, never the accent.
 *
 * Families are HSL hue arcs in degrees (representative, start, end), circular
 * where an arc crosses zero. Neutral words (black, white, grey, ink) name no
 * hue and are not accents here.
 */
final class AccentHue
{
    /** @var array<string, array{0:float,1:float,2:float}> word => [representative, start, end] */
    public const FAMILIES = [
        'red'        => [0.0, 345.0, 12.0],
        'crimson'    => [352.0, 340.0, 6.0],
        'coral'      => [12.0, 4.0, 22.0],
        'terracotta' => [18.0, 8.0, 30.0],
        'rust'       => [20.0, 10.0, 32.0],
        'orange'     => [28.0, 15.0, 45.0],
        'amber'      => [42.0, 32.0, 52.0],
        'ochre'      => [40.0, 30.0, 50.0],
        'ocher'      => [40.0, 30.0, 50.0],
        'mustard'    => [46.0, 38.0, 56.0],
        'yellow'     => [52.0, 45.0, 66.0],
        'lime'       => [85.0, 66.0, 105.0],
        'olive'      => [75.0, 55.0, 95.0],
        'sage'       => [120.0, 90.0, 150.0],
        'green'      => [130.0, 95.0, 165.0],
        'emerald'    => [150.0, 135.0, 170.0],
        'teal'       => [180.0, 165.0, 195.0],
        'cyan'       => [190.0, 180.0, 205.0],
        'blue'       => [222.0, 200.0, 250.0],
        'cobalt'     => [225.0, 212.0, 238.0],
        'navy'       => [228.0, 215.0, 245.0],
        'indigo'     => [250.0, 235.0, 265.0],
        'violet'     => [275.0, 258.0, 292.0],
        'purple'     => [280.0, 262.0, 300.0],
        'magenta'    => [310.0, 292.0, 330.0],
        'pink'       => [335.0, 318.0, 352.0],
        'burgundy'   => [350.0, 335.0, 5.0],
        'maroon'     => [355.0, 340.0, 8.0],
    ];

    /**
     * The accent family a brief names, or null.
     *
     * @return array{word:string,hue:float,start:float,end:float}|null
     */
    public static function statedInBrief(string $brief): ?array
    {
        $text = mb_strtolower(preg_replace('/\s+/u', ' ', $brief) ?? $brief, 'UTF-8');
        $words = implode('|', array_map(
            static fn (string $word): string => preg_quote($word, '/'),
            array_keys(self::FAMILIES),
        ));
        $patterns = [
            // "a single orange accent", "one cobalt accent", "orange accent on the buttons"
            '/(?<![\p{L}-])(' . $words . ')(?:-[\p{L}]+)? accents?(?![\p{L}-])/u',
            // "accent in orange", "an accent of cobalt"
            '/(?<![\p{L}-])accents? (?:in|of) (' . $words . ')(?![\p{L}-])/u',
            // "orange buttons", "a red CTA", "cobalt pill CTA"
            '/(?<![\p{L}-])(' . $words . ') (?:pill )?(?:buttons?|ctas?|pills?)(?![\p{L}-])/u',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m) === 1) {
                $word = $m[1];
                [$hue, $start, $end] = self::FAMILIES[$word];
                return ['word' => $word, 'hue' => $hue, 'start' => $start, 'end' => $end];
            }
        }
        return null;
    }

    /**
     * The stated accent family from a build's meta, the user's own words
     * first and the refined brief second.
     *
     * @param array<string,mixed> $meta
     * @return array{word:string,hue:float,start:float,end:float}|null
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
     * Whether a hex sits inside the family's arc with enough chroma to read
     * as that colour. A grey or an unreadable value is never in a family.
     *
     * @param array{word:string,hue:float,start:float,end:float} $family
     */
    public static function inFamily(string $hex, array $family): bool
    {
        $hue = PaletteFloor::hue($hex);
        $chroma = PaletteFloor::chroma($hex);
        if ($hue === null || $chroma === null || $chroma <= PaletteFloor::CHROMA_MIN) {
            return false;
        }
        $start = $family['start'];
        $end = $family['end'];
        if ($start <= $end) {
            return $hue >= $start && $hue <= $end;
        }
        return $hue >= $start || $hue <= $end;
    }

    /**
     * The hex moved onto the family's representative hue, saturation and
     * lightness held; null for an unreadable hex or one too grey to carry a hue.
     *
     * @param array{word:string,hue:float,start:float,end:float} $family
     */
    public static function toFamily(string $hex, array $family): ?string
    {
        $chroma = PaletteFloor::chroma($hex);
        if ($chroma === null || $chroma <= PaletteFloor::CHROMA_MIN) {
            return null;
        }
        return PaletteFloor::atHue($hex, $family['hue']);
    }
}
