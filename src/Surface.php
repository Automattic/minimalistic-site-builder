<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Site-wide surface catalog. One committed value, one reviewed stylesheet.
 * Textures are CSS-only (no SVG filters — those do not paint as CSS
 * backgrounds in most browsers, so a committed surface would be invisible).
 */
final class Surface
{
    public const ALL = ['none', 'paper', 'concrete', 'film', 'fabric', 'noise', 'dot-grid'];

    public const DEFAULT = 'none';

    public static function explicit(mixed $raw): ?string
    {
        return BoundedChoice::explicit($raw, self::ALL);
    }

    /**
     * WCAG normal-text floor the contrast pipeline must clear before this
     * overlay ships. 7:1 (AAA) leaves 4.5:1 after a 0.26–0.48 soft-light sheet.
     */
    public static function contrastFloor(?string $surface): float
    {
        $surface = self::explicit($surface);
        if ($surface === null || $surface === 'none') {
            return ContrastMath::NORMAL_TEXT;
        }
        return 7.0;
    }

    /**
     * Build-owned overlay for a committed surface.
     *
     * The texture carries both a dark and a light ink, under `soft-light`.
     * Inks are the delivered `base`/`contrast` pair so a cool or neon
     * direction is not hue-shifted by a hardcoded kraft recipe. Opacity is
     * still tuned from the page's base: a dark page carries grain that a
     * light one would wear too heavily.
     */
    public static function kitCss(?string $surface, ?string $baseHex = null, ?string $contrastHex = null): ?string
    {
        $surface = self::explicit($surface);
        if ($surface === null || $surface === 'none') {
            return null;
        }

        $dark = self::isDark($baseHex);
        [$darkRgb, $lightRgb] = self::inkPair($baseHex, $contrastHex);
        $midRgb = [
            (int) round(($darkRgb[0] + $lightRgb[0]) / 2),
            (int) round(($darkRgb[1] + $lightRgb[1]) / 2),
            (int) round(($darkRgb[2] + $lightRgb[2]) / 2),
        ];
        [$opacity, $background] = match ($surface) {
            'paper' => [
                $dark ? '0.40' : '0.32',
                self::paperLayers(self::rgba($darkRgb, 0.12), self::rgba($darkRgb, 0.07))
                    . ', ' . self::paperLayers(self::rgba($lightRgb, 0.14), self::rgba($lightRgb, 0.08)),
            ],
            'concrete' => [
                $dark ? '0.48' : '0.34',
                self::concreteLayers(self::rgba($darkRgb, 0.16), self::rgba($darkRgb, 0.10), self::rgba($darkRgb, 0.05))
                    . ', ' . self::concreteLayers(
                        self::rgba($lightRgb, 0.20),
                        self::rgba($midRgb, 0.16),
                        self::rgba($midRgb, 0.07),
                    ),
            ],
            'film' => [
                $dark ? '0.38' : '0.28',
                self::filmLayers(self::rgba($darkRgb, 0.12), self::rgba($darkRgb, 0.06))
                    . ', ' . self::filmLayers(self::rgba($lightRgb, 0.12), self::rgba($lightRgb, 0.06)),
            ],
            'fabric' => [
                $dark ? '0.36' : '0.26',
                self::fabricLayers(self::rgba($darkRgb, 0.10), self::rgba($darkRgb, 0.07))
                    . ', ' . self::fabricLayers(self::rgba($lightRgb, 0.10), self::rgba($lightRgb, 0.07)),
            ],
            // frm W4d: the fine grain of the dark product references
            // (Dreammotion, Spector). Finer than film, both inks, no lines.
            'noise' => [
                $dark ? '0.34' : '0.22',
                self::noiseLayers(self::rgba($darkRgb, 0.14), self::rgba($darkRgb, 0.08))
                    . ', ' . self::noiseLayers(self::rgba($lightRgb, 0.14), self::rgba($lightRgb, 0.08)),
            ],
            // frm W4d: Zova's dotted grid as a page ground: one dot every
            // 24px in the page's own ink, faint enough to read as paper.
            'dot-grid' => [
                $dark ? '0.30' : '0.22',
                self::dotGridLayer(self::rgba($dark ? $lightRgb : $darkRgb, 0.55)),
            ],
            default => [null, null],
        };
        if ($opacity === null || $background === null) {
            return null;
        }
        $blend = 'soft-light';
        $mode = $dark ? 'dark' : 'light';
        $size = $surface === 'dot-grid' ? '24px 24px' : 'auto';
        return "/* Committed '{$surface}' page surface ({$mode}). "
            . "CSS-only grain — written by the build, never by a model. */\n"
            . self::overlayCss($opacity, $blend, $background, $size);
    }

    public static function isDark(?string $hex): bool
    {
        return GroundKey::classify((string) $hex) === 'dark';
    }

    /**
     * Darker and lighter inks from the delivered palette. Missing halves
     * fall back to a warm pair so a fontless or pre-theme run still ships.
     *
     * @return array{0: array{0:int,1:int,2:int}, 1: array{0:int,1:int,2:int}}
     */
    private static function inkPair(?string $baseHex, ?string $contrastHex): array
    {
        $fallbackDark = [48, 36, 22];
        $fallbackLight = [239, 232, 218];
        $baseRgb = ContrastMath::hexToRgb((string) $baseHex);
        $contrastRgb = ContrastMath::hexToRgb((string) $contrastHex);
        if ($baseRgb !== null && $contrastRgb !== null) {
            return ContrastMath::luminance($baseRgb) <= ContrastMath::luminance($contrastRgb)
                ? [$baseRgb, $contrastRgb]
                : [$contrastRgb, $baseRgb];
        }
        if ($baseRgb !== null) {
            return self::isDark($baseHex)
                ? [$baseRgb, $fallbackLight]
                : [$fallbackDark, $baseRgb];
        }
        return [$fallbackDark, $fallbackLight];
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    private static function rgba(array $rgb, float $alpha): string
    {
        return sprintf('rgba(%d,%d,%d,%.2f)', $rgb[0], $rgb[1], $rgb[2], $alpha);
    }

    private static function paperLayers(string $a, string $b): string
    {
        return "repeating-linear-gradient(0deg, {$a} 0 1px, transparent 1px 3px), "
            . "repeating-linear-gradient(90deg, {$b} 0 1px, transparent 1px 4px)";
    }

    private static function concreteLayers(string $speckle, string $grit, string $dust): string
    {
        return "repeating-radial-gradient(circle at 8% 12%, {$speckle} 0 0.7px, transparent 1px 8px), "
            . "repeating-radial-gradient(circle at 72% 58%, {$grit} 0 0.6px, transparent 1px 11px), "
            . "repeating-radial-gradient(circle at 40% 80%, {$dust} 0 0.5px, transparent 1px 13px), "
            . "repeating-linear-gradient(90deg, {$grit} 0 1px, transparent 1px 9px)";
    }

    private static function filmLayers(string $fine, string $coarser): string
    {
        return "repeating-radial-gradient(circle at 15% 20%, {$fine} 0 0.45px, transparent 0.7px 4px), "
            . "repeating-radial-gradient(circle at 80% 70%, {$coarser} 0 0.5px, transparent 0.8px 5px)";
    }

    private static function noiseLayers(string $fine, string $finer): string
    {
        return "repeating-radial-gradient(circle at 23% 31%, {$fine} 0 0.35px, transparent 0.6px 3px), "
            . "repeating-radial-gradient(circle at 67% 79%, {$finer} 0 0.4px, transparent 0.65px 3.5px), "
            . "repeating-radial-gradient(circle at 48% 12%, {$finer} 0 0.3px, transparent 0.55px 2.5px)";
    }

    private static function dotGridLayer(string $dot): string
    {
        return "radial-gradient(circle, {$dot} 1px, transparent 1.4px)";
    }

    private static function fabricLayers(string $warp, string $weft): string
    {
        return "repeating-linear-gradient(0deg, {$warp} 0 1px, transparent 1px 4px), "
            . "repeating-linear-gradient(90deg, {$weft} 0 1px, transparent 1px 4px)";
    }

    /**
     * Claim `html body::before` outright.
     *
     * Specificity (0,0,3) beats a generated `body::before` and a page-scoped
     * `body:where(.page)::before`. z-index sits above in-flow content and
     * below `.site-header-shell` (1000) so sticky chrome and submenus stay
     * un-blended. The sheet is gated on mix-blend-mode support — without it
     * this is a fog, not grain — and it does not print or show to users who
     * asked for less transparency.
     */
    private static function overlayCss(string $opacity, string $blend, string $background, string $size = 'auto'): string
    {
        return <<<CSS
@supports (mix-blend-mode: soft-light) {
html body::before {
    content: "";
    display: block;
    visibility: visible;
    position: fixed;
    inset: 0;
    width: auto;
    height: auto;
    max-width: none;
    max-height: none;
    margin: 0;
    padding: 0;
    border: 0;
    border-radius: 0;
    transform: none;
    clip-path: none;
    filter: none;
    -webkit-mask-image: none;
    mask-image: none;
    pointer-events: none;
    z-index: 1;
    opacity: {$opacity};
    mix-blend-mode: {$blend};
    background-color: transparent;
    background-image: {$background};
    background-size: {$size};
    background-repeat: repeat;
    background-attachment: scroll;
}
}

@media (prefers-reduced-transparency: reduce) {
html body::before {
    display: none;
    content: none;
}
}

@media print {
html body::before {
    display: none;
    content: none;
}
}

CSS;
    }
}
