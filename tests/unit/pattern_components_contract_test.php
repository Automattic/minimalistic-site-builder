<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Steps\ExtractPatternsStep;

/** @return list<array<mixed>> */
function pattern_components_of_kind(Project $project, string $kind): array
{
    return array_values(array_filter(
        $project->readJson('patterns.json')['patterns'],
        static fn (array $pattern): bool => ($pattern['kind'] ?? null) === $kind,
    ));
}

/** @return array<mixed> */
function pattern_components_manifest_entry(Project $project, string $slug): array
{
    foreach ($project->readJson('patterns.json')['patterns'] as $pattern) {
        if (($pattern['slug'] ?? null) === $slug) {
            return $pattern;
        }
    }
    throw new RuntimeException("No manifest entry for '{$slug}'");
}

function pattern_components_file(Project $project, string $slug): string
{
    return $project->readText("theme/patterns/{$slug}.php");
}

function pattern_components_body(Project $project, string $slug): string
{
    $file = pattern_components_file($project, $slug);
    $offset = strpos($file, "?>\n");
    if ($offset === false) {
        throw new RuntimeException("Pattern '{$slug}' has no closed PHP header");
    }
    return substr($file, $offset + 3);
}

function pattern_components_paragraph(string $copy): string
{
    return '<!-- wp:paragraph --><p>' . $copy . '</p><!-- /wp:paragraph -->';
}

function pattern_components_card(string $copy): string
{
    return '<!-- wp:group {"className":"card-style--borderless"} -->'
        . '<div class="wp-block-group card-style--borderless">'
        . pattern_components_paragraph($copy)
        . '</div><!-- /wp:group -->';
}

/** @param list<list<string>> $columnChildren */
function pattern_components_columns(array $columnChildren, string $marker): string
{
    $columns = '';
    foreach ($columnChildren as $children) {
        $columns .= '<!-- wp:column --><div class="wp-block-column">'
            . implode('', $children)
            . '</div><!-- /wp:column -->';
    }
    return '<!-- wp:columns {"className":"' . $marker . '"} -->'
        . '<div class="wp-block-columns ' . $marker . '">'
        . $columns
        . '</div><!-- /wp:columns -->';
}

function pattern_components_grid(int $count): string
{
    $columns = [];
    for ($index = 0; $index < $count; $index++) {
        $columns[] = [pattern_components_paragraph('card')];
    }
    return extract_patterns_group(pattern_components_columns($columns, "grid-{$count}"));
}

/** @return array<string,string> */
function pattern_components_non_component_shapes(): array
{
    return [
        'split' => pattern_components_grid(2),
        'cover' => '<!-- wp:cover --><div class="wp-block-cover">'
            . '<!-- wp:heading --><h2>Hero</h2><!-- /wp:heading -->'
            . '</div><!-- /wp:cover -->',
        'media-stack' => '<!-- wp:group --><div class="wp-block-group">'
            . '<!-- wp:image --><figure><img src="photo.jpg"/></figure><!-- /wp:image -->'
            . '<!-- wp:heading --><h2>Story</h2><!-- /wp:heading -->'
            . '</div><!-- /wp:group -->',
        'stack' => '<!-- wp:group --><div class="wp-block-group">'
            . pattern_components_paragraph('Plain section.')
            . '</div><!-- /wp:group -->',
        'form' => '<!-- wp:group --><div class="wp-block-group">'
            . '<!-- wp:form --><form></form><!-- /wp:form -->'
            . '</div><!-- /wp:group -->',
        'gallery' => '<!-- wp:group --><div class="wp-block-group">'
            . '<!-- wp:gallery --><figure></figure><!-- /wp:gallery -->'
            . '</div><!-- /wp:group -->',
    ];
}

test('grid component uses widest earliest row and unwraps one-child first card', function (): void {
    with_project('builder_pattern_grid_contract_', function (Project $project): void {
        $three = pattern_components_columns([
            [pattern_components_card('THREE FIRST')],
            [pattern_components_card('THREE SECOND')],
            [pattern_components_card('THREE THIRD')],
        ], 'three-row');
        $fourFirst = pattern_components_columns([
            [pattern_components_card('WINNING CARD')],
            [pattern_components_card('WINNING TWO')],
            [pattern_components_card('WINNING THREE')],
            [pattern_components_card('WINNING FOUR')],
        ], 'winning-row');
        $fourTie = pattern_components_columns([
            [pattern_components_card('LATE CARD')],
            [pattern_components_card('LATE TWO')],
            [pattern_components_card('LATE THREE')],
            [pattern_components_card('LATE FOUR')],
        ], 'late-row');
        $section = extract_patterns_group($three . $fourFirst . $fourTie);
        extract_patterns_seed($project, [['slug' => 'testimonial', 'markup' => $section]]);

        quietly(fn () => (new ExtractPatternsStep())->run($project));

        $row = pattern_components_body($project, 'testimonial-row');
        $card = pattern_components_body($project, 'testimonial-card');
        assert_contains('winning-row', $row);
        assert_contains('WINNING FOUR', $row);
        assert_true(!str_contains($row, 'three-row'));
        assert_true(!str_contains($row, 'late-row'));
        assert_contains('card-style--borderless', $card);
        assert_contains('WINNING CARD', $card);
        assert_true(!str_contains($card, 'wp:column'));
        assert_true(!str_contains($card, 'WINNING TWO'));
        assert_contains('Title: Testimonial row', pattern_components_file($project, 'testimonial-row'));
        assert_contains('Title: Testimonial card', pattern_components_file($project, 'testimonial-card'));
    });
});

test('grid multi-child first card keeps its core column wrapper', function (): void {
    with_project('builder_pattern_grid_multi_contract_', function (Project $project): void {
        $row = pattern_components_columns([
            [pattern_components_paragraph('FIRST A'), pattern_components_paragraph('FIRST B')],
            [pattern_components_card('SECOND')],
            [pattern_components_card('THIRD')],
        ], 'multi-child-row');
        extract_patterns_seed($project, [[
            'slug' => 'service',
            'markup' => extract_patterns_group($row),
        ]]);

        quietly(fn () => (new ExtractPatternsStep())->run($project));

        $card = pattern_components_body($project, 'service-card');
        assert_contains('wp:column', $card);
        assert_contains('FIRST A', $card);
        assert_contains('FIRST B', $card);
        assert_true(!str_contains($card, 'SECOND'));
        assert_contains('Title: Service card', pattern_components_file($project, 'service-card'));
    });
});

test('quotes component emits containing block and first quote', function (): void {
    with_project('builder_pattern_quotes_contract_', function (Project $project): void {
        $quote = '<!-- wp:quote --><blockquote>FIRST QUOTE</blockquote><!-- /wp:quote -->';
        $pullquote = '<!-- wp:pullquote --><figure>LATE QUOTE</figure><!-- /wp:pullquote -->';
        $section = extract_patterns_group($quote . $pullquote);
        extract_patterns_seed($project, [['slug' => 'testimonial', 'markup' => $section]]);

        quietly(fn () => (new ExtractPatternsStep())->run($project));

        $row = pattern_components_body($project, 'testimonial-row');
        $card = pattern_components_body($project, 'testimonial-card');
        assert_contains('wp:group', $row);
        assert_contains('FIRST QUOTE', $row);
        assert_contains('LATE QUOTE', $row);
        assert_contains('wp:quote', $card);
        assert_contains('FIRST QUOTE', $card);
        assert_true(!str_contains($card, 'LATE QUOTE'));
    });
});

foreach (pattern_components_non_component_shapes() as $shape => $markup) {
    test("{$shape} section emits no component patterns", function () use ($shape, $markup): void {
        with_project("builder_pattern_{$shape}_contract_", function (Project $project) use ($shape, $markup): void {
            extract_patterns_seed($project, [['slug' => 'service', 'markup' => $markup]]);

            quietly(fn () => (new ExtractPatternsStep())->run($project));

            assert_eq($shape, pattern_components_manifest_entry($project, "service-{$shape}")['shape']);
            assert_eq([], pattern_components_of_kind($project, 'component'));
        });
    });
}

test('component CTA remains because band stripping applies only to sections', function (): void {
    with_project('builder_pattern_component_cta_contract_', function (Project $project): void {
        $row = pattern_components_columns([
            [pattern_components_card('CARD') . extract_patterns_buttons('CARD ACTION')],
            [pattern_components_card('SECOND')],
            [pattern_components_card('THIRD')],
        ], 'cta-row');
        extract_patterns_seed($project, [[
            'slug' => 'service',
            'markup' => extract_patterns_group($row . extract_patterns_buttons('BAND ACTION')),
        ]]);

        quietly(fn () => (new ExtractPatternsStep())->run($project));

        assert_contains('CARD ACTION', pattern_components_body($project, 'service-row'));
        assert_contains('CARD ACTION', pattern_components_body($project, 'service-card'));
        assert_true(!str_contains(pattern_components_body($project, 'service-grid'), 'BAND ACTION'));
    });
});

test('manifest v2 freezes section component drop and starter contracts', function (): void {
    with_project('builder_pattern_manifest_contract_', function (Project $project): void {
        extract_patterns_seed($project, [
            ['slug' => 'hero', 'markup' => pattern_components_grid(1)],
            ['slug' => 'testimonial', 'markup' => pattern_components_grid(3)],
            ['slug' => 'cta', 'markup' => pattern_components_grid(1)],
        ]);

        quietly(fn () => (new ExtractPatternsStep())->run($project));
        $manifest = $project->readJson('patterns.json');

        assert_eq(2, $manifest['version']);
        assert_eq(['version', 'patterns', 'starter', 'dropped'], array_keys($manifest));
        $section = pattern_components_manifest_entry($project, 'testimonial-grid');
        assert_eq([
            'slug', 'kind', 'label', 'shape', 'title', 'categories', 'source', 'score', 'contains', 'alternates',
        ], array_keys($section));
        assert_eq('section', $section['kind']);
        assert_eq(['demo-sections', 'testimonials'], $section['categories']);

        foreach (['row', 'card'] as $componentKind) {
            $component = pattern_components_manifest_entry($project, "testimonial-{$componentKind}");
            assert_eq([
                'slug', 'kind', 'component', 'from', 'label', 'shape', 'title', 'categories',
                'source', 'score', 'contains', 'alternates',
            ], array_keys($component));
            assert_eq('component', $component['kind']);
            assert_eq($componentKind, $component['component']);
            assert_eq('testimonial-grid', $component['from']);
            assert_eq(['demo-components'], $component['categories']);
        }

        assert_eq(['slug', 'sections'], array_keys($manifest['starter']));
        foreach ($manifest['starter']['sections'] as $slug) {
            assert_eq('section', pattern_components_manifest_entry($project, $slug)['kind']);
        }
        $slugs = array_column($manifest['patterns'], 'slug');
        assert_eq(count($slugs), count(array_unique($slugs)), 'pattern slugs never collide');
    });
});

test('two budgets retain ten sections and six complete component source pairs', function (): void {
    with_project('builder_pattern_budgets_contract_', function (Project $project): void {
        $sections = [['slug' => 'hero', 'markup' => pattern_components_grid(1)]];
        foreach (['alpha', 'bravo', 'charlie', 'delta', 'echo', 'foxtrot', 'golf', 'hotel'] as $slug) {
            $sections[] = ['slug' => $slug, 'markup' => pattern_components_grid(3)];
        }
        $sections[] = ['slug' => 'about', 'markup' => pattern_components_grid(1)];
        $sections[] = ['slug' => 'contact', 'markup' => pattern_components_grid(1)];
        $sections[] = ['slug' => 'cta', 'markup' => pattern_components_grid(1)];
        extract_patterns_seed($project, $sections);

        quietly(fn () => (new ExtractPatternsStep())->run($project));
        $manifest = $project->readJson('patterns.json');
        $sectionPatterns = pattern_components_of_kind($project, 'section');
        $componentPatterns = pattern_components_of_kind($project, 'component');

        assert_eq(10, count($sectionPatterns));
        assert_eq(12, count($componentPatterns));
        $from = array_values(array_unique(array_column($componentPatterns, 'from')));
        sort($from, SORT_STRING);
        assert_eq(6, count($from));
        foreach ($from as $source) {
            $pair = array_values(array_filter(
                $componentPatterns,
                static fn (array $pattern): bool => $pattern['from'] === $source,
            ));
            assert_eq(['card', 'row'], array_values(array_unique(array_column($pair, 'component'))));
        }
        $sectionSlugs = array_column($sectionPatterns, 'slug');
        assert_true(in_array('hero-stack', $sectionSlugs, true));
        assert_true(in_array('cta-stack', $sectionSlugs, true));
        assert_eq(4, count($manifest['dropped']));
        foreach ($manifest['dropped'] as $drop) {
            assert_eq(['kind', 'key', 'reason', 'total'], array_keys($drop));
            assert_true(in_array($drop['kind'], ['section', 'component'], true));
        }
        assert_eq(2, count(array_filter(
            $manifest['dropped'],
            static fn (array $drop): bool => $drop['kind'] === 'section',
        )));
        assert_eq(2, count(array_filter(
            $manifest['dropped'],
            static fn (array $drop): bool => $drop['kind'] === 'component',
        )));
        assert_contains('disposition', $project->readText('warnings.json'));
    });
});

test('component outputs are valid PHP and byte-identical across two runs', function (): void {
    with_project('builder_pattern_idempotence_contract_', function (Project $project): void {
        extract_patterns_seed($project, [
            ['slug' => 'hero', 'markup' => pattern_components_grid(1)],
            ['slug' => 'testimonial', 'markup' => pattern_components_grid(3)],
            ['slug' => 'cta', 'markup' => pattern_components_grid(1)],
        ]);

        quietly(fn () => (new ExtractPatternsStep())->run($project));
        $first = extract_patterns_snapshot($project);
        foreach (glob($project->themePath('patterns/*.php')) ?: [] as $file) {
            $output = [];
            $status = 1;
            exec(PHP_BINARY . ' -l ' . escapeshellarg($file), $output, $status);
            assert_eq(0, $status, basename($file) . ': ' . implode("\n", $output));
        }

        quietly(fn () => (new ExtractPatternsStep())->run($project));
        assert_eq($first, extract_patterns_snapshot($project));
    });
});
