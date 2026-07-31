<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\BlockFixers;
use Automattic\SiteBuild\Package;
use Automattic\SiteBuild\SiteBuilder;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\ThemeValidator;

function html_first_integration_builder(FakeLlm $llm, string $outputRoot): SiteBuilder
{
    return new SiteBuilder(
        llm: $llm,
        promptsDir: Package::promptsDir(),
        outputRoot: $outputRoot,
        blockFixer: BlockFixers::default(),
    );
}

/** @return array<string,mixed> */
function html_first_theme_payload(): array
{
    return [
        'version' => 3,
        'settings' => [
            'color' => ['palette' => [
                ['slug' => 'base', 'color' => '#fff8ea', 'name' => 'Base'],
                ['slug' => 'contrast', 'color' => '#251d16', 'name' => 'Contrast'],
                ['slug' => 'primary', 'color' => '#8a5a2b', 'name' => 'Primary'],
                ['slug' => 'secondary', 'color' => '#cc9988', 'name' => 'Secondary'],
                ['slug' => 'accent', 'color' => '#e08a3c', 'name' => 'Accent'],
            ]],
            'typography' => ['fontFamilies' => [
                ['slug' => 'heading', 'fontFamily' => 'Fraunces, serif', 'name' => 'Heading'],
                ['slug' => 'body', 'fontFamily' => 'Source Sans 3, sans-serif', 'name' => 'Body'],
            ]],
        ],
    ];
}

/** @return array<string,mixed> */
function html_first_site_spec(array $pages): array
{
    return [
        'name' => 'Hearth & Crumb',
        'slug' => 'hearth-crumb',
        'title' => 'Hearth & Crumb',
        'description' => 'Neighborhood bread and pastry studio',
        'site_type' => 'bakery storefront',
        'topic' => 'artisan bread and pastries',
        'area' => 'bakery',
        'audience' => 'neighborhood locals',
        'visual_vibe' => 'warm editorial',
        'language' => 'en',
        'persona_name' => '',
        'email_domain' => 'hearthandcrumb.example',
        'invented' => ['name', 'email_domain'],
        'sections' => ['Hero', 'Story'],
        'pages' => $pages,
    ];
}

/** @return array<string,mixed> */
function html_first_direction(): array
{
    return ['direction' => [
        'title' => 'Flour Archive',
        'description' => 'Warm editorial system with documentary bakery imagery.',
        'palette' => [
            'base' => '#FFF8EA',
            'contrast' => '#251D16',
            'primary' => '#8A5A2B',
            'secondary' => '#CC9988',
            'accent' => '#E08A3C',
        ],
        'type' => [
            'heading' => 'Fraunces 700',
            'body' => 'Source Sans 3 400/700',
        ],
        'image_grade' => 'warm documentary light',
        'motion' => 'calm',
        'motion_note' => 'Keep transitions restrained.',
        'signature_device' => 'hairline rules and numbered folios',
        'hero_composition' => 'editorial split with a left-aligned headline',
    ]];
}

function html_first_home_document(string $marker = 'HTML-FIRST-HOME'): string
{
    $html = <<<'HTML'
<!doctype html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
.site-shell { color: #251d16; background-color: #fff8ea; font-family: "Source Sans 3", sans-serif; }
.hero { color: #251d16; background-color: #fff8ea; font-family: Fraunces, serif; padding: 4rem 1rem; }
.story { color: #251d16; background-color: #fff8ea; padding: 3rem 1rem; }
</style>
</head>
<body class="site-shell">
<header class="site-shell"><p>Hearth &amp; Crumb</p></header>
<main>
<section id="hero" class="hero"><h1 class="has-display-font-size">HTML-FIRST-HOME</h1><p>Fresh loaves every morning.</p></section>
<section id="story" class="story"><h2>Our bakehouse</h2><p>Slow fermentation, local grain.</p></section>
</main>
<footer class="site-shell"><p>Visit the neighborhood oven.</p></footer>
</body>
</html>
HTML;

    return str_replace('HTML-FIRST-HOME', $marker, $html);
}

function html_first_queue_success(FakeLlm $llm, array $siteSpec, string $home): void
{
    $llm->queueText('A warm neighborhood bakery site with a clear visit path.');
    $llm->queueText($home);

    $llm->queueJson($siteSpec);
    $llm->queueJson(['seeds' => ['Flour Archive', 'Bread Ledger', 'Oven Journal', 'Grain Index']]);
    $llm->queueJson(html_first_direction());
    $llm->queueJson(['seeds' => ['Editorial bread ledger']]);
    $llm->queueJson(['winner' => 0, 'why' => 'Clear hierarchy and strong site shell']);
    $llm->queueJson(['verdict' => 'pass', 'notes' => []]);
    $llm->queueJson(html_first_theme_payload());
}

test('HTML-first default builds and validates every single-page artifact', function () {
    $tmp = sys_get_temp_dir() . '/builder_html_first_' . uniqid();
    $previous = getenv('SITE_BUILD_LEGACY');
    putenv('SITE_BUILD_LEGACY');
    try {
        $llm = new FakeLlm();
        html_first_queue_success($llm, html_first_site_spec([
            ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome visitors', 'children' => []],
        ]), html_first_home_document());

        $builder = html_first_integration_builder($llm, $tmp);
        $project = $builder->createProject('A neighborhood bakery', 'demo');
        $meta = $project->readJson('meta.json');
        $meta['design_candidates'] = 1;
        $meta['critique_rounds'] = 1;
        $project->writeJson('meta.json', $meta);

        $builder->pipeline()->runThrough($project);

        foreach ([
            'design/candidate-1.html',
            'design/judge.json',
            'design/critique-1.json',
            'design/home.html',
            'design/site.css',
            'design/transform-report.json',
            'design/transformer-carried.css',
            'pages.json',
            'theme/theme.json',
            'theme/style.css',
            'theme/parts/header.html',
            'theme/parts/footer.html',
            'theme/templates/page.html',
            'theme/templates/index.html',
            'theme/fonts.php',
            'theme/functions.php',
            'plugin/pages/home.html',
            'plugin/pages.json',
            'logs/validate-theme.log',
        ] as $path) {
            assert_true($project->exists($path), "missing HTML-first artifact {$path}");
        }

        $home = $project->readText('plugin/pages/home.html');
        assert_contains('HTML-FIRST-HOME', $home);
        assert_true(count(BlockMarkup::parse($home)->indices()) > 0, 'transformed page contains serialized blocks');
        assert_eq([], ThemeValidator::validate($project));
        assert_eq([], ThemeValidator::layoutWarnings($project));
        assert_eq("Final theme validation passed.\n", $project->readText('logs/validate-theme.log'));
        assert_true(!$project->exists('theme/parts/page-home--hero.html'), 'assemble removes transient section parts');
    } finally {
        $previous === false
            ? putenv('SITE_BUILD_LEGACY')
            : putenv('SITE_BUILD_LEGACY=' . $previous);
        if (is_dir($tmp)) {
            exec('rm -rf ' . escapeshellarg($tmp));
        }
    }
});
