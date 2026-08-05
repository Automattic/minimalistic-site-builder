<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\BlockFixers;
use Automattic\SiteBuild\Package;
use Automattic\SiteBuild\Project;
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

function html_first_home_body(string $marker = 'HTML-FIRST-HOME'): string
{
    $html = <<<'HTML'
<main>
<section id="story" class="story"><h2>HTML-FIRST-HOME</h2><p>Slow fermentation, local grain.</p></section>
</main>
<footer class="site-shell"><p>Visit the neighborhood oven.</p></footer>
HTML;

    return str_replace('HTML-FIRST-HOME', $marker, $html);
}

function html_first_preview_document(string $marker = 'DESIGN-PREVIEW'): string
{
    return '<!doctype html>'
        . '<html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<style>:root { --content-size: 800px; --wide-size: 1280px; }'
        . 'body { margin: 0; font-family: system-ui, sans-serif; }</style>'
        . '</head><body><header><nav aria-label="Primary"><a href="/">Home</a></nav></header>'
        . '<main><section id="hero"><h1 class="has-display-font-size">' . $marker . '</h1>'
        . '<img alt="AI_IMAGE: A baker sliding a sourdough loaf into a stone oven, viewed from counter height | homepage hero beside the primary headline | photorealistic | landscape">'
        . '</section></main></body></html>';
}

function html_first_queue_success(
    FakeLlm $llm,
    array $siteSpec,
    string $homeBody,
    ?string $previewDocument = null,
    ?array $themePayload = null,
): void
{
    $llm->queueText('A warm neighborhood bakery site with a clear visit path.');
    $llm->queueText($previewDocument ?? html_first_preview_document());
    $llm->queueText($homeBody);

    $llm->queueJson($siteSpec);
    $llm->queueJson(['seeds' => ['Flour Archive', 'Bread Ledger', 'Oven Journal', 'Grain Index']]);
    $llm->queueJson(html_first_direction());
    $llm->queueJson($themePayload ?? html_first_theme_payload());
}

function html_first_foundation_preview_document(): string
{
    return '<!doctype html>'
        . '<html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<style>'
        . ':root{--content-size:800px;--wide-size:1280px;}'
        . 'body{margin:0;font-family:system-ui,sans-serif}'
        . '#hero{padding:4rem clamp(1.25rem,5vw,4.5rem) 6rem}'
        . '#story{padding-inline:3rem}'
        . '#about-intro{padding-left:4rem;padding-right:4rem;padding-top:2rem;padding-bottom:3rem}'
        . '#about-values{padding:2rem 5rem}'
        . '#about-intro .about-copy{padding:0 1.75rem}'
        . '</style></head>'
        . '<body><header><nav aria-label="Primary"><a href="/">Home</a></nav></header>'
        . '<main><section id="hero"><h1 class="has-display-font-size">FOUNDATION-HERO</h1>'
        . '<img alt="AI_IMAGE: A baker sliding a sourdough loaf into a stone oven, viewed from counter height | homepage hero beside the primary headline | photorealistic | landscape">'
        . '</section></main></body></html>';
}

/** @return array<string,mixed> */
function html_first_foundation_theme_payload(): array
{
    $theme = html_first_theme_payload();
    $theme['settings']['layout'] = [
        'contentSize' => '48rem',
        'wideSize' => '80rem',
    ];
    $theme['styles']['spacing']['padding'] = [
        'left' => 'clamp(1.25rem, 5vw, 4.5rem)',
        'right' => 'clamp(1.25rem, 5vw, 4.5rem)',
    ];
    return $theme;
}

/** @return list<string> */
function html_first_css_bodies_for_selector(string $css, string $wanted): array
{
    preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $matches, PREG_SET_ORDER);
    $bodies = [];
    foreach ($matches as $match) {
        $selectorList = preg_replace('/\/\*.*?\*\//s', '', $match[1]);
        if (!is_string($selectorList)) {
            continue;
        }
        foreach (explode(',', $selectorList) as $selector) {
            if (trim($selector) === $wanted) {
                $bodies[] = $match[2];
            }
        }
    }
    return $bodies;
}

function assert_html_first_page_sections_constrained(Project $project, string $slug): int
{
    $doc = BlockMarkup::parse($project->readText("plugin/pages/{$slug}.html"));
    $count = 0;
    foreach ($doc->indices() as $i) {
        if ($doc->parent($i) !== null) {
            continue;
        }
        $count++;
        assert_eq('group', $doc->name($i), "{$slug} section root is wp:group");
        assert_eq(['type' => 'constrained'], ($doc->attrs($i) ?? [])['layout'] ?? null);
        assert_contains(
            'is-layout-constrained',
            $doc->ownHtml($i),
            "{$slug} section root carries the serialized constrained-layout class",
        );
    }
    assert_true($count > 0, "{$slug} has transformed sections");
    return $count;
}

test('HTML-first default builds and validates every single-page artifact', function () {
    $tmp = sys_get_temp_dir() . '/builder_html_first_' . uniqid();
    $previous = getenv('SITE_BUILD_LEGACY');
    putenv('SITE_BUILD_LEGACY');
    try {
        $llm = new FakeLlm();
        $homeBody = html_first_home_body();
        html_first_queue_success($llm, html_first_site_spec([
            ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome visitors', 'children' => []],
        ]), $homeBody);

        $builder = html_first_integration_builder($llm, $tmp);
        $project = $builder->createProject('A neighborhood bakery', 'demo');
        $meta = $project->readJson('meta.json');
        $meta['design_candidates'] = 1;
        $meta['critique_rounds'] = 1;
        $project->writeJson('meta.json', $meta);

        $builder->pipeline()->runThrough($project);

        foreach ([
            'design/preview.html',
            'design/home-body.html',
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

        assert_eq(html_first_preview_document(), $project->readText('design/preview.html'));
        assert_eq($homeBody, $project->readText('design/home-body.html'));
        assert_true($project->exists('design/home.html'), 'homepage design output exists');
        assert_contains('DESIGN-PREVIEW', $project->readText('design/home.html'));
        assert_contains(
            'HTML-FIRST-HOME',
            $project->readText('design/home.html'),
            'homepage content survives downstream image-source assignment',
        );
        assert_true(
            trim($project->readText('theme/parts/footer.html')) !== '',
            'single-page full build lifts non-empty home-body footer part',
        );

        $home = $project->readText('plugin/pages/home.html');
        assert_contains('HTML-FIRST-HOME', $home);
        assert_true(count(BlockMarkup::parse($home)->indices()) > 0, 'transformed page contains serialized blocks');
        // The design's prose <img> reaches images.json as a generable spec at
        // the exact theme path the delivered markup references.
        $images = $project->readJson('images.json');
        assert_eq(1, count($images), 'the design image was collected');
        assert_eq(
            'A baker sliding a sourdough loaf into a stone oven, viewed from counter height',
            $images[0]['subject'],
        );
        assert_contains('theme:./assets/', $images[0]['src']);
        assert_contains($images[0]['src'], $home);

        assert_true(assert_html_first_page_sections_constrained($project, 'home') > 0);
        $style = $project->readText('theme/style.css');
        assert_contains('hyphens: none;', $style);
        assert_eq(1, substr_count($style, 'Wrap at spaces only'), 'the wrap policy is merged exactly once');
        assert_true(!str_contains($style, 'break-all'), 'no mid-word break rule ships');

        assert_eq([], ThemeValidator::validate($project));
        assert_eq([], ThemeValidator::layoutWarnings($project, true));
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

test('HTML-first multi-page build adopts one declared inset system in markup and delivered CSS', function () {
    $tmp = sys_get_temp_dir() . '/builder_html_first_foundation_insets_' . uniqid();
    $previous = getenv('SITE_BUILD_LEGACY');
    putenv('SITE_BUILD_LEGACY');
    try {
        $pages = [
            ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome visitors', 'children' => []],
            ['title' => 'About', 'slug' => 'about', 'purpose' => 'Explain the bakery', 'children' => []],
        ];
        $llm = new FakeLlm();
        html_first_queue_success(
            $llm,
            html_first_site_spec($pages),
            html_first_home_body(),
            html_first_foundation_preview_document(),
            html_first_foundation_theme_payload(),
        );
        $llm->queueText(
            '<main><section id="about-intro"><h1>About</h1>'
                . '<p class="about-copy">HTML-FIRST-ABOUT</p></section>'
                . '<section id="about-values"><h2>Values</h2><p>Local grain.</p></section></main>',
        );

        $builder = html_first_integration_builder($llm, $tmp);
        $project = $builder->createProject(
            'A neighborhood bakery',
            'demo',
            multiPage: true,
            pages: $pages,
        );
        $meta = $project->readJson('meta.json');
        $meta['design_candidates'] = 1;
        $meta['critique_rounds'] = 1;
        $project->writeJson('meta.json', $meta);

        $builder->pipeline()->runThrough($project);

        $theme = $project->readJson('theme/theme.json');
        assert_eq('48rem', $theme['settings']['layout']['contentSize'] ?? null);
        assert_eq('80rem', $theme['settings']['layout']['wideSize'] ?? null);
        assert_eq(true, $theme['settings']['useRootPaddingAwareAlignments'] ?? null);
        assert_eq(
            'clamp(1.25rem, 5vw, 4.5rem)',
            $theme['styles']['spacing']['padding']['left'] ?? null,
        );
        assert_eq(
            'clamp(1.25rem, 5vw, 4.5rem)',
            $theme['styles']['spacing']['padding']['right'] ?? null,
        );
        assert_true(
            $project->exists('design/about.html'),
            'inner page design survives: ' . implode(
                ' | ',
                $project->readJson('warnings.json')['inner-pages-design'] ?? [],
            ),
        );

        $sectionCount = 0;
        foreach (['home', 'about'] as $slug) {
            $sectionCount += assert_html_first_page_sections_constrained($project, $slug);
        }
        assert_eq(4, $sectionCount, 'home and inner-page sections share constrained root layout');

        $style = $project->readText('theme/style.css');
        foreach (['#hero', '#story', '#about-intro', '#about-values'] as $selector) {
            foreach (html_first_css_bodies_for_selector($style, $selector) as $body) {
                assert_true(
                    preg_match(
                        '/(?:^|;)\s*(?:padding|padding-inline|padding-left|padding-right)\s*:/i',
                        $body,
                    ) !== 1,
                    "{$selector} retains competing horizontal padding in delivered CSS",
                );
            }
        }
        assert_contains(
            'padding:0 1.75rem',
            implode("\n", html_first_css_bodies_for_selector($style, '#about-intro .about-copy')),
            'inner-element padding survives root neutralization',
        );
        assert_eq([], ThemeValidator::validate($project));
        assert_eq([], ThemeValidator::layoutWarnings($project, true));
    } finally {
        $previous === false
            ? putenv('SITE_BUILD_LEGACY')
            : putenv('SITE_BUILD_LEGACY=' . $previous);
        if (is_dir($tmp)) {
            exec('rm -rf ' . escapeshellarg($tmp));
        }
    }
});
