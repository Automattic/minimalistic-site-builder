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
    ];

    /** @var array<string,string> */
    private const RECIPE_TEMPLATES = [
        'typographic-billboard' => 'footer-compositions/typographic-billboard.md',
        'photographic-split' => 'footer-compositions/photographic-split.md',
        'image-plinth' => 'footer-compositions/image-plinth.md',
        'conversion-panel' => 'footer-compositions/conversion-panel.md',
        'editorial-colophon' => 'footer-compositions/editorial-colophon.md',
        'split-ledger' => 'footer-compositions/split-ledger.md',
    ];

    /** @var array<string,string> */
    private const SURFACES = [
        'typographic-billboard' => 'base',
        'photographic-split' => 'contrast',
        'image-plinth' => 'base',
        'conversion-panel' => 'contrast',
        'editorial-colophon' => 'base',
        'split-ledger' => 'contrast',
    ];

    /** @var list<string> */
    private const IMAGE_ARCHETYPES = ['photographic-split', 'image-plinth'];

    /** Footer utility/action behavior when sibling pages offer useful destinations. */
    private const NAV_RULE_MULTI = '- This site has multiple pages. A compact `wp:page-list` is permitted for '
        . 'site-wide utility navigation. A footer button may use one purposeful canonical SITE PAGES destination '
        . '(for example booking, contact, or work), but never a generic Home/back action merely to fill the design; '
        . 'a spec-backed mailto: action is also valid.';

    /** Footer utility/action behavior for a one-page site. */
    private const NAV_RULE_SINGLE = '- This site is ONE page: NEVER use `wp:page-list`, `href="/"`, or '
        . '`"url":"/"` in the footer because each is a self-link. Utility navigation may use only exact '
        . 'root-relative `/#anchor` destinations from the HOMEPAGE OUTLINE. A footer action may use one such real '
        . 'section destination or a spec-backed mailto:; omit navigation/action entirely when neither exists. '
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
