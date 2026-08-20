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
    public const ALL = ['none', 'paper', 'concrete', 'film', 'fabric'];

    public const DEFAULT = 'none';

    public static function explicit(mixed $raw): ?string
    {
        return BoundedChoice::explicit($raw, self::ALL);
    }

    /**
     * Build-owned overlay for a committed surface.
     *
     * The texture carries both a dark and a light ink, under `soft-light`.
     * Picking one blend mode from the page's base color gave the whole site
     * one recipe, so a `multiply` texture chosen for a light base faded out on
     * every dark band, and an `overlay` texture chosen for a dark base faded
     * out on every light one — the texture disappeared on exactly the sections
     * that used the opposite color. With both inks present, whichever one
     * contrasts with the band underneath is the one that reads.
     *
     * `$baseHex` still tunes the overall opacity, since a dark page carries a
     * grain that a light one would wear too heavily.
     */
    public static function kitCss(?string $surface, ?string $baseHex = null): ?string
    {
        $surface = self::explicit($surface);
        if ($surface === null || $surface === 'none') {
            return null;
        }

        $dark = self::isDark($baseHex);
        // Both inks, always. The opacity is the only thing the page's base
        // still decides.
        [$opacity, $background] = match ($surface) {
            'paper' => [
                $dark ? '0.40' : '0.32',
                self::paperLayers('rgba(48,36,22,0.12)', 'rgba(48,36,22,0.07)')
                    . ', ' . self::paperLayers('rgba(239,232,218,0.14)', 'rgba(239,232,218,0.08)'),
            ],
            'concrete' => [
                $dark ? '0.48' : '0.34',
                self::concreteLayers('rgba(40,40,40,0.16)', 'rgba(70,70,70,0.10)', 'rgba(40,40,40,0.05)')
                    . ', ' . self::concreteLayers(
                        'rgba(239,232,218,0.20)',
                        'rgba(140,143,140,0.16)',
                        'rgba(216,162,43,0.07)',
                    ),
            ],
            'film' => [
                $dark ? '0.38' : '0.28',
                self::filmLayers('rgba(20,20,20,0.12)', 'rgba(20,20,20,0.06)')
                    . ', ' . self::filmLayers('rgba(255,255,255,0.12)', 'rgba(255,255,255,0.06)'),
            ],
            'fabric' => [
                $dark ? '0.36' : '0.26',
                self::fabricLayers('rgba(40,32,24,0.10)', 'rgba(40,32,24,0.07)')
                    . ', ' . self::fabricLayers('rgba(239,232,218,0.10)', 'rgba(239,232,218,0.07)'),
            ],
            default => [null, null],
        };
        if ($opacity === null || $background === null) {
            return null;
        }
        // soft-light darkens under a dark ink and lightens under a light one,
        // on any backdrop, which is what lets one sheet serve every band.
        $blend = 'soft-light';

        $mode = $dark ? 'dark' : 'light';
        return "/* Committed '{$surface}' page surface ({$mode}). "
            . "CSS-only grain — written by the build, never by a model. */\n"
            . self::overlayCss($opacity, $blend, $background);
    }

    public static function isDark(?string $hex): bool
    {
        $rgb = ContrastMath::hexToRgb((string) $hex);
        if ($rgb === null) {
            return false;
        }
        return ContrastMath::luminance($rgb) < 0.28;
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

    private static function fabricLayers(string $warp, string $weft): string
    {
        return "repeating-linear-gradient(0deg, {$warp} 0 1px, transparent 1px 4px), "
            . "repeating-linear-gradient(90deg, {$weft} 0 1px, transparent 1px 4px)";
    }

    /**
     * Claim `body::before` outright.
     *
     * The generated design CSS may already be using this pseudo-element, and
     * whatever it set that this rule does not set would still apply — a
     * `display: none`, a `transform`, or a width would leave the texture
     * invisible while the build reported it shipped. So every property that
     * could suppress or displace the layer is written here, not just the ones
     * the texture needs. FinalizeThemeStep warns when the generated stylesheet
     * had it first, because a claimed pseudo-element is a loss for whatever
     * was using it.
     */
    private static function overlayCss(string $opacity, string $blend, string $background): string
    {
        return <<<CSS
            body::before {
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
                z-index: 9999;
                opacity: {$opacity};
                mix-blend-mode: {$blend};
                background-color: transparent;
                background-image: {$background};
                background-repeat: repeat;
                background-attachment: scroll;
            }

            CSS;
    }

    /**
     * Hover that actually reads: the motion kit's -2px lift is invisible,
     * and a mustard underline is the one interaction the profiles allow
     * without inventing keyframes.
     */
}
