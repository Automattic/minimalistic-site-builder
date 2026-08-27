<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\TreeGraph;

/**
 * Mechanical cross-checks on the brief beyond its JSON Schema, ported from
 * x-pipeline's pipeline/lib/brief-checks.mjs plus the brochure gate from
 * s1-brief.mjs. Violations ride the same validate() lane as the schema, so
 * they trigger the one metered schema-retry with the exact correction.
 */
final class BriefChecks
{
    /**
     * @param array<string,mixed> $brief
     * @return list<array{path:string,message:string}>
     */
    public static function crossChecks(array $brief): array
    {
        $issues = [];
        $blockSlugs = [];
        foreach ((array) ($brief['custom_blocks'] ?? []) as $block) {
            $blockSlugs[(string) ($block['slug'] ?? '')] = true;
        }
        $pageSlugs = [];
        foreach ((array) ($brief['pages'] ?? []) as $page) {
            $pageSlugs[(string) ($page['slug'] ?? '')] = true;
        }

        foreach ((array) ($brief['pages'] ?? []) as $pi => $page) {
            $seen = [];
            $accentBands = 0;
            $axisBreaks = 0;
            foreach ((array) ($page['sections'] ?? []) as $si => $section) {
                $design = is_array($section['design'] ?? null) ? $section['design'] : [];
                if (($design['axis_break'] ?? null) === true) {
                    $axisBreaks++;
                    if (empty($design['notes'])) {
                        $issues[] = [
                            'path'    => "/pages/{$pi}/sections/{$si}/design/notes",
                            'message' => 'section "' . ($section['id'] ?? '') . '" breaks the site axis without arguing it — an axis break carries its reason in design.notes or it is scattering, not design',
                        ];
                    }
                }
                if (!empty($section['uses_custom_block']) && !isset($blockSlugs[(string) $section['uses_custom_block']])) {
                    $issues[] = [
                        'path'    => "/pages/{$pi}/sections/{$si}/uses_custom_block",
                        'message' => 'no custom_blocks entry "' . $section['uses_custom_block'] . '"',
                    ];
                }
                $id = (string) ($section['id'] ?? '');
                if (isset($seen[$id])) {
                    $issues[] = [
                        'path'    => "/pages/{$pi}/sections/{$si}/id",
                        'message' => "duplicate section id \"{$id}\"",
                    ];
                }
                $seen[$id] = true;
                if (($design['band'] ?? null) === 'accent') {
                    $accentBands++;
                }
                if (($section['role'] ?? null) === 'gallery'
                    && !(is_array($section['image_intent'] ?? null) && array_is_list($section['image_intent']))
                ) {
                    $issues[] = [
                        'path'    => "/pages/{$pi}/sections/{$si}/image_intent",
                        'message' => 'a gallery section must carry an ARRAY of image_intent entries (3-6) — a gallery without images is an empty frame',
                    ];
                }
            }
            if ($accentBands > 1) {
                $issues[] = [
                    'path'    => "/pages/{$pi}/sections",
                    'message' => "{$accentBands} accent bands on one page — exactly one bright moment (§2): at most one section may sit on the accent band",
                ];
            }
            if ($axisBreaks > 1) {
                $issues[] = [
                    'path'    => "/pages/{$pi}/sections",
                    'message' => "{$axisBreaks} axis breaks on one page — the axis is one site-wide decision (§2, One axis): at most one section per page may break it, the way at most one band is accent",
                ];
            }
        }

        foreach (['navigation' => $brief['navigation']['items'] ?? null, 'footer' => $brief['footer']['items'] ?? null] as $field => $items) {
            foreach ((array) ($items ?? []) as $i => $item) {
                if (!isset($pageSlugs[(string) ($item['page_slug'] ?? '')])) {
                    $issues[] = [
                        'path'    => "/{$field}/items/{$i}/page_slug",
                        'message' => 'no page "' . ($item['page_slug'] ?? '') . '"',
                    ];
                }
            }
        }

        // Planned bands must be expressible in the palette, or alternation
        // silently collapses (a surface band falling back to base merges
        // adjacent sections).
        $roles = [];
        foreach ((array) ($brief['palette'] ?? []) as $entry) {
            $roles[(string) ($entry['role'] ?? '')] = true;
        }
        $planned = [];
        foreach ((array) ($brief['pages'] ?? []) as $page) {
            foreach ((array) ($page['sections'] ?? []) as $section) {
                $planned[(string) ($section['design']['band'] ?? '')] = true;
            }
        }
        if (isset($planned['surface']) && !isset($roles['surface']) && !isset($roles['background'])) {
            $issues[] = [
                'path'    => '/palette',
                'message' => 'a section plans a "surface" band but the palette has no role "surface" (or "background") entry — add a tint one step off the background, or plan "base"',
            ];
        }
        if (isset($planned['accent']) && !isset($roles['accent']) && !isset($roles['primary'])) {
            $issues[] = [
                'path'    => '/palette',
                'message' => 'a section plans an "accent" band but the palette has no role "accent" (or "primary") entry',
            ];
        }

        $fronts = 0;
        foreach ((array) ($brief['pages'] ?? []) as $page) {
            if (!empty($page['front_page'])) {
                $fronts++;
            }
        }
        if ($fronts !== 1) {
            $issues[] = ['path' => '/pages', 'message' => "exactly one page must set front_page:true (got {$fronts})"];
        }

        foreach (['custom_blocks', 'schema_packages'] as $where) {
            $seen = [];
            foreach ((array) ($brief[$where] ?? []) as $i => $entry) {
                $slug = (string) ($entry['slug'] ?? '');
                if (isset($seen[$slug])) {
                    $issues[] = ['path' => "/{$where}/{$i}/slug", 'message' => "duplicate slug \"{$slug}\""];
                }
                $seen[$slug] = true;
            }
        }

        return $issues;
    }

    /**
     * Brochure mode: composition only. The R7 ladder stops at rung 2 — a
     * custom_blocks[] or schema_packages[] entry is ruled out of scope before
     * the model gets to argue for one.
     *
     * @param array<string,mixed> $brief
     * @return list<array{path:string,message:string}>
     */
    public static function brochureChecks(array $brief): array
    {
        $issues = [];
        $blocks = count((array) ($brief['custom_blocks'] ?? []));
        if ($blocks > 0) {
            $issues[] = [
                'path'    => '/custom_blocks',
                'message' => "brochure mode: must be an empty array (declared {$blocks}) — compose with existing blocks instead",
            ];
        }
        $packages = count((array) ($brief['schema_packages'] ?? []));
        if ($packages > 0) {
            $issues[] = [
                'path'    => '/schema_packages',
                'message' => "brochure mode: must be an empty array (declared {$packages}) — data-backed features are out of scope",
            ];
        }
        return $issues;
    }
}
