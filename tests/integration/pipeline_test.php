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
    // design-doc (text) — DESIGN.md standard: YAML front matter + body sections
    $designMd = "---\nname: Hearth & Crumb\ndescription: Warm rustic bakery\n"
        . "colors:\n  base: \"#fdf6ec\"\n  contrast: \"#2b2118\"\n  primary: \"#8a5a2b\"\n"
        . "  secondary: \"#c98a5a\"\n  accent: \"#e08a3c\"\n"
        . "typography:\n  heading:\n    fontFamily: Fraunces\n  body:\n    fontFamily: Source Sans 3\n---\n\n"
        . "## Overview\n" . str_repeat('Warm bakery brand. ', 20)
        . "\n## Colors\nCream base, cocoa contrast.\n## Typography\nFraunces + Source Sans 3.";
    $llm->queueText($designMd);
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
        'design-doc', 'theme-json', 'landing-page', 'collect-images', 'fix-blocks', 'finalize-theme',
    ], $ids);
});
