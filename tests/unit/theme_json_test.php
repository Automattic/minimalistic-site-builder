<?php
declare(strict_types=1);

use Automattic\SiteBuild\PresetReferences;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\ThemeJsonStep;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\ThemeValidator;

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
            ], 'fontSizes' => [
                ['slug' => 'caption', 'name' => 'Caption', 'size' => '0.875rem'],
                ['slug' => 'body', 'name' => 'Body', 'size' => '1.125rem'],
                ['slug' => 'lead', 'name' => 'Lead', 'size' => '1.375rem'],
                ['slug' => 'heading', 'name' => 'Heading', 'size' => '1.75rem'],
                ['slug' => 'section-title', 'name' => 'Section Title', 'size' => 'clamp(2.25rem, 3vw, 3rem)'],
                ['slug' => 'display', 'name' => 'Display', 'size' => 'clamp(3rem, 7vw, 6rem)'],
            ]],
        ],
    ];
}

/** @return array{Project,string} */
function theme_json_test_project(string $prefix): array
{
    $tmp = sys_get_temp_dir() . '/' . $prefix . uniqid();
    return [(new ProjectStore($tmp))->create('demo'), $tmp];
}

/** @param list<array<mixed>> $presets */
function theme_json_preset(array $presets, string $slug): array
{
    foreach ($presets as $preset) {
        if (is_array($preset) && ($preset['slug'] ?? null) === $slug) {
            return $preset;
        }
    }
    throw new RuntimeException("Missing test preset: {$slug}");
}

/** @param list<array<mixed>> $presets */
function assert_theme_json_presets_usable(array $presets, string $valueKey): void
{
    assert_true(array_is_list($presets), 'preset container is a list');
    foreach ($presets as $preset) {
        assert_true(is_string($preset['slug'] ?? null) && trim($preset['slug']) !== '', 'usable slug');
        assert_true(is_string($preset['name'] ?? null) && trim($preset['name']) !== '', 'usable name');
        assert_true(
            is_string($preset[$valueKey] ?? null) && trim($preset[$valueKey]) !== '',
            "usable {$valueKey}",
        );
    }
}

test('theme-json declaration includes warnings.json for durable repairs', function () {
    $step = new ThemeJsonStep(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    assert_eq(
        ['theme/theme.json', 'warnings.json', 'logs/theme-json.log'],
        $step->declaration()->writes,
    );
});

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

test('theme-json forces a non-null blockGap so frontend spacing matches the editor', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy neighborhood bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);

    $llm = new FakeLlm();
    $llm->queueJson(valid_theme_payload()); // no blockGap anywhere
    $renderer = new PromptRenderer(repo_path('prompts'));
    (new ThemeJsonStep($llm, $renderer))->run($project);

    $theme = $project->readJson('theme/theme.json');
    assert_eq(true, $theme['settings']['spacing']['blockGap']);
    assert_eq('var:preset|spacing|md', $theme['styles']['spacing']['blockGap']);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json keeps a model-provided blockGap', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy neighborhood bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);

    $payload = valid_theme_payload();
    $payload['settings']['spacing']['blockGap'] = true;
    $payload['styles']['spacing']['blockGap'] = 'var:preset|spacing|lg';

    $llm = new FakeLlm();
    $llm->queueJson($payload);
    $renderer = new PromptRenderer(repo_path('prompts'));
    (new ThemeJsonStep($llm, $renderer))->run($project);

    assert_eq('var:preset|spacing|lg', $project->readJson('theme/theme.json')['styles']['spacing']['blockGap']);
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

test('theme-json fills a missing color slug from an original model role', function () {
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
    (new ThemeJsonStep($llm, $renderer))->run($project);

    $theme = $project->readJson('theme/theme.json');
    assert_eq(
        '#2f6b4f',
        theme_json_preset($theme['settings']['color']['palette'], 'accent')['color'],
        'accent copies original model primary',
    );
    $warnings = $project->readJson('warnings.json')['theme-json'] ?? [];
    assert_eq(1, count($warnings));
    assert_contains('settings.color.palette[slug=accent].color', $warnings[0]);
    assert_contains('original model settings.color.palette[slug=primary].color', $warnings[0]);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json color repair never chains a repaired role as a model fallback', function () {
    [$project, $tmp] = theme_json_test_project('builder_tj_color_chain_');
    $theme = ThemeJsonStep::assertColors([
        'settings' => ['color' => [
            'custom' => false,
            'palette' => [
                ['slug' => 'contrast', 'name' => 'Ink', 'color' => '#123456'],
                ['slug' => 'brand-extra', 'name' => 'Brand Extra', 'color' => '#abcdef'],
            ],
        ]],
        'customTemplates' => [['name' => 'landing']],
    ], $project);

    $palette = $theme['settings']['color']['palette'];
    assert_eq('#123456', theme_json_preset($palette, 'primary')['color'], 'primary copies original contrast');
    assert_eq('#123456', theme_json_preset($palette, 'secondary')['color'], 'secondary copies original contrast');
    assert_eq('#9C3D2E', theme_json_preset($palette, 'accent')['color'], 'accent cannot copy repaired primary');
    assert_eq('#FAF8F4', theme_json_preset($palette, 'base')['color'], 'base uses same-slug default');
    assert_eq('#abcdef', theme_json_preset($palette, 'brand-extra')['color'], 'unrelated model preset preserved');
    assert_eq(false, $theme['settings']['color']['custom'], 'unrelated color setting preserved');
    assert_eq([['name' => 'landing']], $theme['customTemplates'], 'unrelated theme field preserved');

    $warnings = $project->readJson('warnings.json')['theme-json'] ?? [];
    assert_eq(4, count($warnings));
    assert_contains('DEFAULT_PALETTE[slug=accent].color', implode("\n", $warnings));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json color repair leaves a complete model palette byte-for-byte equivalent', function () {
    [$project, $tmp] = theme_json_test_project('builder_tj_color_complete_');
    $theme = [
        'settings' => ['color' => [
            'custom' => false,
            'palette' => array_merge(
                valid_theme_payload()['settings']['color']['palette'],
                [['slug' => 'brand-extra', 'name' => 'Brand Extra', 'color' => '#abcdef']],
            ),
        ]],
        'styles' => ['color' => ['background' => '#fedcba']],
    ];

    assert_eq($theme, ThemeJsonStep::assertColors($theme, $project));
    assert_true(!$project->exists('warnings.json'), 'complete palette produces no warning');
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

test('theme-json fills a missing font slug from the other original model role', function () {
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
    (new ThemeJsonStep($llm, $renderer))->run($project);

    $theme = $project->readJson('theme/theme.json');
    assert_eq(
        'X',
        theme_json_preset($theme['settings']['typography']['fontFamilies'], 'heading')['fontFamily'],
    );
    $warnings = $project->readJson('warnings.json')['theme-json'] ?? [];
    assert_eq(1, count($warnings));
    assert_contains('settings.typography.fontFamilies[slug=heading].fontFamily', $warnings[0]);
    assert_contains('original model settings.typography.fontFamilies[slug=body].fontFamily', $warnings[0]);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json font repair is directly testable and preserves the original role', function () {
    [$project, $tmp] = theme_json_test_project('builder_tj_font_copy_');
    $theme = [
        'settings' => ['typography' => [
            'fluid' => true,
            'fontFamilies' => [
                ['slug' => 'body', 'fontFamily' => 'Inter, sans-serif', 'name' => 'Custom Body', 'fontFace' => []],
                ['slug' => 'mono', 'fontFamily' => 'monospace', 'name' => 'Mono'],
            ],
        ]],
        'styles' => ['typography' => ['lineHeight' => '1.6']],
    ];

    $repaired = ThemeJsonStep::assertFonts($theme, $project);
    assert_eq('Inter, sans-serif', theme_json_preset(
        $repaired['settings']['typography']['fontFamilies'],
        'heading',
    )['fontFamily']);
    assert_eq(
        theme_json_preset($theme['settings']['typography']['fontFamilies'], 'body'),
        theme_json_preset($repaired['settings']['typography']['fontFamilies'], 'body'),
        'original model body entry preserved',
    );
    assert_eq(true, $repaired['settings']['typography']['fluid']);
    assert_eq($theme['styles'], $repaired['styles']);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json font repair uses same-slug defaults when no original role is usable', function () {
    [$project, $tmp] = theme_json_test_project('builder_tj_font_defaults_');
    $theme = ThemeJsonStep::assertFonts([
        'settings' => ['typography' => ['fontFamilies' => [
            ['slug' => 'heading', 'name' => 'Broken Heading', 'fontFamily' => ''],
            ['slug' => 'mono', 'name' => 'Mono', 'fontFamily' => 'monospace'],
        ]]],
    ], $project);

    $families = $theme['settings']['typography']['fontFamilies'];
    assert_eq(
        '"Fraunces", Georgia, "Times New Roman", serif',
        theme_json_preset($families, 'heading')['fontFamily'],
    );
    assert_eq(
        '"Source Sans 3", "Helvetica Neue", Arial, sans-serif',
        theme_json_preset($families, 'body')['fontFamily'],
    );
    assert_eq('monospace', theme_json_preset($families, 'mono')['fontFamily']);
    assert_eq(3, count($project->readJson('warnings.json')['theme-json'] ?? []));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json font-size repair preserves model sizes and fills each omission', function () {
    [$project, $tmp] = theme_json_test_project('builder_tj_sizes_');
    $theme = ThemeJsonStep::assertFontSizes([
        'settings' => ['typography' => [
            'fluid' => true,
            'fontSizes' => [
                ['slug' => 'caption', 'name' => 'Tiny', 'size' => '1rem', 'fluid' => false],
                ['slug' => 'heading', 'name' => 'Broken', 'size' => ''],
                ['slug' => 'poster', 'name' => 'Poster', 'size' => '9rem'],
            ],
        ]],
        'styles' => ['typography' => ['fontSize' => 'var:preset|font-size|body']],
    ], $project);

    $sizes = $theme['settings']['typography']['fontSizes'];
    assert_eq('1rem', theme_json_preset($sizes, 'caption')['size'], 'usable model size preserved');
    assert_eq(false, theme_json_preset($sizes, 'caption')['fluid'], 'model metadata preserved');
    assert_eq('1.75rem', theme_json_preset($sizes, 'heading')['size'], 'unusable size repaired in place');
    assert_eq('9rem', theme_json_preset($sizes, 'poster')['size'], 'unrelated preset preserved');
    assert_eq('clamp(3rem, 7vw, 6rem)', theme_json_preset($sizes, 'display')['size']);
    assert_eq(true, $theme['settings']['typography']['fluid']);
    assert_eq(6, count($project->readJson('warnings.json')['theme-json'] ?? []));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json discards associative preset containers for every profile', function () {
    [$project, $tmp] = theme_json_test_project('builder_tj_assoc_profiles_');
    $theme = [
        'settings' => [
            'color' => ['palette' => [
                'base' => ['slug' => 'base', 'name' => 'Base', 'color' => '#eeeeee'],
            ]],
            'typography' => [
                'fontFamilies' => [
                    'body' => ['slug' => 'body', 'name' => 'Body', 'fontFamily' => 'Inter, sans-serif'],
                ],
                'fontSizes' => [
                    'body' => ['slug' => 'body', 'name' => 'Body', 'size' => '1rem'],
                ],
            ],
        ],
    ];

    $theme = ThemeJsonStep::assertColors($theme, $project);
    $theme = ThemeJsonStep::assertFonts($theme, $project);
    $theme = ThemeJsonStep::assertFontSizes($theme, $project);

    assert_eq(5, count($theme['settings']['color']['palette']));
    assert_eq(2, count($theme['settings']['typography']['fontFamilies']));
    assert_eq(6, count($theme['settings']['typography']['fontSizes']));
    assert_theme_json_presets_usable($theme['settings']['color']['palette'], 'color');
    assert_theme_json_presets_usable($theme['settings']['typography']['fontFamilies'], 'fontFamily');
    assert_theme_json_presets_usable($theme['settings']['typography']['fontSizes'], 'size');

    $warnings = $project->readJson('warnings.json')['theme-json'] ?? [];
    assert_eq(16, count($warnings), 'three discarded containers plus thirteen default fills');
    foreach ([
        'settings.color.palette',
        'settings.typography.fontFamilies',
        'settings.typography.fontSizes',
    ] as $path) {
        assert_contains("invalid {$path} container", implode("\n", $warnings));
    }
    assert_contains('authored type=associative array, value={', implode("\n", $warnings));
    assert_contains(
        'discarded invalid preset container; delivered defaults/remaining usable presets',
        implode("\n", $warnings),
    );

    $firstWarnings = $project->readJson('warnings.json');
    $again = ThemeJsonStep::assertColors($theme, $project);
    $again = ThemeJsonStep::assertFonts($again, $project);
    $again = ThemeJsonStep::assertFontSizes($again, $project);
    assert_eq($theme, $again, 'container repair reaches a fixed point');
    assert_eq($firstWarnings, $project->readJson('warnings.json'), 'fixed point adds no warnings');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json preserves usable preset rows while discarding garbage rows', function () {
    [$project, $tmp] = theme_json_test_project('builder_tj_partial_garbage_');
    $theme = [
        'settings' => [
            'color' => ['palette' => [
                ['slug' => 'base', 'name' => 'Paper', 'color' => '#fafafa', 'meta' => 'keep'],
                ['slug' => 'contrast', 'name' => 'Ink', 'color' => '#101010'],
                ['slug' => 'brand-extra', 'name' => 'Brand Extra', 'color' => '#abcdef'],
                'garbage',
                ['slug' => 'unknown', 'color' => '#777777'],
                ['slug' => 'primary', 'name' => '', 'color' => '#123456'],
            ]],
            'typography' => [
                'fontFamilies' => [
                    ['slug' => 'body', 'name' => 'Body', 'fontFamily' => 'Inter, sans-serif', 'meta' => 'keep'],
                    ['slug' => 'mono', 'name' => 'Mono', 'fontFamily' => 'monospace'],
                    false,
                    ['slug' => 'unknown', 'fontFamily' => 'serif'],
                    ['slug' => 'heading', 'name' => 'Heading', 'fontFamily' => ''],
                ],
                'fontSizes' => [
                    ['slug' => 'body', 'name' => 'Body', 'size' => '1.2rem', 'meta' => 'keep'],
                    ['slug' => 'poster', 'name' => 'Poster', 'size' => '9rem'],
                    null,
                    ['slug' => 'unknown', 'name' => '', 'size' => '2rem'],
                    ['slug' => 'caption', 'name' => 'Caption', 'size' => ''],
                ],
            ],
        ],
    ];

    $theme = ThemeJsonStep::assertColors($theme, $project);
    $theme = ThemeJsonStep::assertFonts($theme, $project);
    $theme = ThemeJsonStep::assertFontSizes($theme, $project);

    $palette = $theme['settings']['color']['palette'];
    $families = $theme['settings']['typography']['fontFamilies'];
    $fontSizes = $theme['settings']['typography']['fontSizes'];
    assert_theme_json_presets_usable($palette, 'color');
    assert_theme_json_presets_usable($families, 'fontFamily');
    assert_theme_json_presets_usable($fontSizes, 'size');
    assert_eq(6, count($palette), 'five required plus usable unrelated row');
    assert_eq(3, count($families), 'two required plus usable unrelated row');
    assert_eq(7, count($fontSizes), 'six required plus usable unrelated row');
    assert_eq('keep', theme_json_preset($palette, 'base')['meta']);
    assert_eq('keep', theme_json_preset($families, 'body')['meta']);
    assert_eq('keep', theme_json_preset($fontSizes, 'body')['meta']);
    assert_eq('#101010', theme_json_preset($palette, 'primary')['color']);
    assert_eq('#101010', theme_json_preset($palette, 'secondary')['color']);
    assert_eq('#9C3D2E', theme_json_preset($palette, 'accent')['color']);

    $warnings = $project->readJson('warnings.json')['theme-json'] ?? [];
    assert_eq(18, count($warnings), 'nine discarded rows plus nine required fills');
    $joined = implode("\n", $warnings);
    assert_contains('settings.color.palette[index=3]', $joined);
    assert_contains('settings.typography.fontFamilies[index=2]', $joined);
    assert_contains('settings.typography.fontSizes[index=2]', $joined);
    assert_contains('authored type=string, value="garbage"', $joined);
    assert_contains('discarded invalid preset; delivered defaults/remaining usable presets', $joined);

    $firstWarnings = $project->readJson('warnings.json');
    $again = ThemeJsonStep::assertColors($theme, $project);
    $again = ThemeJsonStep::assertFonts($again, $project);
    $again = ThemeJsonStep::assertFontSizes($again, $project);
    assert_eq($theme, $again, 'row repair reaches a fixed point');
    assert_eq($firstWarnings, $project->readJson('warnings.json'), 'fixed point adds no warnings');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json records a warning for every defaulted preset', function () {
    [$project, $tmp] = theme_json_test_project('builder_tj_all_warnings_');
    $step = new ThemeJsonStep(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $step->consume($project, ['theme-json' => []]);

    $warnings = $project->readJson('warnings.json')['theme-json'] ?? [];
    assert_eq(13, count($warnings), 'five colors, two font families, six font sizes');
    foreach ($warnings as $warning) {
        assert_contains('theme/theme.json:', $warning, 'artifact path');
        assert_contains('missing or unusable', $warning, 'defect path');
        assert_contains('substituted', $warning, 'replacement value');
        assert_contains('from ', $warning, 'replacement source');
        assert_contains('delivered with repaired preset', $warning, 'delivered disposition');
    }
    foreach (['base', 'contrast', 'primary', 'secondary', 'accent'] as $slug) {
        assert_contains("settings.color.palette[slug={$slug}].color", implode("\n", $warnings));
    }
    foreach (['heading', 'body'] as $slug) {
        assert_contains("settings.typography.fontFamilies[slug={$slug}].fontFamily", implode("\n", $warnings));
    }
    foreach (['caption', 'body', 'lead', 'heading', 'section-title', 'display'] as $slug) {
        assert_contains("settings.typography.fontSizes[slug={$slug}].size", implode("\n", $warnings));
    }
    $log = $project->readText('logs/theme-json.log');
    foreach ($warnings as $warning) {
        assert_contains($warning, $log, 'consume log keeps detailed actionable warning row');
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json never fails on an empty model response', function () {
    [$project, $tmp] = theme_json_test_project('builder_tj_empty_');
    $project->writeText('theme/style.css', "/*\nTheme Name: Default Theme\n*/\n");
    foreach ([
        'theme/templates/index.html',
        'theme/templates/page.html',
        'theme/parts/header.html',
        'theme/parts/footer.html',
    ] as $file) {
        $project->writeText(
            $file,
            '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->',
        );
    }

    $step = new ThemeJsonStep(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $step->consume($project, ['theme-json' => []]);

    assert_eq([], ThemeValidator::validate($project));
    assert_eq([], PresetReferences::problems($project));
    assert_eq(3, $project->readJson('theme/theme.json')['version']);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json repairs a missing or unusable batch member and records the root defect', function () {
    foreach ([
        [],
        ['theme-json' => 'not-an-array'],
        ['theme-json' => ['scalar-list-value']],
        ['theme-json' => [7, ['settings' => []]]],
    ] as $index => $results) {
        [$project, $tmp] = theme_json_test_project("builder_tj_root_{$index}_");
        $step = new ThemeJsonStep(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
        $step->consume($project, $results);

        assert_true($project->exists('theme/theme.json'));
        $theme = $project->readJson('theme/theme.json');
        assert_true(!array_key_exists(0, $theme), 'unusable list root leaves no numeric root key');
        assert_eq(5, count($theme['settings']['color']['palette']));
        assert_eq(2, count($theme['settings']['typography']['fontFamilies']));
        assert_eq(6, count($theme['settings']['typography']['fontSizes']));
        $warnings = $project->readJson('warnings.json')['theme-json'] ?? [];
        assert_eq(14, count($warnings), 'root warning plus every defaulted preset');
        assert_contains('missing or unusable model output at document root', $warnings[0]);
        assert_contains('substituted an empty theme as repair input', $warnings[0]);
        assert_contains('delivered with complete documented defaults', $warnings[0]);
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('theme-json complete model response round-trips apart from existing normalizers', function () {
    [$project, $tmp] = theme_json_test_project('builder_tj_roundtrip_');
    $payload = valid_theme_payload();
    $payload['settings']['layout'] = ['contentSize' => '720px'];
    $payload['customTemplates'] = [['name' => 'landing', 'title' => 'Landing']];
    $payload['styles'] = [
        'color' => ['background' => 'var:preset|color|base'],
        'typography' => ['fontFamily' => 'var:preset|font-family|body'],
        'elements' => [
            'button' => ['color' => ['background' => 'var:preset|color|accent']],
            'link' => ['color' => ['text' => 'var:preset|color|primary']],
            'heading' => ['typography' => ['lineHeight' => '1.1']],
        ],
    ];

    $expected = $payload;
    $expected['$schema'] = 'https://schemas.wp.org/trunk/theme.json';
    $expected['version'] = 3;
    $expected = ThemeJsonStep::disableCoreDefaultPresets($expected);
    $expected = ThemeJsonStep::normalizeSpacingSettings($expected);
    $expected = ThemeJsonStep::normalizeRootPadding($expected);
    $expected['styles']['spacing']['blockGap'] = 'var:preset|spacing|md';

    $step = new ThemeJsonStep(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $step->consume($project, ['theme-json' => $payload]);

    assert_eq($expected, $project->readJson('theme/theme.json'));
    assert_true(!$project->exists('warnings.json'), 'complete model response needs no repair warning');
    assert_true(!$project->exists('logs/theme-json.log'), 'complete model response needs no repair log');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json leaves button, link and heading to the model', function () {
    [$project, $tmp] = theme_json_test_project('builder_tj_elements_');
    $elements = [
        'button' => [
            'color' => ['background' => '#ff00ff', 'text' => '#001122'],
            'border' => ['radius' => '13px'],
            ':hover' => ['color' => ['background' => '#112233']],
        ],
        'link' => [
            'color' => ['text' => '#445566'],
            ':hover' => ['color' => ['text' => '#778899']],
        ],
        'heading' => ['typography' => ['lineHeight' => '0.95']],
    ];

    $step = new ThemeJsonStep(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $step->consume($project, ['theme-json' => ['styles' => ['elements' => $elements]]]);

    assert_eq($elements, $project->readJson('theme/theme.json')['styles']['elements']);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json preset repairs reach a fixed point without duplicate warnings', function () {
    [$project, $tmp] = theme_json_test_project('builder_tj_fixed_point_');
    $theme = ThemeJsonStep::assertColors([], $project);
    $theme = ThemeJsonStep::assertFonts($theme, $project);
    $theme = ThemeJsonStep::assertFontSizes($theme, $project);
    $firstWarnings = $project->readJson('warnings.json');

    $repairedAgain = ThemeJsonStep::assertColors($theme, $project);
    $repairedAgain = ThemeJsonStep::assertFonts($repairedAgain, $project);
    $repairedAgain = ThemeJsonStep::assertFontSizes($repairedAgain, $project);

    assert_eq($theme, $repairedAgain);
    assert_eq($firstWarnings, $project->readJson('warnings.json'));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json discards unusable required rows before filling them', function () {
    [$project, $tmp] = theme_json_test_project('builder_tj_unusable_');
    $theme = valid_theme_payload();
    $theme['settings']['color']['palette'][2] = [
        'slug' => 'primary',
        'name' => 'Keep This Name',
        'color' => null,
        'custom-metadata' => 'keep',
    ];
    $theme['settings']['typography']['fontFamilies'][0]['fontFamily'] = [];
    $theme['settings']['typography']['fontSizes'][0]['size'] = null;

    $theme = ThemeJsonStep::assertColors($theme, $project);
    $theme = ThemeJsonStep::assertFonts($theme, $project);
    $theme = ThemeJsonStep::assertFontSizes($theme, $project);

    $primary = theme_json_preset($theme['settings']['color']['palette'], 'primary');
    assert_eq('#111', $primary['color'], 'unusable primary copies original contrast');
    assert_eq('Primary', $primary['name'], 'invalid authored row does not survive');
    assert_true(!isset($primary['custom-metadata']), 'invalid authored metadata discarded with row');
    assert_eq(
        'Source Sans 3, sans-serif',
        theme_json_preset($theme['settings']['typography']['fontFamilies'], 'heading')['fontFamily'],
    );
    assert_eq(
        '0.875rem',
        theme_json_preset($theme['settings']['typography']['fontSizes'], 'caption')['size'],
    );
    $warnings = $project->readJson('warnings.json')['theme-json'] ?? [];
    assert_eq(6, count($warnings), 'three dropped rows plus three replacement fills');
    assert_contains('discarded invalid preset', implode("\n", $warnings));
    exec('rm -rf ' . escapeshellarg($tmp));
});
