<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\TreeGraph;

/**
 * Deterministic instance-reading helpers for the tree graph, ported from the
 * x-pipeline's lib/instance.mjs and s2-read-instance.mjs: the role -> block
 * family map behind each section's manifest slice, the deterministic pattern
 * pick, and the parse-shape -> TreeIR conversion.
 */
final class Instance
{
    /**
     * Role -> block-family map: the manifest slice a section's tree call
     * receives contains ONLY these families.
     *
     * @var array<string,list<string>>
     */
    public const ROLE_FAMILIES = [
        'header'      => ['core/group', 'core/site-title', 'core/navigation', 'core/buttons', 'core/button'],
        'hero'        => ['core/cover', 'core/group', 'core/heading', 'core/paragraph', 'core/buttons', 'core/button', 'core/image', 'core/spacer'],
        'features'    => ['core/columns', 'core/column', 'core/group', 'core/heading', 'core/paragraph', 'core/image', 'core/list', 'core/list-item'],
        'gallery'     => ['core/gallery', 'core/image', 'core/group', 'core/heading'],
        'testimonial' => ['core/quote', 'core/group', 'core/columns', 'core/column', 'core/paragraph', 'core/image', 'core/heading'],
        'pricing'     => ['core/columns', 'core/column', 'core/group', 'core/heading', 'core/paragraph', 'core/list', 'core/list-item', 'core/buttons', 'core/button', 'core/separator'],
        'faq'         => ['core/details', 'core/group', 'core/heading', 'core/paragraph'],
        'cta'         => ['core/group', 'core/cover', 'core/heading', 'core/paragraph', 'core/buttons', 'core/button'],
        'contact'     => ['core/group', 'core/columns', 'core/column', 'core/heading', 'core/paragraph', 'core/social-links', 'core/social-link'],
        'content'     => ['core/group', 'core/heading', 'core/paragraph', 'core/image', 'core/list', 'core/list-item', 'core/separator', 'core/quote'],
        'footer'      => ['core/group', 'core/columns', 'core/column', 'core/paragraph', 'core/site-title', 'core/social-links', 'core/social-link'],
        'section'     => ['core/group', 'core/columns', 'core/column', 'core/heading', 'core/paragraph', 'core/image', 'core/buttons', 'core/button'],
    ];

    /** @var array<string,list<string>> */
    public const ROLE_PATTERN_QUERIES = [
        'header'      => ['header'],
        'hero'        => ['hero', 'cover', 'banner'],
        'features'    => ['features', 'services', 'columns'],
        'gallery'     => ['gallery'],
        'testimonial' => ['testimonial', 'quote'],
        'pricing'     => ['pricing'],
        'faq'         => ['faq'],
        'cta'         => ['call to action', 'cta'],
        'contact'     => ['contact'],
        'content'     => ['text', 'about'],
        'footer'      => ['footer'],
        'section'     => ['text'],
    ];

    /**
     * The site furniture (header + footer template parts) slice: identity and
     * navigation blocks no section role carries.
     *
     * @var list<string>
     */
    public const FURNITURE_BLOCKS = [
        'core/group', 'core/columns', 'core/column', 'core/paragraph', 'core/heading',
        'core/site-title', 'core/site-tagline', 'core/navigation', 'core/navigation-link', 'core/buttons',
        'core/button', 'core/separator', 'core/social-links', 'core/social-link', 'core/spacer',
    ];

    /**
     * Content-context patterns (comments, query loops, post templates) are
     * never section idioms; this filter removes them mechanically.
     *
     * @var list<string>
     */
    public const CONTEXT_BLOCK_PREFIXES = ['core/comments', 'core/post-', 'core/query', 'core/loginout', 'core/term-'];

    /** Manifest block entry keys a slice keeps. */
    private const SLICE_KEYS = ['attributes', 'supports', 'parent', 'styles', 'variations'];

    /**
     * Deterministic pick: first query term with matches wins; among matches,
     * the alphabetically-first pattern name wins. Patterns carry their parsed
     * blocks under `parsed` (the companion route's field) or `parsed_tree`.
     *
     * @param array<int,array<string,mixed>> $patterns
     * @return array<string,mixed>|null
     */
    public static function pickPattern(array $patterns, string $role): ?array
    {
        $eligible = array_values(array_filter(
            $patterns,
            static fn (array $p): bool => !self::usesContextBlocks($p['parsed'] ?? $p['parsed_tree'] ?? []),
        ));
        foreach (self::ROLE_PATTERN_QUERIES[$role] ?? [] as $term) {
            $matches = array_values(array_filter($eligible, static function (array $p) use ($term): bool {
                $hay = strtolower(
                    (string) ($p['name'] ?? '') . ' ' . (string) ($p['title'] ?? '')
                    . ' ' . implode(' ', array_map('strval', (array) ($p['categories'] ?? []))),
                );
                return str_contains($hay, $term);
            }));
            if ($matches !== []) {
                usort($matches, static fn (array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
                return $matches[0];
            }
        }
        return null;
    }

    /**
     * The manifest slice a section's tree call receives: only the block
     * families its role needs, each entry trimmed to the five schema keys.
     *
     * @param array<string,array<string,mixed>> $blocks Manifest blocks map.
     * @param array<string,mixed> $section
     * @return array{blocks: array<string,array<string,mixed>>}
     */
    public static function sliceManifest(array $blocks, array $section): array
    {
        $families = self::ROLE_FAMILIES[(string) ($section['role'] ?? '')] ?? self::ROLE_FAMILIES['section'];
        return ['blocks' => self::sliceFamilies($blocks, $families)];
    }

    /**
     * The furniture slice for the header/footer template-part calls.
     *
     * @param array<string,array<string,mixed>> $blocks Manifest blocks map.
     * @return array<string,array<string,mixed>>
     */
    public static function furnitureSlice(array $blocks): array
    {
        return self::sliceFamilies($blocks, self::FURNITURE_BLOCKS);
    }

    /**
     * Convert Gutenberg PARSE shape (blockName/attrs, null nodes for
     * whitespace, innerHTML) to TreeIR node shape. The model copies whatever
     * idiom it is shown, and a baseline in parse shape would die at validate.
     * Empty attributes/innerBlocks are omitted so the JSON encoding of a node
     * never turns an empty object into an empty array.
     *
     * @return list<array<string,mixed>>
     */
    public static function toTreeIrBlocks(mixed $parsed): array
    {
        $nodes = [];
        foreach (is_array($parsed) ? $parsed : [] as $b) {
            if (!is_array($b) || (empty($b['blockName']) && empty($b['name']))) {
                continue; // whitespace nodes
            }
            $node = ['name' => (string) ($b['name'] ?? $b['blockName'])];
            $attributes = $b['attributes'] ?? $b['attrs'] ?? [];
            if (is_array($attributes) && $attributes !== []) {
                $node['attributes'] = $attributes;
            }
            $inner = self::toTreeIrBlocks($b['innerBlocks'] ?? []);
            if ($inner !== []) {
                $node['innerBlocks'] = $inner;
            }
            $nodes[] = $node;
        }
        return $nodes;
    }

    /**
     * @param array<string,array<string,mixed>> $blocks
     * @param list<string> $families
     * @return array<string,array<string,mixed>>
     */
    private static function sliceFamilies(array $blocks, array $families): array
    {
        $slice = [];
        foreach ($families as $name) {
            if (!isset($blocks[$name]) || !is_array($blocks[$name])) {
                continue;
            }
            $entry = [];
            foreach (self::SLICE_KEYS as $key) {
                if (array_key_exists($key, $blocks[$name])) {
                    $entry[$key] = $blocks[$name][$key];
                }
            }
            $slice[$name] = $entry;
        }
        return $slice;
    }

    private static function usesContextBlocks(mixed $tree): bool
    {
        foreach (is_array($tree) ? $tree : [] as $node) {
            if (!is_array($node)) {
                continue;
            }
            $name = (string) ($node['name'] ?? $node['blockName'] ?? '');
            foreach (self::CONTEXT_BLOCK_PREFIXES as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    return true;
                }
            }
            if (self::usesContextBlocks($node['innerBlocks'] ?? [])) {
                return true;
            }
        }
        return false;
    }
}
