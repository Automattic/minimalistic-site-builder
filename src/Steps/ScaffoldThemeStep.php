<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Package;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

/**
 * Step 1 (deterministic): scaffold a new block theme.
 *
 * Input:  none
 * Output: theme/style.css and theme/readme.txt with {{placeholders}} that the
 *         ApplyIdentityStep fills once the site name/slug are known, plus the
 *         static motion kit copied verbatim into theme/assets/motion/ (all
 *         four profiles — the design direction hasn't been chosen yet; the
 *         finalize-theme step later prunes to the committed profile and
 *         enqueues the kit).
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

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: [],
            writes: [
                'theme/style.css',
                'theme/readme.txt',
                'theme/assets/motion/*',
            ],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $project->writeText('theme/style.css', self::STYLE_CSS);
        $project->writeText('theme/readme.txt', self::README);
        self::copyMotionKit($project);
    }

    /** Copy the hand-written motion kit into the theme, byte-for-byte. */
    private static function copyMotionKit(Project $project): void
    {
        $kit = Package::motionDir();
        foreach (['motion.css', 'motion.js'] as $file) {
            $project->writeText('theme/assets/motion/' . $file, (string) file_get_contents("{$kit}/{$file}"));
        }
        foreach (glob("{$kit}/profiles/*.css") ?: [] as $profile) {
            $project->writeText(
                'theme/assets/motion/profiles/' . basename($profile),
                (string) file_get_contents($profile)
            );
        }
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

        /* Core gives pullquotes a font-relative 4em vertical pad and a trailing
           margin, which silently multiplies the whitespace around fluid display
           quotes. Keep the editorial emphasis on the shared spacing scale so it
           composes predictably with the section rhythm. */
        .wp-site-blocks .wp-block-pullquote {
            margin-block: 0;
            padding-block: var(--wp--preset--spacing--lg);
        }

        /* Chrome-less overlay header (the header part opts in via className="header-overlay"):
           floats transparently over the full-bleed hero instead of stacking above it. The
           absolute positioning resolves against the viewport, not the padded body, so the
           horizontal padding mirrors the theme's root padding (--wp--style--root--padding-*,
           emitted when useRootPaddingAwareAlignments is on) — the title/nav then share the
           same gutter as the constrained content below. The top offset clears the WP admin
           bar when logged in (core defines the var only while the bar renders); logged-out
           visitors get the 0px fallback. */
        .wp-site-blocks .header-overlay {
            position: absolute;
            top: var(--wp-admin--admin-bar--height, 0px);
            left: 0;
            right: 0;
            z-index: 10;
            background: transparent;
            padding-top: var(--wp--preset--spacing--sm);
            padding-bottom: var(--wp--preset--spacing--sm);
            padding-left: var(--wp--style--root--padding-left, var(--wp--preset--spacing--md));
            padding-right: var(--wp--style--root--padding-right, var(--wp--preset--spacing--md));
        }

        /* The root block-gap margin would open a page-background band between every pair
           of top-level template parts — above the hero (behind the transparent overlay
           header, where light text lands on the page background) and as a visible stripe
           between adjacent dark/tinted section bands. Sections bring their own vertical
           padding by design, so the root flow gap is never wanted: kill it on every
           top-level child. */
        .wp-site-blocks > * {
            margin-block-start: 0;
        }

        /* Page content is the same stack of self-padded section bands, seeded by
           the companion content plugin into post content — kill the flow gap
           there too, or a page-background stripe opens between adjacent bands. */
        .wp-block-post-content > * {
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
