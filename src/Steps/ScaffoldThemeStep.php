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
           Uniform media proportions live here, in the theme stylesheet, instead
           of per-image inline CSS — fix-blocks deletes inline styles that
           aren't mirrored in block attributes, and a class hook survives
           untouched. Aspect ratios, not fixed pixel heights: a fixed crop
           height distorts card media proportions across 2/3/4-column layouts
           and viewports (BIGR-771); the list thumb's old fixed 110px height
           letterboxed its square image to whatever ratio the column width
           produced and left it floating in taller rows (BIGR-777). */
        .card-media img,
        .card-media-tall img,
        .card-media-thumb img {
            width: 100%;
            object-fit: cover;
            display: block;
        }
        .card-media img { aspect-ratio: 3 / 2; height: auto; }
        .card-media-tall img { aspect-ratio: 4 / 5; height: auto; }
        .card-media-thumb img { aspect-ratio: 1 / 1; height: auto; }

        /* Caption readability (ContrastFix opts a figure in via className).
           A figcaption inherits the surrounding text color and the image
           block supports no textColor of its own, so an unreadable inherited
           caption inside a tinted band is repaired through these class hooks
           (BIGR-784). The bare figcaption selector covers both caption class
           spellings the generator emits (wp-element-caption and the legacy
           wp-block-image__caption). Hooks mirror ContrastFix's candidates. */
        .caption-text-base > figcaption { color: var(--wp--preset--color--base); }
        .caption-text-contrast > figcaption { color: var(--wp--preset--color--contrast); }

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
        /* A nested card body becomes another growing flex column only in an
           equal-height row, so a nested .cta-bottom can consume its remaining
           height and align with CTAs in sibling cards. */
        .equal-cards .wp-block-group.card-body {
            display: flex;
            flex-direction: column;
            flex-grow: 1;
        }
        /* Core's constrained layout gives direct children auto side margins
           with !important. Reset that geometry for any marked card's optional
           text wrapper, not only equal-grid cards: staggered and editorial
           overlap cards need the same side reveal in both editor and front end. */
        .wp-block-group:is(.card-style--flush, .card-style--framed, .card-style--overlap, .card-style--borderless) > .wp-block-group.card-body {
            box-sizing: border-box;
            width: 100%;
            max-width: none;
            margin-left: 0 !important;
            margin-right: 0 !important;
            align-self: stretch;
        }
        /* The overlap panel deliberately retains a one-rem reveal on each side.
           Its explicit width keeps the fixed margins inside the card box. */
        .wp-block-group.card-style--overlap > .wp-block-group.card-body.overlap-up {
            width: calc(100% - 2rem);
            margin-left: 1rem !important;
            margin-right: 1rem !important;
        }
        .wp-block-group:is(.card-style--flush, .card-style--framed, .card-style--overlap, .card-style--borderless) > .wp-block-group.card-body > :where(:not(.alignleft):not(.alignright):not(.alignfull)) {
            box-sizing: border-box;
            width: 100%;
            max-width: none;
            margin-left: 0 !important;
            margin-right: 0 !important;
            align-self: stretch;
        }
        /* The flex-column card above defeats core's constrained layout on its
           media: `.is-layout-constrained > *` gives the figure auto side
           margins, and in a flex container auto cross-axis margins beat
           stretch, so the figure shrink-wraps to the image's intrinsic width
           and floats centered (BIGR-771). Pin card media to the card's full
           content box regardless of the source image's aspect ratio. */
        .equal-cards .wp-block-group > figure.wp-block-image {
            width: 100%;
            max-width: none;
            margin-left: 0 !important;
            margin-right: 0 !important;
            align-self: stretch;
        }
        .equal-cards .cta-bottom {
            margin-top: auto;
            justify-content: center;
        }

        /* Flush-media cards (sections opt in via className="card-flush" on the
           card wp:group): the media is the card's first child at full width and
           only an inner .card-body group carries padding. Reset the card itself
           with enough specificity and importance to beat a global Group
           padding rule and stray generated inline padding without touching the
           body's authored padding. The card's radius clips the bleeding image. */
        .wp-block-group.card-flush {
            overflow: hidden;
            padding: 0 !important;
        }
        /* An image's block attributes serialize radius inline. The flush-card
           contract owns this edge, so importance is required to keep a stray
           generated image radius from surviving; descendants also cover images
           wrapped in a link. */
        .wp-block-group.card-flush > figure.wp-block-image img {
            border-radius: 0 !important;
        }

        /* Flush list-thumb rows (sections opt in via className="list-thumb-flush"
           on the row wp:columns): the thumbnail bleeds to the row's top/left/
           bottom edges and stretches to the row height while only the text
           column carries padding (BIGR-777). Zeroed row padding must beat
           generated inline padding, exactly like .card-flush, and the row's
           border radius clips the bleeding image. The column gap is zeroed
           too: the text column's own left padding is the whole image-to-text
           distance — left to the default md gap it stacks with that padding
           and pushes each row's text farther from its own thumb than the md
           rhythm separating the rows. */
        .wp-block-columns.list-thumb-flush {
            overflow: hidden;
            padding: 0 !important;
            align-items: stretch;
            flex-wrap: nowrap !important;
            gap: 0;
        }
        /* Generators drift into verticalAlignment:center on these rows, and
           core's align-self on the column beats the row's align-items, which
           would collapse the stretched thumb back to a floating strip. */
        .wp-block-columns.list-thumb-flush > .wp-block-column {
            align-self: stretch;
        }
        /* The recipe authors isStackedOnMobile:false, but keep the behavior
           hook safe when generated attributes drift. Core forces both columns
           to flex-basis:100% at <=781px, so restore this recipe's reviewed
           media/text proportions with greater specificity and importance. */
        @media (max-width: 781px) {
            .wp-block-columns.list-thumb-flush > .wp-block-column:first-child {
                flex-basis: 18% !important;
            }
            .wp-block-columns.list-thumb-flush > .wp-block-column:last-child {
                flex-basis: 82% !important;
            }
        }
        /* At wide viewports the square thumb can out-measure a short text
           stack; the row then takes the thumb's height and top-pinned copy
           would ride the row's upper edge. Centering only spends the extra
           space — a text-driven row height leaves none. */
        .wp-block-columns.list-thumb-flush > .wp-block-column:not(:has(figure.card-media-thumb)) {
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .list-thumb-flush > .wp-block-column > figure.wp-block-image.card-media-thumb {
            height: 100%;
            margin: 0;
        }
        /* The text column defines the row height; the thumb follows it instead
           of imposing the square crop (the reviewed full-height media pattern). */
        .list-thumb-flush .card-media-thumb img {
            aspect-ratio: auto;
            height: 100%;
            border-radius: 0 !important;
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
        .hero-composition--layered-poster {
            overflow: hidden;
        }
        /* The type-led recipe has no media to balance the composition, so
           the statement itself must set well: balance the rag of its two-to
           four-line headline. Inert for every other recipe. */
        .hero-composition--statement-type .wp-block-heading {
            text-wrap: balance;
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
            .hero-mobile--stack-media-first .wp-block-columns {
                flex-direction: column;
            }
            .hero-mobile--stack-copy-first .wp-block-media-text,
            .hero-mobile--stack-media-first .wp-block-media-text {
                grid-template-columns: minmax(0, 1fr) !important;
            }
            .hero-mobile--stack-copy-first .wp-block-media-text__content,
            .hero-mobile--stack-media-first .wp-block-media-text__media {
                grid-column: 1;
                grid-row: 1;
            }
            .hero-mobile--stack-copy-first .wp-block-media-text__media,
            .hero-mobile--stack-media-first .wp-block-media-text__content {
                grid-column: 1;
                grid-row: 2;
            }
            .hero-mobile--stack-copy-first .hero-composition__copy {
                order: 1;
            }
            .hero-mobile--stack-copy-first .hero-composition__media {
                order: 2;
            }
            .hero-mobile--stack-media-first .hero-composition__media {
                order: 1;
            }
            .hero-mobile--stack-media-first .hero-composition__copy {
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
            /* The transform above swaps the copy's surface from dimmed image
               to solid contrast, so it must own the copy color too: authored
               inline/preset colors were picked for the overlay surface and can
               land light-on-light on this panel (invisible H1, BIGR-788).
               base-on-contrast is the palette's maximum-contrast pair. Buttons
               and links keep their own chrome. */
            .hero-composition--cinematic-safe-zone.hero-mobile--stack-media-first
                .wp-block-cover__inner-container
                :is(h1, h2, h3, h4, h5, h6, p, cite):not(.wp-block-button__link) {
                color: var(--wp--preset--color--base) !important;
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
