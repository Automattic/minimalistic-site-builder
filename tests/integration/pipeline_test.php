<?php
declare(strict_types=1);

/**
 * Full step sequence as a deterministic integration test: runs the real
 * Pipeline (build_pipeline) with a FakeLlm scripted with realistic canned
 * outputs, then asserts the produced theme passes structural validation.
 */

test('full pipeline produces a structurally valid theme', function () {
    $tmp = sys_get_temp_dir() . '/builder_int_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy neighborhood bakery']);

    $llm = new FakeLlm();

    // refine-prompt (text) — fast small-model clean-up of the raw prompt, runs first
    $llm->queueText('A cozy neighborhood bakery selling artisan bread and pastries to local residents, with a warm and rustic feel.');
    // site-spec (json) — factual info only, no design fields
    $llm->queueJson([
        'name' => 'Hearth & Crumb', 'slug' => 'hearth-crumb',
        'title' => 'Hearth & Crumb', 'site_type' => 'bakery storefront',
        'topic' => 'artisan bread and pastries', 'area' => 'bakery',
        'audience' => 'neighborhood locals', 'visual_vibe' => 'warm and rustic',
        'sections' => ['Hero', 'Specials', 'About'],
    ]);
    // design-direction (json) — the model returns 4 candidate directions and the
    // step samples ONE at random. Runs after site-spec, before the concurrent
    // group, and is read by theme-json/section-plan/sections.
    $llm->queueJson(['directions' => [
        ['title' => 'Hearth & Grain',  'description' => 'Editorial-magazine warmth, 1970s print feel. Earthy neutrals, one electric accent; serif display over grotesque body. Avoid the centered all-sans hero.'],
        ['title' => 'Flour & Steel',   'description' => 'Industrial-utilitarian bakery, raw concrete tones and stencilled type.'],
        ['title' => 'Sugar Bloom',     'description' => 'Playful-pop pastels with oversized display type and rounded frames.'],
        ['title' => 'Midnight Levain', 'description' => 'Dark-luxe patisserie, gold on near-black with fine serif detailing.'],
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
        ['slug' => 'hero', 'title' => 'Hero', 'type' => 'hero'],
        ['slug' => 'specials', 'title' => 'Specials', 'type' => 'features'],
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

    build_pipeline($llm)->runThrough($project);

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

    // finalize-theme produced font loading AND the style.css enqueue (block
    // themes don't load style.css automatically, so without it the utility
    // CSS above would never apply).
    assert_true($project->exists('theme/functions.php'), 'functions.php written');
    assert_contains('fonts.googleapis.com', $project->readText('theme/functions.php'));
    assert_contains('get_stylesheet_uri()', $project->readText('theme/functions.php'));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('pipeline step order is correct', function () {
    $ids = build_pipeline(new FakeLlm())->stepIds();
    assert_eq([
        'scaffold-theme', 'refine-prompt', 'site-spec', 'apply-identity', 'design-direction',
        'theme-json+section-plan', 'sections', 'assemble-landing-page',
        'collect-images', 'fix-blocks', 'page-styles', 'finalize-theme',
    ], $ids);
});
