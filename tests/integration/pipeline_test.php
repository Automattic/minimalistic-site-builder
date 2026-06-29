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

    // site-spec (json) — factual info only, no design fields
    $llm->queueJson([
        'name' => 'Hearth & Crumb', 'slug' => 'hearth-crumb',
        'title' => 'Hearth & Crumb', 'site_type' => 'bakery storefront',
        'topic' => 'artisan bread and pastries', 'area' => 'bakery',
        'audience' => 'neighborhood locals', 'visual_vibe' => 'warm and rustic',
        'sections' => ['Hero', 'Specials', 'About'],
    ]);
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
        ['slug' => 'hero', 'title' => 'Hero', 'type' => 'hero', 'wants_image' => true],
        ['slug' => 'specials', 'title' => 'Specials', 'type' => 'features'],
    ]]);
    // sections (raw markup) — header, footer, then one part per section, in requests() order
    $hdr = '<!-- wp:group --><div class="wp-block-group"><!-- wp:site-title /--></div><!-- /wp:group -->';
    $ftr = '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>(c) Hearth</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
    $llm->queueText($hdr);
    $llm->queueText($ftr);
    $llm->queueText('<!-- wp:heading --><h2>Hero</h2><!-- /wp:heading -->');
    $llm->queueText('<!-- wp:heading --><h2>Specials</h2><!-- /wp:heading -->');

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

    // finalize-theme produced font loading.
    assert_true($project->exists('theme/functions.php'), 'functions.php written');
    assert_contains('fonts.googleapis.com', $project->readText('theme/functions.php'));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('pipeline step order is correct', function () {
    $ids = build_pipeline(new FakeLlm())->stepIds();
    assert_eq([
        'scaffold-theme', 'site-spec', 'apply-identity',
        'theme-json+section-plan', 'sections', 'assemble-landing-page',
        'collect-images', 'fix-blocks', 'finalize-theme',
    ], $ids);
});
