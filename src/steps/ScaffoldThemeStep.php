<?php
declare(strict_types=1);

/**
 * Step 1 (deterministic): scaffold a new block theme.
 *
 * Input:  none
 * Output: theme/style.css and theme/readme.txt with {{placeholders}} that the
 *         ApplyIdentityStep fills once the site name/slug are known.
 */
final class ScaffoldThemeStep implements Step
{
    public function id(): string
    {
        return 'scaffold-theme';
    }

    public function label(): string
    {
        return 'Scaffold theme';
    }

    public function run(Project $project): void
    {
        $project->writeText('theme/style.css', self::STYLE_CSS);
        $project->writeText('theme/readme.txt', self::README);
    }

    private const STYLE_CSS = <<<CSS
        /*
        Theme Name: {{THEME_NAME}}
        Theme URI:
        Author: {{AUTHOR}}
        Author URI:
        Description: {{DESCRIPTION}}
        Version: 0.1.0
        Requires at least: 6.5
        Tested up to: 6.5
        Requires PHP: 7.4
        License: GNU General Public License v2 or later
        License URI: https://www.gnu.org/licenses/gpl-2.0.html
        Text Domain: {{THEME_SLUG}}
        */

        /* Card image cropping (sections opt in via className on the wp:image).
           Uniform media heights live here, in the theme stylesheet, instead of
           per-image inline CSS — fix-blocks deletes inline styles that aren't
           mirrored in block attributes, and a class hook survives untouched. */
        .card-media img,
        .card-media-tall img,
        .card-media-thumb img {
            width: 100%;
            object-fit: cover;
            display: block;
        }
        .card-media img { height: 200px; }
        .card-media-tall img { height: 320px; }
        .card-media-thumb img { height: 110px; }

        /* Equal-height, equal-width card rows (sections opt in via className="equal-cards"). */
        .equal-cards > .wp-block-column {
            display: flex;
            flex-direction: column;
            flex-grow: 0;
        }
        .equal-cards > .wp-block-column > .wp-block-group {
            display: flex;
            flex-direction: column;
            flex-grow: 1;
        }
        .equal-cards .cta-bottom {
            margin-top: auto;
            justify-content: center;
        }

        /* Chrome-less overlay header (the header part opts in via className="header-overlay"):
           floats transparently over the full-bleed hero instead of stacking above it. The
           absolute positioning resolves against the viewport, not the padded body, so the
           horizontal padding mirrors the theme's root padding (--wp--style--root--padding-*,
           emitted when useRootPaddingAwareAlignments is on) — the title/nav then share the
           same gutter as the constrained content below. */
        .wp-site-blocks .header-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            z-index: 10;
            background: transparent;
            padding-top: var(--wp--preset--spacing--sm);
            padding-bottom: var(--wp--preset--spacing--sm);
            padding-left: var(--wp--style--root--padding-left, var(--wp--preset--spacing--md));
            padding-right: var(--wp--style--root--padding-right, var(--wp--preset--spacing--md));
        }

        /* The root block-gap margin would open a page-background band between the header
           and the first section (above the hero) — and when the header is an absolutely
           positioned overlay, <main> still counts as "not the first child", so the band
           shows at the very top of the page. Sections space themselves; kill it on both
           flow neighbors. */
        .wp-site-blocks > main,
        .wp-site-blocks > footer {
            margin-block-start: 0;
        }

        CSS;

    private const README = <<<TXT
        === {{THEME_NAME}} ===

        Contributors: {{AUTHOR}}
        Requires at least: 6.5
        Tested up to: 6.5
        Requires PHP: 7.4
        License: GNU General Public License v2 or later
        License URI: https://www.gnu.org/licenses/gpl-2.0.html

        == Description ==

        {{DESCRIPTION}}

        TXT;
}
