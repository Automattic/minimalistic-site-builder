<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Bounded band geometry (frm W4c): whether a full-width section band that
 * paints its own surface runs edge to edge (`square`) or sits inset from the
 * viewport with the band radius (`rounded`), the way Luzia's dark
 * process band and closing band do. The build executes it on the page's
 * top-level section groups that carry a contrast or band surface; page
 * openings and image covers keep their edges.
 */
final class BandGeometry
{
    public const ALL = ['square', 'rounded'];

    public const DEFAULT = 'square';

    public static function explicit(mixed $raw): ?string
    {
        return BoundedChoice::explicit($raw, self::ALL);
    }

    public static function meaning(string $geometry): string
    {
        return match ($geometry) {
            'rounded' => 'every contrast or band-coloured section band takes the band radius and clips to it, so'
                . ' dark bands read as rounded plates on the page ground; a full-bleed band also sits inset from'
                . ' the viewport by one gutter, and a wide band keeps the wide measure it already has;'
                . ' the hero and image covers keep their edges. Author no radius, margin or width on section roots',
            default   => 'section bands run edge to edge; nothing is inset or rounded at the band level',
        };
    }

    public static function kitCss(?string $raw, ?string $shape = 'soft'): ?string
    {
        // The radius answers to the committed corner language. A `sharp`
        // direction sets every card, button and image corner to zero, and a
        // 24px plate in the middle of that argues with the whole page, so it
        // gets the smallest radius that still reads as a plate rather than the
        // soft one.
        $radius = match ($shape) { 'round' => '2.5rem', 'sharp' => '0.5rem', default => '1.5rem' };
        $geometry = self::explicit($raw);
        if ($geometry === null || $geometry === 'square') {
            return null;
        }
        $band = ':is(.wp-site-blocks, .entry-content, .wp-block-post-content) > .wp-block-group.has-background'
            . ':is(.has-contrast-background-color, .has-band-background-color)'
            . ':not([class*="hero-composition--"]):not(.page-opening--section):not(.section-composition--full-bleed-cover)';
        return <<<CSS
            /* Committed 'rounded' band geometry (frm W4c). A top-level section
               group that paints a contrast or band surface becomes a plate:
               the committed panel radius, clipped so a background follows the
               corner. Page openings and image covers keep their edges. */
            {$band} {
                border-radius: {$radius};
                overflow: clip;
            }
            /* The viewport gutter is for a full-bleed band only, and that is
               not a shortcut. WordPress marks the auto centring margins it
               gives every OTHER constrained child as important, so a margin
               here cannot win on a wide band without fighting Core for the
               centring — and a wide band is already inset from the viewport
               by the wide measure, which is the inset a framed canvas asks
               for. The gutter is the site's md space on desktop and its sm
               space on phones. */
            {$band}.alignfull {
                margin-inline: var(--wp--preset--spacing--md, 1.5rem);
            }
            @media (max-width: 781px) {
                {$band}.alignfull {
                    margin-inline: var(--wp--preset--spacing--sm, 0.75rem);
                }
            }

            CSS;
    }
}
