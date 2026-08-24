<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Deterministic palette floors: WCAG contrast, primary/accent hue
 * separation, and a chroma ceiling at extreme lightness.
 *
 * Pure. No I/O. Palette maps are slug => "#RRGGBB" (or 3-digit hex).
 * Relative luminance and contrast ratio come from ContrastMath; this
 * class does not reimplement WCAG math.
 *
 * Frozen contract for the palette-floor slice. Hue/chroma use the same
 * HSL geometry GroundTint already measures: chroma is (max-min) of the
 * 0-1 channels, hue is the standard 0-360 HSL angle.
 */
final class PaletteFloor
{
    /** contrast on base — prompts/theme-json.md CONTRAST REQUIREMENTS. */
    public const CONTRAST_ON_BASE = 7.0;

    /** primary on base, secondary on base. */
    public const ROLE_ON_BASE = 4.5;

    /** base on accent (button labels). Ratio is symmetric. */
    public const BASE_ON_ACCENT = 4.5;

    /** Primary/accent closer than this, with chroma on both, is a miss. */
    public const HUE_TOO_CLOSE = 25.0;

    /** Accent is rotated to at least this many degrees from primary. */
    public const HUE_SEPARATION = 40.0;

    /** Below this chroma a hue is too faint to count as a competing color. */
    public const CHROMA_MIN = 0.1;

    /** Chroma above this at extreme luminance is the garish-lime failure. */
    public const CHROMA_CEILING = 0.55;

    public const LUMA_HIGH = 0.6;
    public const LUMA_LOW = 0.06;

    /**
     * Structured findings for one palette. Empty means every floor holds.
     *
     * @param array<string,string> $palette slug => hex
     * @return list<array{
     *     class: 'contrast'|'hue-separation'|'chroma-ceiling',
     *     role: string,
     *     against: string,
     *     authored: string,
     *     metric: float,
     *     floor: float
     * }>
     */
    public static function check(array $palette): array
    {
        $findings = [];
        foreach (self::contrastPairs() as [$role, $against, $floor]) {
            $hex = self::hexOf($palette, $role);
            $other = self::hexOf($palette, $against);
            if ($hex === null || $other === null) {
                continue;
            }
            $ratio = self::ratio($hex, $other);
            if ($ratio !== null && $ratio < $floor) {
                $findings[] = [
                    'class' => 'contrast',
                    'role' => $role,
                    'against' => $against,
                    'authored' => $hex,
                    'metric' => $ratio,
                    'floor' => $floor,
                ];
            }
        }

        $primary = self::hexOf($palette, 'primary');
        $accent = self::hexOf($palette, 'accent');
        if ($primary !== null && $accent !== null) {
            $cPrimary = self::chroma($primary);
            $cAccent = self::chroma($accent);
            $delta = self::hueDistance($primary, $accent);
            if (
                $cPrimary !== null && $cAccent !== null
                && $cPrimary > self::CHROMA_MIN
                && $cAccent > self::CHROMA_MIN
                && $delta !== null
                && $delta < self::HUE_TOO_CLOSE
            ) {
                $findings[] = [
                    'class' => 'hue-separation',
                    'role' => 'accent',
                    'against' => 'primary',
                    'authored' => $accent,
                    'metric' => $delta,
                    'floor' => self::HUE_TOO_CLOSE,
                ];
            }
        }

        foreach (['primary', 'secondary', 'accent'] as $role) {
            $hex = self::hexOf($palette, $role);
            if ($hex === null) {
                continue;
            }
            $chroma = self::chroma($hex);
            $y = self::luminance($hex);
            if (
                $chroma !== null && $y !== null
                && $chroma > self::CHROMA_CEILING
                && ($y > self::LUMA_HIGH || $y < self::LUMA_LOW)
            ) {
                $findings[] = [
                    'class' => 'chroma-ceiling',
                    'role' => $role,
                    'against' => '',
                    'authored' => $hex,
                    'metric' => $chroma,
                    'floor' => self::CHROMA_CEILING,
                ];
            }
        }

        return $findings;
    }

    /**
     * Repair in spec order: contrast, hue separation, chroma ceiling,
     * then contrast again so a rotation or chroma cut cannot leave a
     * pair under its floor.
     *
     * Never moves `base` — that slug belongs to a GroundTint family.
     * contrast-on-base moves `contrast`; base-on-accent moves `accent`.
     *
     * @param array<string,string> $palette slug => hex
     * @param list<string>         $warnings appended in the repo's
     *        authored=/delivered=/disposition= shape
     * @return array<string,string>
     */
    public static function repair(array $palette, array &$warnings): array
    {
        $out = $palette;
        $out = self::repairContrast($out, $warnings);
        $out = self::repairHue($out, $warnings);
        $out = self::repairChroma($out, $warnings);
        $out = self::repairContrast($out, $warnings);
        return $out;
    }

    /** WCAG relative luminance, or null when the value is not a hex color. */
    public static function luminance(string $hex): ?float
    {
        $rgb = ContrastMath::hexToRgb($hex);
        return $rgb === null ? null : ContrastMath::luminance($rgb);
    }

    /** WCAG contrast ratio, or null when either value is not a hex color. */
    public static function ratio(string $a, string $b): ?float
    {
        $ra = ContrastMath::hexToRgb($a);
        $rb = ContrastMath::hexToRgb($b);
        return $ra === null || $rb === null ? null : ContrastMath::ratio($ra, $rb);
    }

    /** HSL hue in degrees [0, 360), or null when the value is not a hex color. */
    public static function hue(string $hex): ?float
    {
        $rgb = ContrastMath::hexToRgb($hex);
        return $rgb === null ? null : self::hueOf($rgb);
    }

    /**
     * HSL chroma (max-min of the 0-1 channels), the quantity GroundTint
     * already calls chroma. Null when the value is not a hex color.
     */
    public static function chroma(string $hex): ?float
    {
        $rgb = ContrastMath::hexToRgb($hex);
        if ($rgb === null) {
            return null;
        }
        [$r, $g, $b] = [$rgb[0] / 255.0, $rgb[1] / 255.0, $rgb[2] / 255.0];
        return max($r, $g, $b) - min($r, $g, $b);
    }

    /** Circular hue distance in degrees, or null when either hex is unreadable. */
    public static function hueDistance(string $a, string $b): ?float
    {
        $ha = self::hue($a);
        $hb = self::hue($b);
        if ($ha === null || $hb === null) {
            return null;
        }
        $delta = abs($ha - $hb);
        return min($delta, 360.0 - $delta);
    }

    /**
     * @return list<array{0:string,1:string,2:float}> role, against, floor
     */
    private static function contrastPairs(): array
    {
        return [
            ['contrast', 'base', self::CONTRAST_ON_BASE],
            ['primary', 'base', self::ROLE_ON_BASE],
            ['secondary', 'base', self::ROLE_ON_BASE],
            ['accent', 'base', self::BASE_ON_ACCENT],
        ];
    }

    /**
     * @param array<string,string> $palette
     * @param list<string>         $warnings
     * @return array<string,string>
     */
    private static function repairContrast(array $palette, array &$warnings): array
    {
        $base = self::hexOf($palette, 'base');
        if ($base === null) {
            return $palette;
        }
        foreach (self::contrastPairs() as [$role, $against, $floor]) {
            // The pair is always judged against base, but the slug we
            // move is never base: GroundTint owns that family's hue.
            $hex = self::hexOf($palette, $role);
            if ($hex === null) {
                continue;
            }
            $fixed = self::meetContrast($hex, $base, $floor);
            if (self::sameHex($fixed, $hex)) {
                continue;
            }
            $palette[$role] = $fixed;
            $warnings[] = self::warning(
                $role,
                $hex,
                $fixed,
                sprintf(
                    'repaired — contrast floor %s:1 on base, lightness moved at fixed hue',
                    self::floorLabel($floor),
                ),
            );
        }
        return $palette;
    }

    /**
     * @param array<string,string> $palette
     * @param list<string>         $warnings
     * @return array<string,string>
     */
    private static function repairHue(array $palette, array &$warnings): array
    {
        $primary = self::hexOf($palette, 'primary');
        $accent = self::hexOf($palette, 'accent');
        if ($primary === null || $accent === null) {
            return $palette;
        }
        $cPrimary = self::chroma($primary);
        $cAccent = self::chroma($accent);
        $delta = self::hueDistance($primary, $accent);
        if (
            $cPrimary === null || $cAccent === null || $delta === null
            || $cPrimary <= self::CHROMA_MIN
            || $cAccent <= self::CHROMA_MIN
            || $delta >= self::HUE_TOO_CLOSE
        ) {
            return $palette;
        }

        $primaryHue = self::hue($primary);
        $accentHue = self::hue($accent);
        $rgb = ContrastMath::hexToRgb($accent);
        if ($primaryHue === null || $accentHue === null || $rgb === null) {
            return $palette;
        }
        [, $saturation, $lightness] = self::toHsl($rgb);

        $plus = self::wrapHue($primaryHue + self::HUE_SEPARATION);
        $minus = self::wrapHue($primaryHue - self::HUE_SEPARATION);
        $target = self::hueDistanceDegrees($accentHue, $plus)
            <= self::hueDistanceDegrees($accentHue, $minus)
            ? $plus
            : $minus;

        $fixed = self::toHex(self::hslToRgb($target, $saturation, $lightness));
        if (self::sameHex($fixed, $accent)) {
            return $palette;
        }
        $palette['accent'] = $fixed;
        $warnings[] = self::warning(
            'accent',
            $accent,
            $fixed,
            sprintf(
                'repaired — hue separation, accent rotated to %.0f degrees from primary; lightness and chroma held',
                self::HUE_SEPARATION,
            ),
        );
        return $palette;
    }

    /**
     * @param array<string,string> $palette
     * @param list<string>         $warnings
     * @return array<string,string>
     */
    private static function repairChroma(array $palette, array &$warnings): array
    {
        foreach (['primary', 'secondary', 'accent'] as $role) {
            $hex = self::hexOf($palette, $role);
            if ($hex === null) {
                continue;
            }
            $chroma = self::chroma($hex);
            $y = self::luminance($hex);
            if (
                $chroma === null || $y === null
                || $chroma <= self::CHROMA_CEILING
                || ($y <= self::LUMA_HIGH && $y >= self::LUMA_LOW)
            ) {
                continue;
            }
            $fixed = self::capChroma($hex, $y);
            if (self::sameHex($fixed, $hex)) {
                continue;
            }
            $palette[$role] = $fixed;
            $warnings[] = self::warning(
                $role,
                $hex,
                $fixed,
                'repaired — chroma ceiling, chroma reduced at extreme luminance; hue and luminance held',
            );
        }
        return $palette;
    }

    /**
     * Move LIGHTNESS at fixed hue/saturation until the pair meets $floor.
     * Prefers the side of the other color the authored hex already sits on.
     */
    private static function meetContrast(string $hex, string $other, float $floor): string
    {
        $ratio = self::ratio($hex, $other);
        if ($ratio !== null && $ratio >= $floor) {
            return self::toHex(ContrastMath::hexToRgb($hex) ?? [0, 0, 0]);
        }
        $rgb = ContrastMath::hexToRgb($hex);
        $otherRgb = ContrastMath::hexToRgb($other);
        if ($rgb === null || $otherRgb === null) {
            return $hex;
        }
        [$hue, $saturation] = self::toHsl($rgb);
        $y = ContrastMath::luminance($rgb);
        $yOther = ContrastMath::luminance($otherRgb);

        // A hair past the exact inversion so 8-bit rounding still clears.
        $margin = 0.004;
        $yHigh = min(1.0, $floor * ($yOther + 0.05) - 0.05 + $margin);
        $yLow = max(0.0, ($yOther + 0.05) / $floor - 0.05 - $margin);
        $preferHigh = $y >= $yOther;
        $targetY = $preferHigh ? $yHigh : $yLow;
        $direction = $preferHigh ? 1.0 : -1.0;

        $out = self::toHex(self::atLuminance($hue, $saturation, $targetY));
        $guard = 0;
        while ($guard++ < 80) {
            $now = self::ratio($out, $other);
            if ($now !== null && $now >= $floor) {
                break;
            }
            $cur = ContrastMath::hexToRgb($out);
            if ($cur === null) {
                break;
            }
            $yNow = ContrastMath::luminance($cur);
            $nextY = min(1.0, max(0.0, $yNow + $direction * 0.012));
            if (abs($nextY - $yNow) < 1e-9) {
                break;
            }
            // Hold the authored hue/saturation — re-extracting from the
            // quantized candidate would walk the hue on each nudge.
            $next = self::toHex(self::atLuminance($hue, $saturation, $nextY));
            if ($next === $out) {
                break;
            }
            $out = $next;
        }
        return $out;
    }

    /** Reduce chroma to the ceiling while holding hue and relative luminance. */
    private static function capChroma(string $hex, float $targetY): string
    {
        $rgb = ContrastMath::hexToRgb($hex);
        if ($rgb === null) {
            return $hex;
        }
        [$hue, $saturation] = self::toHsl($rgb);
        $lo = 0.0;
        $hi = $saturation;
        $best = $rgb;
        for ($i = 0; $i < 40; $i++) {
            $mid = ($lo + $hi) / 2;
            $candidate = self::atLuminance($hue, $mid, $targetY);
            $chroma = self::chroma(self::toHex($candidate));
            $best = $candidate;
            if ($chroma !== null && $chroma > self::CHROMA_CEILING) {
                $hi = $mid;
            } else {
                $lo = $mid;
            }
        }
        // The bisection may still sit a hair over the ceiling after
        // 8-bit rounding; walk saturation down until it actually clears.
        $sat = ($lo + $hi) / 2;
        for ($i = 0; $i < 32; $i++) {
            $candidate = self::atLuminance($hue, max(0.0, $sat), $targetY);
            $chroma = self::chroma(self::toHex($candidate));
            $best = $candidate;
            if ($chroma !== null && $chroma <= self::CHROMA_CEILING) {
                break;
            }
            $sat -= 0.02;
            if ($sat < 0.0) {
                break;
            }
        }
        return self::toHex($best);
    }

    /**
     * The color at hue/saturation whose relative luminance is closest to
     * $target. Copied in approach from GroundTint::atLuminance — bisection
     * on HSL lightness, then a neighborhood scan so 8-bit rounding does
     * not throw the answer off the target.
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
        [$r, $g, $b] = [$rgb[0] / 255.0, $rgb[1] / 255.0, $rgb[2] / 255.0];
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $lightness = ($max + $min) / 2.0;
        $chroma = $max - $min;
        $saturation = $chroma === 0.0 || $lightness === 0.0 || $lightness === 1.0
            ? 0.0
            : $chroma / (1 - abs(2 * $lightness - 1));
        return [self::hueOf($rgb), $saturation, $lightness];
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private static function hslToRgb(float $hue, float $saturation, float $lightness): array
    {
        $hue = self::wrapHue($hue);
        $saturation = min(1.0, max(0.0, $saturation));
        $lightness = min(1.0, max(0.0, $lightness));
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
            (int) round(max(0.0, min(1.0, $r + $base)) * 255.0),
            (int) round(max(0.0, min(1.0, $g + $base)) * 255.0),
            (int) round(max(0.0, min(1.0, $b + $base)) * 255.0),
        ];
    }

    /**
     * @param array{0:int,1:int,2:int} $rgb
     */
    private static function hueOf(array $rgb): float
    {
        [$r, $g, $b] = [$rgb[0] / 255.0, $rgb[1] / 255.0, $rgb[2] / 255.0];
        $max = max($r, $g, $b);
        $chroma = $max - min($r, $g, $b);
        if ($chroma <= 1e-12) {
            return 0.0;
        }
        $hue = match (true) {
            $max === $r => fmod(($g - $b) / $chroma, 6.0),
            $max === $g => ($b - $r) / $chroma + 2.0,
            default     => ($r - $g) / $chroma + 4.0,
        };
        return self::wrapHue($hue * 60.0);
    }

    /**
     * @param array{0:int,1:int,2:int} $rgb
     */
    private static function toHex(array $rgb): string
    {
        return sprintf('#%02X%02X%02X', $rgb[0], $rgb[1], $rgb[2]);
    }

    private static function wrapHue(float $hue): float
    {
        $hue = fmod($hue, 360.0);
        return $hue < 0.0 ? $hue + 360.0 : $hue;
    }

    private static function hueDistanceDegrees(float $a, float $b): float
    {
        $delta = abs($a - $b);
        return min($delta, 360.0 - $delta);
    }

    /** @param array<string,string> $palette */
    private static function hexOf(array $palette, string $slug): ?string
    {
        $raw = $palette[$slug] ?? null;
        if (!is_string($raw)) {
            return null;
        }
        $rgb = ContrastMath::hexToRgb($raw);
        return $rgb === null ? null : self::toHex($rgb);
    }

    private static function sameHex(string $a, string $b): bool
    {
        $ra = ContrastMath::hexToRgb($a);
        $rb = ContrastMath::hexToRgb($b);
        return $ra !== null && $rb !== null && $ra === $rb;
    }

    private static function warning(string $role, string $authored, string $delivered, string $disposition): string
    {
        return "file='theme/theme.json'; path=\"palette.{$role}\"; authored="
            . Warnings::value($authored)
            . '; delivered=' . Warnings::value($delivered)
            . '; disposition=' . $disposition;
    }

    private static function floorLabel(float $floor): string
    {
        return $floor === self::CONTRAST_ON_BASE ? '7.0' : '4.5';
    }
}
