<?php
declare(strict_types=1);

use Automattic\SiteBuild\NodeBlockFixer;
use Automattic\SiteBuild\Package;
use Automattic\SiteBuild\SiteBuilder;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\ThemeValidator;

/**
 * Full step sequence as a deterministic integration test: runs the real
 * pipeline via SiteBuilder with a FakeLlm scripted with realistic canned
 * outputs for a TWO-PAGE site, then asserts the produced theme + content
 * plugin pass structural validation.
 */

function make_integration_builder(FakeLlm $llm, string $outputRoot): SiteBuilder
{
    return new SiteBuilder(
        llm: $llm,
        promptsDir: Package::promptsDir(),
        outputRoot: $outputRoot,
        blockFixer: NodeBlockFixer::default(),
        models: [],
    );
}

test('full pipeline produces a structurally valid theme and content plugin', function () {
    $tmp = sys_get_temp_dir() . '/builder_int_' . uniqid();
    $llm = new FakeLlm();

    // refine-prompt (text) — fast small-model clean-up of the raw prompt, runs first
    $llm->queueText('A cozy neighborhood bakery selling artisan bread and pastries to local residents, with a warm and rustic feel.');
    // site-spec (json) — factual info only, no design fields; carries the page tree
    $llm->queueJson([
        'name' => 'Hearth & Crumb', 'slug' => 'hearth-crumb',
        'title' => 'Hearth & Crumb', 'site_type' => 'bakery storefront',
        'topic' => 'artisan bread and pastries', 'area' => 'bakery',
        'audience' => 'neighborhood locals', 'visual_vibe' => 'warm and rustic',
        'language' => 'en', 'persona_name' => '',
        'email_domain' => 'hearthandcrumb.com', 'invented' => ['name', 'email_domain'],
        'sections' => ['Hero', 'Specials', 'About'],
        'pages' => [
            ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome visitors and set the tone', 'children' => []],
            ['title' => 'Menu', 'slug' => 'menu', 'purpose' => 'Everything we bake, by category', 'children' => []],
        ],
    ]);
    // design-direction-seeds (json) — 4 cheap concept titles; ONE is picked at
    // random and expanded by the design-direction call below. Runs after
    // site-spec, before the concurrent group.
    $llm->queueJson(['seeds' => ['Hearth & Grain', 'Flour & Steel', 'Sugar Bloom', 'Midnight Levain']]);
    // design-direction (json) — the expanded direction, read by
    // theme-json/page-plan/sections.
    $llm->queueJson(['direction' => [
        'title' => 'Hearth & Grain',
        'description' => 'Editorial-magazine warmth, 1970s print feel. Earthy neutrals, one electric accent; serif display over grotesque body. Avoid the centered all-sans hero.',
        'palette' => ['base' => '#FDF6EC', 'contrast' => '#2B2118', 'primary' => '#8A5A2B', 'secondary' => '#CC9988', 'accent' => '#E08A3C'],
        'type' => ['heading' => 'Fraunces 700/900', 'body' => 'Source Sans 3 400/600'],
        'image_grade' => 'warm kodachrome color, soft golden light, gentle film grain',
        'signature_device' => 'hairline rules with small caps folios',
        'hero_composition' => 'full-bleed bakery photo, headline pinned lower-left',
    ]]);
    // Concurrent group, request order is [theme-json, page-plan(home), page-plan(menu)]:
    // theme-json (json) — design decisions made inline, no design.md
    $llm->queueJson([
        'settings' => [
            'color' => ['palette' => [
                ['slug' => 'base', 'color' => '#fdf6ec', 'name' => 'Base'],
                ['slug' => 'contrast', 'color' => '#2b2118', 'name' => 'Contrast'],
                ['slug' => 'primary', 'color' => '#8a5a2b', 'name' => 'Primary'],
                ['slug' => 'secondary', 'color' => '#cc9988', 'name' => 'Secondary'],
                ['slug' => 'accent', 'color' => '#e08a3c', 'name' => 'Accent'],
            ]],
            'typography' => ['fontFamilies' => [
                ['slug' => 'heading', 'fontFamily' => 'Fraunces, serif', 'name' => 'Heading'],
                ['slug' => 'body', 'fontFamily' => 'Source Sans 3, sans-serif', 'name' => 'Body'],
            ]],
        ],
    ]);
    // page-plan home (json) — ordered list of the front page's sections
    $llm->queueJson(['sections' => [
        ['slug' => 'hero', 'title' => 'Hero', 'type' => 'hero', 'layout_archetype' => 'full-bleed-cover', 'background' => 'image', 'handoff' => 'Between the site header above and the base specials grid below.'],
        ['slug' => 'specials', 'title' => 'Specials', 'type' => 'features', 'layout_archetype' => 'equal-card-grid', 'background' => 'base', 'handoff' => 'Between the image hero above and the footer below.'],
    ]]);
    // page-plan menu (json) — the interior page's sections
    $llm->queueJson(['sections' => [
        ['slug' => 'menu-hero', 'title' => 'Our Menu', 'type' => 'hero', 'layout_archetype' => 'centered-stack', 'background' => 'tinted', 'handoff' => 'Between the site header above and the base bread list below.'],
        ['slug' => 'breads', 'title' => 'Breads', 'type' => 'features', 'layout_archetype' => 'list-with-thumbnails', 'background' => 'base', 'handoff' => 'Between the tinted page hero above and the footer below.'],
    ]]);
    // sections (raw markup) — header, footer, then home's parts, then menu's, in requests() order
    $hdr = '<!-- wp:group --><div class="wp-block-group"><!-- wp:site-title /--></div><!-- /wp:group -->';
    $ftr = '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>(c) Hearth</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
    $llm->queueText($hdr);
    $llm->queueText($ftr);
    $llm->queueText(
        '<!-- wp:group {"style":{"spacing":{"margin":{"top":"0"}}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group" style="margin-top:0">'
        . '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Hero</h1><!-- /wp:heading -->'
        . '</div><!-- /wp:group -->'
    );
    // The specials section opts into a layout utility class (hover-lift) so the
    // page-styles step downstream has something to style — and we can assert the
    // class survives the block-fixer AND is still seen after the markup moves
    // into the content plugin.
    $llm->queueText(
        '<!-- wp:group {"className":"hover-lift","style":{"spacing":{"margin":{"top":"0"}}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group hover-lift" style="margin-top:0">'
        . '<!-- wp:heading --><h2>Specials</h2><!-- /wp:heading -->'
        . '</div><!-- /wp:group -->'
    );
    $llm->queueText('<!-- wp:heading --><h2>Our Menu</h2><!-- /wp:heading -->');
    $llm->queueText(
        '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:heading --><h2>Breads</h2><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>See you at <a href="/">home</a>.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->'
    );
    // page-styles (text) — runs after assemble-pages, sees hover-lift in the
    // PLUGIN content markup, and returns the CSS appendix for it.
    $llm->queueText(
        ".hover-lift {\n    transition: transform 0.25s ease;\n}\n"
        . ".hover-lift:hover {\n    transform: translateY(-6px);\n    box-shadow: var(--wp--preset--shadow--natural);\n}"
    );
    // fonts-php (text) — the generated fonts module; must cover the scanned
    // 400/700 floor for both theme.json families or the step falls back.
    $llm->queueText(
        "<?php\nadd_action('enqueue_block_assets', function () {\n"
        . "    wp_enqueue_style('preconnect-gfonts', 'https://fonts.gstatic.com', array(), null);\n"
        . "    wp_enqueue_style('demo-fonts', 'https://fonts.googleapis.com/css2?family=Fraunces:wght@400;700&family=Source+Sans+3:wght@400;700&display=swap', array(), null);\n"
        . "});\n"
    );

    $builder = make_integration_builder($llm, $tmp);
    $project = $builder->createProject('A cozy neighborhood bakery', 'demo', multiPage: true);
    $builder->pipeline()->runThrough($project);

    $problems = ThemeValidator::validate($project);
    assert_eq([], $problems, 'theme should validate; problems: ' . implode('; ', $problems));
    $layoutWarnings = ThemeValidator::layoutWarnings($project);
    assert_eq([], $layoutWarnings, 'theme should have no layout warnings: ' . implode('; ', $layoutWarnings));

    // Identity propagated end to end — theme AND content plugin.
    assert_contains('Theme Name: Hearth & Crumb', $project->readText('theme/style.css'));
    assert_contains('Plugin Name: Hearth & Crumb Content', $project->readText('plugin/site-content.php'));
    assert_eq(3, $project->readJson('theme/theme.json')['version']);

    // Every page's content was inlined into the plugin in plan order, and the
    // transient page parts left the theme.
    $home = $project->readText('plugin/pages/home.html');
    assert_contains('>Hero<', $home);
    assert_contains('hover-lift', $home);
    assert_true(strpos($home, 'Hero') < strpos($home, 'Specials'), 'home sections in plan order');
    assert_contains('>Breads<', $project->readText('plugin/pages/menu.html'));
    assert_true(!$project->exists('theme/parts/page-home--hero.html'), 'transient parts removed from the theme');
    assert_true($project->exists('theme/parts/header.html'), 'chrome parts stay in the theme');

    // The seeder manifest fronts the homepage and orders the pages.
    $manifest = $project->readJson('plugin/pages.json');
    assert_eq(['home', 'menu'], array_column($manifest['pages'], 'slug'));
    assert_eq(true, $manifest['pages'][0]['front']);
    assert_eq([0, 10], array_column($manifest['pages'], 'menu_order'));

    // The theme renders pages through page.html; the old front-page template
    // is gone (the seeded homepage + page_on_front owns the front).
    assert_contains('wp:post-content', $project->readText('theme/templates/page.html'));
    assert_true(!$project->exists('theme/templates/front-page.html'), 'no front-page.html');

    // The utility class survived the fix-blocks re-serialization and the move
    // into the plugin, and page-styles appended its CSS to style.css (after
    // the theme header).
    $style = $project->readText('theme/style.css');
    assert_contains('.hover-lift:hover', $style);
    assert_true(
        strpos($style, 'Theme Name:') < strpos($style, '.hover-lift'),
        'appendix appended after the theme header'
    );

    // fonts-php accepted the model's module; finalize-theme wrote the
    // deterministic loader that enqueues style.css (block themes don't load
    // it automatically) and require_once's fonts.php.
    assert_contains('fonts.googleapis.com', $project->readText('theme/fonts.php'));
    $functions = $project->readText('theme/functions.php');
    assert_contains('get_stylesheet_uri()', $functions);
    assert_contains("require_once __DIR__ . '/fonts.php'", $functions);
    assert_true(!str_contains($functions, 'googleapis'), 'fonts stay in fonts.php');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('pipeline step order is correct', function () {
    $tmp = sys_get_temp_dir() . '/builder_int_order_' . uniqid();
    $ids = make_integration_builder(new FakeLlm(), $tmp)->pipeline()->stepIds();
    assert_eq([
        'scaffold-theme', 'scaffold-plugin', 'refine-prompt', 'site-spec', 'apply-identity', 'design-direction',
        'theme-json+page-plan', 'sections',
        'collect-images', 'contrast-fix', 'fix-blocks', 'assemble-pages', 'page-styles', 'fonts-php', 'finalize-theme',
    ], $ids);
    exec('rm -rf ' . escapeshellarg($tmp));
});
