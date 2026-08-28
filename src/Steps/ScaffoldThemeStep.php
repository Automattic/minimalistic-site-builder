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

           Two guards make the pin safe. `align-self` opts the column out of the
           row's default stretch, without which a full-height column can never
           stick. `max-block-size` with a scroll keeps a lead column that
           outgrew its copy budget reachable: a pinned column taller than the
           viewport otherwise holds its own bottom permanently off screen. The
           whole behavior is desktop-only, so the stacked state is an ordinary
           column in source order. */
        .section-composition--asymmetric-split .wp-block-column.sticky-side {
            align-self: flex-start;
        }
        @media (min-width: 782px) {
            .section-composition--asymmetric-split .wp-block-column.sticky-side {
                position: sticky;
                top: var(--wp--preset--spacing--lg, 3rem);
                max-block-size: calc(100vh - (var(--wp--preset--spacing--lg, 3rem) * 2));
                overflow-y: auto;
                overscroll-behavior: contain;
            }
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
        /* knockout-type (BIGR-935) cuts the headline out of a solid panel so
           the cover photograph shows only through the letterforms.

           The blend has to match the panel's luminance, and which palette slug
           is dark is a per-site fact, so the build stamps --ink or --paper from
           the delivered colour (GeneratedMarkup::knockoutBlend). On a dark
           panel, multiply resolves white text to the photograph beneath; on a
           light panel, screen resolves dark text the same way. The text colour
           is forced to pure white or pure black rather than a palette slug,
           because a tinted ink tints every letter — this is the one place a
           colour must be exact.

           An unstamped panel keeps its solid colour and its authored text
           colour: no knockout, but a legible hero. */
        /* !important, deliberately: the generator pads its cover through an
           inline style, which no class hook can outrank, and this recipe's
           panel must reach every edge of the band. */
        .hero-composition--knockout-type > .wp-block-cover {
            isolation: isolate;
            padding: 0 !important;
            display: grid !important;
            grid-template-rows: 1fr;
            grid-template-columns: 100%;
            align-content: stretch;
        }
        /* The panel has to reach every edge of the band. Core constrains the
           cover's inner container to contentSize and pads the cover, which
           leaves the photograph showing in a frame around the panel — and this
           composition promises the image appears in the letters and nowhere
           else. The first audited cohort shipped exactly that frame. */
        .hero-composition--knockout-type > .wp-block-cover > .wp-block-cover__inner-container {
            max-inline-size: none;
            inline-size: 100%;
            block-size: 100%;
            display: flex;
            flex-direction: column;
            gap: 0;
        }
        /* A constrained cover caps its inner children at contentSize, which
           puts the panel back in the middle of the band with raw image beside
           it. The panel and the copy strip own the full width; whatever they
           hold keeps the reading measure through their own layout. */
        .hero-composition--knockout-type > .wp-block-cover > .wp-block-cover__inner-container > * {
            max-inline-size: none;
            inline-size: 100%;
        }
        .hero-composition--knockout-type .hero-knockout {
            flex: 1 1 auto;
            display: flex;
            flex-direction: column;
            justify-content: center;
            inline-size: 100%;
        }
        .hero-composition--knockout-type .hero-composition__copy {
            inline-size: 100%;
        }
        .hero-composition--knockout-type .hero-knockout--ink {
            mix-blend-mode: multiply;
        }
        /* !important: core's preset rule (.has-base-color { color: ... !important })
           wins over any unflagged declaration, and the blend maths need the
           exact endpoints. */
        .hero-composition--knockout-type .hero-knockout--ink :is(h1, h2, p) {
            color: #fff !important;
        }
        .hero-composition--knockout-type .hero-knockout--paper {
            mix-blend-mode: screen;
        }
        .hero-composition--knockout-type .hero-knockout--paper :is(h1, h2, p) {
            color: #000 !important;
        }
        /* Forced-colours users get a solid, unblended headline: the knockout is
           decoration, and the words must survive without it. */
        @media (forced-colors: active) {
            .hero-composition--knockout-type :is(.hero-knockout--ink, .hero-knockout--paper) {
                mix-blend-mode: normal;
            }
        }
        .hero-composition--layered-poster {
            overflow: hidden;
        }
        /* type-manifesto carries no image (BIGR-885), so the offset between the
           wide headline and the narrower standfirst IS the composition. The
           constrained layout centers every child of the copy region, and that
           rule and this one have equal specificity, so the offset needs
           !important to survive it. Logical properties keep the step on the
           trailing side under both writing directions. */
        .hero-composition--type-manifesto .hero-composition__standfirst {
            max-width: 32rem !important;
            margin-inline-start: auto !important;
            margin-inline-end: 0 !important;
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
            /* One narrow screen cannot hold both a wide headline and an offset
               column, so the standfirst returns to the reading edge. */
            .hero-composition--type-manifesto .hero-composition__standfirst {
                max-width: none !important;
                margin-inline-start: 0 !important;
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
