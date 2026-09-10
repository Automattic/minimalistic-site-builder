<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Optional section textures. The build supplies one repeatable SVG tile without filters. */
final class Surface
{
    public const ALL = ['none', 'paper', 'concrete', 'film', 'fabric', 'noise', 'dot-grid'];
    public const DEFAULT = 'none';
    public const CLASS_PREFIX = 'surface--';

    public static function explicit(mixed $raw): ?string
    {
        return BoundedChoice::explicit($raw, self::ALL);
    }

    public static function className(?string $surface): ?string
    {
        $surface = self::explicit($surface);
        return $surface === null || $surface === 'none' ? null : self::CLASS_PREFIX . $surface;
    }

    /** Reserve contrast for the maximum background change of 12 percent. */
    public static function contrastFloor(?string $surface): float
    {
        return self::className($surface) === null ? ContrastMath::NORMAL_TEXT : 7.0;
    }

    /**
     * Paint below section content. Photographs, text, and controls retain their colors.
     * Separate dark and light marks remain visible on both light and dark backgrounds.
     */
    public static function kitCss(?string $surface, ?string $baseHex = null, ?string $contrastHex = null): ?string
    {
        $class = self::className($surface);
        if ($class === null) {
            return null;
        }
        $surface = self::explicit($surface);
        [$dark, $light] = self::inkPair($baseHex, $contrastHex);
        $svg = self::tile($surface, $dark, $light);
        $image = 'url("data:image/svg+xml,' . rawurlencode($svg) . '")';
        $size = $surface === 'dot-grid' ? 24 : 160;
        $selector = ':where(.wp-block-post-content, .editor-styles-wrapper .is-root-container) > .' . $class;

        return <<<CSS
/* Optional '{$surface}' section texture. The build supplies this stylesheet. */
{$selector} {
    position: relative;
    isolation: isolate;
}
{$selector}::before {
    content: "";
    display: block;
    visibility: visible;
    position: absolute;
    inset: 0;
    width: auto;
    height: auto;
    max-width: none;
    max-height: none;
    margin: 0;
    padding: 0;
    border: 0;
    border-radius: inherit;
    transform: none;
    clip-path: none;
    filter: none;
    -webkit-mask-image: none;
    mask-image: none;
    pointer-events: none;
    z-index: -1;
    opacity: 0.12;
    mix-blend-mode: normal;
    background-color: transparent;
    background-image: {$image};
    background-size: {$size}px {$size}px;
    background-repeat: repeat;
    background-attachment: scroll;
}
@media (prefers-reduced-transparency: reduce), print {
    {$selector}::before {
        display: none;
        content: none;
    }
}

CSS;
    }

    /** @return array{string, string} */
    private static function inkPair(?string $baseHex, ?string $contrastHex): array
    {
        $base = ContrastMath::hexToRgb((string) $baseHex);
        $contrast = ContrastMath::hexToRgb((string) $contrastHex);
        if ($base === null || $contrast === null) {
            return ['#000000', '#ffffff'];
        }
        $pair = ContrastMath::luminance($base) <= ContrastMath::luminance($contrast)
            ? [$base, $contrast] : [$contrast, $base];
        return array_map(static fn (array $rgb): string => sprintf('#%02x%02x%02x', ...$rgb), $pair);
    }

    /** A fixed seed keeps every build and repair pass identical without global random state. */
    private static function tile(string $surface, string $dark, string $light): string
    {
        $size = $surface === 'dot-grid' ? 24 : 160;
        $marks = '';
        if ($surface === 'dot-grid') {
            $marks = '<circle cx="11" cy="12" r="1" fill="' . $dark . '"/>'
                . '<circle cx="13" cy="12" r="1" fill="' . $light . '"/>';
        } elseif ($surface === 'fabric') {
            for ($i = 2; $i < $size; $i += 5) {
                $marks .= '<path d="M' . $i . ' 0V160" stroke="' . $dark . '" stroke-width="0.7"/>'
                    . '<path d="M0 ' . $i . 'H160" stroke="' . $light . '" stroke-width="0.7"/>';
            }
        } else {
            $seed = 997;
            $next = static function () use (&$seed): int {
                $seed = ($seed * 48271) % 2147483647;
                return $seed;
            };
            $count = match ($surface) {
                'paper' => 460,
                'concrete' => 360,
                'film' => 700,
                default => 900,
            };
            for ($i = 0; $i < $count; $i++) {
                $x = 2 + ($next() % 15600) / 100;
                $y = 2 + ($next() % 15600) / 100;
                $ink = $i % 2 === 0 ? $dark : $light;
                $radius = match ($surface) {
                    'concrete' => 0.7 + ($next() % 130) / 100,
                    'paper' => 0.4 + ($next() % 50) / 100,
                    'film' => 0.5 + ($next() % 50) / 100,
                    default => 0.45 + ($next() % 35) / 100,
                };
                $marks .= '<ellipse cx="' . $x . '" cy="' . $y . '" rx="' . $radius
                    . '" ry="' . ($surface === 'paper' ? $radius * 2 : $radius) . '" fill="' . $ink . '"/>';
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size
            . '" viewBox="0 0 ' . $size . ' ' . $size . '">' . $marks . '</svg>';
    }
}
