<?php
declare(strict_types=1);

/**
 * Read-only comparison: php eval/design-intent-report.php <slug> <slug> [...]
 * Reports evidence, not aesthetic grades. Review full-page desktop/mobile
 * screenshots separately for requested-style fidelity, hierarchy, overflow,
 * content completeness, and differences in composition rather than skin.
 * No model calls, image generation, site startup, or project mutation.
 */
if (count($argv) < 3) {
    fwrite(STDERR, "Usage: php eval/design-intent-report.php <slug> <slug> [...]\n");
    exit(1);
}
$reports = [];
foreach (array_slice($argv, 1) as $slug) {
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)) {
        throw new InvalidArgumentException('Invalid project slug');
    }
    $root = dirname(__DIR__) . '/projects/' . $slug;
    $read = static function (string $name, bool $optional = false) use ($root): array {
        if ($optional && !is_file($root . '/' . $name)) {
            return [];
        }
        $bytes = file_get_contents($root . '/' . $name);
        if ($bytes === false) {
            throw new RuntimeException('Cannot read ' . $name);
        }
        $value = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new RuntimeException('Expected object in ' . $name);
        }
        return $value;
    };
    $meta = $read('meta.json');
    $spec = $read('siteSpec.json');
    $direction = $read('designDirection.json');
    $pages = $read('pages.json');
    $stats = $read('build-stats.json', true);
    $seeds = $read('logs/design-direction-seeds.json', true);
    $selected = array_values(array_filter($seeds['candidates'] ?? [],
        static fn (array $seed): bool => ($seed['text'] ?? '') === ($direction['concept_seed'] ?? null)));
    $sections = [];
    foreach ($pages['pages'] ?? [] as $page) {
        if (empty($page['front'])) {
            continue;
        }
        foreach ($page['sections'] as $section) {
            $sections[] = array_intersect_key($section, array_flip([
                'slug', 'type', 'layout_archetype', 'background', 'vertical_density', 'item_pattern', 'text_placement',
            ]));
        }
    }
    $reports[] = [
        'slug' => $slug,
        'brief' => $meta['original_prompt'] ?? $meta['prompt'],
        'graph' => $meta['graph'] ?? null,
        'models' => $stats['step_models'] ?? [],
        'requested_style' => $spec['visual_vibe'] ?? '',
        'selected_seed' => $direction['concept_seed'] ?? null,
        'selected_register' => $selected[0]['register'] ?? null,
        'hero' => $direction['hero_blueprint'] ?? null,
        'fonts' => [$direction['type']['heading']['family'] ?? null, $direction['type']['body']['family'] ?? null],
        'axes' => array_intersect_key($direction, array_flip(['measure', 'density', 'rhythm', 'text_placement', 'card_style', 'item_pattern'])),
        'sections' => $sections,
        'warnings' => $read('warnings.json', true),
    ];
}
$pairs = [];
foreach ($reports as $i => $left) {
    foreach (array_slice($reports, $i + 1) as $right) {
        $geometry = static fn (array $report): array => [
            $report['hero'],
            array_map(static fn (array $section): array => array_diff_key($section, ['slug' => true, 'type' => true]), $report['sections']),
        ];
        $pairs[] = [
            'projects' => [$left['slug'], $right['slug']],
            'identical_planned_geometry' => $geometry($left) === $geometry($right),
            'visual_fidelity' => 'Requires rendered review; differing labels or geometry do not establish beauty or style fidelity.',
        ];
    }
}
echo json_encode(['builds' => $reports, 'comparisons' => $pairs], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
