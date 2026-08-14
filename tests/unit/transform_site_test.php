<?php
declare(strict_types=1);

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\AssemblePagesStep;
use Automattic\SiteBuild\Steps\PagePlanStep;
use Automattic\SiteBuild\Steps\PageStylesStep;
use Automattic\SiteBuild\Steps\TransformSiteStep;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\TransformArtifacts;

/** @return array{0:Project,1:FakeLlm,2:string} */
function transform_site_fixture(string $home, array $inner = [], int $repairBudget = 12): array
{
    $tmp = sys_get_temp_dir() . '/builder_transform_site_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'Demo site', 'repair_budget' => $repairBudget]);
    $pages = [['slug' => 'home', 'title' => 'Home', 'purpose' => 'Welcome']];
    if (array_key_exists('about', $inner)) {
        $about = ['slug' => 'about', 'title' => 'About', 'purpose' => 'Explain'];
        if (array_key_exists('team', $inner)) {
            $about['children'] = [['slug' => 'team', 'title' => 'Team', 'purpose' => 'Introduce']];
        }
        $pages[] = $about;
    }
    $project->writeJson('siteSpec.json', [
        'name' => 'Northstar Studio',
        'language' => 'English',
        'pages' => $pages,
    ]);
    $artifactMap = ['home' => 'home'];
    if (array_key_exists('about', $inner)) {
        $artifactMap['about'] = 'about';
    }
    if (array_key_exists('team', $inner)) {
        $artifactMap['team'] = 'team';
    }
    $project->writeJson('design/page-artifact-map.json', $artifactMap);
    $project->writeJson('designDirection.json', test_design_direction('cinematic-safe-zone', [
        'description' => 'Crisp editorial system',
    ]));
    $project->writeJson('theme/theme.json', ['version' => 3, 'settings' => []]);
    $project->writeJson('images.json', []);
    $project->writeText('design/site.css', ".shared{color:#123}\n");
    $project->writeText('design/home.html', $home);
    foreach ($inner as $slug => $html) {
        $project->writeText("design/{$slug}.html", $html);
    }
    return [$project, new FakeLlm(), $tmp];
}

function transform_site_run(Project $project, FakeLlm $llm): TransformSiteStep
{
    $step = new TransformSiteStep($llm, new PromptRenderer(repo_path('prompts')));
    $step->run($project);
    return $step;
}

function transform_site_cleanup(string $tmp): void
{
    exec('rm -rf ' . escapeshellarg($tmp));
}

/** @return array<string,string> */
function transform_site_outputs(Project $project): array
{
    $out = [];
    foreach (glob($project->themePath('parts/*.html')) ?: [] as $path) {
        $rel = 'theme/parts/' . basename($path);
        $out[$rel] = $project->readText($rel);
    }
    foreach ([
        TransformArtifacts::CARRIED_CSS_BEFORE_AUTHOR,
        TransformArtifacts::CARRIED_CSS_AFTER_AUTHOR,
        'design/transform-report.json',
        'pages.json',
    ] as $rel) {
        if ($project->exists($rel)) {
            $out[$rel] = $project->readText($rel);
        }
    }
    ksort($out);
    return $out;
}

function transform_site_carried_css(Project $project): string
{
    $css = '';
    foreach ([
        TransformArtifacts::CARRIED_CSS_BEFORE_AUTHOR,
        TransformArtifacts::CARRIED_CSS_AFTER_AUTHOR,
    ] as $path) {
        if ($project->exists($path)) {
            $css .= $project->readText($path);
        }
    }
    return $css;
}

function transform_site_finish_styles(Project $project): string
{
    $project->writeText('theme/style.css', "/*\nTheme Name: Demo\n*/\n");
    (new PageStylesStep(
        new FakeLlm(),
        new PromptRenderer(repo_path('prompts')),
        htmlFirst: true,
    ))->run($project);
    return $project->readText('theme/style.css');
}

test('transform-site writes exact legacy part names and AssemblePagesStep accepts pages.json unchanged', function () {
    [$project, $llm, $tmp] = transform_site_fixture(
        '<!doctype html><html><body>'
        . '<header><p>Shared header</p></header>'
        . '<section id="hero"><h1 style="width:321px">Hero</h1></section>'
        . '<section id="feature"><p>Feature</p></section>'
        . '<footer><p>Shared footer</p></footer>'
        . '</body></html>',
        [
            'about' => '<main><section id="about-intro"><h1>About</h1></section></main>',
            'team' => '<main><section id="team-intro"><h1>Team</h1></section></main>',
        ],
    );

    $step = transform_site_run($project, $llm);

    assert_eq('transform-site', $step->id());
    assert_eq(0, $llm->completeBatchCalls);
    foreach ([
        'theme/parts/header.html',
        'theme/parts/footer.html',
        'theme/parts/page-home--hero.html',
        'theme/parts/page-home--feature.html',
        'theme/parts/page-about--about-intro.html',
        'theme/parts/page-team--team-intro.html',
    ] as $rel) {
        assert_true($project->exists($rel), $rel);
    }
    $plan = $project->readJson('pages.json');
    assert_eq(['home', 'about', 'team'], array_column($plan['pages'], 'slug'));
    assert_eq([true, false, false], array_column($plan['pages'], 'front'));
    assert_eq([0, 10, 20], array_column($plan['pages'], 'menu_order'));
    assert_eq([null, null, 'about'], array_column($plan['pages'], 'parent'));
    assert_contains('.be-inline-geometry-', transform_site_carried_css($project));

    $before = $project->readText('pages.json');
    (new AssemblePagesStep())->run($project);
    assert_eq($before, $project->readText('pages.json'), 'reader does not rewrite transform plan');
    assert_eq(['home', 'about', 'team'], array_column($project->readJson('plugin/pages.json')['pages'], 'slug'));
    transform_site_cleanup($tmp);
});

test('G1 engine-support families reach final theme CSS after transform-site and page-styles', function () {
    $html = '<!doctype html><html><body>'
        . '<header class="site-header"><a class="brand" href="/">Verified Artifact</a>'
        . '<nav class="desktop-nav"><ul><li><a href="/">Home</a></li><li><a href="/about">About</a></li></ul></nav>'
        . '<div class="mobile-nav"><nav><ul><li><a href="/">Home</a></li><li><a href="/about">About</a></li></ul></nav></div>'
        . '<div class="site-utils"><span class="provider-search"><form id="provider-search" action="/apps/search" method="get">'
        . '<input type="text" name="q" placeholder="Search"></form></span>'
        . '<button class="search-icon"><svg width="12px" height="13px" viewBox="0 0 12 13"><path d="M1 1"></path></svg></button>'
        . '<button class="search-close">close</button></div>'
        . '<script>document.querySelector(".search-icon").classList.add("visible")</script></header><main>'
        . '<section id="geometry"><p id="target" style="width:30rem">Geometry</p></section>'
        . '<section id="richtext"><ul class="maintenance-loop"><li><span>Build</span></li></ul></section>'
        . '<section id="layout"><div class="hero-visual"><div class="artifact-card"><span class="card-label">Input</span>'
        . '<strong>index.html</strong><span>styles.css</span><span>assets/</span></div></div></section>'
        . '<section id="flow"><div class="services-cards"><article><h2>One</h2><p>First service.</p></article>'
        . '<article><h2>Two</h2><p>Second service.</p></article></div></section>'
        . '<section id="positioned"><a class="skip-link" href="#content">Skip to content</a><div id="content">Content</div></section>'
        . '<section id="empty"><div class="utils"><span>Action</span><div class="placeholder"></div></div></section>'
        . '<section id="native-button"><div style="text-align:center"><a class="cta highlight" href="/learn">'
        . '<span class="cta-inner">Learn more</span></a></div></section>'
        . '<section id="direct-flex"><div class="stack"><a class="row" href="/product">'
        . '<span class="row__name">Product</span><span>$25</span></a></div></section>'
        . '<section id="full-width"><a class="btn btn--full selector-submit" href="/submit">Submit</a></section>'
        . '</main><footer><span>Portable input.</span></footer></body></html>';

    [$project, $llm, $tmp] = transform_site_fixture($html);
    $project->writeText('design/site.css', implode('', [
        '.contract-author-only{color:#123456}',
        '#target{width:12rem}',
        '.maintenance-loop{display:grid}.maintenance-loop li > span{display:inline-block;width:10px;height:10px;border-radius:50%;background:#e8a020}',
        'p{margin:0}.site-header{display:flex;align-items:center}.brand{font-size:18px;font-weight:700}',
        '.desktop-nav a{color:#fff}@media(max-width:700px){.desktop-nav{display:none}.mobile-nav{background:rgba(0,0,0,.9)}}',
        '.artifact-card{display:grid;grid-template-columns:1fr auto}.artifact-card > span:not(.card-label){grid-column:2}',
        '.artifact-card > strong{display:block;grid-column:1;margin:12px 0 4.8px}',
        '.artifact-card .card-label{display:block;grid-column:1 / -1;color:#6040cc;margin:2px 0}',
        '.hero-visual{display:grid;gap:2rem}',
        '.services-cards{display:flex;gap:24px;align-items:stretch}',
        '.skip-link{position:fixed;top:-200px;left:0;padding:12px 18px;background:#135e96;color:#fff;border-radius:999px}.skip-link:focus{top:0}',
        '.utils{display:flex}.placeholder{visibility:hidden;height:80px}',
        '.search-icon{display:none;height:80px}.search-icon.visible{display:block}',
        '.cta{display:inline-block;border:1px solid #000}.cta .cta-inner{display:inline-block;min-width:170px;padding:22px 26px;background-color:#00ff8e;color:#000;font-size:16px;line-height:1;font-weight:700}.highlight .cta-inner{background:#fff;color:#000}',
        '.stack{display:flex;flex-direction:column;gap:2rem}.row{display:flex;align-items:center;gap:1rem;padding:1rem;background:#123456}.row__name{flex:1}',
        '.btn{display:inline-flex;align-items:center;padding:1rem;background:#123456}.btn--full{width:100%}',
    ]) . "\n");

    transform_site_run($project, $llm);
    $css = preg_replace('/\s+/', '', transform_site_finish_styles($project));
    assert_true(is_string($css));

    foreach ([
        'be-inline-geometry' => '.be-inline-geometry-',
        'richtext-marker reset' => ':where(mark)[style*="--blocks-engine-richtext-marker:"]{background-color:transparent;color:inherit}',
        'synthetic-paragraph' => ':where(.blocks-engine-synthetic-paragraph){margin-top:0;margin-bottom:0}',
        'synthetic-anchor-undecorated' => 'blocks-engine-synthetic-anchor-undecorated',
        'inline-layout-carrier' => ':where(p.blocks-engine-inline-layout-carrier){display:contents;margin:0!important;padding:0!important;border:0!important}',
        'css-owned-flow paragraph' => ':where(.blocks-engine-css-owned-flow)>p{margin-top:0;margin-bottom:0}',
        'css-owned-flow direct children' => ':where(.wp-block-group.blocks-engine-css-owned-flow)>*{margin-block-start:0;margin-block-end:0}',
        'css-owned-grid' => ':where(.blocks-engine-css-owned-grid)>*{margin-block-start:0;margin-block-end:0}',
        'css-owned-layout' => '.wp-block-group.blocks-engine-css-owned-layout>:where(:not(.alignleft):not(.alignright):not(.alignfull)){max-width:none!important;margin-left:0!important;margin-right:0!important}',
        'css-owned-layout-item' => ':where(.wp-block-group.blocks-engine-css-owned-layout-item)>*{margin-block-start:0;margin-block-end:0}',
        'positioned-fragment-link-carrier' => ':where(.blocks-engine-positioned-fragment-link-carrier){display:contents!important}',
        'empty-flex-item' => ':where(.blocks-engine-empty-flex-item){flex:000!important;width:0!important;min-width:0!important;margin-left:0!important;margin-right:0!important}',
        'list-navigation base' => '.wp-block-navigation.blocks-engine-list-navigation.wp-block-navigation-item.wp-block-navigation-link{display:list-item;font:inherit}',
        'list-navigation host' => '.wp-block-navigation.blocks-engine-list-navigation{display:flex!important}',
        'list-navigation mobile overlay' => '.wp-block-navigation.blocks-engine-list-navigation.wp-block-navigation__responsive-container.is-menu-open{background:rgba(0,0,0,.9)!important}',
        'nativeSearchTrigger' => 'flex:0024px!important;width:24px!important;height:80px!important',
        'nativeButton' => 'background-color:#fff!important;color:#000!important',
        'directFlexButton' => '.wp-block-buttons){display:block!important;gap:0!important;min-width:0;width:100%!important}',
        'fullWidthButton' => '.wp-block-buttons){display:block!important;gap:0!important;width:100%!important}',
    ] as $family => $needle) {
        assert_contains($needle, $css, "missing engine-support family {$family}");
    }

    transform_site_cleanup($tmp);
});

test('G2 transformer author-css is excluded and repo author selector reaches final theme CSS once', function () {
    [$project, $llm, $tmp] = transform_site_fixture(
        '<!doctype html><html><body><header><p>Header</p></header><main>'
        . '<section id="contract"><p class="contract-author-only" style="width:30rem">Contract</p></section>'
        . '</main><footer><p>Footer</p></footer></body></html>',
    );
    $project->writeText('design/site.css', ".contract-author-only{color:#123456}\n");

    transform_site_run($project, $llm);
    assert_true(
        !str_contains(transform_site_carried_css($project), '.contract-author-only'),
        'rewritten transformer author-css absent from both carried artifacts',
    );
    $style = transform_site_finish_styles($project);
    assert_eq(1, substr_count($style, '.contract-author-only'), 'repo author selector appears exactly once');

    transform_site_cleanup($tmp);
});

test('G6 unknown engine-support placement warns while known placements carry silently', function () {
    $warnings = [];
    $assets = [[
        [
            'kind' => 'css',
            'source' => 'engine-support',
            'stylesheet_placement' => 'before-author',
            'path' => 'assets/css/engine-before.css',
            'content' => ".engine-before{display:block}\n",
        ],
        [
            'kind' => 'css',
            'source' => 'engine-support',
            'stylesheet_placement' => 'future-author',
            'path' => 'assets/css/engine-future.css',
            'content' => ".engine-future{display:block}\n",
        ],
        [
            'kind' => 'css',
            'source' => 'engine-support',
            'stylesheet_placement' => 'after-author',
            'path' => 'assets/css/engine-after.css',
            'content' => ".engine-after{display:block}\n",
        ],
    ]];
    $method = new ReflectionMethod(TransformSiteStep::class, 'carriedCss');
    $method->setAccessible(true);
    $args = [$assets, &$warnings];

    $carried = $method->invokeArgs(null, $args);

    assert_eq(".engine-before{display:block}\n", $carried['before-author']);
    assert_eq(".engine-after{display:block}\n", $carried['after-author']);
    assert_true(!str_contains(implode("\n", $carried), 'engine-future'));
    assert_eq(1, count($warnings), 'known placements carry silently; unknown placement warns once');
    $warning = $warnings[0];
    foreach ([
        'file="assets/css/engine-future.css"',
        'block_path="transformer asset assets/css/engine-future.css"',
        'authored_value={"stylesheet_placement":"future-author"}',
        'delivered_value=removed',
        'disposition=dropped',
    ] as $context) {
        assert_contains($context, $warning, "unknown-placement warning contains {$context}");
    }
});

test('transform-site excludes design preview from the compiler bundle', function () {
    [$project, $llm, $tmp] = transform_site_fixture(
        '<!doctype html><html><body>'
        . '<header><p>Shared header</p></header>'
        . '<main><section id="hero"><h1>Homepage hero</h1></section></main>'
        . '<footer><p>Shared footer</p></footer>'
        . '</body></html>',
    );
    $cssMarker = 'preview-only-css-marker';
    $preview = '<!doctype html><html><head><style>:root{--content-size:800px;--wide-size:1280px}'
        . ".{$cssMarker}{width:777px}</style></head>"
        . '<body><header><nav></nav></header><main><section id="hero">'
        . '<dialog>PREVIEW-ONLY-UNSUPPORTED</dialog>'
        . '<img alt="AI_IMAGE: A baker at a stone oven | homepage hero | photorealistic | landscape">'
        . '</section></main></body></html>';
    $project->writeText('design/preview.html', $preview);

    transform_site_run($project, $llm);

    assert_eq($preview, $project->readText('design/preview.html'), 'transform leaves preview byte-identical');
    assert_eq(0, count($llm->calls), 'preview diagnostics never enter repair');
    $report = $project->readText(TransformArtifacts::REPORT);
    assert_eq([], $project->readJson(TransformArtifacts::REPORT)['fallback_codes']);
    assert_true(!str_contains($report, $cssMarker), 'preview CSS marker absent from report');
    assert_true(
        !str_contains(transform_site_carried_css($project), $cssMarker),
        'preview CSS marker absent from carried CSS',
    );
    $outputs = implode("\n", transform_site_outputs($project));
    assert_true(!str_contains($outputs, $cssMarker), 'preview CSS marker absent from transformed output');
    assert_true(
        !str_contains($outputs, 'PREVIEW-ONLY-UNSUPPORTED'),
        'preview bytes never enter transformed output',
    );
    transform_site_cleanup($tmp);
});

test('transform-site batches missing shell through legacy unit and keeps marker local', function () {
    $benign = 'BENIGN __TRANSFORM_SENTINEL__ COPY';
    [$project, $llm, $tmp] = transform_site_fixture(
        '<!doctype html><html><body><header><p>Header</p></header>'
        . '<main><section id="copy"><p>' . $benign . '</p></section></main></body></html>',
    );
    $llm->queueText(
        '<!-- wp:group {"tagName":"footer"} --><footer class="wp-block-group">'
        . '<!-- wp:paragraph --><p>STUB-FOOTER-MARKER</p><!-- /wp:paragraph -->'
        . '</footer><!-- /wp:group -->',
    );

    transform_site_run($project, $llm);

    assert_eq(1, $llm->completeBatchCalls);
    assert_contains('Build the site FOOTER template part', $llm->calls[0]['prompt']);
    assert_contains('STUB-FOOTER-MARKER', $project->readText('theme/parts/footer.html'));
    assert_contains($benign, $project->readText('theme/parts/page-home--copy.html'));
    $all = implode("\n", transform_site_outputs($project));
    assert_true(!str_contains($all, '__TRANSFORM_INTERNAL_'), 'no internal sentinel leaks');
    $warnings = implode("\n", $project->readJson('warnings.json')['transform-site'] ?? []);
    assert_contains('missing_shell_landmark', $warnings);
    assert_contains('design/home.html', $warnings);
    assert_contains('footer', $warnings);
    transform_site_cleanup($tmp);
});

test('transform-site repairs each unsupported diagnostic once in one batch', function () {
    [$project, $llm, $tmp] = transform_site_fixture(
        '<!doctype html><html><body><header><p>Header</p></header><main>'
        . '<section id="bad"><dialog data-probe="authored">Unsupported copy</dialog><p>Sibling</p></section>'
        . '</main><footer><p>Footer</p></footer></body></html>',
    );
    $llm->queueText('<div data-probe="repaired"><p>Supported copy</p></div>');

    $step = new TransformSiteStep(
        $llm,
        new PromptRenderer(repo_path('prompts')),
        'repair-model',
        0.37,
    );
    $step->run($project);

    assert_eq(1, $llm->completeBatchCalls);
    assert_eq(1, count($llm->calls));
    assert_eq('repair-model', $llm->calls[0]['opts']['model'] ?? null);
    assert_eq(0.37, $llm->calls[0]['opts']['temperature'] ?? null);
    assert_contains('<dialog data-probe="authored">Unsupported copy</dialog>', $llm->calls[0]['prompt']);
    assert_contains('SUPPORTED SLICE', $llm->calls[0]['prompt']);
    $part = $project->readText('theme/parts/page-home--bad.html');
    assert_contains('Supported copy', $part);
    assert_contains('Sibling', $part);
    $report = $project->readJson(TransformArtifacts::REPORT);
    assert_true(in_array('html_unsupported_element', $report['fallback_codes'], true));
    assert_eq('repaired', $report['repair_outcomes'][0]['disposition']);
    assert_eq([], $report['dropped_fragments']);
    transform_site_cleanup($tmp);
});

test('transform-site matches identical unsupported HTML to its exact sibling fragment', function () {
    [$project, $llm, $tmp] = transform_site_fixture(
        '<!doctype html><html><body><header><p>Header</p></header><main>'
        . '<section id="first"><dialog>SAME</dialog><p>FIRST-SIBLING</p></section>'
        . '<section id="second"><dialog>SAME</dialog><p>SECOND-SIBLING</p></section>'
        . '</main><footer><p>Footer</p></footer></body></html>',
    );
    $llm->queueText('<div><p>FIRST-REPAIRED</p></div>');
    $llm->queueText('<div><p>SECOND-REPAIRED</p></div>');

    transform_site_run($project, $llm);

    assert_eq(1, $llm->completeBatchCalls);
    assert_eq(2, count($llm->calls));
    $first = $project->readText('theme/parts/page-home--first.html');
    $second = $project->readText('theme/parts/page-home--second.html');
    assert_contains('FIRST-REPAIRED', $first);
    assert_contains('FIRST-SIBLING', $first);
    assert_true(!str_contains($first, 'SECOND-REPAIRED'));
    assert_contains('SECOND-REPAIRED', $second);
    assert_contains('SECOND-SIBLING', $second);
    assert_true(!str_contains($second, 'FIRST-REPAIRED'));
    assert_eq([], $project->readJson(TransformArtifacts::REPORT)['dropped_fragments']);
    transform_site_cleanup($tmp);
});

test('transform-site repair budget drops only exhausted fragment and reports actionable context', function () {
    [$project, $llm, $tmp] = transform_site_fixture(
        '<!doctype html><html><body><header><p>Header</p></header><main>'
        . '<section id="first"><dialog>First bad</dialog></section>'
        . '<section id="safe"><p>BYTE-STABLE-SAFE-SIBLING</p></section>'
        . '<section id="second"><dialog>Second bad</dialog></section>'
        . '</main><footer><p>Footer</p></footer></body></html>',
        [],
        1,
    );
    $llm->queueText('<div><p>First repaired</p></div>');

    transform_site_run($project, $llm);

    assert_eq(1, $llm->completeBatchCalls);
    assert_true($project->exists('theme/parts/page-home--first.html'));
    assert_true($project->exists('theme/parts/page-home--safe.html'));
    assert_true(!$project->exists('theme/parts/page-home--second.html'));
    assert_contains('BYTE-STABLE-SAFE-SIBLING', $project->readText('theme/parts/page-home--safe.html'));
    $report = $project->readJson(TransformArtifacts::REPORT);
    assert_eq(1, count($report['dropped_fragments']));
    $drop = $report['dropped_fragments'][0];
    assert_eq('design/home.html', $drop['source']);
    assert_contains('Second bad', $drop['authored_value']);
    assert_eq('removed', $drop['delivered_value']);
    assert_eq('dropped', $drop['disposition']);
    $warnings = implode("\n", $project->readJson('warnings.json')['transform-site'] ?? []);
    assert_contains('repair_budget', $warnings);
    assert_contains('authored_value', $warnings);
    assert_contains('delivered_value removed', $warnings);
    assert_contains('disposition dropped', $warnings);
    transform_site_cleanup($tmp);
});

test('transform-site drops an empty interior page explicitly while keeping the front page', function () {
    [$project, $llm, $tmp] = transform_site_fixture(
        '<!doctype html><html><body><header><p>Header</p></header>'
        . '<main><section id="home-content"><h1>Home</h1></section></main>'
        . '<footer><p>Footer</p></footer></body></html>',
        ['about' => '<main></main>'],
    );

    transform_site_run($project, $llm);

    assert_eq(['home'], array_column($project->readJson('pages.json')['pages'], 'slug'));
    $report = $project->readJson(TransformArtifacts::REPORT);
    assert_true(in_array('page_content_dropped', $report['fallback_codes'], true));
    assert_eq('design/about.html', $report['dropped_fragments'][0]['source']);
    assert_eq('removed', $report['dropped_fragments'][0]['delivered_value']);
    $warnings = implode("\n", $project->readJson('warnings.json')['transform-site'] ?? []);
    assert_contains('page_content_dropped', $warnings);
    assert_contains('disposition dropped', $warnings);
    transform_site_cleanup($tmp);
});

test('transform-site is byte-identical and same-tag sibling reorder cannot mis-target carrier CSS', function () {
    $prefix = '<!doctype html><html><body><header><p>Header</p></header><main><section id="order">';
    $suffix = '</section></main><footer><p>Footer</p></footer></body></html>';
    $home = $prefix
        . '<p style="width:111px">Alpha</p><p style="width:222px">Beta</p>'
        . $suffix;
    $reordered = $prefix
        . '<p style="width:222px">Beta</p><p style="width:111px">Alpha</p>'
        . $suffix;
    [$first, $firstLlm, $firstTmp] = transform_site_fixture($home);
    [$second, $secondLlm, $secondTmp] = transform_site_fixture($home);
    [$swapped, $swappedLlm, $swappedTmp] = transform_site_fixture($reordered);

    transform_site_run($first, $firstLlm);
    transform_site_run($second, $secondLlm);
    transform_site_run($swapped, $swappedLlm);

    assert_eq(transform_site_outputs($first), transform_site_outputs($second));
    foreach ([$first, $swapped] as $project) {
        $css = transform_site_carried_css($project);
        $part = $project->readText('theme/parts/page-home--order.html');
        assert_true(!str_contains($css, 'nth-of-type'));
        foreach (['Alpha' => '111px', 'Beta' => '222px'] as $text => $width) {
            assert_true(
                preg_match(
                    '/class="[^"]*\\b(be-inline-geometry-[a-z0-9-]+)\\b[^"]*"[^>]*>' . $text . '<\\/p>/',
                    $part,
                    $match,
                ) === 1,
                "carrier class remains attached to {$text}",
            );
            assert_contains('.' . $match[1] . '{width:' . $width, $css);
        }
    }
    transform_site_cleanup($firstTmp);
    transform_site_cleanup($secondTmp);
    transform_site_cleanup($swappedTmp);
});

test('transform-site explicitly preserves or reports styled core html and preserves sibling', function () {
    [$project, $llm, $tmp] = transform_site_fixture(
        '<!doctype html><html><body><header><p>Header</p></header><main>'
        . '<section id="raw"><table style="width:333px"><tr><td rowspan="2">A</td></tr><tr></tr></table></section>'
        . '<section id="safe"><p>SAFE-CORE-PARAGRAPH</p></section>'
        . '</main><footer><p>Footer</p></footer></body></html>',
    );

    transform_site_run($project, $llm);

    assert_contains('SAFE-CORE-PARAGRAPH', $project->readText('theme/parts/page-home--safe.html'));
    $report = $project->readJson(TransformArtifacts::REPORT);
    if ($project->exists('theme/parts/page-home--raw.html')) {
        $raw = $project->readText('theme/parts/page-home--raw.html');
        assert_contains('wp:html', $raw);
        assert_contains('width:333px', $raw);
    } else {
        assert_eq(1, count($report['dropped_fragments']));
        assert_eq('dropped', $report['dropped_fragments'][0]['disposition']);
        $warnings = implode("\n", $project->readJson('warnings.json')['transform-site'] ?? []);
        assert_contains('delivered_value removed', $warnings);
    }
    transform_site_cleanup($tmp);
});

test('transform-site reroutes only failed inner pages through scoped legacy planning and sections', function () {
    $home = '<!doctype html><html><body><header><p>TRANSFORMED-HEADER</p></header><main>'
        . '<section id="home-content"><h1>TRANSFORMED-HOME</h1></section></main>'
        . '<footer><p>TRANSFORMED-FOOTER</p></footer></body></html>';
    $inner = [
        'about' => '<main><section id="failed-source"><h1>FAILED-SOURCE-MUST-NOT-COMPILE</h1></section></main>',
        'team' => '<main><section id="team-intro"><h1>TRANSFORMED-TEAM</h1></section></main>',
    ];
    [$control, $controlLlm, $controlTmp] = transform_site_fixture($home, $inner);
    [$mixed, $mixedLlm, $mixedTmp] = transform_site_fixture($home, $inner);
    $mixed->writeText('design/about.failed', "Inner-page generation failed after one semantic repair.\n");

    transform_site_run($control, $controlLlm);
    assert_eq(0, $controlLlm->completeJsonBatchCalls, 'no marker keeps planner call behavior unchanged');
    assert_eq(0, $controlLlm->completeCalls, 'no marker adds no cache warm');
    assert_eq(0, $controlLlm->completeBatchCalls, 'no marker adds no section batch');

    $renderer = new PromptRenderer(repo_path('prompts'));
    $planner = new PagePlanStep($mixedLlm, $renderer);
    assert_eq(['about'], array_keys($planner->requestsForSlugs($mixed, ['about'])));

    $mixedLlm->queueJson(['sections' => [[
        'slug' => 'legacy-about',
        'title' => 'Legacy About',
        'type' => 'about',
        'purpose' => 'Explain the studio',
        'content_notes' => 'Introduce the team.',
        'layout_archetype' => 'centered-stack',
        'background' => 'base',
        'vertical_density' => 'standard',
        'handoff' => 'Between the site header and footer.',
    ]]]);
    $mixedLlm->queueText('OK');
    $mixedLlm->queueText(
        '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
        . '<!-- wp:paragraph --><p>LEGACY-ABOUT</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->',
    );

    transform_site_run($mixed, $mixedLlm);

    assert_eq(1, $mixedLlm->completeJsonBatchCalls, 'one scoped page-plan JSON batch, no repair');
    assert_eq(1, $mixedLlm->completeCalls, 'one existing section cache warm');
    assert_eq(1, $mixedLlm->completeBatchCalls, 'one scoped section batch');
    assert_eq(3, count($mixedLlm->calls), 'one plan + one warm + one failed-page section request');
    assert_eq('Warm the cached section context.', $mixedLlm->calls[1]['prompt']);
    $allPrompts = implode("\n", array_column($mixedLlm->calls, 'prompt'));
    assert_true(!str_contains($allPrompts, 'Build the site HEADER template part'));
    assert_true(!str_contains($allPrompts, 'Build the site FOOTER template part'));

    assert_eq(['home', 'about', 'team'], array_column($mixed->readJson('pages.json')['pages'], 'slug'));
    assert_true(!$mixed->exists('theme/parts/page-about--failed-source.html'));
    assert_contains('LEGACY-ABOUT', $mixed->readText('theme/parts/page-about--legacy-about.html'));
    foreach ([
        'theme/parts/header.html',
        'theme/parts/footer.html',
        'theme/parts/page-home--home-content.html',
        'theme/parts/page-team--team-intro.html',
    ] as $path) {
        assert_eq($control->readText($path), $mixed->readText($path), "{$path} remains byte-identical");
    }

    $warnings = implode("\n", $mixed->readJson('warnings.json')['transform-site'] ?? []);
    assert_contains('source design/about.failed', $warnings);
    assert_contains('page[slug=about]', $warnings);
    assert_contains('theme/parts/page-about--legacy-about.html', $warnings);
    assert_contains('disposition rerouted', $warnings);
    $report = $mixed->readJson(TransformArtifacts::REPORT);
    assert_true(in_array('inner_page_legacy_reroute', $report['fallback_codes'], true));
    assert_eq([], $report['dropped_fragments']);

    transform_site_cleanup($controlTmp);
    transform_site_cleanup($mixedTmp);
});

test('transform-site degrades scoped legacy generation failure without aborting survivor delivery', function () {
    [$project, $llm, $tmp] = transform_site_fixture(
        '<!doctype html><html><body><header><p>Header</p></header><main>'
        . '<section id="home-content"><h1>SURVIVING-HOME</h1></section></main>'
        . '<footer><p>Footer</p></footer></body></html>',
        ['about' => '<main><section id="about"><h1>Stale About</h1></section></main>'],
    );
    $project->writeText('design/about.failed', "Inner-page generation failed after one semantic repair.\n");

    transform_site_run($project, $llm);

    assert_eq(['home'], array_column($project->readJson('pages.json')['pages'], 'slug'));
    assert_contains('SURVIVING-HOME', $project->readText('theme/parts/page-home--home-content.html'));
    assert_true(!$project->exists('theme/parts/page-about--about.html'));
    $warnings = $project->readJson('warnings.json');
    $pagePlanWarning = implode("\n", $warnings['page-plan'] ?? []);
    assert_contains('file pages.json', $pagePlanWarning);
    assert_contains('block_path pages[slug=about].sections', $pagePlanWarning);
    assert_contains('authored_value scoped legacy plan unavailable', $pagePlanWarning);
    assert_contains('delivered_value deterministic fallback section', $pagePlanWarning);
    assert_contains('disposition degraded', $pagePlanWarning);
    $sectionsWarning = implode("\n", $warnings['sections'] ?? []);
    assert_contains('file theme/parts/page-about--content.html', $sectionsWarning);
    assert_contains('block_path pages[slug=about].sections[slug=content]', $sectionsWarning);
    assert_contains('authored_value scoped legacy section batch unavailable', $sectionsWarning);
    assert_contains('delivered_value removed', $sectionsWarning);
    assert_contains('disposition dropped', $sectionsWarning);
    $transformWarning = implode("\n", $warnings['transform-site'] ?? []);
    assert_contains('source design/about.failed', $transformWarning);
    assert_contains('selector page[slug=about]', $transformWarning);
    assert_contains('block_path pages.json', $transformWarning);
    assert_contains('diagnostic_code inner_page_legacy_reroute_failed', $transformWarning);
    assert_contains('authored_value failed design marker', $transformWarning);
    assert_contains('delivered_value removed', $transformWarning);
    assert_contains('disposition dropped', $transformWarning);
    $report = $project->readJson(TransformArtifacts::REPORT);
    assert_true(in_array('inner_page_legacy_reroute_failed', $report['fallback_codes'], true));
    assert_eq('inner_page_legacy_reroute_failed', $report['dropped_fragments'][0]['diagnostic_code']);
    assert_eq('removed', $report['dropped_fragments'][0]['delivered_value']);
    assert_eq('dropped', $report['dropped_fragments'][0]['disposition']);
    transform_site_cleanup($tmp);
});

test('transform-site threads inline <style> shape rules into image aspectRatio/scale', function () {
    // The authored crop lives only in a `.hero-frame img` descendant rule inside
    // the page's <style>, which extractPage strips. transform-site must still
    // thread it through compileFragment so the image gains native block geometry.
    [$project, $llm, $tmp] = transform_site_fixture(
        '<!doctype html><html><head><style>'
        . '.hero-frame img { aspect-ratio: 4 / 3; object-fit: cover; }'
        . '</style></head><body>'
        . '<header><p>Header</p></header>'
        . '<section id="hero"><figure class="hero-figure"><div class="hero-frame">'
        . '<img src="hero.jpg" alt="Hero portrait"></div></figure></section>'
        . '<footer><p>Footer</p></footer>'
        . '</body></html>',
    );

    transform_site_run($project, $llm);

    $part = $project->readText('theme/parts/page-home--hero.html');
    assert_contains('"aspectRatio":"4/3"', $part);
    assert_contains('"scale":"cover"', $part);
    transform_site_cleanup($tmp);
});

test('transform-site threads shared site.css shape rules into image aspectRatio/scale', function () {
    // Same promotion, but the shape rule is carried by the shared design
    // stylesheet (design/site.css) rather than the page's inline <style>.
    [$project, $llm, $tmp] = transform_site_fixture(
        '<!doctype html><html><body>'
        . '<header><p>Header</p></header>'
        . '<section id="hero"><figure class="hero-figure"><div class="hero-frame">'
        . '<img src="hero.jpg" alt="Hero portrait"></div></figure></section>'
        . '<footer><p>Footer</p></footer>'
        . '</body></html>',
    );
    $project->writeText('design/site.css', ".hero-frame img { aspect-ratio: 3 / 2; object-fit: contain; }\n");

    transform_site_run($project, $llm);

    $part = $project->readText('theme/parts/page-home--hero.html');
    assert_contains('"aspectRatio":"3/2"', $part);
    assert_contains('"scale":"contain"', $part);
    transform_site_cleanup($tmp);
});
