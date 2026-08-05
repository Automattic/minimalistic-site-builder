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
 *         four profiles — the design direction hasn't been chosen yet), and
 *         the trusted adaptive-header kit in theme/assets/header/. The
 *         finalize-theme step later prunes assets the committed design does
 *         not use and enqueues the rest.
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
                'theme/assets/header/*',
            ],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $project->writeText('theme/style.css', self::STYLE_CSS);
        $project->writeText('theme/readme.txt', self::README);
        self::copyMotionKit($project);
        self::copyHeaderKit($project);
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

    /** Copy the hand-written adaptive-header kit into the theme byte-for-byte. */
    private static function copyHeaderKit(Project $project): void
    {
        $kit = Package::headerDir();
        foreach (['header.css', 'header.js'] as $file) {
            $source = "{$kit}/{$file}";
            $contents = @file_get_contents($source);
            if ($contents === false) {
                throw new \RuntimeException("Missing or unreadable trusted header asset: {$source}");
            }
            $project->writeText('theme/assets/header/' . $file, $contents);
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
        Requires at least: 7.0
        Tested up to: 7.0
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

        /* Raise the navigation's hamburger breakpoint from core's 600px to 720px
           (BIGR-735). A tracked-uppercase title/nav row that fits comfortably at
           768px can still wrap or overflow in the 600-719px band, where core's
           overlayMenu:"mobile" has already switched back to the inline nav. Core
           gates the swap with two 600px media queries; these two rules re-apply
           the collapsed state for the band above them. The .wp-site-blocks
           prefix outranks core's selectors regardless of enqueue order, and
           scopes the fix to the front end. overlayMenu:"always" (hidden-by-
           default) and the opened modal (is-menu-open) are untouched. */
        @media (min-width: 600px) and (max-width: 719.98px) {
            .wp-site-blocks .wp-block-navigation__responsive-container-open:not(.always-shown) {
                display: flex;
            }
            .wp-site-blocks .wp-block-navigation__responsive-container:not(.hidden-by-default):not(.is-menu-open) {
                display: none;
            }
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

        /* Reviewed hero recipe skeletons. The generator owns site-specific
           ratios and spacing inside these bounds; these inert, code-owned
           hooks preserve each recipe's essential media behavior when no
           generated page-style appendix is available. */
        .hero-composition--cinematic-safe-zone > .wp-block-cover,
        .hero-composition--layered-poster > .wp-block-cover {
            overflow: hidden;
        }
        .hero-composition--editorial-split .wp-block-columns,
        .hero-composition--framed-portrait .wp-block-columns,
        .hero-composition--focal-subject-stage .wp-block-columns {
            align-items: center;
        }
        .hero-composition--editorial-split .hero-composition__media img,
        .hero-composition--focal-subject-stage .hero-composition__media img {
            width: 100%;
            height: auto;
            object-fit: cover;
        }
        .hero-composition--framed-portrait .hero-composition__media img {
            width: 100%;
            aspect-ratio: 3 / 4;
            object-fit: cover;
        }
        .hero-composition--panorama-rail .hero-composition__media img {
            width: 100%;
            aspect-ratio: 16 / 7;
            object-fit: cover;
        }
        /* A generated rail authored beside the media (instead of the recipe's
           band-then-rail rows) would bottom-align a short image against a
           taller copy column and open dead canvas above it; stretching the
           media to the row keeps even that deviation composed. */
        .hero-composition--panorama-rail .wp-block-columns:has(> .hero-composition__media) {
            align-items: stretch;
        }
        .hero-composition--panorama-rail .wp-block-column.hero-composition__media .wp-block-image,
        .hero-composition--panorama-rail .wp-block-column.hero-composition__media img {
            height: 100%;
        }
        .hero-composition--panorama-rail .wp-block-column.hero-composition__media img {
            aspect-ratio: auto;
        }
        .hero-composition--layered-poster {
            overflow: hidden;
        }
        /* A display word that cannot fit its measure must hyphenate at a
           language break as a last resort, never snap mid-word; the prompts
           size headline presets so this rule stays dormant. */
        .hero-composition__copy .wp-block-heading,
        .hero-composition--layered-poster .wp-block-heading {
            overflow-wrap: break-word;
            hyphens: auto;
        }

        /* Blueprint-selected mobile transformations. These rules act only on
           the exact root marker normalized by HeroUnit; CSS never guesses a
           quiet image region or changes the selected recipe at runtime. */
        @media (max-width: 781.98px) {
            .hero-mobile--stack-copy-first .wp-block-columns,
            .hero-mobile--stack-media-first .wp-block-columns,
            .hero-mobile--rail-below .wp-block-columns {
                flex-direction: column;
            }
            .hero-mobile--stack-copy-first .wp-block-media-text,
            .hero-mobile--stack-media-first .wp-block-media-text,
            .hero-mobile--rail-below .wp-block-media-text {
                grid-template-columns: minmax(0, 1fr) !important;
            }
            .hero-mobile--stack-copy-first .wp-block-media-text__content,
            .hero-mobile--stack-media-first .wp-block-media-text__media,
            .hero-mobile--rail-below .wp-block-media-text__media {
                grid-column: 1;
                grid-row: 1;
            }
            .hero-mobile--stack-copy-first .wp-block-media-text__media,
            .hero-mobile--stack-media-first .wp-block-media-text__content,
            .hero-mobile--rail-below .wp-block-media-text__content {
                grid-column: 1;
                grid-row: 2;
            }
            .hero-mobile--stack-copy-first .hero-composition__copy {
                order: 1;
            }
            .hero-mobile--stack-copy-first .hero-composition__media {
                order: 2;
            }
            .hero-mobile--stack-media-first .hero-composition__media,
            .hero-mobile--rail-below .hero-composition__media {
                order: 1;
            }
            .hero-mobile--stack-media-first .hero-composition__copy,
            .hero-mobile--rail-below .hero-composition__copy {
                order: 2;
            }
            /* A cover owns both the media and copy DOM, so flex order cannot
               reparent its nested copy. Turn only the assigned cinematic
               mobile variant into a visual media-then-copy sequence: the
               cover image/protection layer occupies the upper field and the
               inner container becomes the solid readable lower field. */
            .hero-composition--cinematic-safe-zone.hero-mobile--stack-media-first .wp-block-cover {
                min-height: 0 !important;
                padding: min(62vw, 28rem) 0 0;
                background: var(--wp--preset--color--contrast);
            }
            .hero-composition--cinematic-safe-zone.hero-mobile--stack-media-first
                .wp-block-cover__image-background,
            .hero-composition--cinematic-safe-zone.hero-mobile--stack-media-first
                .wp-block-cover__background {
                top: 0;
                bottom: auto;
                height: min(62vw, 28rem);
            }
            .hero-composition--cinematic-safe-zone.hero-mobile--stack-media-first
                .wp-block-cover__inner-container {
                width: 100%;
                box-sizing: border-box;
                padding: var(--wp--preset--spacing--lg) var(--wp--preset--spacing--md);
                background: var(--wp--preset--color--contrast);
                color: var(--wp--preset--color--base);
            }
            .hero-mobile--flatten-layers .hero-composition__layers,
            .hero-mobile--flatten-layers .hero-composition__copy,
            .hero-mobile--flatten-layers .hero-composition__media {
                position: static;
                inset: auto;
                transform: none;
            }
            .hero-mobile--retain-media-overlay .hero-composition__copy {
                max-width: min(88%, 32rem);
            }
        }

        CSS;

    private const README = <<<TXT
        === {{THEME_NAME}} ===

        Contributors: {{AUTHOR}}
        Requires at least: 7.0
        Tested up to: 7.0
        Requires PHP: 7.4
        License: GNU General Public License v2 or later
        License URI: https://www.gnu.org/licenses/gpl-2.0.html

        == Description ==

        {{DESCRIPTION}}

        TXT;
}
