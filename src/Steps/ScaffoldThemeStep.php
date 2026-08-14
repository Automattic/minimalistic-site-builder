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

        /* Browser surfaces the model never themes. Palette-tinted selection,
           caret, scrollbar, focus, underline offset, and tabular numerals so
           core defaults do not announce a template. */
        ::selection {
            color: var(--wp--preset--color--base);
            background-color: var(--wp--preset--color--primary);
        }
        ::-moz-selection {
            color: var(--wp--preset--color--base);
            background-color: var(--wp--preset--color--primary);
        }
        html {
            caret-color: var(--wp--preset--color--accent);
            scrollbar-color: var(--wp--preset--color--secondary) var(--wp--preset--color--base);
        }
        .wp-site-blocks a {
            text-underline-offset: 0.18em;
        }
        .wp-site-blocks :focus-visible {
            outline: 2px solid var(--wp--preset--color--accent);
            outline-offset: 3px;
        }
        .wp-site-blocks :is(.has-caption-font-size, .wp-element-caption, figcaption) {
            font-variant-numeric: tabular-nums;
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

        /* Hamburger / overlay panel.
           Color: core paints `:not(.has-background) .is-menu-open { #fff }`,
           so contrast-colored labels on a dark canvas become cream-on-white.
           Layout: the open modal inherits the desktop justification
           (`items-justified-right` / --navigation-layout-justification-setting),
           which glues the list to one viewport edge and clips labels. Reset
           both here so every header — static or adaptive — gets a readable
           stacked sheet. */
        .wp-site-blocks .wp-block-navigation__responsive-container.is-menu-open {
            --navigation-layout-justification-setting: flex-start;
            --navigation-layout-justify: flex-start;
            position: fixed !important;
            inset: 0 !important;
            z-index: 10000 !important;
            width: 100% !important;
            height: 100% !important;
            max-width: none !important;
            box-sizing: border-box !important;
            padding: max(1.25rem, env(safe-area-inset-top, 0px))
                max(1.25rem, env(safe-area-inset-right, 0px))
                max(1.25rem, env(safe-area-inset-bottom, 0px))
                max(1.25rem, env(safe-area-inset-left, 0px)) !important;
            overflow: auto !important;
            color: var(--wp--preset--color--contrast) !important;
            background-color: var(--wp--preset--color--base) !important;
            background-image: none !important;
        }
        .wp-site-blocks .wp-block-navigation__responsive-container.is-menu-open
            .wp-block-navigation__responsive-dialog,
        .wp-site-blocks .wp-block-navigation__responsive-container.is-menu-open
            .wp-block-navigation__responsive-container-content {
            width: 100% !important;
            max-width: none !important;
            margin: 0 !important;
            color: inherit !important;
            background-color: transparent !important;
            background-image: none !important;
        }
        .wp-site-blocks .wp-block-navigation__responsive-container.is-menu-open
            .wp-block-navigation__responsive-container-content {
            align-items: stretch !important;
            justify-content: flex-start !important;
            text-align: start !important;
            padding-block-start: 3.25rem !important;
        }
        .wp-site-blocks .wp-block-navigation__responsive-container.is-menu-open
            :is(.wp-block-navigation__container, .wp-block-page-list) {
            display: flex !important;
            flex-direction: column !important;
            align-items: stretch !important;
            justify-content: flex-start !important;
            gap: 0.15rem !important;
            width: 100% !important;
        }
        .wp-site-blocks .wp-block-navigation__responsive-container.is-menu-open
            .wp-block-navigation-item {
            width: 100% !important;
        }
        .wp-site-blocks .wp-block-navigation__responsive-container.is-menu-open
            .wp-block-navigation-item__content:not(.wp-element-button) {
            display: block !important;
            width: 100% !important;
            box-sizing: border-box !important;
            padding-block: 0.85rem !important;
            text-align: start !important;
            font-size: var(--wp--preset--font-size--heading, 1.5rem) !important;
            line-height: 1.25 !important;
            white-space: normal !important;
            overflow-wrap: anywhere;
            color: var(--wp--preset--color--contrast) !important;
        }
        .wp-site-blocks .wp-block-navigation__responsive-container.is-menu-open
            :is(
                .wp-block-navigation__responsive-container-close,
                .wp-block-navigation__submenu-icon
            ) {
            color: var(--wp--preset--color--contrast) !important;
        }
        .wp-site-blocks .wp-block-navigation__responsive-container.is-menu-open
            .wp-block-navigation__responsive-container-close {
            top: max(1rem, env(safe-area-inset-top, 0px)) !important;
            inset-inline-end: max(1rem, env(safe-area-inset-right, 0px)) !important;
            inset-inline-start: auto !important;
        }
        .wp-site-blocks .wp-block-navigation__responsive-container.is-menu-open
            .wp-block-navigation-item__content:not(.wp-element-button):is(:hover, :focus) {
            color: var(--wp--preset--color--accent) !important;
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
        /* cinematic-safe-zone overlays copy on a full-bleed image and reserves
           image room with a right-side percentage inset. When the copy is
           authored as columns, the constrained layout otherwise caps it at
           contentSize while the inset resolves against the wider hero — the two
           collide and starve the copy. Let the copy span the hero's full width
           so the inset leaves its intended measure. */
        .hero-composition--cinematic-safe-zone .wp-block-columns {
            max-width: none;
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
        /* Hero headlines wrap whole words. `hyphens: auto` was here to prefer
           a language break over a mid-word snap, but it hyphenates at EVERY
           line-break opportunity, not only the impossible ones: tbilisi5's
           "A long ta-/ble in Old Town" split a word that would have sat whole
           on the next line, on a browser that ships hyphenation dictionaries.
           No one sets a display headline hyphenated, so it is off by default
           and HeroHeadlineFit opts a heading in — via .headline-hyphenate —
           only for a word no size it can pin will fit. break-word stays as the
           final resort under both. */
        .hero-composition__copy .wp-block-heading,
        .hero-composition--layered-poster .wp-block-heading {
            overflow-wrap: break-word;
            hyphens: manual;
        }
        .hero-composition__copy .wp-block-heading.headline-hyphenate,
        .hero-composition--layered-poster .wp-block-heading.headline-hyphenate {
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

        /* HTML-first layout floor. Home-body may invent .section / .card-grid /
           .footer-inner without a stylesheet (preview CSS is first-fold only).
           These rules are inert when the classes are absent. */
        .section,
        .page-head {
            padding-top: var(--wp--preset--spacing--xl, clamp(2.5rem, 6vw, 4.5rem));
            padding-bottom: var(--wp--preset--spacing--xl, clamp(2.5rem, 6vw, 4.5rem));
        }
        .section-inner,
        .page-head-inner,
        .footer-inner {
            box-sizing: border-box;
            width: 100%;
            max-width: var(--wp--style--global--wide-size, 1280px);
            margin-left: auto;
            margin-right: auto;
            padding-left: clamp(1rem, 4vw, 2.5rem);
            padding-right: clamp(1rem, 4vw, 2.5rem);
        }
        .card-grid {
            display: grid;
            gap: 1.25rem 1.5rem;
            align-items: stretch;
        }
        @media (min-width: 40rem) {
            .card-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (min-width: 64rem) {
            .card-grid:has(> :nth-child(3)) { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .card-grid:has(> :nth-child(4)) { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        }
        .card-grid > :is(.card, article, .wp-block-group) {
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        .hero-actions,
        .hero-actions .wp-block-buttons {
            display: flex;
            flex-direction: row;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.9rem 1.4rem;
        }
        .hero-actions > .wp-block-buttons {
            margin: 0;
            width: auto;
        }
        .card-grid > :is(.card, article, .wp-block-group),
        .grid-2 > :is(.card, article, .wp-block-group, .note-card),
        .grid-3 > :is(.card, article, .wp-block-group, .note-card) {
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        .grid-2,
        .grid-3 {
            display: grid;
            gap: 1.25rem 1.5rem;
            align-items: stretch;
        }
        @media (min-width: 40rem) {
            .grid-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (min-width: 64rem) {
            .grid-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        /* theme.json underlines every link. Nav items also draw a hover
           border, which reads as two lines. Current page uses WP's
           current-menu-item, not a hardcoded is-current. */
        header nav a,
        header nav .wp-block-navigation-item__content,
        .nav-links a {
            text-decoration: none;
        }
        header nav a:hover,
        header nav .wp-block-navigation-item__content:hover,
        .nav-links a:hover {
            text-decoration: none;
        }
        header nav .current-menu-item > a,
        header nav .current-menu-item > .wp-block-navigation-item__content,
        .nav-links .current-menu-item > a {
            color: var(--wp--preset--color--primary, inherit);
            border-bottom: 2px solid var(--wp--preset--color--primary, currentColor);
            text-decoration: none;
        }
        header nav > :is(.brand, p.brand) {
            margin-block: 0;
            display: flex;
            align-items: center;
        }
        header nav .wp-block-navigation__responsive-container-open {
            align-self: center;
        }
        figure.wp-block-table,
        .wp-block-table figcaption,
        .k-table-scroll {
            margin-bottom: 1.5rem;
        }
        .k-section + .k-section,
        .sec + .sec {
            margin-top: 0.5rem;
        }
        /* Designed row headers become constrained groups after convert.
           Core's is-layout-constrained beats `header nav { display:flex }`
           and overlayMenu:always leaves the hamburger where the link list
           was — between the wordmark and the CTA. Keep a start-aligned row
           and park the nav (hamburger) at the inline end. */
        header nav.wp-block-group:is(.is-layout-constrained, .is-layout-flow, .is-layout-flex) {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-start;
            gap: 0.75rem 1.5rem;
            width: 100%;
            max-width: var(--wp--style--global--wide-size, 1280px);
            margin-inline: auto;
        }
        header nav > .wp-block-navigation {
            margin-inline-start: auto;
        }
        header nav .wp-block-navigation__responsive-container-open {
            margin-inline-start: auto;
        }
        .footer-inner {
            display: grid;
            gap: 1.5rem 2rem;
            padding-top: clamp(2rem, 5vw, 3.5rem);
            padding-bottom: clamp(1.5rem, 4vw, 2.5rem);
        }
        @media (min-width: 52rem) {
            .footer-inner {
                grid-template-columns: minmax(0, 1.1fr) minmax(0, 1.6fr);
                align-items: start;
            }
        }
        .footer-nav {
            display: grid;
            gap: 1.25rem 2rem;
        }
        @media (min-width: 40rem) {
            .footer-nav { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        .footer-word {
            font-size: clamp(1.5rem, 1.2rem + 1vw, 2rem);
            letter-spacing: -0.03em;
            margin: 0 0 0.4rem;
        }
        .footer-head {
            font-size: 0.75rem;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            margin: 0 0 0.6rem;
        }
        .footer-legal {
            grid-column: 1 / -1;
            margin: 0;
            opacity: 0.72;
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
