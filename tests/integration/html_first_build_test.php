<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\BlockFixers;
use Automattic\SiteBuild\Package;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\SiteBuilder;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepComposition;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\ThemeValidator;

const HTML_FIRST_RULELESS_TRANSFORMER_SIGNAL_CLASSES = [
    // Consumed as a code signal by #259; the transformer intentionally ships no matching rule.
    'blocks-engine-css-owned-layout',
];

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
        . 'body { margin: 0; font-family: system-ui, sans-serif; }'
        . '.site-header{display:flex;align-items:center;gap:1rem}.brand{font-weight:700;text-decoration:none}'
        . '.maintenance-loop{display:grid}.maintenance-loop li > span{display:inline-block;width:10px;max-width:10px;height:10px;border-radius:50%;background:#e08a3c}</style>'
        . '</head><body><header class="site-header"><a class="brand" href="/">Hearth &amp; Crumb</a>'
        . '<nav aria-label="Primary"><a href="/">Home</a></nav></header>'
        . '<main><section id="hero"><h1 class="has-display-font-size">' . $marker . '</h1>'
        . '<ul class="maintenance-loop"><li><span>Fresh daily</span></li></ul>'
        . '<img alt="AI_IMAGE: A baker sliding a sourdough loaf into a stone oven, viewed from counter height | homepage hero beside the primary headline | photorealistic | landscape">'
        . '</section></main></body></html>';
}

function html_first_nav_defect_preview_document(): string
{
    return str_replace(
        '<header class="site-header"><a class="brand" href="/">Hearth &amp; Crumb</a>'
            . '<nav aria-label="Primary"><a href="/">Home</a></nav></header>',
        '<header><div class="header-shell"><nav aria-label="Primary">'
            . '<a class="brand" href="#hero">Hearth &amp; Crumb</a>'
            . '<a href="#hero">Home</a>'
            . '<a href="#hero">About</a>'
            . '</nav></div></header>',
        html_first_preview_document('NAV-LINK-HERO'),
    );
}

/**
 * The same header as html_first_nav_defect_preview_document(), but with the menu
 * in a `<ul>` — which is what every project in the corpus actually authors.
 * The list shape takes a different path through the transformer's navigation
 * pattern than a run of bare anchors, so a gate built only on the bare-anchor
 * shape cannot see a regression in this one.
 */
function html_first_nav_list_preview_document(): string
{
    return str_replace(
        '<header class="site-header"><a class="brand" href="/">Hearth &amp; Crumb</a>'
            . '<nav aria-label="Primary"><a href="/">Home</a></nav></header>',
        '<header><div class="header-shell"><nav aria-label="Primary">'
            . '<a class="brand" href="#hero">Hearth &amp; Crumb</a>'
            . '<ul class="navlinks">'
            . '<li><a class="is-current" href="#hero" aria-current="page">Home</a></li>'
            . '<li><a href="#hero">About</a></li>'
            . '</ul>'
            . '</nav></div></header>',
        html_first_preview_document('NAV-LINK-HERO'),
    );
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

function html_first_foundation_cover_fixture_step(): Step
{
    return new class implements Step {
        public function id(): string
        {
            return 'foundation-cover-fixture';
        }

        public function label(): string
        {
            return 'Install full-pipeline cover fixture';
        }

        public function declaration(): StepDeclaration
        {
            return new StepDeclaration(
                id: $this->id(),
                label: $this->label(),
                reads: ['theme/parts/*'],
                writes: ['theme/parts/*'],
                concurrent: false,
            );
        }

        public function run(Project $project): void
        {
            $project->writeText(
                'theme/parts/page-home--story.html',
                '<!-- wp:group {"tagName":"section","anchor":"story"} -->'
                    . '<section id="story" class="wp-block-group">'
                    . '<!-- wp:cover {"overlayColor":"contrast","dimRatio":100} -->'
                    . '<div class="wp-block-cover has-contrast-background-color has-background-dim-100 has-background-dim">'
                    . '<span aria-hidden="true" class="wp-block-cover__background has-contrast-background-color has-background-dim-100 has-background-dim"></span>'
                    . '<div class="wp-block-cover__inner-container">'
                    . '<!-- wp:paragraph --><p>FOUNDATION-COVER</p><!-- /wp:paragraph -->'
                    . '</div></div><!-- /wp:cover -->'
                    . '</section><!-- /wp:group -->',
            );
        }
    };
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

/** @return array<string,string> design/*.html path => sha1 of its bytes */
function html_first_design_html_hashes(Project $project): array
{
    $hashes = [];
    foreach (glob($project->path('design') . '/*.html') ?: [] as $abs) {
        $rel = 'design/' . basename($abs);
        $hashes[$rel] = sha1($project->readText($rel));
    }
    return $hashes;
}

function html_first_delivered_markup(Project $project): string
{
    $chunks = [];
    foreach (['plugin/pages', 'theme/parts'] as $directory) {
        foreach (glob($project->path($directory) . '/*.html') ?: [] as $path) {
            $chunks[] = file_get_contents($path) ?: '';
        }
    }
    return implode("\n", $chunks);
}

/** @return list<string> */
function html_first_transformer_marker_classes(string $markup): array
{
    preg_match_all('/\bclass=(["\'])(.*?)\1/s', $markup, $matches);
    $classes = [];
    foreach ($matches[2] ?? [] as $classList) {
        foreach (preg_split('/\s+/', trim($classList)) ?: [] as $class) {
            if (preg_match(
                '/^(?:be-inline-geometry-[a-z0-9-]+'
                    . '|blocks-engine-(?:synthetic-paragraph|synthetic-anchor-undecorated|inline-layout-carrier'
                    . '|css-owned-flow|css-owned-grid|css-owned-layout|css-owned-layout-item'
                    . '|positioned-fragment-link-carrier|empty-flex-item|list-navigation'
                    . '|control-[a-z0-9-]+|specificity-class-[a-z0-9-]+))$/i',
                $class,
            ) === 1) {
                $classes[$class] = true;
            }
        }
    }
    $classes = array_keys($classes);
    sort($classes);
    return $classes;
}

function html_first_unstyled_mark_count(string $markup, string $css): int
{
    preg_match_all('/<mark\b[^>]*>/i', $markup, $matches);
    $normalizedCss = preg_replace('/\s+/', '', $css) ?? '';
    $hasMarkerReset = str_contains(
        $normalizedCss,
        ':where(mark)[style*="--blocks-engine-richtext-marker:"]{background-color:transparent;color:inherit}',
    );
    $unstyled = 0;
    foreach ($matches[0] ?? [] as $tag) {
        $hasInlineBackground = preg_match('/\bstyle="[^"]*background-color\s*:/i', $tag) === 1
            || preg_match("/\\bstyle='[^']*background-color\\s*:/i", $tag) === 1;
        $hasMatchingReset = str_contains($tag, '--blocks-engine-richtext-marker:') && $hasMarkerReset;
        if (!$hasInlineBackground && !$hasMatchingReset) {
            $unstyled++;
        }
    }
    return $unstyled;
}

test('--from resumes the deterministic tail against on-disk artifacts with no LLM', function () {
    $tmp = sys_get_temp_dir() . '/builder_html_first_resume_' . uniqid();
    $previous = getenv('SITE_BUILD_LEGACY');
    putenv('SITE_BUILD_LEGACY');
    try {
        $llm = new FakeLlm();
        html_first_queue_success($llm, html_first_site_spec([
            ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome visitors', 'children' => []],
        ]), html_first_home_body());

        $builder = html_first_integration_builder($llm, $tmp);
        $project = $builder->createProject('A neighborhood bakery', 'demo');
        $meta = $project->readJson('meta.json');
        $meta['design_candidates'] = 1;
        $meta['critique_rounds'] = 1;
        $project->writeJson('meta.json', $meta);

        // A full build materializes design/*.html, site.css, theme parts, etc.
        $builder->pipeline()->runThrough($project);

        $designBefore = html_first_design_html_hashes($project);
        assert_true($designBefore !== [], 'the full build produced design/*.html to resume from');
        $callsAfterBuild = count($llm->calls);
        $sentinel = "/* SENTINEL-NO-TAIL */\n";

        // A sentinel that lacks the deterministic wrap-policy tail: page-styles
        // must rewrite theme/style.css during the resume for the tail to return.
        $resume = static function () use ($builder, $project, $sentinel): string {
            $project->writeText('theme/style.css', $sentinel);
            // Fresh pipeline, resuming at transform-site through page-styles.
            $builder->pipeline()->runThrough($project, 'page-styles', fromId: 'transform-site');
            return $project->readText('theme/style.css');
        };

        $styleFirst = $resume();

        // (a) The deterministic tail touches no LLM: zero calls added on resume.
        assert_eq($callsAfterBuild, count($llm->calls), 'resume made zero new LLM calls');
        // (b) The design HTML the resume reads is left byte-for-byte unchanged.
        assert_eq($designBefore, html_first_design_html_hashes($project), 'design/*.html bytes unchanged by resume');
        // (c) page-styles (re)wrote theme/style.css: the tail is back, sentinel gone.
        assert_true($styleFirst !== $sentinel, 'theme/style.css was rewritten during resume');
        assert_contains('hyphens: none;', $styleFirst, 'page-styles re-merged the deterministic wrap policy');

        // (d) A second identical resume from the same inputs is byte-identical.
        $styleSecond = $resume();
        assert_eq($styleFirst, $styleSecond, 'a repeated resume is deterministic (byte-identical style.css)');
        assert_eq($callsAfterBuild, count($llm->calls), 'the repeat resume also made zero LLM calls');
        assert_eq($designBefore, html_first_design_html_hashes($project), 'design/*.html still unchanged after the repeat');
    } finally {
        $previous === false
            ? putenv('SITE_BUILD_LEGACY')
            : putenv('SITE_BUILD_LEGACY=' . $previous);
        if (is_dir($tmp)) {
            exec('rm -rf ' . escapeshellarg($tmp));
        }
    }
});

test('G4 HTML-first output gives every transformer marker class matching final theme CSS', function () {
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

        $style = $project->readText('theme/style.css');
        $deliveredMarkup = html_first_delivered_markup($project);
        assert_contains('<mark', $deliveredMarkup, 'fixture emits a real richtext-marker mark');
        assert_contains(
            'blocks-engine-synthetic-paragraph',
            $deliveredMarkup,
            'fixture emits a real synthetic paragraph',
        );
        assert_contains('blocks-engine-css-owned-flow', $deliveredMarkup, 'fixture emits a real css-owned-flow group');
        $markerClasses = html_first_transformer_marker_classes($deliveredMarkup);
        assert_true($markerClasses !== [], 'fixture emits transformer marker classes');
        foreach (HTML_FIRST_RULELESS_TRANSFORMER_SIGNAL_CLASSES as $signalClass) {
            assert_true(
                in_array($signalClass, $markerClasses, true),
                "markup retains ruleless transformer code signal {$signalClass}",
            );
        }
        foreach ($markerClasses as $class) {
            if (in_array($class, HTML_FIRST_RULELESS_TRANSFORMER_SIGNAL_CLASSES, true)) {
                continue;
            }
            assert_true(
                preg_match('/\.' . preg_quote($class, '/') . '(?![a-z0-9_-])/i', $style) === 1,
                "transformer marker class {$class} has matching final theme CSS",
            );
        }
        assert_true(substr_count(strtolower($deliveredMarkup), '<mark') > 0, 'G5 control contains mark elements');
        assert_eq(
            0,
            html_first_unstyled_mark_count($deliveredMarkup, $style),
            'G5 mark elements without inline background-color or matching stylesheet reset',
        );

        foreach ([
            'design/preview.html',
            'design/home-body.html',
            'design/home.html',
            'design/site.css',
            'design/transform-report.json',
            'design/transformer-carried-before-author.css',
            'design/transformer-carried-after-author.css',
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
        assert_contains('hyphens: none;', $style);
        assert_eq(1, substr_count($style, 'Wrap at spaces only'), 'the wrap policy is merged exactly once');
        assert_true(!str_contains($style, 'break-all'), 'no mid-word break rule ships');

        $emptyButtonShellCount = 0;
        foreach (glob($project->path('plugin/pages') . '/*.html') ?: [] as $pagePath) {
            $pageMarkup = $project->readText('plugin/pages/' . basename($pagePath));
            $matchCount = preg_match_all('/<div class="wp-block-buttons[^"]*"><\/div>/', $pageMarkup);
            assert_true($matchCount !== false, 'empty wp:buttons shell count regex is valid');
            $emptyButtonShellCount += $matchCount;
        }
        assert_eq(0, $emptyButtonShellCount, 'integration output contains no empty wp:buttons shells');
        echo "G4 count: {$emptyButtonShellCount}\n";

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

test('G5 HTML-first build delivers only resolved header navigation destinations', function () {
    $tmp = sys_get_temp_dir() . '/builder_html_first_nav_links_' . uniqid();
    $previous = getenv('SITE_BUILD_LEGACY');
    $previousHeaderArchetype = getenv('HEADER_ARCHETYPE');
    putenv('SITE_BUILD_LEGACY');
    putenv('HEADER_ARCHETYPE=standard-row');
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
            html_first_nav_defect_preview_document(),
        );
        $llm->queueText(
            '<main><section id="team"><h1>About</h1><p>HTML-FIRST-ABOUT</p></section></main>',
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

        $header = $project->readText('theme/parts/header.html');
        assert_contains('"url":"/"', $header, 'brand link inherits the front page root');
        assert_true(
            !str_contains($header, '#hero'),
            'shared nav keeps no front-page placeholder, bare or path-prefixed',
        );
        assert_contains('"url":"/about/"', $header, 'About label resolves to the site page path');

        $emptyHrefCount = 0;
        foreach ($project->markupFiles() as $file) {
            $markup = (string) file_get_contents($file);
            $emptyHrefCount += preg_match_all('/\bhref\s*=\s*(["\'])#\1/i', $markup);
            assert_eq(0, preg_match_all('/"url"\s*:\s*"#"/i', $markup), 'delivered block URL "#" count');
        }
        $headerBareAnchorCount = preg_match_all(
            '/\bhref\s*=\s*(["\'])#[^"\']+\1/i',
            $header,
        ) + preg_match_all('/"url"\s*:\s*"#[^"]+"/i', $header);
        assert_eq(0, $emptyHrefCount, 'delivered markup href="#" count');
        assert_eq(0, $headerBareAnchorCount, 'shared header bare #anchor count');
    } finally {
        $previous === false
            ? putenv('SITE_BUILD_LEGACY')
            : putenv('SITE_BUILD_LEGACY=' . $previous);
        $previousHeaderArchetype === false
            ? putenv('HEADER_ARCHETYPE')
            : putenv('HEADER_ARCHETYPE=' . $previousHeaderArchetype);
        if (is_dir($tmp)) {
            exec('rm -rf ' . escapeshellarg($tmp));
        }
    }
});

/**
 * G5's fixture authors its menu as a run of bare anchors. Every project in
 * the corpus authors a `<ul>`, which reaches core/navigation by a different
 * route — silver-summit's shipped header carried three unresolved `#hero`
 * destinations and a baked `aria-current` while G5 was green.
 */
test('G6 HTML-first build resolves a list-shaped header navigation too', function () {
    $tmp = sys_get_temp_dir() . '/builder_html_first_nav_links_' . uniqid();
    $previous = getenv('SITE_BUILD_LEGACY');
    $previousHeaderArchetype = getenv('HEADER_ARCHETYPE');
    putenv('SITE_BUILD_LEGACY');
    putenv('HEADER_ARCHETYPE=standard-row');
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
            html_first_nav_list_preview_document(),
        );
        $llm->queueText(
            '<main><section id="team"><h1>About</h1><p>HTML-FIRST-ABOUT</p></section></main>',
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

        $header = $project->readText('theme/parts/header.html');
        assert_contains('"url":"/"', $header, 'brand link inherits the front page root');
        // Every MENU destination is resolved. The brand anchor is hoisted out of
        // the menu into its own block and still resolves to `/#hero` rather than
        // `/` — a separate resolver behaviour for raw anchors, and the reason this
        // guard names menu destinations instead of scanning the whole part.
        preg_match_all('/"url":"([^"]*)"/', $header, $psMenuUrls);
        assert_eq(
            [],
            array_values(array_filter($psMenuUrls[1], static fn (string $url): bool => str_contains($url, '#hero'))),
            'no menu destination is left on a front-page placeholder',
        );
        assert_true(
            !str_contains($header, 'href="#hero"'),
            'no anchor is left on a bare unresolved fragment',
        );
        assert_contains('"url":"/about/"', $header, 'About label resolves to the site page path');
        assert_true(
            !str_contains($header, 'aria-current'),
            'a shared header ships no design-time current-page marker',
        );

        $emptyHrefCount = 0;
        foreach ($project->markupFiles() as $file) {
            $markup = (string) file_get_contents($file);
            $emptyHrefCount += preg_match_all('/\bhref\s*=\s*(["\'])#\1/i', $markup);
            assert_eq(0, preg_match_all('/"url"\s*:\s*"#"/i', $markup), 'delivered block URL "#" count');
        }
        $headerBareAnchorCount = preg_match_all(
            '/\bhref\s*=\s*(["\'])#[^"\']+\1/i',
            $header,
        ) + preg_match_all('/"url"\s*:\s*"#[^"]+"/i', $header);
        assert_eq(0, $emptyHrefCount, 'delivered markup href="#" count');
        assert_eq(0, $headerBareAnchorCount, 'shared header bare #anchor count');
    } finally {
        $previous === false
            ? putenv('SITE_BUILD_LEGACY')
            : putenv('SITE_BUILD_LEGACY=' . $previous);
        $previousHeaderArchetype === false
            ? putenv('HEADER_ARCHETYPE')
            : putenv('HEADER_ARCHETYPE=' . $previousHeaderArchetype);
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

        $composition = StepComposition::default(
            $llm,
            new PromptRenderer(Package::promptsDir()),
            blockFixer: BlockFixers::default(),
        )->insertAfter('transform-site', html_first_foundation_cover_fixture_step());
        $builder->pipeline($composition)->runThrough($project);

        $theme = $project->readJson('theme/theme.json');
        assert_eq('1366px', $theme['settings']['layout']['contentSize'] ?? null);
        assert_eq('1280px', $theme['settings']['layout']['wideSize'] ?? null);
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

        $home = BlockMarkup::parse($project->readText('plugin/pages/home.html'));
        $coverIndex = null;
        foreach ($home->indices() as $index) {
            if ($home->name($index) === 'cover') {
                $coverIndex = $index;
                break;
            }
        }
        assert_true($coverIndex !== null, 'full pipeline delivers the generated cover block');
        $coverAttrs = $home->attrs($coverIndex) ?? [];
        assert_eq('full', $coverAttrs['align'] ?? null, 'delivered cover stays full bleed after FixBlocks');
        assert_eq(
            ['type' => 'constrained'],
            $coverAttrs['layout'] ?? null,
            'delivered cover inner content uses the declared content column',
        );
        assert_contains(
            'is-layout-constrained',
            $coverAttrs['className'] ?? '',
            'delivered cover keeps the constrained-layout serializer bridge',
        );
        $coverHtml = $home->ownHtml($coverIndex);
        assert_contains('alignfull', $coverHtml, 'delivered cover wrapper stays edge-to-edge');
        assert_contains(
            'is-layout-constrained',
            $coverHtml,
            'FixBlocks serializes the constrained-layout bridge on the delivered wrapper',
        );
        assert_contains(
            '<div class="wp-block-cover__inner-container">',
            $coverHtml,
            'delivered cover keeps the core inner content container',
        );
        assert_contains(
            'FOUNDATION-COVER',
            $home->innerHtml($coverIndex),
            'cover inner content survives full pipeline',
        );

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
