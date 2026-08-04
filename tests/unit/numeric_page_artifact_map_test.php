<?php
declare(strict_types=1);

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\PageStylesStep;
use Automattic\SiteBuild\Steps\TransformSiteStep;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\TransformArtifacts;

/** @return array{0:Project,1:string} */
function numeric_map_project(string $prefix): array
{
    $tmp = sys_get_temp_dir() . '/' . $prefix . uniqid();
    return [(new ProjectStore($tmp))->create('demo'), $tmp];
}

/** @param array<int|string,mixed> $map @return array{0:Project,1:string} */
function numeric_map_transform_fixture(array $map): array
{
    [$project, $tmp] = numeric_map_project('builder_numeric_map_transform_');
    $project->writeJson('meta.json', ['prompt' => 'Numeric semantic slug']);
    $project->writeJson('siteSpec.json', [
        'name' => 'Year Studio',
        'language' => 'English',
        'pages' => [
            ['slug' => 'landing', 'title' => 'Landing', 'purpose' => 'Welcome'],
            ['slug' => '2026', 'title' => 'Year 2026', 'purpose' => 'Archive'],
        ],
    ]);
    $project->writeJson('designDirection.json', ['description' => 'Numeric editorial']);
    $project->writeJson('theme/theme.json', ['version' => 3, 'settings' => []]);
    $project->writeText(TransformArtifacts::SITE_CSS, '.numeric-map{display:block;}');
    $project->writeJson('design/page-artifact-map.json', $map);
    $project->writeText(
        'design/home.html',
        '<!doctype html><html><body><header>Header</header><main>'
            . '<section id="landing">Landing</section></main><footer>Footer</footer></body></html>',
    );
    $project->writeText(
        'design/year-2026.html',
        '<main><section id="year">NUMERIC-SEMANTIC-PAGE</section></main>',
    );
    return [$project, $tmp];
}

test('transform-site canonicalizes JSON integer map keys and retains numeric semantic slugs', function () {
    [$project, $tmp] = numeric_map_transform_fixture([
        'landing' => 'home',
        2026 => 'year-2026',
    ]);
    $decoded = $project->readJson('design/page-artifact-map.json');
    assert_true(array_key_exists(2026, $decoded), 'JSON associative decode canonicalizes numeric key to int');
    assert_true(is_int(array_key_first(array_slice($decoded, 1, null, true))));

    (new TransformSiteStep(new FakeLlm(), new PromptRenderer(repo_path('prompts'))))->run($project);

    assert_eq(['landing', '2026'], array_column($project->readJson('pages.json')['pages'], 'slug'));
    assert_contains(
        'NUMERIC-SEMANTIC-PAGE',
        $project->readText('theme/parts/page-2026--year.html'),
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('page-styles canonicalizes JSON integer map keys to physical page sources', function () {
    [$project, $tmp] = numeric_map_project('builder_numeric_map_styles_');
    $project->writeText('theme/style.css', '');
    $project->writeText(TransformArtifacts::SITE_CSS, '.site{display:block;}');
    $project->writeJson('pages.json', ['pages' => [
        ['slug' => 'landing', 'front' => true],
        ['slug' => '2026', 'front' => false],
    ]]);
    $project->writeJson('design/page-artifact-map.json', [
        'landing' => 'home',
        2026 => 'year-2026',
    ]);
    $project->writeText('design/home.html', '<style data-page-css>.home{display:block;}</style>');
    $project->writeText(
        'design/year-2026.html',
        '<style data-page-css>.numeric-year{display:grid;}</style>',
    );
    $project->writeText('plugin/pages/landing.html', '<p>Landing</p>');
    $project->writeText('plugin/pages/2026.html', '<p>Year</p>');

    (new PageStylesStep(
        new FakeLlm(),
        new PromptRenderer(repo_path('prompts')),
        htmlFirst: true,
    ))->run($project);

    $css = $project->readText('theme/style.css');
    assert_contains('numeric-year', $css);
    assert_contains('home{display:block', $css);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('numeric semantic keys never relax physical map values beyond strict strings', function () {
    [$transform, $transformTmp] = numeric_map_transform_fixture([
        'landing' => 'home',
        2026 => 2026,
    ]);
    $transformError = null;
    try {
        (new TransformSiteStep(new FakeLlm(), new PromptRenderer(repo_path('prompts'))))->run($transform);
    } catch (Throwable $error) {
        $transformError = $error;
    }
    assert_true($transformError instanceof RuntimeException);
    assert_contains('expected direct semantic-slug to physical-basename string map', $transformError->getMessage());

    [$styles, $stylesTmp] = numeric_map_project('builder_numeric_map_strict_styles_');
    $styles->writeText('theme/style.css', '');
    $styles->writeText(TransformArtifacts::SITE_CSS, '.site{display:block;}');
    $styles->writeJson('pages.json', ['pages' => [
        ['slug' => 'landing', 'front' => true],
        ['slug' => '2026', 'front' => false],
    ]]);
    $styles->writeJson('design/page-artifact-map.json', ['landing' => 'home', 2026 => 2026]);
    $stylesError = null;
    try {
        (new PageStylesStep(
            new FakeLlm(),
            new PromptRenderer(repo_path('prompts')),
            htmlFirst: true,
        ))->run($styles);
    } catch (Throwable $error) {
        $stylesError = $error;
    }
    assert_true($stylesError instanceof RuntimeException);
    assert_contains('expected direct semantic-slug to physical-basename string map', $stylesError->getMessage());

    exec('rm -rf ' . escapeshellarg($transformTmp));
    exec('rm -rf ' . escapeshellarg($stylesTmp));
});
