<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** One bounded call-to-action construction and its exact theme.json wiring. */
final class CtaStyle
{
    public const ALL = ['solid', 'outline', 'underline', 'ghost-arrow', 'block'];
    public const DEFAULT = 'solid';

    /**
     * The widest container in which a `block` button may fill its whole
     * width, as a share of the theme's contentSize. A button in a wider
     * container keeps its intrinsic width: a 1040px slab reads as a band,
     * not as an action (BIGR-980).
     */
    public const NARROW_CONTAINER_SHARE = 1 / 3;

    /**
     * Build-owned rules for the `block` construction, shipped in the
     * theme.json top-level `styles.css` (theme.json has no structured path to
     * the wp-block-button wrapper).
     *
     * Rule 1, the vertical wrapper: current WordPress sizes a width-classed
     * button in a VERTICAL wp:buttons container with
     * `.wp-block-buttons.is-vertical > .wp-block-button[class*=wp-block-button__width]
     * { width: calc(var(--wp--block-button--width) * 1%) }`. Only the block's
     * `width` support attribute emits that custom property, and the frozen
     * serializer canonicalizes delivery to the width-100 class alone (no
     * attribute). The unresolved var() makes the declaration invalid at
     * computed-value time, it computes to `unset`, and it still wins the
     * cascade over the horizontal `width: 100%` rule, so the wrapper
     * collapses to content width. This equal-specificity rule loads after the
     * block-library stylesheet and wins the source-order tie. Horizontal
     * containers never hit the var() rule and need no help.
     *
     * Rule 2, the slab minimum: the wrapper is a flex item of the buttons
     * container, so its percentage min-width resolves against a definite
     * width. Core gives the link `width:100%` of the wrapper. A min-width on
     * the link itself would resolve against the content-sized wrapper and
     * never widen a short label (measured: a "Go" slab stayed 64px wide).
     *
     * Rule 3, the mobile fill: below the core column-stacking breakpoint every
     * content button sits in a container at most one phone wide, so the slab
     * fills it there. Header and footer chrome is outside post content and
     * keeps intrinsic width.
     */
    public const BLOCK_WRAPPER_CSS =
        '.wp-block-buttons.is-vertical > .wp-block-button.wp-block-button__width-100{width:100%;}'
        . '.wp-block-buttons > .wp-block-button{min-width:min(12rem,100%);}'
        . '@media (max-width:781px){'
        . '.wp-block-post-content .wp-block-buttons > .wp-block-button{flex-basis:100%;width:100%;}'
        . '.wp-block-post-content .wp-block-button > .wp-block-button__link{width:100%;}'
        . '}';

    public static function explicit(mixed $value): ?string
    {
        return BoundedChoice::explicit($value, self::ALL);
    }

    /**
     * Build-owned button construction. Typography outside textDecoration and
     * shape-owned border.radius remain authored by their respective owners.
     * Solid label color deliberately remains contrast-fix-owned because the
     * accent can be either light or dark.
     *
     * @return array<mixed>|null
     */
    public static function themeStyle(mixed $style): ?array
    {
        $style = self::explicit($style);
        if ($style === null) {
            return null;
        }

        $transparent = ['background' => 'transparent', 'text' => 'inherit'];
        $safeDark = ['background' => 'var:preset|color|contrast', 'text' => 'var:preset|color|base'];
        $safeLight = ['background' => 'var:preset|color|base', 'text' => 'var:preset|color|contrast'];
        $boxPadding = [
            'top' => 'var:preset|spacing|sm',
            'bottom' => 'var:preset|spacing|sm',
            'left' => 'var:preset|spacing|md',
            'right' => 'var:preset|spacing|md',
        ];
        $linkPadding = [
            'top' => 'var:preset|spacing|xs',
            'bottom' => 'var:preset|spacing|xs',
            'left' => '0',
            'right' => '0',
        ];

        return match ($style) {
            'solid' => [
                'color' => ['background' => 'var:preset|color|accent'],
                'border' => ['color' => 'transparent', 'style' => 'solid', 'width' => '0'],
                'spacing' => ['padding' => $boxPadding],
                ':hover' => ['color' => $safeDark],
                ':focus' => ['color' => $safeDark],
                ':active' => ['color' => $safeDark],
            ],
            'outline' => [
                'color' => $transparent,
                'border' => ['color' => 'currentColor', 'style' => 'solid', 'width' => '2px'],
                'spacing' => ['padding' => $boxPadding],
                ':hover' => [
                    'color' => $safeDark,
                    'border' => ['color' => 'var:preset|color|contrast', 'style' => 'solid', 'width' => '2px'],
                ],
                ':focus' => [
                    'color' => $safeDark,
                    'border' => ['color' => 'var:preset|color|contrast', 'style' => 'solid', 'width' => '2px'],
                ],
                ':active' => [
                    'color' => $safeDark,
                    'border' => ['color' => 'var:preset|color|contrast', 'style' => 'solid', 'width' => '2px'],
                ],
            ],
            'underline' => [
                'color' => $transparent,
                // The text-decoration below already draws the line. A
                // border-bottom drew a second one under the padding box, and
                // the shape-owned border.radius (never stripped) curved that
                // bottom-only border into a trough. Pin the border off so the
                // construction keeps exactly one line, whatever the shape.
                'border' => ['color' => 'transparent', 'style' => 'solid', 'width' => '0'],
                'typography' => ['textDecoration' => 'underline'],
                'spacing' => ['padding' => $linkPadding],
                'css' => 'text-underline-offset:0.25em;text-decoration-thickness:0.14em;',
                ':hover' => ['color' => $transparent],
                ':focus' => ['color' => $transparent],
                ':active' => ['color' => $transparent],
            ],
            'ghost-arrow' => [
                'color' => $transparent,
                'border' => ['color' => 'transparent', 'style' => 'solid', 'width' => '0'],
                'spacing' => ['padding' => $linkPadding],
                'css' => 'display:inline-flex;align-items:center;gap:0.55em;'
                    . '&::after{content:"→";font-size:1.15em;line-height:1;transition:transform .18s ease;}'
                    . '&:hover::after,&:focus-visible::after{transform:translateX(.25em);}',
                ':hover' => ['color' => $transparent],
                ':focus' => ['color' => $transparent],
                ':active' => ['color' => $transparent],
            ],
            'block' => [
                'color' => $safeDark,
                'border' => ['color' => 'var:preset|color|contrast', 'style' => 'solid', 'width' => '2px'],
                'spacing' => ['padding' => $boxPadding],
                // Intrinsic width. Width is a container decision, never an
                // element rule: CtaStyleMarkup keeps the width-100 class only
                // in a narrow container, and BLOCK_WRAPPER_CSS owns the slab
                // minimum and the mobile fill.
                'css' => 'box-sizing:border-box;text-align:center;',
                ':hover' => ['color' => $safeLight, 'border' => ['color' => 'var:preset|color|base']],
                ':focus' => ['color' => $safeLight, 'border' => ['color' => 'var:preset|color|base']],
                ':active' => ['color' => $safeLight, 'border' => ['color' => 'var:preset|color|base']],
            ],
        };
    }

    public static function meaning(string $style): string
    {
        return match ($style) {
            'solid' => 'a filled accent button with a decisive contrast/base interaction state',
            'outline' => 'a transparent 2px current-color outline that fills on interaction',
            'underline' => 'an unboxed text action with a strong underline',
            'ghost-arrow' => 'an unboxed text action followed by an animated arrow glyph',
            'block' => 'a square contrast slab that reverses on interaction and fills only a narrow container (at most one third of the content width)',
            default => 'the committed deterministic CTA construction',
        };
    }
}
