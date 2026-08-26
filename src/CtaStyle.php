<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** One bounded call-to-action construction and its exact theme.json wiring. */
final class CtaStyle
{
    public const ALL = ['solid', 'outline', 'underline', 'ghost-arrow', 'block'];
    public const DEFAULT = 'solid';

    /**
     * Build-owned wrapper rule for the `block` construction, shipped in the
     * theme.json top-level `styles.css` (theme.json has no structured path to
     * the wp-block-button wrapper).
     *
     * Why it exists: current WordPress sizes a width-classed button in a
     * VERTICAL wp:buttons container with
     * `.wp-block-buttons.is-vertical > .wp-block-button[class*=wp-block-button__width]
     * { width: calc(var(--wp--block-button--width) * 1%) }`. Only the block's
     * `width` support attribute emits that custom property, and the frozen
     * serializer canonicalizes `block` delivery to the width-100 class alone
     * (no attribute). The unresolved var() makes the declaration invalid at
     * computed-value time, it computes to `unset`, and it still wins the
     * cascade over the horizontal `width: 100%` rule — so the wrapper
     * collapses to content width. This equal-specificity rule loads after the
     * block-library stylesheet and wins the source-order tie. Horizontal
     * containers never hit the var() rule and need no help.
     */
    public const BLOCK_VERTICAL_WRAPPER_CSS =
        '.wp-block-buttons.is-vertical > .wp-block-button.wp-block-button__width-100{width:100%;}';

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
                'border' => [
                    'bottom' => ['color' => 'currentColor', 'style' => 'solid', 'width' => '2px'],
                ],
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
                'css' => 'display:block;width:100%;box-sizing:border-box;text-align:center;',
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
            'block' => 'a full-width contrast slab that reverses on interaction',
            default => 'the committed deterministic CTA construction',
        };
    }
}
