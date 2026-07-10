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
 * outputs, then asserts the produced theme passes structural validation.
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

test('full pipeline produces a structurally valid theme', function () {
    $tmp = sys_get_temp_dir() . '/builder_int_' . uniqid();
    $llm = new FakeLlm();

    // refine-prompt (text) — fast small-model clean-up of the raw prompt, runs first
    $llm->queueText('A cozy neighborhood bakery selling artisan bread and pastries to local residents, with a warm and rustic feel.');
    // site-spec (json) — factual info only, no design fields
    $llm->queueJson([
        'name' => 'Hearth & Crumb', 'slug' => 'hearth-crumb',
        'title' => 'Hearth & Crumb', 'site_type' => 'bakery storefront',
        'topic' => 'artisan bread and pastries', 'area' => 'bakery',
        'audience' => 'neighborhood locals', 'visual_vibe' => 'warm and rustic',
        'language' => 'en', 'persona_name' => '',
        'email_domain' => 'hearthandcrumb.com', 'invented' => ['name', 'email_domain'],
        'sections' => ['Hero', 'Specials', 'About'],
    ]);
    // design-direction-seeds (json) — 4 cheap concept titles; ONE is picked at
    // random and expanded by the design-direction call below. Runs after
    // site-spec, before the concurrent group.
    $llm->queueJson(['seeds' => ['Hearth & Grain', 'Flour & Steel', 'Sugar Bloom', 'Midnight Levain']]);
    // design-direction (json) — the expanded direction, read by
    // theme-json/section-plan/sections.
    $llm->queueJson(['direction' => [
        'title' => 'Hearth & Grain',
        'description' => 'Editorial-magazine warmth, 1970s print feel. Earthy neutrals, one electric accent; serif display over grotesque body. Avoid the centered all-sans hero.',
        'palette' => ['base' => '#FDF6EC', 'contrast' => '#2B2118', 'primary' => '#8A5A2B', 'secondary' => '#CC9988', 'accent' => '#E08A3C'],
        'type' => ['heading' => 'Fraunces 700/900', 'body' => 'Source Sans 3 400/600'],
        'image_grade' => 'warm kodachrome color, soft golden light, gentle film grain',
        'signature_device' => 'hairline rules with small caps folios',
        'hero_composition' => 'full-bleed bakery photo, headline pinned lower-left',
    ]]);
    // Concurrent group, request order is [theme-json, section-plan]:
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
    // section-plan (json) — ordered list of sections
    $llm->queueJson(['sections' => [
        ['slug' => 'hero', 'title' => 'Hero', 'type' => 'hero', 'layout_archetype' => 'full-bleed-cover', 'background' => 'image', 'handoff' => 'Between the site header above and the base specials grid below.'],
        ['slug' => 'specials', 'title' => 'Specials', 'type' => 'features', 'layout_archetype' => 'equal-card-grid', 'background' => 'base', 'handoff' => 'Between the image hero above and the footer below.'],
    ]]);
    // sections (raw markup) — header, footer, then one part per section, in requests() order
    $hdr = '<!-- wp:group --><div class="wp-block-group"><!-- wp:site-title /--></div><!-- /wp:group -->';
    $ftr = '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>(c) Hearth</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
    $llm->queueText($hdr);
    $llm->queueText($ftr);
    $llm->queueText('<!-- wp:heading --><h2>Hero</h2><!-- /wp:heading -->');
    // The specials section opts into a layout utility class (hover-lift) so the
    // page-styles step downstream has something to style — and we can assert the
    // class survives the block-fixer's re-serialization.
    $llm->queueText(
        '<!-- wp:group {"className":"hover-lift"} --><div class="wp-block-group hover-lift">'
        . '<!-- wp:heading --><h2>Specials</h2><!-- /wp:heading -->'
        . '</div><!-- /wp:group -->'
    );
    // page-styles (text) — runs after fix-blocks, sees hover-lift in the final
    // markup, and returns the CSS appendix for it.
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
    $project = $builder->createProject('A cozy neighborhood bakery', 'demo');
    $builder->pipeline()->runThrough($project);

    $problems = ThemeValidator::validate($project);
    assert_eq([], $problems, 'theme should validate; problems: ' . implode('; ', $problems));

    // Identity propagated end to end.
    assert_contains('Theme Name: Hearth & Crumb', $project->readText('theme/style.css'));
    assert_eq(3, $project->readJson('theme/theme.json')['version']);

    // Sections were generated as parts and composed in order into front-page.
    assert_true($project->exists('theme/parts/section-hero.html'), 'hero part written');
    assert_true($project->exists('theme/parts/section-specials.html'), 'specials part written');
    $front = $project->readText('theme/templates/front-page.html');
    assert_contains('wp:template-part', $front);
    assert_true(
        strpos($front, 'section-hero') < strpos($front, 'section-specials'),
        'sections composed in plan order'
    );

    // The utility class survived the fix-blocks re-serialization, and the
    // page-styles step appended its CSS to style.css (after the theme header).
    assert_contains('hover-lift', $project->readText('theme/parts/section-specials.html'));
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
        'scaffold-theme', 'refine-prompt', 'site-spec', 'apply-identity', 'design-direction',
        'theme-json+section-plan', 'sections', 'assemble-landing-page',
        'collect-images', 'contrast-fix', 'fix-blocks', 'page-styles', 'fonts-php', 'finalize-theme',
    ], $ids);
    exec('rm -rf ' . escapeshellarg($tmp));
});
