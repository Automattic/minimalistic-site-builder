<?php
declare(strict_types=1);

function valid_theme_payload(): array
{
    return [
        'version' => 2, // should be forced to 3
        'settings' => [
            'color' => ['palette' => [
                ['slug' => 'base', 'color' => '#fff', 'name' => 'Base'],
                ['slug' => 'contrast', 'color' => '#111', 'name' => 'Contrast'],
                ['slug' => 'primary', 'color' => '#2f6b4f', 'name' => 'Primary'],
                ['slug' => 'secondary', 'color' => '#a7c4a0', 'name' => 'Secondary'],
                ['slug' => 'accent', 'color' => '#d98c3f', 'name' => 'Accent'],
            ]],
            'typography' => ['fontFamilies' => [
                ['slug' => 'heading', 'fontFamily' => 'Fraunces, serif', 'name' => 'Heading'],
                ['slug' => 'body', 'fontFamily' => 'Source Sans 3, sans-serif', 'name' => 'Body'],
            ]],
        ],
    ];
}

test('theme-json writes valid theme.json and forces version 3', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy neighborhood bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo', 'visual_vibe' => 'warm and rustic']);

    $llm = new FakeLlm();
    $llm->queueJson(valid_theme_payload());
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new ThemeJsonStep($llm, $renderer))->run($project);

    $theme = $project->readJson('theme/theme.json');
    assert_eq(3, $theme['version']);
    assert_eq('https://schemas.wp.org/trunk/theme.json', $theme['$schema']);
    $slugs = array_column($theme['settings']['color']['palette'], 'slug');
    assert_true(in_array('accent', $slugs, true));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json accepts extra palette colors and a third font (loosened contract)', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A dark-luxe jewellery brand']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);

    $payload = valid_theme_payload();
    // Required five + two expressive extras.
    $payload['settings']['color']['palette'][] = ['slug' => 'surface', 'color' => '#f4efe7', 'name' => 'Surface'];
    $payload['settings']['color']['palette'][] = ['slug' => 'muted', 'color' => '#dcd6cc', 'name' => 'Muted'];
    // Required two + an optional display face.
    $payload['settings']['typography']['fontFamilies'][] = ['slug' => 'display', 'fontFamily' => 'Bodoni Moda, serif', 'name' => 'Display'];

    $llm = new FakeLlm();
    $llm->queueJson($payload);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new ThemeJsonStep($llm, $renderer))->run($project);

    $theme = $project->readJson('theme/theme.json');
    $colorSlugs = array_column($theme['settings']['color']['palette'], 'slug');
    $fontSlugs = array_column($theme['settings']['typography']['fontFamilies'], 'slug');
    assert_eq(['base', 'contrast', 'primary', 'secondary', 'accent', 'surface', 'muted'], $colorSlugs);
    assert_eq(['heading', 'body', 'display'], $fontSlugs, 'optional third font preserved');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json throws when a required color slug is missing', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy neighborhood bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);

    $payload = valid_theme_payload();
    // Drop "accent".
    $payload['settings']['color']['palette'] = array_values(array_filter(
        $payload['settings']['color']['palette'],
        fn ($c) => $c['slug'] !== 'accent'
    ));

    $llm = new FakeLlm();
    $llm->queueJson($payload);
    $renderer = new PromptRenderer(repo_path('prompts'));
    assert_throws(function () use ($llm, $renderer, $project) {
        (new ThemeJsonStep($llm, $renderer))->run($project);
    });
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json throws when brand colors collapse to mostly neutral palette', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A documentary photojournalism portfolio']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);

    $payload = valid_theme_payload();
    $payload['settings']['color']['palette'] = [
        ['slug' => 'base', 'color' => '#F7F6F2', 'name' => 'Paper White'],
        ['slug' => 'contrast', 'color' => '#16161A', 'name' => 'Ink Black'],
        ['slug' => 'primary', 'color' => '#2B2B2E', 'name' => 'Archive Charcoal'],
        ['slug' => 'secondary', 'color' => '#8A8A86', 'name' => 'Silver Gray'],
        ['slug' => 'accent', 'color' => '#C4341B', 'name' => 'Pampas Red'],
    ];

    $llm = new FakeLlm();
    $llm->queueJson($payload);
    $renderer = new PromptRenderer(repo_path('prompts'));
    assert_throws(function () use ($llm, $renderer, $project) {
        (new ThemeJsonStep($llm, $renderer))->run($project);
    });
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json allows a neutral palette when the user explicitly asks for black and white', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A black and white documentary photography portfolio']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);

    $payload = valid_theme_payload();
    $payload['settings']['color']['palette'] = [
        ['slug' => 'base', 'color' => '#F7F6F2', 'name' => 'Paper White'],
        ['slug' => 'contrast', 'color' => '#16161A', 'name' => 'Ink Black'],
        ['slug' => 'primary', 'color' => '#2B2B2E', 'name' => 'Archive Charcoal'],
        ['slug' => 'secondary', 'color' => '#8A8A86', 'name' => 'Silver Gray'],
        ['slug' => 'accent', 'color' => '#C4341B', 'name' => 'Pampas Red'],
    ];

    $llm = new FakeLlm();
    $llm->queueJson($payload);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new ThemeJsonStep($llm, $renderer))->run($project);

    $theme = $project->readJson('theme/theme.json');
    assert_eq('#2B2B2E', $theme['settings']['color']['palette'][2]['color']);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json throws when brand colors are chromatic but too close in hue', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A vibrant restaurant']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);

    $payload = valid_theme_payload();
    $payload['settings']['color']['palette'] = [
        ['slug' => 'base', 'color' => '#FFF8EE', 'name' => 'Warm Paper'],
        ['slug' => 'contrast', 'color' => '#20140F', 'name' => 'Ink'],
        ['slug' => 'primary', 'color' => '#C44722', 'name' => 'Tomato'],
        ['slug' => 'secondary', 'color' => '#D95A2A', 'name' => 'Tangerine'],
        ['slug' => 'accent', 'color' => '#B83C20', 'name' => 'Chili'],
    ];

    $llm = new FakeLlm();
    $llm->queueJson($payload);
    $renderer = new PromptRenderer(repo_path('prompts'));
    assert_throws(function () use ($llm, $renderer, $project) {
        (new ThemeJsonStep($llm, $renderer))->run($project);
    });
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json throws when a required font slug is missing', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy neighborhood bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);

    $payload = valid_theme_payload();
    $payload['settings']['typography']['fontFamilies'] = [
        ['slug' => 'body', 'fontFamily' => 'X', 'name' => 'Body'],
    ];

    $llm = new FakeLlm();
    $llm->queueJson($payload);
    $renderer = new PromptRenderer(repo_path('prompts'));
    assert_throws(function () use ($llm, $renderer, $project) {
        (new ThemeJsonStep($llm, $renderer))->run($project);
    });
    exec('rm -rf ' . escapeshellarg($tmp));
});
