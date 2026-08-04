<?php
declare(strict_types=1);

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\PageStylesStep;
use Automattic\SiteBuild\Steps\SpliceHomeDesignStep;
use Automattic\SiteBuild\Steps\TransformSiteStep;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\TransformArtifacts;

/** @return array{0:Project,1:string} */
function dp2_review_project(string $prefix): array
{
    $tmp = sys_get_temp_dir() . '/' . $prefix . uniqid();
    return [(new ProjectStore($tmp))->create('demo'), $tmp];
}

function dp2_page_styles_step(): PageStylesStep
{
    return new PageStylesStep(
        new FakeLlm(),
        new PromptRenderer(repo_path('prompts')),
        htmlFirst: true,
    );
}

/** @param list<array<string,mixed>> $pages @param array<string,string> $map */
function dp2_page_styles_fixture(array $pages, array $map): array
{
    [$project, $tmp] = dp2_review_project('builder_dp2_page_styles_');
    $project->writeText('theme/style.css', '');
    $project->writeText(TransformArtifacts::SITE_CSS, '.site-base{display:grid;}');
    $project->writeJson('pages.json', ['pages' => $pages]);
    $project->writeJson('design/page-artifact-map.json', $map);
    foreach ($pages as $page) {
        $project->writeText('plugin/pages/' . $page['slug'] . '.html', '<p>Delivered</p>');
    }
    return [$project, $tmp];
}

test('page-styles declares and resolves the required semantic-to-physical artifact map', function () {
    $step = dp2_page_styles_step();
    assert_true(
        in_array('design/page-artifact-map.json', $step->declaration()->reads, true),
        'HTML-first declaration names the concrete required map',
    );
    [$project, $tmp] = dp2_page_styles_fixture(
        [
            ['slug' => 'landing', 'front' => true],
            ['slug' => 'preview', 'front' => false],
            ['slug' => 'site', 'front' => false],
        ],
        ['landing' => 'home', 'preview' => 'preview-2', 'site' => 'site-2'],
    );
    $project->writeText(
        'design/home.html',
        '<style data-page-css>.mapped-home{display:block;}</style>',
    );
    $project->writeText(
        'design/preview-2.html',
        '<style data-page-css>.mapped-preview{display:flex;}</style>',
    );
    $project->writeText(
        'design/site-2.html',
        '<style data-page-css>.mapped-site{display:grid;}</style>',
    );
    $project->writeText(
        'design/preview.html',
        '<style data-page-css>.seed-preview-must-not-land{position:fixed;}</style>',
    );
    $project->writeText(
        'design/site.html',
        '<style data-page-css>.semantic-path-must-not-land{position:fixed;}</style>',
    );

    $step->run($project);

    $css = $project->readText('theme/style.css');
    foreach (['mapped-home', 'mapped-preview', 'mapped-site'] as $marker) {
        assert_contains($marker, $css, "physical {$marker} CSS lands");
    }
    assert_true(!str_contains($css, 'seed-preview-must-not-land'));
    assert_true(!str_contains($css, 'semantic-path-must-not-land'));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('page-styles resolves failed markers through the physical artifact map', function () {
    [$project, $tmp] = dp2_page_styles_fixture(
        [
            ['slug' => 'landing', 'front' => true],
            ['slug' => 'about', 'front' => false],
        ],
        ['landing' => 'home', 'about' => 'about-2'],
    );
    $project->writeText('design/home.html', '<style data-page-css>.home-ok{display:block;}</style>');
    $project->writeText(
        'design/about-2.html',
        '<style data-page-css>.failed-physical-must-not-land{position:fixed;}</style>',
    );
    $project->writeText('design/about-2.failed', "Mapped page failed.\n");

    dp2_page_styles_step()->run($project);

    $css = $project->readText('theme/style.css');
    assert_contains('home-ok', $css);
    assert_true(!str_contains($css, 'failed-physical-must-not-land'));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('transform residual evidence names physical sources while outputs keep semantic slugs', function () {
    [$project, $tmp] = dp2_review_project('builder_dp2_transform_source_');
    $project->writeJson('meta.json', ['prompt' => 'Mapped residual source']);
    $project->writeJson('siteSpec.json', [
        'name' => 'Mapped Sources',
        'language' => 'English',
        'pages' => [
            ['slug' => 'landing', 'title' => 'Landing', 'purpose' => 'Welcome'],
            ['slug' => 'preview', 'title' => 'Preview', 'purpose' => 'Empty residual'],
            ['slug' => 'site', 'title' => 'Site', 'purpose' => 'Delivered'],
        ],
    ]);
    $project->writeJson('designDirection.json', ['description' => 'Mapped editorial']);
    $project->writeJson('theme/theme.json', ['version' => 3, 'settings' => []]);
    $project->writeText(TransformArtifacts::SITE_CSS, '.mapped{display:block;}');
    $project->writeJson('design/page-artifact-map.json', [
        'landing' => 'home',
        'preview' => 'preview-2',
        'site' => 'site-2',
    ]);
    $project->writeText(
        'design/home.html',
        '<!doctype html><html><body><header>Header</header><main>'
            . '<section id="home">Home</section></main><footer>Footer</footer></body></html>',
    );
    $project->writeText('design/preview-2.html', '<main></main>');
    $project->writeText(
        'design/site-2.html',
        '<main><section id="site-content">MAPPED-SITE-CONTENT</section></main>',
    );

    (new TransformSiteStep(new FakeLlm(), new PromptRenderer(repo_path('prompts'))))->run($project);

    assert_eq(['landing', 'site'], array_column($project->readJson('pages.json')['pages'], 'slug'));
    assert_contains('MAPPED-SITE-CONTENT', $project->readText('theme/parts/page-site--site-content.html'));
    $warnings = implode("\n", $project->readJson('warnings.json')['transform-site'] ?? []);
    assert_contains('source design/preview-2.html', $warnings);
    assert_true(!str_contains($warnings, 'source design/preview.html'));
    $report = $project->readText(TransformArtifacts::REPORT);
    assert_contains('design/preview-2.html', $report);
    exec('rm -rf ' . escapeshellarg($tmp));
});

function dp2_valid_fold(string $heroOpening = '<section id="hero">'): string
{
    return '<!doctype html><html><head><style>.hero{display:block;}</style></head><body>'
        . '<header><nav>Header</nav></header><main>' . $heroOpening
        . '<h1>REAL-HERO</h1></section></main></body></html>';
}

function dp2_valid_home_body(string $mainOpening = '<main>'): string
{
    return $mainOpening . '<section id="story">BODY-STORY</section></main><footer>BODY-FOOTER</footer>';
}

/** @return array{0:string,1:array<string,mixed>} */
function dp2_splice(string $preview, string $body): array
{
    [$project, $tmp] = dp2_review_project('builder_dp2_splice_');
    $project->writeText('design/preview.html', $preview);
    $project->writeText('design/home-body.html', $body);
    (new SpliceHomeDesignStep())->run($project);
    $result = [
        'home' => $project->readText('design/home.html'),
        'warnings' => $project->exists('warnings.json')
            ? ($project->readJson('warnings.json')['splice-home-design'] ?? [])
            : [],
    ];
    exec('rm -rf ' . escapeshellarg($tmp));
    return [$tmp, $result];
}

test('splice hero id parsing ignores quoted decoys and requires one live hero id', function () {
    [, $real] = dp2_splice(
        dp2_valid_fold('<section data-note=" id=\'decoy\'" id="hero">'),
        dp2_valid_home_body(),
    );
    assert_contains('REAL-HERO', $real['home']);
    assert_contains('BODY-STORY', $real['home']);
    assert_eq([], $real['warnings']);

    [, $fake] = dp2_splice(
        dp2_valid_fold('<section data-note=" id=\'hero\'">'),
        dp2_valid_home_body(),
    );
    assert_eq(dp2_valid_home_body(), $fake['home'], 'fake quoted id degrades to raw body');
    assert_true($fake['warnings'] !== [], 'fake-only hero writes actionable warning');
});

test('splice rejects attributed home-body main instead of silently dropping its attributes', function () {
    $attributed = dp2_valid_home_body('<main class="below-fold" data-unit="home-body">');
    [, $result] = dp2_splice(dp2_valid_fold(), $attributed);

    assert_eq(dp2_valid_fold(), $result['home'], 'malformed body degrades to intact fold');
    assert_true($result['warnings'] !== [], 'attribute loss is never silent');
    assert_contains('home-body missing, empty, or malformed', implode("\n", $result['warnings']));
});

test('splice requires one complete html head body fold envelope', function () {
    $valid = dp2_valid_fold();
    $cases = [
        'missing head' => str_replace('<head><style>.hero{display:block;}</style></head>', '', $valid),
        'top-level text' => 'LEAKED-TEXT' . $valid,
        'extra root' => $valid . '<aside>EXTRA-ROOT</aside>',
        'malformed closer' => str_replace('</main></body>', '</body></main>', $valid),
    ];
    foreach ($cases as $name => $preview) {
        [, $result] = dp2_splice($preview, dp2_valid_home_body());
        assert_eq(dp2_valid_home_body(), $result['home'], "{$name} degrades to raw body");
        assert_true($result['warnings'] !== [], "{$name} writes warning");
    }
});
