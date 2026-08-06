<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\Units\GeneratedMarkup;

/** Pure markup inspection used by both above-fold contract finalizers. */
final class AboveFoldPartFacts
{
    /**
     * @param array<int,array<string,mixed>> $pages
     * @param array<string,string> $parts part key (`header` or page-*), without extension
     * @param array<string,mixed> $contract
     * @return array<string,mixed>
     */
    public static function inspect(array $pages, array $parts, array $contract): array
    {
        $partKeys = array_keys(array_filter($parts, 'is_string'));
        sort($partKeys, SORT_STRING);
        $support = [];
        $surfaces = [];
        foreach ((array) ($contract['openings'] ?? []) as $opening) {
            if (!is_array($opening)) {
                continue;
            }
            $part = (string) ($opening['part'] ?? '');
            $markup = $parts[$part] ?? null;
            $plannedSurface = (string) ($opening['surface'] ?? '');
            $actualSurface = is_string($markup)
                ? self::openingSurface($markup, $plannedSurface)
                : $plannedSurface;
            $surfaces[$part] = $actualSurface;
            $support[$part] = is_string($markup) && self::supportsOverlay(
                $markup,
                $actualSurface,
                (string) ($contract['header']['protection_token'] ?? 'contrast'),
            );
        }

        $action = $contract['primary_action'] ?? null;
        $heroPart = (string) ($contract['hero_part'] ?? '');
        $heroMarkup = $parts[$heroPart] ?? '';
        $actionControlDelivered = !is_array($action)
            || (is_string($heroMarkup) && self::containsAction($heroMarkup, $action));
        $actionTargetDelivered = !is_array($action)
            || self::destinationDelivered((string) ($action['destination'] ?? ''), $pages, $parts);

        return [
            'part_keys' => $partKeys,
            'opening_overlay_support' => $support,
            'opening_surfaces' => $surfaces,
            'primary_action_control_delivered' => $actionControlDelivered,
            'primary_action_target_delivered' => $actionTargetDelivered,
            'primary_action_delivered' => $actionControlDelivered && $actionTargetDelivered,
            'header' => self::headerFacts((string) ($parts['header'] ?? '')),
            'hero' => self::heroFacts(is_string($heroMarkup) ? $heroMarkup : '', (string) ($contract['recipe'] ?? '')),
        ];
    }

    public static function supportsOverlay(string $markup, string $surface, string $protectionToken): bool
    {
        if (str_contains($markup, 'site-build-section-rhythm-degraded-image')) {
            return false;
        }
        $document = BlockMarkup::parse($markup);
        $root = $document->topLevel();
        if ($root === null || $document->name($root) !== 'group' || $document->endOffset($root) === null) {
            return false;
        }
        $rootAttrs = $document->attrs($root) ?? [];
        $directCovers = [];
        foreach ($document->children($root) as $child) {
            if ($document->name($child) === 'cover' && $document->endOffset($child) !== null) {
                $directCovers[] = $child;
            }
        }

        // Judge the delivered top surface even when a caller still carries the
        // planned surface. A newly delivered direct cover sits above any root
        // color; conversely, a reviewed fallback can replace a planned image
        // with a real solid protection-token root.
        $surface = self::openingSurfaceFromDocument($document, $root, $surface);
        if ($surface === 'image') {
            if (count($directCovers) !== 1) {
                return false;
            }
            $attrs = $document->attrs($directCovers[0]) ?? [];
            $dim = $attrs['dimRatio'] ?? 50;
            // WordPress's implicit dim color is black, which cannot be
            // equated with a semantic "contrast" token (the palette may be
            // inverted). Require both the exact assigned token and enough
            // opacity to protect the persisted foreground pair.
            return is_numeric($dim)
                && (float) $dim >= 40
                && trim((string) ($attrs['overlayColor'] ?? '')) === $protectionToken;
        }
        if ($surface !== $protectionToken) {
            return false;
        }
        return self::hasProtectionSurface($rootAttrs, $protectionToken);
    }

    /** @param array<string,mixed> $action */
    public static function containsAction(string $markup, array $action): bool
    {
        return GeneratedMarkup::containsPrimaryAction($markup, $action);
    }

    /**
     * Resolve a planned action against what actually survived: page-only
     * routes need a delivered page, and fragments additionally need an anchor
     * in one of that page's delivered section parts. External/contact targets
     * cannot be probed here, so retain the syntactically valid forms already
     * admitted by PagePlanStep.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param array<string,string> $parts
     */
    private static function destinationDelivered(string $destination, array $pages, array $parts): bool
    {
        $destination = trim($destination);
        if ($destination === '') {
            return false;
        }

        $lower = strtolower($destination);
        if (str_starts_with($lower, 'http://') || str_starts_with($lower, 'https://')) {
            return filter_var($destination, FILTER_VALIDATE_URL) !== false;
        }
        if (str_starts_with($lower, 'mailto:')) {
            return filter_var(substr($destination, 7), FILTER_VALIDATE_EMAIL) !== false;
        }
        if (str_starts_with($lower, 'tel:')) {
            return preg_match('/^tel:\+?[0-9][0-9(). -]*$/i', $destination) === 1;
        }

        $routes = [];
        $frontPath = null;
        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }
            $path = self::normalizePath((string) ($page['path'] ?? '/'));
            $anchors = [];
            foreach ((array) ($page['sections'] ?? []) as $section) {
                if (!is_array($section)) {
                    continue;
                }
                $part = 'page-' . trim((string) ($page['slug'] ?? ''))
                    . '--' . trim((string) ($section['slug'] ?? ''));
                if (is_string($parts[$part] ?? null)) {
                    $anchors += self::anchorsIn($parts[$part]);
                }
            }
            $routes[$path] = $anchors;
            if ($frontPath === null || ($page['front'] ?? false) === true) {
                $frontPath = $path;
            }
        }

        if (str_starts_with($destination, '#')) {
            $fragment = substr($destination, 1);
            return $fragment !== ''
                && $frontPath !== null
                && isset($routes[$frontPath][$fragment]);
        }
        if (!str_starts_with($destination, '/')) {
            return false;
        }

        $url = parse_url($destination);
        if ($url === false
            || isset($url['scheme'])
            || isset($url['host'])
            || isset($url['user'])
            || isset($url['pass'])
            || isset($url['query'])
        ) {
            return false;
        }
        $path = self::normalizePath((string) ($url['path'] ?? '/'));
        if (!array_key_exists($path, $routes)) {
            return false;
        }
        $fragment = (string) ($url['fragment'] ?? '');
        if (str_contains($destination, '#') && $fragment === '') {
            return false;
        }
        return $fragment === '' || isset($routes[$path][$fragment]);
    }

    private static function openingSurface(string $markup, string $plannedSurface): string
    {
        $document = BlockMarkup::parse($markup);
        $root = $document->topLevel();
        if ($root === null || $document->name($root) !== 'group' || $document->endOffset($root) === null) {
            return $plannedSurface;
        }
        return self::openingSurfaceFromDocument($document, $root, $plannedSurface);
    }

    private static function openingSurfaceFromDocument(
        BlockMarkup $document,
        int $root,
        string $plannedSurface,
    ): string {
        foreach ($document->children($root) as $child) {
            if ($document->name($child) === 'cover' && $document->endOffset($child) !== null) {
                return 'image';
            }
        }
        $attrs = $document->attrs($root) ?? [];
        $background = trim((string) ($attrs['backgroundColor'] ?? ''));
        if ($background !== '') {
            return $background;
        }
        foreach (self::classes($attrs) as $class) {
            if (preg_match('/^has-(.+)-background-color$/', $class, $match) === 1) {
                return (string) $match[1];
            }
        }
        return $plannedSurface;
    }

    /** @return array<string,true> */
    private static function anchorsIn(string $markup): array
    {
        $anchors = [];
        $document = BlockMarkup::parse($markup);
        foreach ($document->indices() as $index) {
            if ($document->endOffset($index) === null) {
                continue;
            }
            $anchor = $document->attrs($index)['anchor'] ?? null;
            if (is_string($anchor) && trim($anchor) !== '') {
                $anchors[trim($anchor)] = true;
            }
        }
        if (preg_match_all(
            '~\bid\s*=\s*(?:"([^"]*)"|\'([^\']*)\')~i',
            $markup,
            $matches,
            PREG_SET_ORDER,
        ) !== false) {
            foreach ($matches as $match) {
                $id = html_entity_decode(
                    (string) (($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '')),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8',
                );
                if (trim($id) !== '') {
                    $anchors[trim($id)] = true;
                }
            }
        }
        return $anchors;
    }

    private static function normalizePath(string $path): string
    {
        $path = trim($path, '/');
        return $path === '' ? '/' : "/{$path}/";
    }

    /** @return array<string,mixed> */
    public static function headerFacts(string $markup): array
    {
        if ($markup === '') {
            return [
                'present' => false, 'mode' => null, 'archetype' => null,
                'background' => null, 'gradient' => null, 'custom_background' => false,
                'site_tagline_blocks' => 0, 'malformed_site_tagline_blocks' => 0,
                'invalid_site_tagline_topology' => 0,
            ];
        }
        $document = BlockMarkup::parse($markup);
        $siteTaglineBlocks = 0;
        $malformedSiteTaglineBlocks = 0;
        $invalidSiteTaglineTopology = 0;
        foreach ($document->indices() as $index) {
            if ($document->name($index) !== 'site-tagline') {
                continue;
            }
            if ($document->endOffset($index) === null || self::insideDynamicIdentityBlock($document, $index)) {
                $malformedSiteTaglineBlocks++;
                continue;
            }
            $siteTaglineBlocks++;
            if (!self::isStackedTagline($document, $index)) {
                $invalidSiteTaglineTopology++;
            }
        }
        $root = $document->topLevel();
        if ($root === null || $document->name($root) !== 'group') {
            return [
                'present' => true, 'mode' => null, 'archetype' => null,
                'background' => null, 'gradient' => null, 'custom_background' => false,
                'site_tagline_blocks' => $siteTaglineBlocks,
                'malformed_site_tagline_blocks' => $malformedSiteTaglineBlocks,
                'invalid_site_tagline_topology' => $invalidSiteTaglineTopology,
            ];
        }
        $attrs = $document->attrs($root) ?? [];
        $classes = self::classes($attrs);
        $archetype = null;
        foreach ($classes as $class) {
            if (str_starts_with($class, 'header-archetype--')) {
                $archetype = substr($class, strlen('header-archetype--'));
                break;
            }
        }
        return [
            'present' => true,
            'mode' => in_array('header-overlay', $classes, true) ? 'overlay' : 'stacked',
            'archetype' => $archetype,
            'background' => $attrs['backgroundColor'] ?? null,
            'gradient' => $attrs['gradient'] ?? null,
            'custom_background' => isset($attrs['style']['color']['background'])
                || isset($attrs['style']['color']['gradient']),
            'foreground' => $attrs['textColor'] ?? null,
            'site_tagline_blocks' => $siteTaglineBlocks,
            'malformed_site_tagline_blocks' => $malformedSiteTaglineBlocks,
            'invalid_site_tagline_topology' => $invalidSiteTaglineTopology,
        ];
    }

    private static function insideDynamicIdentityBlock(BlockMarkup $document, int $index): bool
    {
        for ($parent = $document->parent($index); $parent !== null; $parent = $document->parent($parent)) {
            if (in_array($document->name($parent), ['site-title', 'site-tagline'], true)) {
                return true;
            }
        }
        return false;
    }

    private static function isStackedTagline(BlockMarkup $document, int $tagline): bool
    {
        $parent = $document->parent($tagline);
        if ($parent === null || $document->name($parent) !== 'group') {
            return false;
        }
        $children = $document->children($parent);
        if (count($children) !== 2
            || $document->name($children[0]) !== 'site-title'
            || $children[1] !== $tagline
            || self::identityGroupHasRawPayload($document, $parent)
        ) {
            return false;
        }
        $attrs = $document->attrs($parent) ?? [];
        $style = $attrs['style'] ?? [];
        $spacing = is_array($style) ? ($style['spacing'] ?? []) : null;
        $layout = $attrs['layout'] ?? [];
        if (!is_array($style) || !is_array($spacing) || !is_array($layout)) {
            return false;
        }
        $gap = $spacing['blockGap'] ?? null;
        $zeroGap = $gap === 0
            || (is_string($gap) && preg_match('/^0(?:[a-z%]+)?$/i', trim($gap)) === 1);
        $type = $layout['type'] ?? null;
        $vertical = $type === null
            || $type === 'constrained'
            || ($type === 'flex' && ($layout['orientation'] ?? null) === 'vertical');
        return $zeroGap && $vertical;
    }

    /** Whether a title/tagline group's saved-HTML shell owns extra content. */
    private static function identityGroupHasRawPayload(BlockMarkup $document, int $group): bool
    {
        $innerStart = $document->openingOffset($group) + $document->openingLength($group);
        $shell = $document->innerHtml($group);
        $children = $document->children($group);
        for ($position = count($children) - 1; $position >= 0; $position--) {
            $child = $children[$position];
            $end = $document->endOffset($child);
            if ($end === null) {
                return true;
            }
            $start = $document->openingOffset($child);
            $shell = substr_replace($shell, '', $start - $innerStart, $end - $start);
        }
        $shell = preg_replace('/<!--(?!\s*\/?wp:).*?-->/s', '', $shell) ?? $shell;
        if (trim($shell) === '') {
            return false;
        }
        return preg_match(
            '~\A\s*<(?<tag>div|section|article|main|aside|header|footer|nav)\b[^>]*>\s*</\k<tag>>\s*\z~is',
            $shell,
        ) !== 1;
    }

    /** @return list<float> */
    public static function coverViewportHeights(string $markup): array
    {
        $document = BlockMarkup::parse($markup);
        $heights = [];
        foreach ($document->indices() as $index) {
            if ($document->name($index) !== 'cover') {
                continue;
            }
            $attrs = $document->attrs($index) ?? [];
            if ((string) ($attrs['minHeightUnit'] ?? '') === 'vh'
                && is_numeric($attrs['minHeight'] ?? null)
            ) {
                $heights[] = (float) $attrs['minHeight'];
            }
        }
        return $heights;
    }

    /** @return array<string,mixed> */
    public static function heroFacts(string $markup, string $recipe): array
    {
        if ($markup === '') {
            return ['present' => false, 'root_group' => false, 'recipe_marker' => false];
        }
        $document = BlockMarkup::parse($markup);
        $root = $document->topLevel();
        $rootGroup = $root !== null && $document->name($root) === 'group';
        $attrs = $rootGroup ? ($document->attrs($root) ?? []) : [];
        return [
            'present' => true,
            'root_group' => $rootGroup,
            'recipe_marker' => self::hasClass($attrs, 'hero-composition--' . $recipe),
            'rhythm_degraded_image' => str_contains($markup, 'site-build-section-rhythm-degraded-image'),
        ];
    }

    /** @param array<string,mixed> $attrs */
    private static function hasProtectionSurface(array $attrs, string $protectionToken): bool
    {
        return (string) ($attrs['backgroundColor'] ?? '') === $protectionToken
            || self::hasClass($attrs, "has-{$protectionToken}-background-color");
    }

    /** @param array<string,mixed> $attrs */
    private static function hasClass(array $attrs, string $class): bool
    {
        return in_array($class, self::classes($attrs), true);
    }

    /** @param array<string,mixed> $attrs @return list<string> */
    private static function classes(array $attrs): array
    {
        return preg_split('/\s+/', trim((string) ($attrs['className'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
