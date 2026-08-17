<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * The palette slugs a design's own `header` rule asks for.
 *
 * A generated theme can only paint the header with one of five palette slugs,
 * so an authored colour is honored when the palette already contains it and
 * ignored otherwise. The match is a recognition test, not a snap-to-nearest:
 * with five slugs something is always "nearest", so an unbounded nearest
 * would repaint every header whose colour the palette does not carry.
 */
final class DesignHeaderSurface
{
    /**
     * CIELAB dE76 window for "the palette already has this colour". The
     * just-noticeable difference is ~2.3; 3.0 admits encoding noise from
     * var() resolution and colour round-trips without admitting a colour a
     * designer would see as different. calm-lantern's authored #2E0B5A sits
     * dE 34.6 from its nearest slug and is correctly refused.
     */
    public const MATCH_DELTA_E = 3.0;

    /**
     * The stacked pair a design authors, each side null when the design does
     * not author it or authors a colour the palette does not carry.
     *
     * @param array<string,string> $palette slug => hex
     * @return array{protection:?string,foreground:?string}
     */
    public static function stackedPair(?string $css, array $palette): array
    {
        if ($css === null || trim($css) === '') {
            return ['protection' => null, 'foreground' => null];
        }
        $authored = self::authored($css);
        return [
            'protection' => self::slugFor($authored['background'], $palette),
            'foreground' => self::slugFor($authored['text'], $palette),
        ];
    }

    /**
     * The `background` and `color` a design's own `header` rule declares,
     * resolved through any var() chain to a concrete CSS colour.
     *
     * @return array{background:?string,text:?string}
     */
    public static function authored(string $css): array
    {
        $css = (string) preg_replace('!/\*.*?\*/!s', '', $css);
        $vars = self::rootVars($css);
        $background = null;
        $text = null;
        // Later rules win, matching the cascade for equal specificity.
        foreach (self::rules($css) as [$selector, $body]) {
            if (!self::targetsHeaderRoot($selector)) {
                continue;
            }
            if (preg_match_all('/(?:^|;)\s*background(?:-color)?\s*:\s*([^;]+)/i', $body, $m)) {
                $background = self::resolveVar(trim((string) end($m[1])), $vars);
            }
            if (preg_match_all('/(?:^|;)\s*color\s*:\s*([^;]+)/i', $body, $m)) {
                $text = self::resolveVar(trim((string) end($m[1])), $vars);
            }
        }
        return ['background' => $background, 'text' => $text];
    }

    /**
     * The palette slug carrying this colour, or null when none is within the
     * match window. Restricted to HeaderBehavior::SURFACES: a slug outside
     * that vocabulary has no class in the header kit, so a derived surface
     * naming one would never reach the rendered header.
     *
     * @param array<string,string> $palette slug => hex
     */
    public static function slugFor(?string $color, array $palette): ?string
    {
        $rgb = $color === null ? null : ContrastMath::hexToRgb($color);
        if ($rgb === null) {
            return null;
        }
        $lab = self::toLab($rgb);
        $best = null;
        $bestDelta = INF;
        foreach ($palette as $slug => $hex) {
            $slug = (string) $slug;
            if (!in_array($slug, HeaderBehavior::SURFACES, true)) {
                continue;
            }
            $candidate = ContrastMath::hexToRgb((string) $hex);
            if ($candidate === null) {
                continue;
            }
            $delta = self::deltaE($lab, self::toLab($candidate));
            // Strict `<` leaves a tie to the palette's own order rather than
            // to iteration luck, so the mapping stays deterministic.
            if ($delta < $bestDelta) {
                $best = $slug;
                $bestDelta = $delta;
            }
        }
        return $bestDelta <= self::MATCH_DELTA_E ? $best : null;
    }

    /** @return array<string,string> custom property => declared value */
    private static function rootVars(string $css): array
    {
        $out = [];
        if (!preg_match_all('/:root\s*\{([^{}]*)\}/s', $css, $blocks)) {
            return $out;
        }
        foreach ($blocks[1] as $body) {
            foreach (explode(';', $body) as $declaration) {
                if (preg_match('/^\s*(--[\w-]+)\s*:\s*(.+)$/s', $declaration, $m)) {
                    $out[trim($m[1])] = trim($m[2]);
                }
            }
        }
        return $out;
    }

    /**
     * Every `selector { declarations }` pair, at-rule nesting included. A
     * design may declare its header rule inside `@media (min-width: …)`.
     *
     * @return list<array{0:string,1:string}>
     */
    private static function rules(string $css): array
    {
        $out = [];
        if (!preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $matches, PREG_SET_ORDER)) {
            return $out;
        }
        foreach ($matches as $match) {
            $selector = trim((string) preg_replace('/\s+/', ' ', $match[1]));
            // An at-rule prelude is captured with the first selector inside
            // it; keep only the part after the prelude's own brace.
            if (str_contains($selector, '@')) {
                $selector = trim((string) substr($selector, (int) strrpos($selector, '@')));
                $selector = trim((string) preg_replace('/^@[^\s]+[^{]*/', '', $selector));
            }
            if ($selector === '') {
                continue;
            }
            $out[] = [$selector, $match[2]];
        }
        return $out;
    }

    /**
     * Does this selector paint the `<header>` element itself? `header nav`
     * and `.site-header .brand` do not; `header` and `header.site-header` do.
     */
    private static function targetsHeaderRoot(string $selector): bool
    {
        foreach (explode(',', $selector) as $one) {
            $one = trim($one);
            if ($one === '' || str_starts_with($one, '@')) {
                continue;
            }
            $compounds = preg_split('/\s*[\s>+~]\s*/', $one) ?: [];
            $last = trim((string) end($compounds));
            if ($last !== '' && preg_match('/^header(?![\w-])/i', $last)) {
                return true;
            }
        }
        return false;
    }

    private static function resolveVar(?string $value, array $vars, int $depth = 0): ?string
    {
        if ($value === null || $depth > 8) {
            return null;
        }
        $value = trim($value);
        if (!preg_match('/^var\(\s*(--[\w-]+)\s*(?:,\s*(.+))?\)$/s', $value, $m)) {
            return $value;
        }
        if (isset($vars[$m[1]])) {
            return self::resolveVar($vars[$m[1]], $vars, $depth + 1);
        }
        return isset($m[2]) ? self::resolveVar($m[2], $vars, $depth + 1) : null;
    }

    /**
     * sRGB to CIELAB (D65). Euclidean RGB distance is unusable here: it ranks
     * near-black above a brighter violet for a deep-violet input, because
     * dark colours crowd together in RGB.
     *
     * @param array{0:int,1:int,2:int} $rgb
     * @return array{0:float,1:float,2:float}
     */
    private static function toLab(array $rgb): array
    {
        $linear = static function (int $channel): float {
            $c = $channel / 255;
            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $r = $linear($rgb[0]);
        $g = $linear($rgb[1]);
        $b = $linear($rgb[2]);
        $x = ($r * 0.4124564 + $g * 0.3575761 + $b * 0.1804375) / 0.95047;
        $y = ($r * 0.2126729 + $g * 0.7151522 + $b * 0.0721750);
        $z = ($r * 0.0193339 + $g * 0.1191920 + $b * 0.9503041) / 1.08883;
        $f = static fn (float $t): float => $t > 0.008856 ? $t ** (1 / 3) : (7.787 * $t + 16 / 116);
        $fx = $f($x);
        $fy = $f($y);
        $fz = $f($z);
        return [116 * $fy - 16, 500 * ($fx - $fy), 200 * ($fy - $fz)];
    }

    /**
     * @param array{0:float,1:float,2:float} $a
     * @param array{0:float,1:float,2:float} $b
     */
    private static function deltaE(array $a, array $b): float
    {
        return sqrt(($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2);
    }
}
