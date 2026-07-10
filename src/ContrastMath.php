<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * WCAG 2.x contrast math over sRGB colors. Pure functions, no I/O.
 *
 * Colors are [r, g, b] triplets in 0-255. Ratios follow the WCAG relative
 * luminance definition (https://www.w3.org/TR/WCAG21/#dfn-contrast-ratio):
 * 4.5:1 is the minimum for normal text, 3:1 for large text (headings).
 */
final class ContrastMath
{
    /** Minimum ratio for normal (body-size) text. */
    public const NORMAL_TEXT = 4.5;

    /** Minimum ratio for large text — headings at the theme's scale qualify. */
    public const LARGE_TEXT = 3.0;

    /**
     * Parse "#RGB" / "#RRGGBB" into [r,g,b], or null when it isn't a hex color.
     *
     * @return array{0:int,1:int,2:int}|null
     */
    public static function hexToRgb(string $hex): ?array
    {
        $hex = ltrim(trim($hex), '#');
        if (preg_match('/^[0-9a-f]{3}$/i', $hex)) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return null;
        }
        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * WCAG relative luminance of an sRGB color, 0 (black) to 1 (white).
     *
     * @param array{0:int,1:int,2:int} $rgb
     */
    public static function luminance(array $rgb): float
    {
        $chan = [];
        foreach ($rgb as $c) {
            $c = $c / 255;
            $chan[] = $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }
        return 0.2126 * $chan[0] + 0.7152 * $chan[1] + 0.0722 * $chan[2];
    }

    /**
     * WCAG contrast ratio between two colors, 1 (identical) to 21 (black/white).
     *
     * @param array{0:int,1:int,2:int} $a
     * @param array{0:int,1:int,2:int} $b
     */
    public static function ratio(array $a, array $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        [$lighter, $darker] = $la >= $lb ? [$la, $lb] : [$lb, $la];
        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * Alpha-composite a foreground color at $alpha opacity over a background.
     *
     * @param array{0:int,1:int,2:int} $fg
     * @param array{0:int,1:int,2:int} $bg
     * @return array{0:int,1:int,2:int}
     */
    public static function compositeOver(array $fg, float $alpha, array $bg): array
    {
        $alpha = max(0.0, min(1.0, $alpha));
        $out = [];
        foreach ([0, 1, 2] as $i) {
            $out[$i] = (int) round($fg[$i] * $alpha + $bg[$i] * (1 - $alpha));
        }
        return $out;
    }

    /**
     * Extract every color occurring in a CSS value (a gradient, typically):
     * #hex, rgb() and rgba() notations, each with its own alpha — in source
     * order, so the first entry is the gradient's first stop and the last
     * entry its last stop regardless of how each color is written.
     *
     * @return list<array{rgb: array{0:int,1:int,2:int}, alpha: float}>
     */
    public static function parseCssColors(string $css): array
    {
        $colors = [];
        $pattern = '/#[0-9a-f]{6}\b|#[0-9a-f]{3}\b'
            . '|rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*([0-9.]+)\s*)?\)/i';
        if (preg_match_all($pattern, $css, $m, PREG_SET_ORDER)) {
            foreach ($m as $c) {
                if ($c[0][0] === '#') {
                    $rgb = self::hexToRgb($c[0]);
                    if ($rgb !== null) {
                        $colors[] = ['rgb' => $rgb, 'alpha' => 1.0];
                    }
                    continue;
                }
                $colors[] = [
                    'rgb'   => [min(255, (int) $c[1]), min(255, (int) $c[2]), min(255, (int) $c[3])],
                    'alpha' => isset($c[4]) && $c[4] !== '' ? max(0.0, min(1.0, (float) $c[4])) : 1.0,
                ];
            }
        }
        return $colors;
    }

    /**
     * The colors a gradient actually renders: its stops in source order plus
     * the interpolated midpoint between each adjacent pair. Text sits on the
     * in-between colors too — black and white endpoints both pass a mid-grey
     * that reaches 1:1 somewhere inside the gradient — so contrast checks
     * must include them. CSS interpolates sRGB per channel; the midpoint is
     * the worst-case interior approximation for a two-stop gradient.
     *
     * @return list<array{rgb: array{0:int,1:int,2:int}, alpha: float}>
     */
    public static function gradientStops(string $css): array
    {
        $stops = self::parseCssColors($css);
        if (count($stops) < 2) {
            return $stops;
        }
        $out = [$stops[0]];
        for ($k = 1, $n = count($stops); $k < $n; $k++) {
            $prev = $stops[$k - 1];
            $stop = $stops[$k];
            $out[] = [
                'rgb'   => [
                    (int) round(($prev['rgb'][0] + $stop['rgb'][0]) / 2),
                    (int) round(($prev['rgb'][1] + $stop['rgb'][1]) / 2),
                    (int) round(($prev['rgb'][2] + $stop['rgb'][2]) / 2),
                ],
                'alpha' => ($prev['alpha'] + $stop['alpha']) / 2,
            ];
            $out[] = $stop;
        }
        return $out;
    }
}
