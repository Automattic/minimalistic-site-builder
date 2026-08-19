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
        if (!is_string($raw)) {
            return null;
        }
        $surface = strtolower(trim($raw));
        return in_array($surface, self::ALL, true) ? $surface : null;
    }

    /**
     * Build-owned overlay for a committed surface. `$baseHex` picks a dark
     * or light recipe so multiply-on-near-black cannot hide the tooth.
     */
    public static function kitCss(?string $surface, ?string $baseHex = null): ?string
    {
        $surface = self::explicit($surface);
        if ($surface === null || $surface === 'none') {
            return null;
        }

        $dark = self::isDark($baseHex);
        [$opacity, $blend, $background] = match ($surface) {
            'paper' => $dark
                ? ['0.40', 'overlay', self::paperLayers('rgba(239,232,218,0.14)', 'rgba(239,232,218,0.08)')]
                : ['0.32', 'multiply', self::paperLayers('rgba(48,36,22,0.12)', 'rgba(48,36,22,0.07)')],
            'concrete' => $dark
                ? ['0.48', 'overlay', self::concreteLayers(
                    'rgba(239,232,218,0.20)',
                    'rgba(140,143,140,0.16)',
                    'rgba(216,162,43,0.07)',
                )]
                : ['0.34', 'multiply', self::concreteLayers(
                    'rgba(40,40,40,0.16)',
                    'rgba(70,70,70,0.10)',
                    'rgba(40,40,40,0.05)',
                )],
            'film' => $dark
                ? ['0.38', 'overlay', self::filmLayers('rgba(255,255,255,0.12)', 'rgba(255,255,255,0.06)')]
                : ['0.28', 'multiply', self::filmLayers('rgba(20,20,20,0.12)', 'rgba(20,20,20,0.06)')],
            'fabric' => $dark
                ? ['0.36', 'overlay', self::fabricLayers('rgba(239,232,218,0.10)', 'rgba(239,232,218,0.07)')]
                : ['0.26', 'multiply', self::fabricLayers('rgba(40,32,24,0.10)', 'rgba(40,32,24,0.07)')],
            default => [null, null, null],
        };
        if ($opacity === null || $blend === null || $background === null) {
            return null;
        }

        $mode = $dark ? 'dark' : 'light';
        return "/* Committed '{$surface}' page surface ({$mode}). "
            . "CSS-only grain — written by the build, never by a model. */\n"
            . self::overlayCss($opacity, $blend, $background)
            . self::chromeCss();
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

    private static function overlayCss(string $opacity, string $blend, string $background): string
    {
        return <<<CSS
            body::before {
                content: "";
                position: fixed;
                inset: 0;
                pointer-events: none;
                z-index: 9999;
                opacity: {$opacity};
                mix-blend-mode: {$blend};
                background-image: {$background};
                background-repeat: repeat;
            }

            CSS;
    }

    /**
     * Hover that actually reads: the motion kit's -2px lift is invisible,
     * and a mustard underline is the one interaction the profiles allow
     * without inventing keyframes.
     */
    private static function chromeCss(): string
    {
        return <<<CSS
            :root {
                --motion-hover-lift: -8px;
                --motion-hover-shadow-opacity: 0.28;
            }
            a:where(:not(.wp-element-button)):hover {
                text-decoration: underline;
                text-decoration-thickness: 2px;
                text-underline-offset: 0.18em;
                text-decoration-color: var(--wp--preset--color--accent);
            }

            CSS;
    }
}
