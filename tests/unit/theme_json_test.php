<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\ThemeJsonStep;
use Automattic\SiteBuild\Tests\FakeLlm;

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
    assert_eq(true, $theme['settings']['spacing']['blockGap']);
    assert_eq(['sm', 'md', 'lg', 'xl', 'xxl'], array_column($theme['settings']['spacing']['spacingSizes'], 'slug'));
    assert_eq(false, $theme['settings']['color']['defaultPalette']);
    assert_eq(false, $theme['settings']['color']['defaultGradients']);
    assert_eq(false, $theme['settings']['color']['defaultDuotone']);
    assert_eq(false, $theme['settings']['typography']['defaultFontSizes']);
    assert_eq(false, $theme['settings']['spacing']['defaultSpacingSizes']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('disableCoreDefaultPresets forces the flags even when the model re-enables them', function () {
    // Missing settings sections are created.
    $theme = ThemeJsonStep::disableCoreDefaultPresets([]);
    assert_eq(false, $theme['settings']['color']['defaultPalette']);
    assert_eq(false, $theme['settings']['color']['defaultGradients']);
    assert_eq(false, $theme['settings']['color']['defaultDuotone']);
    assert_eq(false, $theme['settings']['typography']['defaultFontSizes']);

    // Model output opting back into core defaults is overridden; everything
    // else in the touched sections is preserved, and core shadows stay on.
    $theme = ThemeJsonStep::disableCoreDefaultPresets([
        'settings' => [
            'color' => [
                'defaultPalette' => true,
                'defaultGradients' => true,
                'palette' => [['slug' => 'base', 'color' => '#fff', 'name' => 'Base']],
            ],
            'typography' => ['defaultFontSizes' => true, 'fluid' => true],
            'shadow' => ['presets' => []],
        ],
    ]);
    assert_eq(false, $theme['settings']['color']['defaultPalette']);
    assert_eq(false, $theme['settings']['color']['defaultGradients']);
    assert_eq(false, $theme['settings']['color']['defaultDuotone']);
    assert_eq(false, $theme['settings']['typography']['defaultFontSizes']);
    assert_eq('base', $theme['settings']['color']['palette'][0]['slug'], 'palette preserved');
    assert_eq(true, $theme['settings']['typography']['fluid'], 'other typography settings preserved');
    assert_true(!isset($theme['settings']['shadow']['defaultPresets']), 'core shadow presets stay enabled');
});

test('normalizeSpacingSettings installs the canonical bounded responsive profile', function () {
    $theme = ThemeJsonStep::normalizeSpacingSettings([]);

    assert_eq(true, $theme['settings']['spacing']['blockGap']);
    assert_eq(false, $theme['settings']['spacing']['defaultSpacingSizes'], 'core spacing sizes disabled');
    assert_eq([
        ['slug' => 'sm', 'name' => 'Small', 'size' => 'clamp(0.75rem, 1vw, 1rem)'],
        ['slug' => 'md', 'name' => 'Medium', 'size' => 'clamp(1.5rem, 2vw, 2rem)'],
        ['slug' => 'lg', 'name' => 'Compact', 'size' => 'clamp(3rem, 4vw, 4rem)'],
        ['slug' => 'xl', 'name' => 'Standard', 'size' => 'clamp(4rem, 6vw, 6rem)'],
        ['slug' => 'xxl', 'name' => 'Spacious', 'size' => 'clamp(5rem, 7vw, 7rem)'],
    ], $theme['settings']['spacing']['spacingSizes']);
});

test('normalizeSpacingSettings repairs malformed and oversized model output', function () {
    $theme = ThemeJsonStep::normalizeSpacingSettings([
        'settings' => [
            'color' => ['custom' => false],
            'spacing' => [
                'blockGap' => 'yes',
                'units' => ['rem'],
                'spacingSizes' => [
                    ['slug' => 'sm', 'size' => 'banana'],
                    ['slug' => 'xxl', 'size' => 'clamp(6rem, 12vw, 11rem)'],
                    ['slug' => 'extra', 'size' => '20rem'],
                ],
            ],
        ],
        'styles' => ['color' => ['text' => '#123456']],
    ]);

    $spacing = $theme['settings']['spacing'];
    assert_eq(true, $spacing['blockGap']);
    assert_eq(['sm', 'md', 'lg', 'xl', 'xxl'], array_column($spacing['spacingSizes'], 'slug'));
    assert_eq('clamp(5rem, 7vw, 7rem)', $spacing['spacingSizes'][4]['size']);
    assert_eq(['rem'], $spacing['units'], 'unrelated spacing settings preserved');
    assert_eq(['custom' => false], $theme['settings']['color'], 'non-spacing settings preserved');
    assert_eq(['color' => ['text' => '#123456']], $theme['styles'], 'styles preserved');

    $theme = ThemeJsonStep::normalizeSpacingSettings(['settings' => ['spacing' => 'invalid']]);
    assert_eq(true, $theme['settings']['spacing']['blockGap']);
    assert_eq(5, count($theme['settings']['spacing']['spacingSizes']));
});

test('canonical spacing profile has monotonic fluid bounds', function () {
    $theme = ThemeJsonStep::normalizeSpacingSettings([]);
    $previousMin = 0.0;
    $previousMax = 0.0;

    foreach ($theme['settings']['spacing']['spacingSizes'] as $preset) {
        $matched = preg_match(
            '/^clamp\\(([0-9.]+)rem, [0-9.]+vw, ([0-9.]+)rem\\)$/',
            $preset['size'],
            $bounds
        );
        assert_eq(1, $matched, $preset['slug'] . ' is a responsive clamp');
        $minimum = (float) $bounds[1];
        $maximum = (float) $bounds[2];
        assert_true($minimum > $previousMin, $preset['slug'] . ' minimum rises');
        assert_true($maximum > $previousMax, $preset['slug'] . ' maximum rises');
        assert_true($minimum <= $maximum, $preset['slug'] . ' bounds are ordered');
        $previousMin = $minimum;
        $previousMax = $maximum;
    }

    assert_eq(7.0, $previousMax, 'largest edge is capped at 7rem');
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

test('normalizeRootPadding only sets the flag on a non-zero side padding', function () {
    // No styles at all — untouched.
    $theme = ThemeJsonStep::normalizeRootPadding([]);
    assert_true(!isset($theme['settings']['useRootPaddingAwareAlignments']), 'no styles');

    // Zero-valued side padding (any unit) — no flag.
    $theme = ThemeJsonStep::normalizeRootPadding(
        ['styles' => ['spacing' => ['padding' => ['left' => '0px', 'right' => '0']]]]
    );
    assert_true(!isset($theme['settings']['useRootPaddingAwareAlignments']), 'zero padding');

    // Vertical-only padding — no flag (nothing to bleed through).
    $theme = ThemeJsonStep::normalizeRootPadding(
        ['styles' => ['spacing' => ['padding' => ['top' => '2rem', 'bottom' => '2rem']]]]
    );
    assert_true(!isset($theme['settings']['useRootPaddingAwareAlignments']), 'vertical only');

    // One non-zero side is enough.
    $theme = ThemeJsonStep::normalizeRootPadding(
        ['styles' => ['spacing' => ['padding' => ['right' => '1.5rem']]]]
    );
    assert_eq(true, $theme['settings']['useRootPaddingAwareAlignments']);
});

test('normalizeRootPadding zeroes vertical root padding — sections own the rhythm', function () {
    // The portfolio6 failure: bottom xxl became 128px of dead space under the
    // footer once the flag moved root padding onto .wp-site-blocks.
    $theme = ThemeJsonStep::normalizeRootPadding(['styles' => ['spacing' => ['padding' => [
        'top'    => 'var(--wp--preset--spacing--md)',
        'bottom' => 'var(--wp--preset--spacing--xxl)',
        'left'   => 'var(--wp--preset--spacing--md)',
        'right'  => 'var(--wp--preset--spacing--md)',
    ]]]]);
    assert_eq('0', $theme['styles']['spacing']['padding']['top']);
    assert_eq('0', $theme['styles']['spacing']['padding']['bottom']);
    assert_eq('var(--wp--preset--spacing--md)', $theme['styles']['spacing']['padding']['left'], 'side padding kept');
    assert_eq(true, $theme['settings']['useRootPaddingAwareAlignments']);

    // No padding stanza at all — nothing invented.
    $theme = ThemeJsonStep::normalizeRootPadding(['styles' => ['spacing' => ['blockGap' => '1rem']]]);
    assert_true(!isset($theme['styles']['spacing']['padding']), 'no padding invented');
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
