<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * A large-area surface derived from the page ground, never from a text role.
 *
 * HSL lightness is the committed L coordinate here: the band stays in the
 * base hue family and exactly ten points away, comfortably inside the six to
 * fourteen point acceptance window. The move stays on the base side of the
 * light/dark midpoint so a dark page cannot acquire a pale tinted band.
 */
final class BandColor
{
    public const MIN_DELTA = 0.06;
    public const MAX_DELTA = 0.14;
    public const TARGET_DELTA = 0.10;

    /** Interior hue for each chromatic GroundTint family. */
    private const FAMILY_CENTERS = [
        'warm' => 40.0,
        'green' => 120.0,
        'cool' => 210.0,
        'violet' => 280.0,
        'blush' => 345.0,
    ];

    public static function valid(string $base, string $band): bool
    {
        $baseRgb = ContrastMath::hexToRgb($base);
        $bandRgb = ContrastMath::hexToRgb($band);
        if ($baseRgb === null || $bandRgb === null) {
            return false;
        }
        $baseFamily = GroundTint::classify($base);
        $bandFamily = GroundTint::classify($band);
        if ($baseFamily === null || $bandFamily === null) {
            return false;
        }
        // Near the neutral threshold the family name is quantization, not a
        // visible difference: a base at chroma 0.019 and a band at 0.035 are
        // two whispers of the same grey, and rejecting the pair replaced
        // authored bands with flat grey (BIGR-919). Two near-grey surfaces
        // are one family whatever the classifier calls each side.
        $nearGrey = static fn (array $rgb): bool =>
            GroundTint::chromaOf($rgb) <= GroundTint::NEUTRAL_CHROMA * 2;
        if ($bandFamily !== $baseFamily && !($nearGrey($baseRgb) && $nearGrey($bandRgb))) {
            return false;
        }
        $baseLightness = self::toHsl($baseRgb)[2];
        $bandLightness = self::toHsl($bandRgb)[2];
        $delta = abs($bandLightness - $baseLightness);
        return $delta + 1e-9 >= self::MIN_DELTA
            && $delta - 1e-9 <= self::MAX_DELTA
            && self::isDark($baseLightness) === self::isDark($bandLightness);
    }

    /** The deterministic band for one valid base hex, or null for invalid input. */
    public static function fromBase(string $base): ?string
    {
        $rgb = ContrastMath::hexToRgb($base);
        if ($rgb === null) {
            return null;
        }
        [$hue, $saturation, $lightness] = self::toHsl($rgb);
        $family = GroundTint::classify($base);
        $target = self::targetLightness($lightness);
        if ($family === 'neutral') {
            // A neutral ground is rarely a mathematical grey: the classifier
            // calls anything under GroundTint::NEUTRAL_CHROMA grey, and real
            // bases keep a whisper of tint inside that band. Saturation zero
            // here shipped flat #313131 / #CCCCCC surfaces on tinted
            // near-greys (BIGR-919). Keep the base's hue and as much of its
            // saturation as still classifies neutral at the band's lightness.
            $saturation = min($saturation, self::neutralSaturationCeiling($target));
        }

        // A visibly tinted base can sit barely above GroundTint's neutral
        // threshold at an extreme. Moving it toward the middle at unchanged
        // saturation usually increases chroma; when rounding still collapses
        // it, increase saturation only until the committed family survives.
        // A neutral base retreats the other way — toward true grey — until
        // the candidate classifies neutral again.
        for ($try = 0; $try < 9; $try++) {
            $candidate = self::toHex(self::hslToRgb($hue, min(1.0, $saturation), $target));
            if (GroundTint::classify($candidate) === $family && self::valid($base, $candidate)) {
                return $candidate;
            }
            if ($family === 'neutral') {
                $saturation *= 0.5;
                continue;
            }
            $saturation += 0.10;
            if (isset(self::FAMILY_CENTERS[$family])) {
                // Quantization can move a hue authored exactly on a family
                // boundary (20, 70, or 255 degrees) a fraction outside it.
                // Walk at most one degree per retry toward the same family's
                // interior instead of returning no band for a valid base.
                $hue = self::toward($hue, self::FAMILY_CENTERS[$family], 1.0);
            }
        }
        return null;
    }

    /**
     * The highest HSL saturation that still reads as neutral at one
     * lightness. RGB chroma of HSL(h, s, l) is s * (1 - |2l - 1|); hold it a
     * step under GroundTint::NEUTRAL_CHROMA so 8-bit rounding cannot push
     * the candidate over the threshold.
     */
    private static function neutralSaturationCeiling(float $lightness): float
    {
        $span = max(1e-6, 1.0 - abs(2.0 * $lightness - 1.0));
        return (GroundTint::NEUTRAL_CHROMA * 0.85) / $span;
    }

    /** HSL lightness in [0,1], or null when the hex is invalid. */
    public static function lightness(string $hex): ?float
    {
        $rgb = ContrastMath::hexToRgb($hex);
        return $rgb === null ? null : self::toHsl($rgb)[2];
    }

    private static function targetLightness(float $base): float
    {
        if (self::isDark($base)) {
            return $base + self::TARGET_DELTA < 0.5
                ? $base + self::TARGET_DELTA
                : $base - self::TARGET_DELTA;
        }
        return $base - self::TARGET_DELTA >= 0.5
            ? $base - self::TARGET_DELTA
            : $base + self::TARGET_DELTA;
    }

    private static function isDark(float $lightness): bool
    {
        return $lightness < 0.5;
    }

    private static function toward(float $from, float $to, float $step): float
    {
        $delta = fmod($to - $from + 540.0, 360.0) - 180.0;
        if (abs($delta) <= $step) {
            return $to;
        }
        return fmod($from + ($delta < 0 ? -$step : $step) + 360.0, 360.0);
    }

    /** @param array{0:int,1:int,2:int} $rgb @return array{0:float,1:float,2:float} */
    private static function toHsl(array $rgb): array
    {
        [$r, $g, $b] = [$rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255];
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $lightness = ($max + $min) / 2;
        $chroma = $max - $min;
        $denominator = 1 - abs(2 * $lightness - 1);
        $saturation = abs($chroma) < 1e-12 || abs($denominator) < 1e-12
            ? 0.0
            : $chroma / $denominator;
        if (abs($chroma) < 1e-12) {
            return [0.0, $saturation, $lightness];
        }
        $hue = $max === $r
            ? fmod(($g - $b) / $chroma, 6.0)
            : ($max === $g ? ($b - $r) / $chroma + 2.0 : ($r - $g) / $chroma + 4.0);
        return [fmod($hue * 60.0 + 360.0, 360.0), $saturation, $lightness];
    }

    /** @return array{0:int,1:int,2:int} */
    private static function hslToRgb(float $hue, float $saturation, float $lightness): array
    {
        $chroma = (1 - abs(2 * $lightness - 1)) * $saturation;
        $second = $chroma * (1 - abs(fmod($hue / 60.0, 2.0) - 1));
        $base = $lightness - $chroma / 2;
        [$r, $g, $b] = match ((int) floor($hue / 60.0) % 6) {
            0 => [$chroma, $second, 0.0],
            1 => [$second, $chroma, 0.0],
            2 => [0.0, $chroma, $second],
            3 => [0.0, $second, $chroma],
            4 => [$second, 0.0, $chroma],
            default => [$chroma, 0.0, $second],
        };
        return [
            (int) round(max(0.0, min(1.0, $r + $base)) * 255),
            (int) round(max(0.0, min(1.0, $g + $base)) * 255),
            (int) round(max(0.0, min(1.0, $b + $base)) * 255),
        ];
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    private static function toHex(array $rgb): string
    {
        return sprintf('#%02X%02X%02X', $rgb[0], $rgb[1], $rgb[2]);
    }
}
