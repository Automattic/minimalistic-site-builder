<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Bounded render-time treatment tying delivered imagery to the site palette. */
final class ImageTreatment
{
    public const ALL = ['natural', 'duotone', 'tinted-overlay', 'high-key-bw'];

    public const DEFAULT = 'natural';

    public const PRESET_SLUG = 'site-image-treatment';

    public static function explicit(mixed $raw): ?string
    {
        return BoundedChoice::explicit($raw, self::ALL);
    }

    /**
     * Own the theme's duotone catalog. A duotone commitment gets exactly one
     * preset derived from the delivered base/contrast pair; every other
     * commitment removes model-authored presets so `natural` stays honest.
     *
     * @param array<mixed> $theme
     * @return array<mixed>
     */
    public static function applyThemeJson(array $theme, mixed $raw): array
    {
        $theme['settings'] = is_array($theme['settings'] ?? null) ? $theme['settings'] : [];
        $theme['settings']['color'] = is_array($theme['settings']['color'] ?? null)
            ? $theme['settings']['color']
            : [];
        unset($theme['settings']['color']['duotone']);

        if (self::explicit($raw) !== 'duotone') {
            return $theme;
        }

        $palette = self::palette($theme);
        $base = $palette['base'] ?? '#FFFFFF';
        $contrast = $palette['contrast'] ?? '#111111';
        [$dark, $light] = self::darkToLight($contrast, $base);
        $theme['settings']['color']['duotone'] = [[
            'slug' => self::PRESET_SLUG,
            'name' => 'Site image treatment',
            'colors' => [$dark, $light],
        ]];
        return $theme;
    }

    /**
     * Build-owned CSS for treatments WordPress block support cannot express
     * globally. Duotone is applied through render_block_data so Core emits its
     * own SVG filter, plus one companion rule for the media-text media half —
     * Core's media-text block has no duotone support, so the attribute route
     * cannot reach it. Natural ships no stylesheet.
     *
     * @param array<string,mixed> $palette
     */
    public static function kitCss(mixed $raw, array $palette = []): ?string
    {
        return match (self::explicit($raw)) {
            'duotone' => self::duotoneCompanionCss(),
            'tinted-overlay' => self::tintedOverlayCss(self::tintColor($palette)),
            'high-key-bw' => self::highKeyCss(),
            default => null,
        };
    }

    /** Whether functions.php must inject the committed preset into image blocks. */
    public static function usesDuotone(mixed $raw): bool
    {
        return self::explicit($raw) === 'duotone';
    }

    /** @param array<string,mixed> $palette */
    private static function tintColor(array $palette): string
    {
        foreach (['primary', 'base'] as $role) {
            $value = $palette[$role] ?? null;
            if (is_string($value) && ContrastMath::hexToRgb($value) !== null) {
                return strtoupper($value);
            }
        }
        return '#6B7280';
    }

    private static function tintedOverlayCss(string $tint): string
    {
        return <<<CSS
            /* Committed palette tint. The overlay sits above image pixels and
               below captions/cover copy, so text and controls stay untreated. */
            :where(figure.card-media, figure.card-media-tall, figure.card-media-thumb) {
                position: relative;
                isolation: isolate;
            }
            :where(figure.card-media, figure.card-media-tall, figure.card-media-thumb)::after,
            .wp-block-cover > .wp-block-cover__background::after {
                content: "";
                position: absolute;
                inset: 0;
                z-index: 1;
                pointer-events: none;
                background: {$tint};
                opacity: 0.14;
                mix-blend-mode: color;
            }
            :where(figure.card-media, figure.card-media-tall, figure.card-media-thumb) > figcaption {
                position: relative;
                z-index: 2;
            }

            CSS;
    }

    /**
     * Core has no duotone block support for core/media-text, so its media
     * image reads the same preset through the custom property WordPress
     * prints for the in-use committed preset. On a page where no image or
     * cover block uses the preset, the property is absent and the fallback
     * leaves the media-text image natural — a safe degradation, never an
     * off-palette second treatment.
     */
    private static function duotoneCompanionCss(): string
    {
        $preset = self::PRESET_SLUG;
        return <<<CSS
            /* Committed duotone for the media-text media half. Core applies
               the preset to image/cover blocks through block support; this
               companion rule reuses the same rendered SVG filter. */
            .wp-block-media-text__media img {
                filter: var(--wp--preset--duotone--{$preset}, none);
            }

            CSS;
    }

    private static function highKeyCss(): string
    {
        return <<<'CSS'
            /* Committed high-key monochrome treatment for delivered content
               imagery. !important keeps generated utility CSS from restoring
               an off-palette local filter. */
            :where(
                .wp-block-image img,
                .wp-block-gallery img,
                .wp-block-media-text__media img,
                .wp-block-cover__image-background,
                .wp-block-post-featured-image img
            ) {
                filter: grayscale(1) brightness(1.12) contrast(0.88) !important;
            }

            CSS;
    }

    /** @param array<mixed> $theme @return array<string,string> */
    private static function palette(array $theme): array
    {
        $out = [];
        foreach ($theme['settings']['color']['palette'] ?? [] as $entry) {
            if (!is_array($entry) || !is_string($entry['slug'] ?? null) || !is_string($entry['color'] ?? null)) {
                continue;
            }
            if (ContrastMath::hexToRgb($entry['color']) !== null) {
                $out[$entry['slug']] = strtoupper($entry['color']);
            }
        }
        return $out;
    }

    /** @return array{0:string,1:string} */
    private static function darkToLight(string $first, string $second): array
    {
        $a = ContrastMath::hexToRgb($first);
        $b = ContrastMath::hexToRgb($second);
        if ($a === null || $b === null) {
            return [$first, $second];
        }
        return ContrastMath::luminance($a) <= ContrastMath::luminance($b)
            ? [strtoupper($first), strtoupper($second)]
            : [strtoupper($second), strtoupper($first)];
    }
}
