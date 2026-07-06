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

test('theme-json forces useRootPaddingAwareAlignments when root side padding is set', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy neighborhood bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);

    $payload = valid_theme_payload();
    // The stanza the model reliably copies from published themes — without the
    // flag it traps every align:full block inside the body padding.
    $payload['styles'] = ['spacing' => ['padding' => [
        'top'    => '0',
        'bottom' => '0',
        'left'   => 'var(--wp--preset--spacing--md)',
        'right'  => 'var(--wp--preset--spacing--md)',
    ]]];

    $llm = new FakeLlm();
    $llm->queueJson($payload);
    (new ThemeJsonStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $theme = $project->readJson('theme/theme.json');
    assert_eq(true, $theme['settings']['useRootPaddingAwareAlignments']);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('withRootPaddingAwareAlignments only fires on a non-zero side padding', function () {
    // No styles at all — untouched.
    $theme = ThemeJsonStep::withRootPaddingAwareAlignments([]);
    assert_true(!isset($theme['settings']['useRootPaddingAwareAlignments']), 'no styles');

    // Zero-valued side padding (any unit) — untouched.
    $theme = ThemeJsonStep::withRootPaddingAwareAlignments(
        ['styles' => ['spacing' => ['padding' => ['left' => '0px', 'right' => '0']]]]
    );
    assert_true(!isset($theme['settings']['useRootPaddingAwareAlignments']), 'zero padding');

    // Vertical-only padding — untouched (nothing to bleed through).
    $theme = ThemeJsonStep::withRootPaddingAwareAlignments(
        ['styles' => ['spacing' => ['padding' => ['top' => '2rem', 'bottom' => '2rem']]]]
    );
    assert_true(!isset($theme['settings']['useRootPaddingAwareAlignments']), 'vertical only');

    // One non-zero side is enough.
    $theme = ThemeJsonStep::withRootPaddingAwareAlignments(
        ['styles' => ['spacing' => ['padding' => ['right' => '1.5rem']]]]
    );
    assert_eq(true, $theme['settings']['useRootPaddingAwareAlignments']);
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
