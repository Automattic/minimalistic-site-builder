<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * The hue family a page's ground leans toward, orthogonal to light/dark.
 *
 * `ground` already says whether a page is built up from light or down from
 * dark; this says which way that ground is tinted. Both readings are needed
 * because the pipeline's audited failure is a warm off-white background on
 * concepts that committed to jewel tones, navy, or burgundy: the ground
 * collapses to cream while the concept's real color survives only in the
 * accent, which is CTA-only and therefore never occupies area.
 */
final class GroundTint
{
    /** Every family a ground may commit to. Evenly weighted — no hue is privileged. */
    public const ALL = ['warm', 'cool', 'violet', 'green', 'blush', 'neutral'];

    /**
     * Below this chroma a ground reads as grey whatever its hue. HSL
     * saturation cannot be used here: near white it inflates, scoring a
     * 4/255-off-grey at 0.25. Measured on real builds, seven bases that a
     * saturation test called cream were this faint.
     *
     * Public because BandColor derives surfaces that must stay inside this
     * threshold without losing the base's residual tint (BIGR-919).
     */
    public const NEUTRAL_CHROMA = 0.02;

    /**
     * RGB chroma of one pixel, the quantity NEUTRAL_CHROMA measures — the
     * (max-min) channel span over [0,1]. One shared implementation for the
     * classifier and for BandColor's near-grey tolerance.
     *
     * @param array{0:int,1:int,2:int} $rgb
     */
    public static function chromaOf(array $rgb): float
    {
        return (max($rgb) - min($rgb)) / 255;
    }

    /**
     * The family a hex belongs to, or null when it is not a hex color.
     */
    public static function classify(string $hex): ?string
    {
        $rgb = ContrastMath::hexToRgb($hex);
        if ($rgb === null) {
            return null;
        }
        if (self::chromaOf($rgb) < self::NEUTRAL_CHROMA) {
            return 'neutral';
        }
        $hue = self::hue($rgb);
        if ($hue < 20 || $hue >= 310) {
            return 'blush';
        }
        if ($hue < 70) {
            return 'warm';
        }
        if ($hue < 160) {
            return 'green';
        }
        if ($hue < 255) {
            return 'cool';
        }
        return 'violet';
    }

    /** The hue each family is rotated onto, degrees — the middle of its band. */
    private const CENTERS = [
        'warm' => 40.0, 'green' => 120.0, 'cool' => 210.0, 'violet' => 280.0, 'blush' => 345.0,
    ];

    /**
     * The same ground, moved into `$tint`. Null when the hex or the family is
     * not one this can honor.
     *
     * Rotating hue at a fixed HSL lightness would move relative luminance —
     * the green channel carries 0.7152 of it and the blue 0.0722, so a cream
     * and a blue at one lightness are nowhere near one luminance. Every
     * contrast floor downstream is stated against this color, so the rotation
     * holds luminance and lets lightness move instead.
     */
    public static function retint(string $hex, string $tint): ?string
    {
        $rgb = ContrastMath::hexToRgb($hex);
        if ($rgb === null || !in_array($tint, self::ALL, true)) {
            return null;
        }
        $groundKey = GroundKey::classify($hex);
        if (self::classify($hex) === $tint) {
            return self::toHex($rgb);
        }
        $target = ContrastMath::luminance($rgb);
        if ($tint === 'neutral') {
            $candidate = self::toHex(self::atLuminance(0.0, 0.0, $target));
            if ($groundKey !== null && GroundKey::classify($candidate) !== $groundKey) {
                $candidate = GroundKey::move($candidate, $groundKey) ?? $candidate;
            }
            return $candidate;
        }

        // Chroma collapses toward the lightness extremes, so a faint ground
        // rotated as-is can land back under the neutral threshold — committing
        // to a family the result does not visibly belong to. Raise saturation
        // until it does, rather than returning a commitment in name only.
        [, $saturation] = self::toHsl($rgb);
        for ($try = 0; $try < 8; $try++) {
            $candidate = self::toHex(
                self::atLuminance(self::CENTERS[$tint], min(1.0, $saturation), $target),
            );
            // Eight-bit rounding can put a color authored immediately beside
            // the shared luminance split onto its other side. Restore that
            // coordinate before accepting the hue-family repair.
            if ($groundKey !== null && GroundKey::classify($candidate) !== $groundKey) {
                $candidate = GroundKey::move($candidate, $groundKey) ?? $candidate;
            }
            if (
                self::classify($candidate) === $tint
                && ($groundKey === null || GroundKey::classify($candidate) === $groundKey)
            ) {
                return $candidate;
            }
            $saturation += 0.12;
        }
        return null;
    }

    /**
     * The color at hue/saturation whose relative luminance is closest to
     * $target. Luminance rises monotonically with HSL lightness at fixed hue
     * and saturation (L=0 is black, L=1 is white whatever the rest), so
     * bisection always converges.
     *
     * @return array{0:int,1:int,2:int}
     */
    private static function atLuminance(float $hue, float $saturation, float $target): array
    {
        $lo = 0.0;
        $hi = 1.0;
        for ($i = 0; $i < 60; $i++) {
            $mid = ($lo + $hi) / 2;
            if (ContrastMath::luminance(self::hslToRgb($hue, $saturation, $mid)) < $target) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }
        // Bisection solves the continuous problem; the answer then rounds to
        // 8-bit, and the nearest lightness is not always the nearest color.
        // Scan the neighborhood and keep the rounding that actually lands
        // closest, so the preserved luminance survives quantization.
        $best = null;
        $bestDelta = INF;
        $centre = ($lo + $hi) / 2;
        for ($step = -32; $step <= 32; $step++) {
            $candidate = self::hslToRgb($hue, $saturation, $centre + $step * 0.001);
            $delta = abs(ContrastMath::luminance($candidate) - $target);
            if ($delta < $bestDelta) {
                $bestDelta = $delta;
                $best = $candidate;
            }
        }
        return $best ?? self::hslToRgb($hue, $saturation, $centre);
    }

    /**
     * @param array{0:int,1:int,2:int} $rgb
     * @return array{0:float,1:float,2:float} hue degrees, saturation, lightness
     */
    private static function toHsl(array $rgb): array
    {
        [$r, $g, $b] = [$rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255];
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $lightness = ($max + $min) / 2;
        $chroma = $max - $min;
        // PHP preserves integer zero for `0 / 255`, so strict comparison with
        // 0.0 misses exact black and divides by a zero HSL denominator. The
        // bounds also cover exact white and any future rounding at an extreme.
        $saturation = $chroma <= 0.0 || $lightness <= 0.0 || $lightness >= 1.0
            ? 0.0
            : $chroma / (1 - abs(2 * $lightness - 1));
        return [self::hue($rgb), $saturation, $lightness];
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private static function hslToRgb(float $hue, float $saturation, float $lightness): array
    {
        $chroma = (1 - abs(2 * $lightness - 1)) * $saturation;
        $second = $chroma * (1 - abs(fmod($hue / 60.0, 2.0) - 1));
        $base = $lightness - $chroma / 2;
        [$r, $g, $b] = match ((int) floor($hue / 60.0) % 6) {
            0       => [$chroma, $second, 0.0],
            1       => [$second, $chroma, 0.0],
            2       => [0.0, $chroma, $second],
            3       => [0.0, $second, $chroma],
            4       => [$second, 0.0, $chroma],
            default => [$chroma, 0.0, $second],
        };
        return [
            (int) round(max(0.0, min(1.0, $r + $base)) * 255),
            (int) round(max(0.0, min(1.0, $g + $base)) * 255),
            (int) round(max(0.0, min(1.0, $b + $base)) * 255),
        ];
    }

    /**
     * @param array{0:int,1:int,2:int} $rgb
     */
    private static function toHex(array $rgb): string
    {
        return sprintf('#%02X%02X%02X', $rgb[0], $rgb[1], $rgb[2]);
    }

    /**
     * Hue angle in degrees, 0-360. Zero for an achromatic color.
     *
     * @param array{0:int,1:int,2:int} $rgb
     */
    private static function hue(array $rgb): float
    {
        [$r, $g, $b] = [$rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255];
        $max = max($r, $g, $b);
        $chroma = $max - min($r, $g, $b);
        if ($chroma <= 0.0) {
            return 0.0;
        }
        $hue = match (true) {
            $max === $r => fmod(($g - $b) / $chroma, 6.0),
            $max === $g => ($b - $r) / $chroma + 2.0,
            default     => ($r - $g) / $chroma + 4.0,
        };
        return fmod($hue * 60.0 + 360.0, 360.0);
    }
}
