<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Bounded band geometry (frm W4c): whether a full-width section band that
 * paints its own surface runs edge to edge (`square`) or sits inset from the
 * viewport with the committed panel radius (`rounded`), the way Luzia's dark
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
            'rounded' => 'every contrast or band-coloured section band sits inset from the viewport by one gutter'
                . ' and takes the committed panel radius, so dark bands read as rounded plates on the page ground;'
                . ' the hero and image covers keep their edges. Author no radius, margin or width on section roots',
            default   => 'section bands run edge to edge; nothing is inset or rounded at the band level',
        };
    }

    public static function kitCss(?string $raw): ?string
    {
        $geometry = self::explicit($raw);
        if ($geometry === null || $geometry === 'square') {
            return null;
        }
        return <<<CSS
            /* Committed 'rounded' band geometry (frm W4c). A top-level section
               group that paints a contrast or band surface becomes an inset
               plate: one gutter from the viewport, the committed panel
               radius, clipped so a background follows the corner. Page
               openings and image covers keep their edges. The gutter is the
               site's md space on desktop and its sm space on phones. */
            :is(.wp-site-blocks, .entry-content, .wp-block-post-content) > .wp-block-group.has-background:is(.has-contrast-background-color, .has-band-background-color):not([class*="hero-composition--"]):not(.section-composition--full-bleed-cover) {
                margin-inline: var(--wp--preset--spacing--md, 1.5rem);
                border-radius: var(--shape-radius-panel, 1.5rem);
                overflow: hidden;
            }
            @media (max-width: 781px) {
                :is(.wp-site-blocks, .entry-content, .wp-block-post-content) > .wp-block-group.has-background:is(.has-contrast-background-color, .has-band-background-color):not([class*="hero-composition--"]):not(.section-composition--full-bleed-cover) {
                    margin-inline: var(--wp--preset--spacing--sm, 0.75rem);
                }
            }

            CSS;
    }
}
