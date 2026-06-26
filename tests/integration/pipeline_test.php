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

    // site-spec (json)
    $llm->queueJson([
        'name' => 'Hearth & Crumb', 'slug' => 'hearth-crumb',
        'description' => 'A neighborhood bakery.',
        'colors' => ['primary' => '#8a5a2b', 'background' => '#fdf6ec', 'text' => '#2b2118', 'secondary' => '#c98', 'accent' => '#e08a3c'],
        'typography' => ['heading' => 'Fraunces', 'body' => 'Source Sans 3'],
        'key_sections' => ['Hero', 'Specials', 'About'],
    ]);
    // design-direction (json)
    $llm->queueJson(['concept' => 'Warm artisanal bakery', 'do' => ['cream backgrounds'], 'dont' => ['neon']]);
    // design-doc (text)
    $llm->queueText("# Hearth & Crumb — Design Document\n\n## Overview\n" . str_repeat('Warm bakery brand. ', 30));
    // theme-json (json)
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
    // landing-page (json map)
    $hdr = '<!-- wp:group --><div class="wp-block-group"><!-- wp:site-title /--></div><!-- /wp:group -->';
    $ftr = '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>(c) Hearth</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
    $front = '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' .
        '<!-- wp:heading --><h2>Hero</h2><!-- /wp:heading -->' .
        '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->';
    $index = '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' .
        '<!-- wp:post-content /-->' .
        '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->';
    $llm->queueJson([
        'parts/header.html' => $hdr,
        'parts/footer.html' => $ftr,
        'templates/index.html' => $index,
        'templates/front-page.html' => $front,
    ]);

    build_pipeline($llm)->runThrough($project);

    $problems = ThemeValidator::validate($project);
    assert_eq([], $problems, 'theme should validate; problems: ' . implode('; ', $problems));

    // Identity propagated end to end.
    assert_contains('Theme Name: Hearth & Crumb', $project->readText('theme/style.css'));
    assert_eq(3, $project->readJson('theme/theme.json')['version']);

    // finalize-theme produced font loading.
    assert_true($project->exists('theme/functions.php'), 'functions.php written');
    assert_contains('fonts.googleapis.com', $project->readText('theme/functions.php'));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('pipeline step order is correct', function () {
    $ids = build_pipeline(new FakeLlm())->stepIds();
    assert_eq([
        'scaffold-theme', 'site-spec', 'apply-identity',
        'design-direction', 'design-doc', 'theme-json', 'landing-page', 'finalize-theme',
    ], $ids);
});
