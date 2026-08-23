<?php
declare(strict_types=1);

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\SectionRole;
use Automattic\SiteBuild\Steps\ExtractPatternsStep;

/** @param list<array{slug:string,markup:string}> $sections */
function pattern_components_generic_seed(Project $project, array $sections): void
{
    $project->writeJson('pages.json', ['pages' => [[
        'slug' => 'home',
        'title' => 'Home',
        'path' => '/',
        'front' => true,
        'menu_order' => 0,
        'parent' => null,
        'sections' => array_map(
            static fn (array $section, int $index): array => [
                'slug' => $section['slug'],
                'type' => 'content',
                'role' => SectionRole::forPosition($index, count($sections)),
            ],
            $sections,
            array_keys($sections),
        ),
    ]]]);
    $project->writeJson('plugin/pages.json', ['pages' => [[
        'slug' => 'home',
        'title' => 'Home',
        'front' => true,
        'menu_order' => 0,
        'parent' => null,
    ]]]);
    $project->writeText('plugin/pages/home.html', implode("\n", array_column($sections, 'markup')));
    $project->writeText('theme/style.css', 'body{color:#fff}');
    $project->writeJson('theme/theme.json', ['version' => 3]);
}

function pattern_components_generic_group(string $content): string
{
    return '<!-- wp:group --><div class="wp-block-group">'
        . $content
        . '</div><!-- /wp:group -->';
}

function pattern_components_generic_grid(): string
{
    $columns = '';
    foreach (['A', 'B', 'C'] as $copy) {
        $columns .= '<!-- wp:column --><div class="wp-block-column">'
            . '<!-- wp:paragraph --><p>' . $copy . '</p><!-- /wp:paragraph -->'
            . '</div><!-- /wp:column -->';
    }
    return pattern_components_generic_group(
        '<!-- wp:columns --><div class="wp-block-columns">'
        . $columns
        . '</div><!-- /wp:columns -->',
    );
}

function pattern_components_generic_quotes(): string
{
    return pattern_components_generic_group(
        '<!-- wp:quote --><blockquote>First quote</blockquote><!-- /wp:quote -->'
        . '<!-- wp:pullquote --><figure>Second quote</figure><!-- /wp:pullquote -->',
    );
}

/** @return array<mixed> */
function pattern_components_generic_entry(Project $project, string $slug): array
{
    foreach ($project->readJson('patterns.json')['patterns'] as $pattern) {
        if (($pattern['slug'] ?? null) === $slug) {
            return $pattern;
        }
    }
    throw new RuntimeException("No manifest entry for '{$slug}'");
}

foreach (['grid' => pattern_components_generic_grid(), 'quotes' => pattern_components_generic_quotes()] as $shape => $markup) {
    test("generic {$shape} section still emits a complete component pair", function () use ($shape, $markup): void {
        with_project("builder_pattern_generic_{$shape}_", function (Project $project) use ($shape, $markup): void {
            $stack = pattern_components_generic_group(
                '<!-- wp:paragraph --><p>Boundary section</p><!-- /wp:paragraph -->',
            );
            pattern_components_generic_seed($project, [
                ['slug' => 'hero', 'markup' => $stack],
                ['slug' => 'section-2', 'markup' => $markup],
                ['slug' => 'closing', 'markup' => $stack],
            ]);

            quietly(fn () => (new ExtractPatternsStep())->run($project));

            $section = pattern_components_generic_entry($project, $shape);
            assert_eq('section', $section['kind']);
            assert_eq(null, $section['label']);
            foreach (['row', 'card'] as $componentKind) {
                $slug = "{$shape}-{$componentKind}";
                $component = pattern_components_generic_entry($project, $slug);
                assert_eq('component', $component['kind']);
                assert_eq($componentKind, $component['component']);
                assert_eq($shape, $component['from']);
                assert_eq(null, $component['label']);
                assert_eq(['demo-components'], $component['categories']);
                assert_true($project->exists("theme/patterns/{$slug}.php"));
            }
        });
    });
}
