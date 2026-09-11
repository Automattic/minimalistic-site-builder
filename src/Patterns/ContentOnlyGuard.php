<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Patterns;

/**
 * The checks a content-only bundle has to pass before a host will apply it.
 *
 * A host that cannot install code has a narrower idea of "valid output" than
 * the build does, and the difference is not visible by looking at the markup:
 * it renders fine beside the stylesheet that produced it and falls apart on
 * the theme it is destined for. So the rules are enforced here, on the
 * finished bundle, rather than trusted to the stages that built it.
 *
 * Measured on a real build (2026-09-11): content produced by authoring markup
 * freely came out 100% core blocks and still carried 228 references to 35
 * utility classes that only existed in the generated theme's stylesheet. Every
 * rule below exists because something was observed to go wrong, not because it
 * sounded prudent.
 */
final class ContentOnlyGuard
{
    /**
     * Class prefixes WordPress itself defines: core block styles, alignments,
     * and the preset-driven families a theme.json generates. Anything else has
     * to be defined by the destination theme, and on a fixed theme that means
     * it has to already exist there.
     */
    private const CORE_CLASS_PREFIXES = [
        'wp-block-',
        'wp-element',
        'wp-container-',
        'align',
        'has-',
        'is-',
        'size-',
        'screen-reader-',
    ];

    /**
     * Check a bundle and return every violation found, most structural first.
     *
     * Returns all of them rather than stopping at the first: a caller deciding
     * whether to apply wants the whole picture, and a caller measuring build
     * quality wants to count them.
     *
     * @param array<string, mixed> $bundle       Pages and shared parts, each with `content`.
     * @param list<string>         $inventoryIds Pattern ids the host approved.
     * @param list<string>         $themeClasses Classes the destination theme defines.
     * @return list<string> Human-readable violations; empty when the bundle may be applied.
     */
    public static function check(array $bundle, array $inventoryIds, array $themeClasses = []): array
    {
        $violations = [];

        foreach (self::nonCoreBlocks($bundle) as $block) {
            $violations[] = sprintf(
                'block "%s" is not a core block, so the destination cannot render it without installing code',
                $block,
            );
        }

        foreach (self::unapprovedSections($bundle, $inventoryIds) as $pattern) {
            $violations[] = sprintf(
                'section came from pattern "%s", which is not in the approved inventory',
                $pattern,
            );
        }

        $unbacked = self::unbackedClasses($bundle, $themeClasses);
        if ($unbacked !== []) {
            arsort($unbacked);
            $shown = array_slice(array_keys($unbacked), 0, 5);
            $violations[] = sprintf(
                '%d reference(s) to %d class(es) the destination theme does not define, so the layout they encode is lost: %s',
                array_sum($unbacked),
                count($unbacked),
                implode(', ', $shown),
            );
        }

        return $violations;
    }

    /**
     * Namespaced blocks outside core. A bare name in serialized markup is core
     * ("group" is core/group), so only an explicit foreign namespace counts.
     *
     * @param array<string, mixed> $bundle
     * @return list<string>
     */
    private static function nonCoreBlocks(array $bundle): array
    {
        $found = [];
        foreach (self::contents($bundle) as $html) {
            preg_match_all('/<!--\s+wp:([a-z0-9-]+\/[a-z0-9-]+)/', $html, $matches);
            foreach ($matches[1] as $block) {
                if (!str_starts_with($block, 'core/')) {
                    $found[$block] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * Sections whose recorded pattern is not in the approved inventory.
     *
     * Fail closed: a section with no recorded pattern counts as unapproved,
     * because "we did not write down where this came from" and "this came from
     * somewhere allowed" are not the same claim.
     *
     * @param array<string, mixed> $bundle
     * @param list<string>         $inventoryIds
     * @return list<string>
     */
    private static function unapprovedSections(array $bundle, array $inventoryIds): array
    {
        $approved = array_fill_keys($inventoryIds, true);
        $found = [];

        foreach (self::pages($bundle) as $page) {
            foreach ($page['sections'] ?? [] as $section) {
                $pattern = $section['pattern'] ?? null;
                if (!is_string($pattern) || $pattern === '') {
                    $found['(unrecorded)'] = true;
                    continue;
                }
                if (!isset($approved[$pattern])) {
                    $found[$pattern] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * Classes the markup leans on that neither WordPress nor the destination
     * theme defines, counted by how often they are referenced.
     *
     * @param array<string, mixed> $bundle
     * @param list<string>         $themeClasses
     * @return array<string, int>
     */
    public static function unbackedClasses(array $bundle, array $themeClasses): array
    {
        $known = array_fill_keys($themeClasses, true);
        $counts = [];

        foreach (self::contents($bundle) as $html) {
            preg_match_all('/class="([^"]+)"/', $html, $matches);
            foreach ($matches[1] as $attr) {
                foreach (preg_split('/\s+/', trim($attr)) ?: [] as $class) {
                    if ($class === '' || isset($known[$class]) || self::isCoreClass($class)) {
                        continue;
                    }
                    $counts[$class] = ($counts[$class] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }

    private static function isCoreClass(string $class): bool
    {
        foreach (self::CORE_CLASS_PREFIXES as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $bundle
     * @return list<array<string, mixed>>
     */
    private static function pages(array $bundle): array
    {
        $pages = $bundle['pages'] ?? [];

        return is_array($pages) ? array_values(array_filter($pages, 'is_array')) : [];
    }

    /**
     * Every piece of block markup in the bundle: page bodies and shared parts.
     *
     * @param array<string, mixed> $bundle
     * @return list<string>
     */
    private static function contents(array $bundle): array
    {
        $html = [];

        foreach (self::pages($bundle) as $page) {
            if (isset($page['content']) && is_string($page['content'])) {
                $html[] = $page['content'];
            }
        }

        foreach ($bundle['parts'] ?? [] as $part) {
            if (is_array($part) && isset($part['content']) && is_string($part['content'])) {
                $html[] = $part['content'];
            }
        }

        return $html;
    }
}
