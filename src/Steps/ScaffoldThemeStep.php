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
        .card-media-thumb img,
        .feature-media img {
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

        /* A section's copy stack widened to sit on the same edge as the grid
           row below it (LayoutFixer marks it "copy-flush"). The group stays
           constrained, so core still caps these children at contentSize; all
           this undoes is core's auto side margins, which would centre that
           cap in the wider box and leave the text floating again. */
        .wp-block-group.copy-flush > * {
            margin-inline-start: 0 !important;
            margin-inline-end: auto !important;
        }
        /* The same readable cap, placed on the wide band's trailing edge for
           an asymmetric-thirds assignment. LayoutFixer respects this authored
           hook instead of replacing it with copy-flush. */
        .wp-block-group.copy-end > * {
            margin-inline-start: auto !important;
            margin-inline-end: 0 !important;
        }
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

        /* Pinned lead region of a split band (BIGR-945). `SectionComposition`
           asks for this class only where the plan already says one region
           repeats and the other does not, so the short lead stays in view
           instead of stranding a blank quadrant beside the long list.

           The rule is owned here rather than left to the page-styles utility
           of the same name: that utility is model-authored per site, and an
           archetype that REQUIRES the behavior cannot depend on a CSS pass
           choosing to emit it. A site whose page-styles also writes the utility
           simply agrees with this.

           `align-self` opts the column out of the row's default stretch,
           without which a full-height column can never stick. The column gets
           no height clamp and no inner scroll: a nested scroll bar reads as a
           defect. A pinned column taller than the viewport stays reachable
           through the page scroll, because sticky positioning releases the
           column when the section end comes into view. The whole behavior is
           desktop-only, so the stacked state is an ordinary column in source
           order. */
        .section-composition--asymmetric-split .wp-block-column.sticky-side {
            align-self: flex-start;
        }
        @media (min-width: 782px) {
            .section-composition--asymmetric-split .wp-block-column.sticky-side {
                position: sticky;
                top: var(--wp--preset--spacing--lg, 3rem);
            }
        }

        /* Centered stack (BIGR-952). The archetype's whole composition is one
           centered column, but its alignment used to live only in prompt
           prose, so a band could ship with a centered heading and a centered
           button over start-aligned copy. The rule is owned here for the same
           reason the pin rule above is: a behavior the archetype requires
           cannot depend on per-element model choices. `text-align` inherits,
           so an element that carries its own `has-text-align-*` class still
           wins. */
        .section-composition--centered-stack {
            text-align: center;
        }
        /* Exempt scope. Three kinds of content keep their own start alignment
           inside the centered band, and every rule below names the same scope:
           - a repeated item row (a centered stack may carry a spec-table item
             pattern);
           - a form the host substituted in for a placeholder: its labels sit
             above input text the browser aligns to the start, so centering
             them is the same mixed alignment;
           - the host's form container (`.jetpack-contact-form-container`,
             from Jetpack's `Contact_Form::get_block_container_classes`),
             which renders the server-side error block before the form and
             the no-reload success message as a sibling of it, so those two
             states follow the form's alignment. */
        .section-composition--centered-stack :is(.item-pattern__item, form, .jetpack-contact-form-container) {
            text-align: start;
        }
        /* A wp:buttons row is a flex container, so the inherited text-align
           cannot move it; an unjustified row stays at the start edge. Only a
           row with no authored justification is centered here. The
           `:not(<exempt scope> *)` guard (BIGR-952 review follow-up) keeps a
           nested buttons row out of this rule: a centered buttons row inside
           a start-aligned exemption would recreate the mixed-alignment
           defect this block exists to remove. */
        .section-composition--centered-stack .wp-block-buttons:not(.is-content-justification-left):not(.is-content-justification-right):not(.is-content-justification-space-between):not(:is(.item-pattern__item, form, .jetpack-contact-form-container) *) {
            justify-content: center;
        }
        /* A list centers as a block while its items stay start-aligned,
           because centered lines under start-anchored markers read as a
           ragged accident. The same guard keeps a list inside an exemption
           at the exemption's start edge: without it, the list would still
           take `margin-inline: auto` and center inside the start-aligned
           row, form, or error block. */
        .section-composition--centered-stack :is(ul, ol):not(:is(.item-pattern__item, form, .jetpack-contact-form-container) *) {
            width: fit-content;
            margin-inline: auto;
            text-align: start;
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

        /* One hamburger per header, not one per nav. A split-nav header holds
           two wp:navigation blocks flanking the wordmark, and below the
           breakpoint core gives each its own toggle — two identical hamburgers
           opening half the site each. HeaderHeroStep::consolidateMobileNav
           marks the second nav header-nav-wide-only and copies its items into
           the first as header-nav-overlay-only; these two rules show exactly
           one of each pair. The 720px boundary is the raised breakpoint above,
           so the swap happens on the same edge as the hamburger's.
           (Class names are HeaderHeroStep::NAV_WIDE_ONLY_CLASS and
           NAV_OVERLAY_ONLY_CLASS — keep the three in step.) */
        @media (max-width: 719.98px) {
            .wp-site-blocks .header-nav-wide-only {
                display: none !important;
            }
        }
        @media (min-width: 720px) {
            .wp-site-blocks .header-nav-overlay-only {
                display: none !important;
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

            /* Name the pair this panel is painted from, so the active state
               below can invert it instead of inventing a third color. The
               header kit re-points both at its resolved behavior surface. */
            --menu-panel-ink: var(--wp--preset--color--contrast);
            --menu-panel-surface: var(--wp--preset--color--base);
        }
        .wp-site-blocks .wp-block-navigation__responsive-container.is-menu-open
            .wp-block-navigation__responsive-dialog,
        .wp-site-blocks .wp-block-navigation__responsive-container.is-menu-open
            .wp-block-navigation__responsive-container-content {
            width: 100% !important;
            max-width: none !important;
            color: inherit !important;
            background-color: transparent !important;
            background-image: none !important;
        }
        .wp-site-blocks .wp-block-navigation__responsive-container.is-menu-open
            .wp-block-navigation__responsive-container-content {
            margin: 0 !important;
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
            /* Inline padding so the active row below is a padded bar rather
               than a band cropped to the text. The matching negative margin
               keeps every label optically aligned with the panel's content
               edge, at rest and active alike — the bar bleeds, the text does
               not move. */
            width: calc(100% + 1.8rem) !important;
            margin-inline: -0.9rem !important;
            box-sizing: border-box !important;
            padding-block: 0.85rem !important;
            padding-inline: 0.9rem !important;
            border-radius: 0.25rem;
            text-align: start !important;
            font-size: var(--wp--preset--font-size--heading, 1.5rem) !important;
            line-height: 1.25 !important;
            white-space: normal !important;
            overflow-wrap: anywhere;
            color: inherit !important;
        }
        .wp-site-blocks .wp-block-navigation__responsive-container.is-menu-open
            :is(
                .wp-block-navigation__responsive-container-close,
                .wp-block-navigation__submenu-icon
            ) {
            color: inherit !important;
        }
        .wp-site-blocks .wp-block-navigation__responsive-container.is-menu-open
            .wp-block-navigation__responsive-container-close {
            top: max(1rem, env(safe-area-inset-top, 0px)) !important;
            inset-inline-end: max(1rem, env(safe-area-inset-right, 0px)) !important;
            inset-inline-start: auto !important;
        }
        /* The active row INVERTS the panel's own pair: its ink becomes the bar,
           its surface becomes the label. Contrast is therefore identical to
           the resting state's by construction — the same two colors, swapped —
           on every build, with no third color to check.

           Two wrong answers this replaces. A fixed `accent` vanished on every
           build whose panel surface IS accent (contrast 1.0). And tinting the
           bar with the ink at low alpha reads as "safe" but is not: it drags
           the background TOWARD the text, so contrast falls as the tint
           strengthens (14% measured 3.8:1, under AA). Only a full swap keeps
           the proven ratio. A fixed white would fail the same way — it is
           3.85:1 on this orange panel and invisible on a light one. */
        .wp-site-blocks .wp-block-navigation__responsive-container.is-menu-open
            .wp-block-navigation-item__content:not(.wp-element-button):is(:hover, :focus) {
            background-color: var(--menu-panel-ink, currentColor);
            color: var(--menu-panel-surface, inherit) !important;
            text-decoration: none !important;
        }

        /* Core gives a page-list flex and list-style only under
           `.wp-block-navigation`; bare, it keeps the UA <ul> discs and indent,
           which footers ship as a bulleted link column. Inside a navigation
           this is a no-op, so it is safe to apply everywhere. */
        .wp-site-blocks .wp-block-page-list {
            list-style: none;
            padding-inline-start: 0;
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
        .hero-composition--foreground-split .wp-block-columns {
            align-items: center;
        }
        .hero-composition--foreground-split .hero-composition__media img {
            width: 100%;
            height: auto;
            object-fit: cover;
        }
        /* The blueprint's portrait media_aspect (BIGR-912). The build stamps
           hero-media--<aspect> on the hero root the same way it stamps
           hero-mobile--<transformation>, so this holds the plate to its ratio
           even when the delivered image drifts and the contained vertical
           frame survives a landscape file (BIGR-925). */
        .hero-composition--foreground-split.hero-media--portrait .hero-composition__media img {
            aspect-ratio: 3 / 4;
        }
        .hero-composition--layered-poster {
            overflow: hidden;
        }
        /* metadata-corners (frm W2c): one full-bleed cover at viewport
           height; the inner container becomes a column with the fact group
           at the top, where its flex row spreads two or three short facts
           into the corners, and the copy centered in the room below. The facts sit
           last in the DOM (the H1 stays the first text line); `order`
           lifts them. The copy keeps a reading measure on the leading side. */
        .hero-composition--metadata-corners > .wp-block-cover {
            min-height: 100svh;
            align-items: stretch;
        }
        .hero-composition--metadata-corners > .wp-block-cover > .wp-block-cover__inner-container {
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            gap: var(--wp--preset--spacing--lg, 3rem);
            width: 100%;
            max-width: none;
            box-sizing: border-box;
            padding: var(--wp--preset--spacing--md, 1.5rem) var(--wp--preset--spacing--lg, 3rem);
        }
        .hero-composition--metadata-corners .hero-composition__meta {
            order: -1;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: var(--wp--preset--spacing--md, 1.5rem);
            width: 100%;
            opacity: 0.85;
        }
        .hero-composition--metadata-corners .hero-composition__meta > p {
            margin: 0;
            max-inline-size: 16rem;
            font-size: var(--wp--preset--font-size--caption, 0.875rem);
        }
        .hero-composition--metadata-corners .hero-composition__meta > p:last-child:not(:first-child) {
            text-align: end;
        }
        /* The copy centers in the room the fact row leaves: auto block
           margins on the one flex child that is not the row. */
        .hero-composition--metadata-corners .hero-composition__copy {
            margin-block: auto;
            margin-inline: auto;
            max-width: min(100%, 48rem);
        }
        .hero-composition--metadata-corners .hero-composition__copy h1 {
            line-height: 0.95;
            text-wrap: balance;
        }
        /* portrait-backdrop (frm W2d): one portrait plate centered on the
           page ground, capped to the viewport so the copy row stays in the
           first screen, rounded from the committed media radius; the copy
           row under it aligns its two columns to their bottom edge so the
           line and the action sit level with the headline's last line. */
        .hero-composition--portrait-backdrop .hero-composition__media {
            display: flex;
            justify-content: center;
        }
        .hero-composition--portrait-backdrop .hero-composition__media figure {
            margin: 0;
        }
        .hero-composition--portrait-backdrop .hero-composition__media img {
            display: block;
            width: auto;
            max-width: min(100%, 36rem);
            max-height: 58vh;
            object-fit: cover;
            border-radius: var(--shape-radius-media, 0);
        }
        .hero-composition--portrait-backdrop.hero-media--portrait .hero-composition__media img {
            aspect-ratio: 3 / 4;
        }
        .hero-composition--portrait-backdrop.hero-media--square .hero-composition__media img {
            aspect-ratio: 1 / 1;
        }
        .hero-composition--portrait-backdrop .hero-composition__copy .wp-block-columns {
            align-items: flex-end;
        }
        .hero-composition--portrait-backdrop .hero-composition__copy h1 {
            margin-block: 0;
            text-wrap: balance;
        }
        /* marquee-name floating objects (frm W7c): the aria-hidden object
           group is taken out of the flow and pinned over the whole hero;
           each cutout takes a corner slot around the centered stack, above
           the marquee name and below the copy, and drifts a few pixels up
           and down on its own clock. Reduced motion holds them still;
           phones keep the first two, smaller. */
        .hero-composition--marquee-name .hero-composition__objects {
            position: absolute;
            inset: 0;
            margin: 0;
            padding: 0;
            pointer-events: none;
            z-index: 0;
        }
        .hero-composition--marquee-name .hero-composition__objects > figure {
            position: absolute;
            margin: 0;
            width: clamp(4.5rem, 11vw, 10rem);
            animation: hero-object-drift 7s ease-in-out infinite alternate;
        }
        .hero-composition--marquee-name .hero-composition__objects > figure img {
            display: block;
            width: 100%;
            height: auto;
        }
        .hero-composition--marquee-name .hero-composition__objects > figure:nth-child(1) {
            inset-block-start: 14%;
            inset-inline-start: 9%;
        }
        .hero-composition--marquee-name .hero-composition__objects > figure:nth-child(2) {
            inset-block-start: 12%;
            inset-inline-end: 10%;
            animation-delay: -2.4s;
        }
        .hero-composition--marquee-name .hero-composition__objects > figure:nth-child(3) {
            inset-block-end: 12%;
            inset-inline-start: 13%;
            animation-delay: -4.1s;
        }
        .hero-composition--marquee-name .hero-composition__objects > figure:nth-child(4) {
            inset-block-end: 10%;
            inset-inline-end: 8%;
            animation-delay: -1.3s;
        }
        @keyframes hero-object-drift {
            from { translate: 0 -0.5rem; }
            to { translate: 0 0.5rem; }
        }
        @media (prefers-reduced-motion: reduce) {
            .hero-composition--marquee-name .hero-composition__objects > figure {
                animation: none;
                translate: none;
            }
        }
        /* panel-stage (frm W2a): the opener is one rounded, tinted panel on
           the page ground. Rounded from the committed shape scale, clipped,
           with a faint dot grid on the tint; the copy row centers vertically
           and the plate fills its column. */
        .hero-composition--panel-stage .hero-composition__panel {
            border-radius: var(--shape-radius-panel, 0);
            overflow: hidden;
            background-image: radial-gradient(color-mix(in srgb, currentColor 9%, transparent) 1px, transparent 1.5px);
            background-size: 22px 22px;
        }
        .hero-composition--panel-stage .wp-block-columns {
            align-items: center;
        }
        .hero-composition--panel-stage .hero-composition__media img,
        .hero-composition--panel-stage .hero-composition__stage img {
            width: 100%;
            height: auto;
            object-fit: cover;
        }
        /* marquee-name (frm W2b): the site name runs giant and clipped
           behind a centered stack. The name is one aria-hidden paragraph the
           model marks; it is painted here at display scale in the heading
           face, at low opacity in the surface's own foreground, centered by
           flex so an over-wide name clips evenly on both sides, and taken
           out of the flow so the copy above it keeps its rhythm. */
        .hero-composition--marquee-name {
            position: relative;
            overflow: hidden;
        }
        .hero-composition--marquee-name .hero-composition__marquee {
            position: absolute;
            inset-inline: 0;
            top: 0;
            display: flex;
            justify-content: center;
            margin: 0;
            padding: 0;
            font-family: var(--wp--preset--font-family--heading, inherit);
            font-size: clamp(6rem, 24vw, 22rem);
            font-weight: 800;
            line-height: 0.9;
            letter-spacing: -0.045em;
            white-space: nowrap;
            color: currentColor;
            opacity: 0.07;
            pointer-events: none;
            user-select: none;
            -webkit-user-select: none;
        }
        .hero-composition--marquee-name .hero-composition__media,
        .hero-composition--marquee-name .hero-composition__copy {
            position: relative;
            z-index: 1;
        }
        .hero-composition--marquee-name .hero-composition__media img {
            display: block;
            width: clamp(7rem, 16vw, 13rem);
            aspect-ratio: 1;
            height: auto;
            margin-inline: auto;
            object-fit: cover;
            border-radius: var(--shape-radius-card, 0);
            box-shadow: 0 18px 40px rgb(0 0 0 / 0.12);
            transform: rotate(-3deg);
        }
        .hero-composition--marquee-name.hero-media--portrait .hero-composition__media img {
            aspect-ratio: 4 / 5;
        }
        @media (max-width: 600px) {
            .hero-composition--marquee-name .hero-composition__marquee {
                font-size: 30vw;
            }
        }
        @media (max-width: 600px) {
            .hero-composition--panel-stage .hero-composition__panel {
                padding-inline: 1.25rem !important;
            }
            .hero-composition--panel-stage .hero-composition__panel :is(h1, h2) {
                font-size: min(var(--wp--preset--font-size--display), 11vw) !important;
            }
        }
        /* Hero headlines wrap whole words (BIGR-864). `hyphens: auto` was
           here to prefer a language break over a mid-word snap, but it
           hyphenates at EVERY line-break opportunity, not only the impossible
           ones: tbilisi5's "A long ta-/ble in Old Town" split a word that
           would have sat whole on the next line, on a browser that ships
           hyphenation dictionaries. `overflow-wrap: break-word` was the
           remaining last-resort snap ("Conversatio / n") on browsers without
           a hyphenation dictionary. Do not split a token; HeroHeadlineFit
           opts a heading in — via .headline-hyphenate — only for a word no
           size it can pin will fit. */
        .hero-composition__copy .wp-block-heading,
        .hero-composition--layered-poster .wp-block-heading {
            overflow-wrap: normal;
            word-break: normal;
            hyphens: manual;
            /* Balanced breaks keep a two- or three-line display heading from
               ending on a one-word rag (frm W5c); a no-op on one line. */
            text-wrap: balance;
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
            .hero-composition--marquee-name .hero-composition__objects > figure {
                width: clamp(3rem, 16vw, 4.5rem);
            }
            .hero-composition--marquee-name .hero-composition__objects > figure:nth-child(n + 3) {
                display: none;
            }
            .hero-composition--metadata-corners > .wp-block-cover {
                min-height: 88svh;
            }
            .hero-composition--metadata-corners > .wp-block-cover > .wp-block-cover__inner-container {
                padding: var(--wp--preset--spacing--md, 1.5rem);
            }
            .hero-composition--metadata-corners .hero-composition__meta {
                flex-wrap: wrap;
                gap: var(--wp--preset--spacing--sm, 0.75rem) var(--wp--preset--spacing--md, 1.5rem);
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

        /* Native accordion rows (the faq-split archetype builds them from
           core/details). Hairline-separated questions with a chevron that
           turns when open; the answer keeps the theme's paragraph rhythm. */
        .wp-block-details {
            padding-block: 0.9rem;
            border-block-end: 1px solid color-mix(in srgb, currentColor 15%, transparent);
        }
        .faq-list > .wp-block-details:first-child {
            border-block-start: 1px solid color-mix(in srgb, currentColor 15%, transparent);
        }
        .wp-block-details > summary {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 1rem;
            cursor: pointer;
            list-style: none;
            font-weight: 600;
        }
        .wp-block-details > summary::-webkit-details-marker {
            display: none;
        }
        .wp-block-details > summary::after {
            content: "+";
            flex: none;
            font-weight: 400;
            font-size: 1.25em;
            line-height: 1;
            transition: transform 200ms ease;
        }
        .wp-block-details[open] > summary::after {
            transform: rotate(45deg);
        }
        .wp-block-details > :not(summary) {
            margin-block-start: 0.75rem;
        }
        @media (prefers-reduced-motion: reduce) {
            .wp-block-details > summary::after {
                transition: none;
            }
        }

        /* Pricing tiers (the pricing-tiers archetype, frm W3c): the price
           figure is set in the heading face at section-title scale, and the
           recommended tier rises above its siblings on desktop. */
        .section-composition--pricing-tiers .price-figure {
            font-family: var(--wp--preset--font-family--heading, inherit);
            font-size: var(--wp--preset--font-size--section-title);
            font-weight: 700;
            line-height: 1.05;
            letter-spacing: -0.02em;
        }
        @media (min-width: 782px) {
            .section-composition--pricing-tiers .equal-cards > .wp-block-column > .card-highlight {
                transform: translateY(-0.75rem);
            }
        }

        /* Stat ledger (the stat-ledger archetype, frm W3e): figure headings
           at display scale in the heading face over caption labels, with
           hairlines between the columns; on phones the row stacks and the
           hairline turns horizontal. Authored separators are not needed. */
        .section-composition--stat-ledger .wp-block-column > .wp-block-heading:first-child {
            font-family: var(--wp--preset--font-family--heading, inherit);
            /* Capped by the viewport so a four-column figure never runs out
               of its column (spector-like6: "4 YEARS" clipped at 1366). */
            font-size: min(var(--wp--preset--font-size--display), 7vw);
            font-weight: 600;
            line-height: 1;
            letter-spacing: -0.03em;
            margin-block-end: 0.35em;
        }
        /* The hairline family (frm W3e): stat-ledger and feature-row-hairlines
           share the column rules. */
        .section-composition--stat-ledger .wp-block-columns > .wp-block-column + .wp-block-column,
        .section-composition--feature-row-hairlines .wp-block-columns > .wp-block-column + .wp-block-column {
            border-inline-start: 1px solid color-mix(in srgb, currentColor 14%, transparent);
            padding-inline-start: var(--wp--preset--spacing--md, 1.5rem);
        }
        .section-composition--feature-row-hairlines .wp-block-column > .wp-block-heading:first-child {
            margin-block-end: 0.5em;
        }
        @media (max-width: 781px) {
            .section-composition--stat-ledger .wp-block-columns > .wp-block-column + .wp-block-column,
            .section-composition--feature-row-hairlines .wp-block-columns > .wp-block-column + .wp-block-column {
                border-inline-start: 0;
                border-block-start: 1px solid color-mix(in srgb, currentColor 14%, transparent);
                padding-inline-start: 0;
                padding-block-start: var(--wp--preset--spacing--md, 1.5rem);
            }
        }

        /* Project grid (the project-grid-2x2 archetype, frm W3h): every tile
           is one cover at the same landscape proportion, rounded from the
           committed media radius, its name and meta line pinned to the
           bottom edge. Core's 430px cover floor is released so the ratio
           owns the height. The picture eases larger under a pointer; the
           reduced-motion query leaves it still. */
        .section-composition--project-grid-2x2 .wp-block-cover {
            aspect-ratio: 4 / 3;
            min-height: 0;
            border-radius: var(--shape-radius-media, 0);
            overflow: hidden;
            padding: var(--wp--preset--spacing--md, 1.5rem);
        }
        .section-composition--project-grid-2x2 .wp-block-cover .wp-block-heading {
            margin-block: 0 0.25em;
        }
        .section-composition--project-grid-2x2 .wp-block-cover .project-meta {
            margin: 0;
            font-size: var(--wp--preset--font-size--caption, 0.875rem);
            opacity: 0.85;
        }
        .section-composition--project-grid-2x2 .wp-block-cover__image-background {
            transition: scale 700ms ease;
        }
        @media (hover: hover) and (prefers-reduced-motion: no-preference) {
            .section-composition--project-grid-2x2 .wp-block-cover:hover .wp-block-cover__image-background {
                scale: 1.04;
            }
        }
        /* Zigzag steps (the zigzag-steps archetype, frm W3g): rows center
           vertically; an empty step plate takes the media's shape; on phones
           every row stacks copy-first whichever side the copy authored. */
        .section-composition--zigzag-steps .wp-block-columns {
            align-items: center;
        }
        .section-composition--zigzag-steps .wp-block-group.step-plate,
        /* A media column emptied by a dropped image (frm PR-3k) keeps the
           plate's shape, so the ladder keeps its rhythm without an asset. */
        .section-composition--zigzag-steps .wp-block-column:not(:has(*)) {
            min-block-size: 14rem;
            border-radius: var(--shape-radius-media, 0);
            background-color: color-mix(in srgb, currentColor 6%, transparent);
        }
        .section-composition--zigzag-steps .wp-block-column > figure.wp-block-image img {
            width: 100%;
            height: auto;
            border-radius: var(--shape-radius-media, 0);
        }
        @media (max-width: 781px) {
            .section-composition--zigzag-steps .wp-block-column:has(> .wp-block-heading) {
                order: -1;
            }
        }

        /* Statement lines (the statement-lines archetype, frm W3e): each line
           at section-title scale in the heading face, a hairline between
           lines, the last one closed by a hairline too. The band's rhythm
           comes from the padding, not from margins the model would author. */
        .section-composition--statement-lines .wp-block-group.statement-lines {
            gap: 0;
        }
        .section-composition--statement-lines .wp-block-group.statement-lines > .wp-block-heading {
            margin: 0;
            padding-block: var(--wp--preset--spacing--md, 1.5rem);
            border-block-start: 1px solid color-mix(in srgb, currentColor 14%, transparent);
            font-size: var(--wp--preset--font-size--section-title);
            line-height: 1.1;
            text-wrap: balance;
        }
        .section-composition--statement-lines .wp-block-group.statement-lines > .wp-block-heading:last-child {
            border-block-end: 1px solid color-mix(in srgb, currentColor 14%, transparent);
        }
        @media (max-width: 600px) {
            /* Four-line statements at section-title scale crowd a phone
               (spector-like8 at 390); the line scales with the viewport. */
            .section-composition--statement-lines .wp-block-group.statement-lines > .wp-block-heading {
                font-size: min(var(--wp--preset--font-size--section-title), 8vw);
            }
        }

        /* Closing invitation panel (the cta-panel archetype): a contained
           card on the page ground, rounded from the committed shape scale
           and clipped so a background image or gradient follows the corner. */
        .wp-block-group.cta-panel {
            border-radius: var(--shape-radius-panel, 0);
            overflow: hidden;
        }
        .wp-block-group.cta-panel > .wp-block-columns {
            align-items: center;
        }
        /* A clipped panel must never clip its own headline: a display-size
           word wider than the narrow panel breaks rather than vanishing, and
           below the hamburger breakpoint the authored side padding gives way
           to a phone-scale inset so the measure stays usable. */
        .wp-block-group.cta-panel :is(h1, h2, h3) {
            overflow-wrap: anywhere;
        }
        @media (max-width: 600px) {
            .wp-block-group.cta-panel {
                padding-inline: 1.25rem !important;
            }
            /* The preset size class carries !important, so the phone cap
               must too: 11vw keeps a nine-letter display word inside a
               390px panel without breaking it mid-word. */
            .wp-block-group.cta-panel :is(h1, h2, h3) {
                font-size: min(var(--wp--preset--font-size--section-title), 11vw) !important;
                overflow-wrap: normal;
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
