<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Shared bounded vocabulary and deterministic execution for visual depth. */
final class Depth
{
    public const ALL = ['flat', 'soft', 'hard-offset', 'inset', 'glow'];

    public const DEFAULT = 'flat';

    /** @var array<string,array{name:string,shadow:string}> */
    private const PRESETS = [
        'flat' => [
            'name' => 'Flat',
            'shadow' => 'none',
        ],
        'soft' => [
            'name' => 'Soft depth',
            'shadow' => '0 0.75rem 2rem color-mix(in srgb, var(--wp--preset--color--contrast) 16%, transparent)',
        ],
        'hard-offset' => [
            'name' => 'Hard offset',
            'shadow' => '0.55rem 0.55rem 0 var(--wp--preset--color--contrast)',
        ],
        'inset' => [
            'name' => 'Inset depth',
            'shadow' => 'inset 0 0 0 1px color-mix(in srgb, var(--wp--preset--color--contrast) 22%, transparent), inset 0 0.75rem 1.5rem color-mix(in srgb, var(--wp--preset--color--contrast) 14%, transparent)',
        ],
        'glow' => [
            'name' => 'Glow',
            'shadow' => '0 0 2rem color-mix(in srgb, var(--wp--preset--color--primary) 48%, transparent)',
        ],
    ];

    public static function explicit(mixed $raw): ?string
    {
        return BoundedChoice::explicit($raw, self::ALL);
    }

    /** @return array{slug:string,name:string,shadow:string}|null */
    public static function preset(mixed $raw): ?array
    {
        $depth = self::explicit($raw);
        if ($depth === null) {
            return null;
        }
        return ['slug' => 'depth'] + self::PRESETS[$depth];
    }

    /**
     * Build-owned depth for image-card shells and contained media surfaces.
     *
     * The stylesheet loads after generated CSS and uses !important because a
     * block shadow can otherwise arrive inline. Full-bleed media is excluded:
     * elevation at the viewport edge renders only as a stray seam. Flush,
     * framed, and overlap cards receive one shadow on their outer shell, then
     * their direct media shadow is suppressed so a card never gets a doubled
     * halo. Borderless cards deliberately keep no shell, so their media gets
     * the commitment directly.
     */
    public static function kitCss(mixed $raw): ?string
    {
        $depth = self::explicit($raw);
        if ($depth === null) {
            return null;
        }
        $shadow = self::PRESETS[$depth]['shadow'];
        // An inset box-shadow on a replaced <img> paints beneath the image
        // pixels and can disappear. A build-owned inner outline makes that
        // same commitment visible on standalone image media without changing
        // its crop or color treatment.
        $insetMedia = $depth === 'inset'
            ? <<<CSS

                figure.wp-block-image:not(.alignfull) > img {
                    outline: 1px solid color-mix(in srgb, var(--wp--preset--color--contrast) 28%, transparent);
                    outline-offset: -0.5rem;
                }
                .wp-block-group:is(.card-style--flush, .card-style--framed, .card-style--overlap) > figure.wp-block-image:not(.alignfull) > img {
                    outline: none;
                }

                CSS
            : '';

        return <<<CSS
            /* Committed '{$depth}' depth. The theme-json step publishes the
               same value as --wp--preset--shadow--depth; the literal fallback
               keeps isolated finalize runs deterministic too. */
            .wp-block-group:is(.card-style--flush, .card-style--framed, .card-style--overlap),
            figure.wp-block-image:not(.alignfull) > img,
            .wp-block-cover:not(.alignfull),
            .wp-block-media-text:not(.alignfull) > .wp-block-media-text__media {
                box-shadow: var(--wp--preset--shadow--depth, {$shadow}) !important;
            }

            /* One elevated card is one surface: its direct media must not draw
               a second shadow. Borderless cards are absent on purpose. */
            .wp-block-group:is(.card-style--flush, .card-style--framed, .card-style--overlap) > figure.wp-block-image:not(.alignfull) > img,
            .wp-block-group:is(.card-style--flush, .card-style--framed, .card-style--overlap) > .wp-block-cover:not(.alignfull),
            .wp-block-group:is(.card-style--flush, .card-style--framed, .card-style--overlap) > .wp-block-media-text:not(.alignfull) > .wp-block-media-text__media {
                box-shadow: none !important;
            }
            {$insetMedia}

            CSS;
    }
}
