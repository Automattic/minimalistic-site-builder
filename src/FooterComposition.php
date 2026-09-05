<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * The reviewed footer-composition catalog shared by the stateful build step
 * and stateless FooterUnit adapters.
 */
final class FooterComposition
{
    /** @var list<string> */
    public const ARCHETYPES = [
        'typographic-billboard',
        'photographic-split',
        'image-plinth',
        'conversion-panel',
        'editorial-colophon',
        'split-ledger',
        'cover-coda',
        'diptych',
        'sunken-wordmark',
        'status-readout',
        'contact-sheet',
        'mosaic-tiles',
        'color-field',
        'repeat-rail',
        'newsletter-columns',
    ];

    /** @var array<string,string> */
    private const RECIPE_TEMPLATES = [
        'typographic-billboard' => 'footer-compositions/typographic-billboard.md',
        'photographic-split' => 'footer-compositions/photographic-split.md',
        'image-plinth' => 'footer-compositions/image-plinth.md',
        'conversion-panel' => 'footer-compositions/conversion-panel.md',
        'editorial-colophon' => 'footer-compositions/editorial-colophon.md',
        'split-ledger' => 'footer-compositions/split-ledger.md',
        'cover-coda' => 'footer-compositions/cover-coda.md',
        'diptych' => 'footer-compositions/diptych.md',
        'sunken-wordmark' => 'footer-compositions/sunken-wordmark.md',
        'status-readout' => 'footer-compositions/status-readout.md',
        'contact-sheet' => 'footer-compositions/contact-sheet.md',
        'mosaic-tiles' => 'footer-compositions/mosaic-tiles.md',
        'color-field' => 'footer-compositions/color-field.md',
        'repeat-rail' => 'footer-compositions/repeat-rail.md',
        'newsletter-columns' => 'footer-compositions/newsletter-columns.md',
    ];

    /** @var array<string,string> */
    private const SURFACES = [
        'typographic-billboard' => 'base',
        'photographic-split' => 'contrast',
        'image-plinth' => 'base',
        'conversion-panel' => 'contrast',
        'editorial-colophon' => 'base',
        'split-ledger' => 'contrast',
        'cover-coda' => 'contrast',
        'diptych' => 'base',
        'sunken-wordmark' => 'contrast',
        'status-readout' => 'contrast',
        'contact-sheet' => 'contrast',
        'mosaic-tiles' => 'base',
        'color-field' => 'base',
        'repeat-rail' => 'base',
        // frm W3i: the product-site footer of the reference corpus (Zova,
        // Dreammotion): an invitation row over three link columns, dark.
        'newsletter-columns' => 'contrast',
    ];

    /** @var list<string> */
    private const IMAGE_ARCHETYPES = ['photographic-split', 'image-plinth', 'mosaic-tiles', 'contact-sheet', 'cover-coda'];

    /**
     * The surfaces a footer root can actually be painted. Only base and
     * contrast are exact solid slugs both sides of the seam can agree on —
     * SectionRhythm::COLLAPSIBLE_SURFACES draws the same line, because two
     * independently authored tints need not match.
     *
     * @var list<string>
     */
    private const SURFACE_CANDIDATES = ['base', 'contrast'];

    /** Footer utility/action behavior when sibling pages offer useful destinations. */
    private const NAV_RULE_MULTI = '- This site has multiple pages. Site-wide utility navigation is a '
        . '`wp:navigation` of hand-authored `wp:navigation-link` entries for SITE PAGES except the front page. '
        . 'NEVER include Home — `wp:site-title` already links home. Do NOT use `<!-- wp:page-list /-->` '
        . '(it lists every page, including Home), do NOT emit `wp:home-link`, and never a bare `wp:page-list`. '
        . 'A footer button may use one purposeful canonical SITE PAGES destination — '
        . 'the page holding what this site actually asks visitors to do next — '
        . 'but never a generic Home/back action merely to fill the design; '
        . 'a mailto: action is valid only for an exact email in SITE SPEC; omit it when the spec has none.';

    /** Footer utility/action behavior for a one-page site. */
    private const NAV_RULE_SINGLE = '- This site is ONE page: NEVER use `wp:page-list`, `href="/"`, or '
        . '`"url":"/"` in the footer because each is a self-link. Utility navigation may use only exact '
        . 'root-relative `/#anchor` destinations from the HOMEPAGE OUTLINE. A footer action may use one such real '
        . 'section destination or a mailto: for an exact email in SITE SPEC; omit the mailto when the spec has none; '
        . 'omit navigation/action entirely when neither a real destination nor a spec email exists. '
        . 'A `wp:site-title` block defaults to a homepage link, so every footer site-title MUST explicitly set '
        . '`"isLink":false`.';

    public static function assertKnown(string $archetype): void
    {
        if (!in_array($archetype, self::ARCHETYPES, true)) {
            throw new \InvalidArgumentException(
                "unknown footer archetype '{$archetype}' (use one of: "
                . implode(', ', self::ARCHETYPES) . ')'
            );
        }
    }

    public static function assignment(string $archetype): string
    {
        self::assertKnown($archetype);
        return "ASSIGNED FOOTER COMPOSITION for this build: **{$archetype}**. "
            . 'Build exactly this ONE composition; do not blend it with another footer pattern or fall back to '
            . 'a generic three- or four-column utility footer.';
    }

    public static function recipeTemplate(string $archetype): string
    {
        self::assertKnown($archetype);
        return self::RECIPE_TEMPLATES[$archetype];
    }

    public static function surface(string $archetype): string
    {
        self::assertKnown($archetype);
        return self::SURFACES[$archetype];
    }

    /**
     * This build's footer composition. Seeded on the site alone — never on a
     * planned page — so page-plan can be told the footer's surface before it
     * plans the sections that have to differ from it.
     */
    /**
     * The archetype for a project, reading the two files the seed is built from.
     *
     * Five call sites used to spell out this pair, two of them re-reading files
     * their own function had already loaded. The seed is the whole coupling
     * between page-plan and sections, so it gets one name.
     */
    public static function archetypeForProject(Project $project): string
    {
        if ($project->exists('pages.json')) {
            $persisted = $project->readJson('pages.json')['footer_archetype'] ?? null;
            if (is_string($persisted) && in_array($persisted, self::ARCHETYPES, true)) {
                return $persisted;
            }
        }
        return self::archetypeFor(
            $project->readText('siteSpec.json'),
            Steps\DesignDirectionStep::readFor($project),
        );
    }

    public static function archetypeFor(string $siteSpec, string $designDirection): string
    {
        $bucket = 0;
        $count = count(self::ARCHETYPES);
        foreach (str_split(hash('sha256', $siteSpec . "\n" . $designDirection, true)) as $byte) {
            $bucket = (($bucket * 256) + ord($byte)) % $count;
        }
        return self::ARCHETYPES[$bucket];
    }

    /**
     * The surface this build's footer is actually painted, given what every
     * page's last section closes on. One footer part renders below all of them,
     * so a surface that matches a page's closing band leaves that page with no
     * boundary at all — the archetype's preference loses to the surface that
     * merges the fewest seams, and ties keep the preference.
     *
     * This is the fallback for pages that reach the footer job without passing
     * the deterministic floor, not a general safety net. On the blocks path
     * withClosingBandOffFooterSurface() has already moved every closing band
     * off the preference before pages.json is written, so there is nothing left
     * here to resolve. And it minimises rather than clears: with two candidates
     * a site closing on both still leaves one seam merged, which is why
     * footerNeighborContract() brands that section for a continuous handoff
     * instead of a cut.
     *
     * @param list<string> $closingBackgrounds each page's last-section background
     */
    public static function resolveSurface(string $archetype, array $closingBackgrounds): string
    {
        $preferred = self::surface($archetype);
        $merged = static fn (string $surface): int => count(array_filter(
            $closingBackgrounds,
            static fn (mixed $background): bool => $background === $surface,
        ));

        $best = $preferred;
        $fewest = $merged($preferred);
        foreach (self::SURFACE_CANDIDATES as $candidate) {
            if ($merged($candidate) < $fewest) {
                $best = $candidate;
                $fewest = $merged($candidate);
            }
        }
        return $best;
    }

    /**
     * The planning constraint every page receives so its closing section does
     * not land on the footer's surface. Page plans are one concurrent request
     * per page, blind to each other, so this is the only place the MODEL can
     * be told about a single footer. withClosingBandOffFooterSurface() is the
     * deterministic counterpart, and it is the one that guarantees the result.
     */
    public static function closingSectionRule(string $surface): string
    {
        if (!in_array($surface, self::SURFACE_CANDIDATES, true)) {
            throw new \InvalidArgumentException(
                "invalid footer surface '{$surface}' (use one of: "
                . implode(', ', self::SURFACE_CANDIDATES) . ')'
            );
        }
        return "- The site footer renders directly below this page's LAST section, on every page of this site, "
            . "on the exact **{$surface}** background. Do NOT give the LAST section the \"{$surface}\" background: "
            . 'choose a different treatment so the footer reads as its own closing band instead of dissolving into '
            . 'the section above it.';
    }

    public static function usesGeneratedImage(string $archetype): bool
    {
        self::assertKnown($archetype);
        return in_array($archetype, self::IMAGE_ARCHETYPES, true);
    }

    public static function navigationRule(int $pageCount): string
    {
        if ($pageCount < 1) {
            throw new \InvalidArgumentException('footer page_count must be at least 1');
        }
        return $pageCount > 1 ? self::NAV_RULE_MULTI : self::NAV_RULE_SINGLE;
    }
}
