<?php
declare(strict_types=1);

function valid_theme_payload(): array
{
    return [
        'version' => 2, // should be forced to 3
        'settings' => [
            // High-contrast palette (passes computed V1 WCAG-AA): deep tones on
            // a white base, accent dark enough for base-colored button labels.
            'color' => ['palette' => [
                ['slug' => 'base', 'color' => '#ffffff', 'name' => 'Base'],
                ['slug' => 'contrast', 'color' => '#111111', 'name' => 'Contrast'],
                ['slug' => 'primary', 'color' => '#1f4d2e', 'name' => 'Primary'],
                ['slug' => 'secondary', 'color' => '#444444', 'name' => 'Secondary'],
                ['slug' => 'accent', 'color' => '#b3471a', 'name' => 'Accent'],
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
