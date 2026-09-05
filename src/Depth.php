<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Shared bounded vocabulary and deterministic execution for visual depth. */
final class Depth
{
    public const ALL = ['flat', 'ring', 'soft', 'hard-offset', 'inset', 'glow', 'glass'];

    /** Glass is a dark-ground treatment; the direction step degrades it to this on a light page (frm W4b). */
    public const GLASS_LIGHT_FALLBACK = 'ring';

    public const DEFAULT = 'flat';

    /** @var array<string,array{name:string,shadow:string}> */
    private const PRESETS = [
        'flat' => [
            'name' => 'Flat',
            'shadow' => 'none',
        ],
        // A 1px hairline ring, not a lift. Product and technical sites build
        // every card and framed screenshot from this one line, so the build
        // owns it here instead of asking the model for decorative borders.
        'ring' => [
            'name' => 'Hairline ring',
            'shadow' => '0 0 0 1px color-mix(in srgb, var(--wp--preset--color--contrast) 12%, transparent)',
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
        // frm W4b: frosted panels on a dark ground (Dreammotion's cards). The
        // shadow is a 1px light hairline plus a deep soft drop; the
        // translucent fill and the blur live in kitCss() because a shadow
        // preset cannot carry them.
        'glass' => [
            'name' => 'Glass',
            'shadow' => '0 0 0 1px color-mix(in srgb, var(--wp--preset--color--contrast) 16%, transparent), 0 1.25rem 3rem rgb(0 0 0 / 0.35)',
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
        // An inset box-shadow can paint beneath replaced/background image
        // pixels and disappear. A build-owned inner outline makes that same
        // commitment visible on standalone image, Cover, and Media & Text
        // surfaces without changing their crop or color treatment.
        $insetMedia = $depth === 'inset'
            ? <<<CSS

                figure.wp-block-image:not(.alignfull) > img,
                figure.wp-block-image:not(.alignfull) > a > img,
                .wp-block-cover:not(.alignfull),
                .wp-block-media-text:not(.alignfull) > .wp-block-media-text__media {
                    outline: 1px solid color-mix(in srgb, var(--wp--preset--color--contrast) 28%, transparent);
                    outline-offset: -0.5rem;
                }
                .wp-block-group:is(.card-style--flush, .card-style--framed, .card-style--overlap) > figure.wp-block-image:not(.alignfull) > img,
                .wp-block-group:is(.card-style--flush, .card-style--framed, .card-style--overlap) > figure.wp-block-image:not(.alignfull) > a > img,
                .wp-block-group:is(.card-style--flush, .card-style--framed, .card-style--overlap) > .wp-block-cover:not(.alignfull),
                .wp-block-group:is(.card-style--flush, .card-style--framed, .card-style--overlap) > .wp-block-media-text:not(.alignfull) > .wp-block-media-text__media {
                    outline: none;
                }

                CSS
            : '';

        // frm W4b: a glass card is a translucent tint of its own band colour
        // with the page blurred behind it. Only band-coloured shells take
        // the fill: a card painted contrast or an accent is an inverted
        // highlight whose text was proven against that solid, so it stays
        // solid. Reduced transparency restores the solid band.
        $glass = $depth === 'glass'
            ? <<<CSS

                .wp-block-group:is(.card-style--flush, .card-style--framed, .card-style--overlap).has-band-background-color {
                    background-color: color-mix(in srgb, var(--wp--preset--color--band) 72%, transparent) !important;
                }
                @supports ((-webkit-backdrop-filter: blur(1px)) or (backdrop-filter: blur(1px))) {
                    .wp-block-group:is(.card-style--flush, .card-style--framed, .card-style--overlap).has-band-background-color {
                        -webkit-backdrop-filter: blur(14px) saturate(1.2);
                        backdrop-filter: blur(14px) saturate(1.2);
                    }
                }
                @media (prefers-reduced-transparency: reduce) {
                    .wp-block-group:is(.card-style--flush, .card-style--framed, .card-style--overlap).has-band-background-color {
                        background-color: var(--wp--preset--color--band) !important;
                        -webkit-backdrop-filter: none;
                        backdrop-filter: none;
                    }
                }

                CSS
            : '';

        return <<<CSS
            /* Committed '{$depth}' depth. The theme-json step publishes the
               same value as --wp--preset--shadow--depth; the literal fallback
               keeps isolated finalize runs deterministic too. */
            .wp-block-group:is(.card-style--flush, .card-style--framed, .card-style--overlap),
            figure.wp-block-image:not(.alignfull) > img,
            figure.wp-block-image:not(.alignfull) > a > img,
            .wp-block-cover:not(.alignfull),
            .wp-block-media-text:not(.alignfull) > .wp-block-media-text__media {
                box-shadow: var(--wp--preset--shadow--depth, {$shadow}) !important;
            }

            /* One elevated card is one surface: its direct media must not draw
               a second shadow. Borderless cards are absent on purpose. */
            .wp-block-group:is(.card-style--flush, .card-style--framed, .card-style--overlap) > figure.wp-block-image:not(.alignfull) > img,
            .wp-block-group:is(.card-style--flush, .card-style--framed, .card-style--overlap) > figure.wp-block-image:not(.alignfull) > a > img,
            .wp-block-group:is(.card-style--flush, .card-style--framed, .card-style--overlap) > .wp-block-cover:not(.alignfull),
            .wp-block-group:is(.card-style--flush, .card-style--framed, .card-style--overlap) > .wp-block-media-text:not(.alignfull) > .wp-block-media-text__media {
                box-shadow: none !important;
            }
            {$insetMedia}{$glass}

            CSS;
    }
}
