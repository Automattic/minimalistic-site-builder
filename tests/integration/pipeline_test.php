<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\NodeBlockFixer;
use Automattic\SiteBuild\Package;
use Automattic\SiteBuild\SiteBuilder;
use Automattic\SiteBuild\Steps\SectionRhythmStep;
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
        'motion' => 'calm',
        'motion_note' => 'Let the hero settle gently and keep card hover restrained.',
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
        ['slug' => 'hero', 'title' => 'Hero', 'type' => 'hero', 'layout_archetype' => 'full-bleed-cover', 'background' => 'image', 'vertical_density' => 'standard', 'handoff' => 'Between the site header above and the base specials grid below.'],
        ['slug' => 'specials', 'title' => 'Specials', 'type' => 'features', 'layout_archetype' => 'equal-card-grid', 'background' => 'base', 'vertical_density' => 'compact', 'handoff' => 'Between the image hero above and the footer below.'],
    ]]);
    // page-plan menu (json) — the interior page's sections
    $llm->queueJson(['sections' => [
        ['slug' => 'menu-hero', 'title' => 'Our Menu', 'type' => 'hero', 'layout_archetype' => 'centered-stack', 'background' => 'tinted', 'vertical_density' => 'standard', 'handoff' => 'Between the site header above and the base bread list below.'],
        ['slug' => 'breads', 'title' => 'Breads', 'type' => 'features', 'layout_archetype' => 'list-with-thumbnails', 'background' => 'base', 'vertical_density' => 'compact', 'handoff' => 'Between the tinted page hero above and the footer below.'],
    ]]);
    // sections (raw markup) — disposable cache probe, then header, footer,
    // home's parts, and menu's parts in requests() order
    $hdr = '<!-- wp:group --><div class="wp-block-group"><!-- wp:site-title /--></div><!-- /wp:group -->';
    $ftr = '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>(c) Hearth</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
    $llm->queueText('OK');
    $llm->queueText($hdr);
    $llm->queueText($ftr);
    $llm->queueText(
        '<!-- wp:group {"className":"ken-burns","style":{"spacing":{"margin":{"top":"0"}}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group ken-burns" style="margin-top:0">'
        . '<!-- wp:cover {"dimRatio":50,"minHeight":500,"align":"full","backgroundColor":"contrast"} -->'
        . '<div class="wp-block-cover alignfull has-contrast-background-color has-background" style="min-height:500px">'
        . '<span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span>'
        . '<div class="wp-block-cover__inner-container">'
        . '<!-- wp:group {"className":"hero-entrance","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group hero-entrance">'
        . '<!-- wp:heading {"level":1,"textColor":"base"} --><h1 class="wp-block-heading has-base-color has-text-color">Hero</h1><!-- /wp:heading -->'
        . '</div><!-- /wp:group -->'
        . '</div></div><!-- /wp:cover -->'
        . '</div><!-- /wp:group -->'
    );
    // The specials section opts into one generated layout utility (overlap-up)
    // and one static motion-kit utility (hover-lift, which must survive
    // serialization without asking the model to implement it). It incorrectly
    // puts overlap-up on the root as well as correctly on an inner group,
    // letting us verify that only the root occurrence is stripped. It also
    // disobeys the "no root padding" instruction with mirrored inline CSS, the
    // case the rhythm pass must repair without the fix-blocks rhythm gate
    // rejecting the replaced declarations as dropped. Its shorthand also
    // carries horizontal padding/auto margins that must survive the
    // vertical-only repair. Both classes must still be visible after the
    // markup moves into the content plugin.
    $llm->queueText(
        '<!-- wp:group {"className":"overlap-up hover-lift","style":{"spacing":{"padding":{"top":"12rem","bottom":"12rem"},"margin":{"top":"0","bottom":"0"}}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group overlap-up hover-lift" style="padding:12rem 2rem;margin:0 auto">'
        . '<!-- wp:heading --><h2>Specials</h2><!-- /wp:heading -->'
        . '<!-- wp:group {"className":"overlap-up"} --><div class="wp-block-group overlap-up">'
        . '<!-- wp:paragraph --><p>Featured today</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->'
    );
    $llm->queueText('<!-- wp:group --><div class="wp-block-group"><!-- wp:heading --><h2>Our Menu</h2><!-- /wp:heading --></div><!-- /wp:group -->');
    $llm->queueText(
        '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:heading --><h2>Breads</h2><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>See you at <a href="/">home</a>.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->'
    );
    // page-styles (text) — runs after assemble-pages, sees only overlap-up as a
    // generated layout utility in the PLUGIN content markup, and returns the
    // CSS appendix for it. Motion variables and hover CSS never ride this
    // model response.
    $llm->queueText(
        ".overlap-up {\n    margin-top: -4rem;\n    position: relative;\n    z-index: 2;\n}"
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

    // The rhythm pass owned each section root's vertical spacing before the
    // markup moved into the plugin: judge the assembled home page's chunks.
    [$heroHtml, $specialsHtml] = SectionRhythmStep::splitTopLevel($home);
    assert_contains('hero-entrance', $heroHtml, 'first section keeps its page-load entrance');
    assert_contains('ken-burns', $heroHtml, 'first section keeps the page ambient effect');
    $heroBlocks = BlockMarkup::parse($heroHtml);
    $heroRoot = $heroBlocks->attrs($heroBlocks->indices()[0]);
    $coverIndex = array_values(array_filter(
        $heroBlocks->indices(),
        fn (int $i): bool => $heroBlocks->name($i) === 'cover'
    ))[0];
    $heroCover = $heroBlocks->attrs($coverIndex);
    assert_eq('0', $heroRoot['style']['spacing']['padding']['top'], 'image-band root has no outside padding');
    assert_eq('var:preset|spacing|xl', $heroCover['style']['spacing']['padding']['top'], 'density lives inside cover');
    $specialsBlocks = BlockMarkup::parse($specialsHtml);
    $specialsRoot = $specialsBlocks->attrs($specialsBlocks->indices()[0]);
    assert_eq('var:preset|spacing|lg', $specialsRoot['style']['spacing']['padding']['top'], 'model root padding replaced by plan density');
    assert_eq('2rem', $specialsRoot['style']['spacing']['padding']['right'], 'horizontal shorthand padding survives');
    assert_eq('auto', $specialsRoot['style']['spacing']['margin']['left'], 'horizontal shorthand margin survives');
    assert_eq('hover-lift', $specialsRoot['className'], 'the root overlap utility is stripped');
    assert_contains('<div class="wp-block-group hover-lift"', $specialsHtml, 'the root wrapper loses overlap-up too');
    assert_contains('wp-block-group overlap-up', $specialsHtml, 'the nested overlap utility remains available');
    assert_true(!str_contains($specialsHtml, '12rem'), 'no orphaned model spacing survives the pipeline');

    // Both classes survived fix-blocks and the move into the plugin. Only the
    // layout utility lands in the generated appendix; hover behavior comes
    // from the static motion kit.
    assert_contains('overlap-up', $specialsHtml);
    assert_contains('hover-lift', $specialsHtml);
    $style = $project->readText('theme/style.css');
    assert_contains('.overlap-up', $style);
    assert_true(!str_contains($style, '.hover-lift'), 'page-styles does not generate static hover CSS');
    assert_true(!str_contains($style, '--motion-'), 'page-styles cannot override profile-owned motion tokens');
    $motionCss = $project->readText('theme/assets/motion/motion.css');
    $profileCss = $project->readText('theme/assets/motion/profiles/calm.css');
    assert_contains('.hover-lift', $motionCss, 'static kit implements hover-lift');
    assert_contains('.hover-reveal', $motionCss, 'static kit implements hover-reveal');
    assert_contains('@keyframes motion-calm-hero', $motionCss, 'kit contains the selected hero family');
    assert_contains('@keyframes motion-calm-ken-burns', $motionCss, 'kit contains the selected ambient family');
    assert_contains('--motion-hero-keyframe: motion-calm-hero', $profileCss, 'profile selects its hero identity');
    assert_contains(
        '--motion-ken-burns-keyframe: motion-calm-ken-burns',
        $profileCss,
        'profile selects its ambient identity'
    );
    assert_true(
        strpos($style, 'Theme Name:') < strpos($style, '.overlap-up'),
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
        'theme-json+page-plan', 'sections', 'section-rhythm',
        'collect-images', 'normalize-layout', 'contrast-fix', 'motion-sanity', 'fix-blocks', 'assemble-pages', 'page-styles', 'custom-motion',
        'fonts-php', 'finalize-theme', 'validate-theme',
    ], $ids);
    exec('rm -rf ' . escapeshellarg($tmp));
});
