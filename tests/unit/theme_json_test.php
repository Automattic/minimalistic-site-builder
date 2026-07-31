<?php
declare(strict_types=1);

use Automattic\SiteBuild\ConcurrentGroup;
use Automattic\SiteBuild\ConcurrentStep;
use Automattic\SiteBuild\JsonBatchRecovery;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\StepDeclaration;
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
    seed_test_design_direction($project);

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

test('theme-json delivers a deterministic base theme when repaired model JSON is still malformed', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy neighborhood bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    seed_test_design_direction($project);

    $llm = new class implements Llm {
        public int $rounds = 0;

        public function complete(string $prompt, array $opts = []): string
        {
            throw new RuntimeException('unused');
        }

        public function completeJson(string $prompt, array $opts = []): array
        {
            throw new RuntimeException('unused');
        }

        public function completeJsonBatch(array $requests): array
        {
            return JsonBatchRecovery::run($requests, function (array $subset): array {
                $this->rounds++;
                $out = [];
                foreach ($subset as $key => $_request) {
                    $out[$key] = ['text' => '{"settings":{]'];
                }
                return $out;
            });
        }

        public function completeBatch(array $requests): \Automattic\SiteBuild\TextBatchResult
        {
            throw new RuntimeException('unused');
        }
    };

    (new ThemeJsonStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $theme = $project->readJson('theme/theme.json');
    assert_eq(3, $theme['version']);
    assert_eq(
        ['base', 'contrast', 'primary', 'secondary', 'accent'],
        array_column($theme['settings']['color']['palette'], 'slug'),
    );
    assert_eq(2, $llm->rounds, 'one malformed response and one malformed repair response');
    $joined = implode(' ', $project->readJson('warnings.json')['theme-json'] ?? []);
    assert_contains('generated JSON remained unusable', $joined);
    assert_contains('deterministic base theme delivered', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json keeps an operational JSON failure fatal', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy neighborhood bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    seed_test_design_direction($project);

    assert_throws(fn () => (new ThemeJsonStep(
        new FakeLlm(), // no queued response => plain RuntimeException
        new PromptRenderer(repo_path('prompts')),
    ))->run($project));

    assert_true(!$project->exists('theme/theme.json'));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json fallback retains a valid concurrent sibling', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy neighborhood bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    seed_test_design_direction($project);

    $sibling = new class implements ConcurrentStep {
        public array $consumed = [];
        public function id(): string { return 'sibling'; }
        public function label(): string { return 'Sibling'; }
        public function requests(Project $project): array { return ['result' => ['prompt' => 'Sibling']]; }
        public function consume(Project $project, array $results): void { $this->consumed = $results; }
        public function run(Project $project): void {}
        public function declaration(): StepDeclaration
        {
            return new StepDeclaration($this->id(), $this->label(), [], ['sibling.json'], false);
        }
    };
    $llm = new class implements Llm {
        public function complete(string $prompt, array $opts = []): string
        {
            throw new RuntimeException('unused');
        }
        public function completeJson(string $prompt, array $opts = []): array
        {
            throw new RuntimeException('unused');
        }
        public function completeJsonBatch(array $requests): array
        {
            return JsonBatchRecovery::run($requests, function (array $subset): array {
                $out = [];
                foreach ($subset as $key => $_request) {
                    $out[$key] = str_contains((string) $key, 'theme-json')
                        ? ['text' => '{"settings":{]']
                        : ['text' => '{"ok":"retained"}'];
                }
                return $out;
            });
        }
        public function completeBatch(array $requests): \Automattic\SiteBuild\TextBatchResult
        {
            throw new RuntimeException('unused');
        }
    };

    (new ConcurrentGroup($llm, [
        new ThemeJsonStep($llm, new PromptRenderer(repo_path('prompts'))),
        $sibling,
    ]))->run($project);

    assert_eq('retained', $sibling->consumed['result']['ok']);
    assert_eq(3, $project->readJson('theme/theme.json')['version']);
    assert_contains(
        'generated JSON remained unusable',
        implode(' ', $project->readJson('warnings.json')['theme-json'] ?? []),
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json forces a non-null blockGap so frontend spacing matches the editor', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy neighborhood bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    seed_test_design_direction($project);

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
    seed_test_design_direction($project);

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

test('theme-json fills a missing required color slug from the direction, then defaults', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy neighborhood bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    // The direction committed an accent hex — the fill honors it.
    $project->writeJson('designDirection.json', [
        'description' => 'Warm hearth tones.',
        'palette'     => ['accent' => '#C0FFEE'],
        'hero_blueprint' => \Automattic\SiteBuild\HeroBlueprint::defaultFor('cinematic-safe-zone'),
    ]);

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

    $palette = $project->readJson('theme/theme.json')['settings']['color']['palette'];
    $bySlug = array_column($palette, 'color', 'slug');
    assert_eq('#C0FFEE', $bySlug['accent'], 'the direction hex fills the gap');
    $joined = implode(' ', $project->readJson('warnings.json')['theme-json'] ?? []);
    assert_contains("palette missing slug 'accent'", $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('repairColors falls back to neutral readable defaults without a direction hex', function () {
    [$theme, $warnings] = \Automattic\SiteBuild\Steps\ThemeJsonStep::repairColors(
        ['settings' => ['color' => ['palette' => [
            ['slug' => 'base', 'color' => '#FFF8F0', 'name' => 'Base'],
        ]]]],
    );
    $bySlug = array_column($theme['settings']['color']['palette'], 'color', 'slug');
    assert_eq('#FFF8F0', $bySlug['base'], 'existing entries are never altered');
    assert_eq('#111111', $bySlug['contrast']);
    assert_eq('#111111', $bySlug['primary']);
    assert_eq(4, count($warnings));
});

test('repairColors and repairFonts record the malformed entries they remove', function () {
    // A removal is a rung-3 excision and must leave a durable record, even
    // when the removed entry was model garbage rather than an authored value.
    [, $warnings] = \Automattic\SiteBuild\Steps\ThemeJsonStep::repairColors(
        ['settings' => ['color' => ['palette' => ['bogus']]]],
    );
    assert_contains('palette: removed 1 malformed (non-object) entry', implode(' ', $warnings));

    [, $fontWarnings] = \Automattic\SiteBuild\Steps\ThemeJsonStep::repairFonts(
        ['settings' => ['typography' => ['fontFamilies' => ['bogus', 42]]]],
    );
    assert_contains('fontFamilies: removed 2 malformed (non-object) entries', implode(' ', $fontWarnings));
});

test('repairColors and repairFonts replace object-shaped required entries with no usable value', function () {
    $theme = valid_theme_payload();
    foreach ($theme['settings']['color']['palette'] as &$entry) {
        if ($entry['slug'] === 'contrast') {
            $entry = ['slug' => 'contrast'];
        }
    }
    unset($entry);

    [$theme, $warnings] = ThemeJsonStep::repairColors($theme);
    $bySlug = array_column($theme['settings']['color']['palette'], 'color', 'slug');
    assert_eq('#111111', $bySlug['contrast']);
    assert_contains("palette slug 'contrast': invalid color null; malformed entry removed", implode(' ', $warnings));
    assert_contains("palette missing slug 'contrast'; filled with #111111", implode(' ', $warnings));

    $theme = valid_theme_payload();
    foreach ($theme['settings']['typography']['fontFamilies'] as &$entry) {
        if ($entry['slug'] === 'heading') {
            $entry = ['slug' => 'heading'];
        }
    }
    unset($entry);

    [$theme, $fontWarnings] = ThemeJsonStep::repairFonts($theme);
    $bySlug = array_column($theme['settings']['typography']['fontFamilies'], 'fontFamily', 'slug');
    assert_eq('system-ui, sans-serif', $bySlug['heading']);
    assert_contains(
        "fontFamilies slug 'heading': invalid fontFamily null; malformed entry removed",
        implode(' ', $fontWarnings),
    );
    assert_contains("fontFamilies missing slug 'heading'; filled with the system stack", implode(' ', $fontWarnings));
});

test('theme-json forces useRootPaddingAwareAlignments when root side padding is set', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy neighborhood bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    seed_test_design_direction($project);

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

test('theme-json fills a missing required font slug with the system stack', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy neighborhood bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    seed_test_design_direction($project);

    $payload = valid_theme_payload();
    $payload['settings']['typography']['fontFamilies'] = [
        ['slug' => 'body', 'fontFamily' => 'X', 'name' => 'Body'],
    ];

    $llm = new FakeLlm();
    $llm->queueJson($payload);
    $renderer = new PromptRenderer(repo_path('prompts'));
    (new ThemeJsonStep($llm, $renderer))->run($project);

    $families = $project->readJson('theme/theme.json')['settings']['typography']['fontFamilies'];
    $bySlug = array_column($families, 'fontFamily', 'slug');
    assert_eq('X', $bySlug['body'], 'existing entries are never altered');
    assert_eq('system-ui, sans-serif', $bySlug['heading']);
    $joined = implode(' ', $project->readJson('warnings.json')['theme-json'] ?? []);
    assert_contains("fontFamilies missing slug 'heading'", $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

function theme_json_preset(array $presets, string $slug): array
{
    foreach ($presets as $preset) {
        if (($preset['slug'] ?? null) === $slug) {
            return $preset;
        }
    }
    return [];
}

test('theme-json declares every artifact it writes', function () {
    $step = new ThemeJsonStep(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    assert_eq(['theme/theme.json', 'warnings.json'], $step->declaration()->writes);
});

test('theme-json font-size repair preserves model sizes and fills each omission', function () {
    [$theme, $warnings] = ThemeJsonStep::repairFontSizes([
        'settings' => ['typography' => [
            'fluid' => true,
            'fontSizes' => [
                ['slug' => 'caption', 'name' => 'Tiny', 'size' => '1rem', 'fluid' => false],
                ['slug' => 'heading', 'name' => 'Broken', 'size' => ''],
                ['slug' => 'poster', 'name' => 'Poster', 'size' => '9rem'],
            ],
        ]],
    ]);

    $sizes = $theme['settings']['typography']['fontSizes'];
    assert_eq('1rem', theme_json_preset($sizes, 'caption')['size'], 'usable model size preserved');
    assert_eq(false, theme_json_preset($sizes, 'caption')['fluid'], 'model metadata preserved');
    assert_eq('1.75rem', theme_json_preset($sizes, 'heading')['size'], 'unusable size refilled from the profile');
    assert_eq('9rem', theme_json_preset($sizes, 'poster')['size'], 'unrelated preset preserved');
    assert_eq('clamp(3rem, 7vw, 6rem)', theme_json_preset($sizes, 'display')['size']);
    assert_eq(true, $theme['settings']['typography']['fluid'], 'sibling typography settings survive');
    assert_true($warnings !== [], 'every repair is warned');
});

test('theme-json font-size repair fills the whole scale when the model omits it', function () {
    [$theme, $warnings] = ThemeJsonStep::repairFontSizes([]);
    assert_eq(
        ['caption', 'body', 'lead', 'heading', 'section-title', 'display'],
        array_column($theme['settings']['typography']['fontSizes'], 'slug'),
    );
    assert_eq(6, count($warnings));
});

test('theme-json font-size repair rejects invalid CSS and duplicate slugs', function () {
    [$theme, $warnings] = ThemeJsonStep::repairFontSizes([
        'settings' => ['typography' => ['fontSizes' => [
            ['slug' => 'body', 'name' => 'Broken body', 'size' => 'banana'],
            ['slug' => 'caption', 'name' => 'Caption', 'size' => '1rem'],
            ['slug' => 'caption', 'name' => 'Duplicate caption', 'size' => '2rem'],
            [
                'slug' => 'lead',
                'name' => 'Lead',
                'size' => 'clamp(1rem, calc(1rem + 1vw), 2rem)',
            ],
            ['slug' => 'poster', 'name' => 'Poster', 'size' => 'var(--wp--custom--poster-size)'],
            ['slug' => 'unsafe-function', 'name' => 'Unsafe', 'size' => 'url(javascript:alert(1))'],
            ['slug' => 'invalid-calc', 'name' => 'Invalid calc', 'size' => 'calc(banana)'],
            ['slug' => 'injected', 'name' => 'Injected', 'size' => '1rem; color: red'],
        ]]],
    ]);

    $sizes = $theme['settings']['typography']['fontSizes'];
    assert_eq('1.125rem', theme_json_preset($sizes, 'body')['size'], 'invalid required size replaced');
    assert_eq('1rem', theme_json_preset($sizes, 'caption')['size'], 'first valid duplicate wins');
    assert_eq(
        1,
        count(array_filter($sizes, static fn (array $entry): bool => $entry['slug'] === 'caption')),
        'duplicate removed',
    );
    assert_eq(
        'clamp(1rem, calc(1rem + 1vw), 2rem)',
        theme_json_preset($sizes, 'lead')['size'],
        'safe nested sizing functions survive',
    );
    assert_eq(
        'var(--wp--custom--poster-size)',
        theme_json_preset($sizes, 'poster')['size'],
        'safe custom-property reference survives',
    );
    foreach (['unsafe-function', 'invalid-calc', 'injected'] as $slug) {
        assert_eq([], theme_json_preset($sizes, $slug), "{$slug} removed");
    }
    $joined = implode(' ', $warnings);
    assert_contains("fontSizes slug 'body': invalid size \"banana\"", $joined);
    assert_contains("fontSizes duplicate slug 'caption'", $joined);
    assert_contains('disposition removed duplicate', $joined);

    [$fixedPoint, $fixedPointWarnings] = ThemeJsonStep::repairFontSizes($theme);
    assert_eq($theme, $fixedPoint, 'repair reaches a fixed point');
    assert_eq([], $fixedPointWarnings, 'fixed point produces no warnings');
});

test('theme-json keeps an authored preset value when only its name is missing', function () {
    $payload = valid_theme_payload();
    unset($payload['settings']['color']['palette'][0]['name']);
    unset($payload['settings']['typography']['fontFamilies'][0]['name']);
    $payload['settings']['color']['palette'][0]['metadata'] = ['source' => 'model'];
    $payload['settings']['typography']['fontFamilies'][0]['metadata'] = ['source' => 'model'];
    $payload['settings']['typography']['fontSizes'] = [
        ['slug' => 'caption', 'size' => '0.8rem', 'metadata' => ['source' => 'model']],
    ];

    [$payload] = ThemeJsonStep::repairColors($payload);
    [$payload] = ThemeJsonStep::repairFonts($payload);
    [$payload] = ThemeJsonStep::repairFontSizes($payload);

    $base = theme_json_preset($payload['settings']['color']['palette'], 'base');
    $heading = theme_json_preset($payload['settings']['typography']['fontFamilies'], 'heading');
    $caption = theme_json_preset($payload['settings']['typography']['fontSizes'], 'caption');

    assert_eq('#fff', $base['color'], 'authored color kept');
    assert_eq('Fraunces, serif', $heading['fontFamily'], 'authored family kept');
    assert_eq('0.8rem', $caption['size'], 'authored size kept, not replaced by the profile');
    assert_eq('Caption', $caption['name'], 'name synthesized from the slug');
    foreach ([$base, $heading, $caption] as $preset) {
        assert_eq(['source' => 'model'], $preset['metadata'], 'model metadata survives');
    }
});

test('theme-json merge helper fills omissions and repairs malformed map nodes', function () {
    $scaffold = [
        'node' => [
            'nested' => ['model-leaf' => 'scaffold', 'missing' => 'filled'],
            'scalar' => 'scaffold',
            'list' => ['scaffold'],
            'empty-map' => ['missing' => 'filled'],
            'map-vs-list' => ['missing' => 'scaffold'],
            'list-vs-map' => ['scaffold'],
        ],
        'new-root' => ['value' => 'filled'],
    ];
    $model = [
        'node' => [
            'nested' => ['model-leaf' => 'model'],
            'scalar' => 17,
            'list' => ['model'],
            'empty-map' => [],
            'map-vs-list' => ['model-list'],
            'list-vs-map' => ['model' => 'map'],
        ],
        'unrelated' => true,
    ];

    $merged = ThemeJsonStep::mergeScaffoldDefaults($scaffold, $model);
    assert_eq(['model-leaf' => 'model', 'missing' => 'filled'], $merged['node']['nested']);
    assert_eq('scaffold', $merged['node']['scalar'], 'wrong scalar type replaced');
    assert_eq(['model'], $merged['node']['list'], 'model list wins');
    assert_eq(['missing' => 'filled'], $merged['node']['empty-map'], 'empty map takes the scaffold');
    assert_eq(
        ['missing' => 'scaffold'],
        $merged['node']['map-vs-list'],
        'scaffold map replaces malformed model list',
    );
    assert_eq(['model' => 'map'], $merged['node']['list-vs-map'], 'scaffold list plus model map keeps model map');
    assert_eq(true, $merged['unrelated'], 'unrelated model branch survives');
    assert_eq(['value' => 'filled'], $merged['new-root'], 'absent root filled');
});

test('theme-json repairs malformed scaffold shapes with durable actionable warnings', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_scaffold_shape_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cold-water swim club']);
    $project->writeJson('siteSpec.json', ['name' => 'Teal Valley']);
    seed_test_design_direction($project);

    $payload = valid_theme_payload();
    $payload['styles'] = [
        'spacing' => ['bad'],
        'blocks' => ['bad'],
        'elements' => [
            'h1' => ['typography' => [
                'fontFamily' => 17,
                'fontSize' => false,
                'fontWeight' => '800',
            ]],
            'h2' => ['typography' => ['fontSize' => ['bad']]],
        ],
    ];
    $llm = new FakeLlm();
    $llm->queueJson($payload);
    (new ThemeJsonStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $theme = $project->readJson('theme/theme.json');
    assert_true(!array_is_list($theme['styles']['blocks']), 'malformed blocks list replaced with scaffold map');
    assert_eq(
        'var:preset|font-size|display',
        $theme['styles']['elements']['h1']['typography']['fontSize'],
        'wrong scalar type replaced',
    );
    assert_eq(
        'var:preset|font-family|heading',
        $theme['styles']['elements']['h1']['typography']['fontFamily'],
        'wrong scalar type replaced independently',
    );
    assert_eq(
        'var:preset|font-size|section-title',
        $theme['styles']['elements']['h2']['typography']['fontSize'],
        'malformed array leaf replaced',
    );
    assert_eq('var:preset|spacing|md', $theme['styles']['spacing']['blockGap']);
    assert_eq('800', $theme['styles']['elements']['h1']['typography']['fontWeight'], 'valid sibling retained');

    $joined = implode(' ', $project->readJson('warnings.json')['theme-json'] ?? []);
    assert_contains('theme/theme.json styles.spacing: authored ["bad"]', $joined);
    assert_contains('theme/theme.json styles.blocks: authored ["bad"]', $joined);
    assert_contains('theme/theme.json styles.elements.h1.typography.fontFamily: authored 17', $joined);
    assert_contains('theme/theme.json styles.elements.h1.typography.fontSize: authored false', $joined);
    assert_contains('theme/theme.json styles.elements.h2.typography.fontSize: authored ["bad"]', $joined);
    assert_contains('delivered', $joined);
    assert_contains('disposition replaced malformed shape with scaffold default', $joined);

    [$fixedPoint, $fixedPointWarnings] = ThemeJsonStep::repairScaffold($theme);
    assert_eq($theme, $fixedPoint, 'scaffold repair reaches a fixed point');
    assert_eq([], $fixedPointWarnings, 'fixed point produces no warnings');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json repairs malformed top-level styles values with durable warnings', function () {
    foreach (['bad', ['bad']] as $authored) {
        $tmp = sys_get_temp_dir() . '/builder_tj_styles_shape_' . uniqid();
        $project = (new ProjectStore($tmp))->create('demo');
        $project->writeJson('meta.json', ['prompt' => 'A cold-water swim club']);
        $project->writeJson('siteSpec.json', ['name' => 'Teal Valley']);
        seed_test_design_direction($project);

        $payload = valid_theme_payload();
        $payload['styles'] = $authored;
        $llm = new FakeLlm();
        $llm->queueJson($payload);
        (new ThemeJsonStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

        $theme = $project->readJson('theme/theme.json');
        assert_eq('var:preset|spacing|md', $theme['styles']['spacing']['blockGap']);
        assert_eq('var:preset|color|base', $theme['styles']['color']['background']);
        $joined = implode(' ', $project->readJson('warnings.json')['theme-json'] ?? []);
        assert_contains(
            'theme/theme.json styles: authored '
                . json_encode($authored, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $joined,
        );
        assert_contains('delivered build-supplied styles object', $joined);
        assert_contains('disposition replaced malformed shape before normalization', $joined);
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('theme-json scaffold lets a model typography override win at the leaf', function () {
    $theme = ThemeJsonStep::applyScaffold([
        'styles' => ['blocks' => [
            'core/quote' => [
                'typography' => [
                    'fontSize' => 'var:preset|font-size|display',
                    'fontWeight' => '800',
                ],
            ],
            'core/group' => ['spacing' => ['blockGap' => 'var:preset|spacing|sm']],
        ]],
    ]);

    $quote = $theme['styles']['blocks']['core/quote'];
    assert_eq('var:preset|font-size|display', $quote['typography']['fontSize'], 'model leaf wins');
    assert_eq('800', $quote['typography']['fontWeight'], 'model sibling survives');
    assert_eq('var:preset|font-family|body', $quote['typography']['fontFamily'], 'scaffold sibling fills');
    assert_eq(
        'var:preset|spacing|sm',
        $theme['styles']['blocks']['core/group']['spacing']['blockGap'],
        'unrelated model block survives',
    );
});

test('theme-json removes context-free text colors with warnings and preserves siblings', function () {
    [$theme, $warnings] = ThemeJsonStep::repairScaffold([
        'styles' => [
            'elements' => [
                'h1' => ['color' => [
                    'text' => 'var:preset|color|primary',
                    'background' => 'var:preset|color|base',
                ]],
                'h2' => ['color' => ['bad']],
                'h3' => ['color' => 'bad'],
                'caption' => ['color' => ['text' => 'var:preset|color|secondary']],
                'heading' => ['color' => ['text' => 'var:preset|color|primary']],
            ],
            'blocks' => [
                'core/quote' => ['color' => [
                    'text' => 'var:preset|color|accent',
                    'background' => 'var:preset|color|base',
                ]],
                'core/group' => ['color' => ['background' => 'var:preset|color|base']],
            ],
        ],
    ]);

    assert_eq(
        ['background' => 'var:preset|color|base'],
        $theme['styles']['elements']['h1']['color'],
        'element background sibling survives',
    );
    assert_true(!array_key_exists('color', $theme['styles']['elements']['h2']), 'malformed color removed');
    assert_true(!array_key_exists('color', $theme['styles']['elements']['h3']), 'scalar color removed');
    assert_true(!array_key_exists('color', $theme['styles']['elements']['caption']), 'empty color pruned');
    assert_eq(
        'var:preset|color|primary',
        $theme['styles']['elements']['heading']['color']['text'],
        'contrast-visible heading color survives',
    );
    assert_eq(
        ['background' => 'var:preset|color|base'],
        $theme['styles']['blocks']['core/quote']['color'],
        'block background sibling survives',
    );
    assert_eq(
        ['background' => 'var:preset|color|base'],
        $theme['styles']['blocks']['core/group']['color'],
        'unrelated block color survives',
    );

    $joined = implode(' ', $warnings);
    assert_contains(
        'theme/theme.json styles.elements.h1.color.text: authored "var:preset|color|primary"; delivered removed',
        $joined,
    );
    assert_contains('theme/theme.json styles.elements.h2.color: authored ["bad"]; delivered removed', $joined);
    assert_contains('theme/theme.json styles.elements.h3.color: authored "bad"; delivered removed', $joined);
    assert_contains(
        'theme/theme.json styles.blocks.core/quote.color.text: authored "var:preset|color|accent"; delivered removed',
        $joined,
    );
    assert_contains('disposition removed context-free text color invisible to contrast repair', $joined);

    [$fixedPoint, $fixedPointWarnings] = ThemeJsonStep::repairScaffold($theme);
    assert_eq($theme, $fixedPoint, 'context color repair reaches a fixed point');
    assert_eq([], $fixedPointWarnings, 'fixed point produces no warnings');
});

test('theme-json scaffold never writes button, link or heading', function () {
    $emptyElements = ThemeJsonStep::applyScaffold([])['styles']['elements'];
    foreach (['button', 'link', 'heading'] as $element) {
        assert_true(!array_key_exists($element, $emptyElements), "{$element} absent from scaffold");
    }

    $modelElements = [
        'button' => ['color' => ['background' => '#ff00ff']],
        'link' => ['color' => ['text' => '#112233']],
        'heading' => ['typography' => ['lineHeight' => '0.95']],
    ];
    $wired = ThemeJsonStep::applyScaffold(['styles' => ['elements' => $modelElements]]);
    foreach ($modelElements as $name => $expected) {
        assert_eq($expected, $wired['styles']['elements'][$name], "{$name} preserved");
    }
});

test('theme-json scaffold adds no contextual block or element text colors', function () {
    $styles = ThemeJsonStep::applyScaffold([])['styles'];
    foreach ($styles['elements'] as $name => $element) {
        assert_true(!array_key_exists('color', $element), "{$name} inherits its rendered context color");
    }
    foreach ($styles['blocks'] as $name => $block) {
        assert_true(!array_key_exists('color', $block), "{$name} inherits its rendered context color");
    }
    assert_true(!array_key_exists('core/separator', $styles['blocks']), 'separator has no typography wiring');
    assert_eq(
        ['background' => 'var:preset|color|base', 'text' => 'var:preset|color|contrast'],
        $styles['color'],
        'only the global page colors are scaffolded',
    );
});

test('theme-json scaffold carries no decorative values', function () {
    $scaffold = ThemeJsonStep::applyScaffold([]);
    $keys = [];
    $values = [];
    $walk = function (array $node) use (&$walk, &$keys, &$values): void {
        foreach ($node as $key => $value) {
            $keys[] = (string) $key;
            if (is_array($value)) {
                $walk($value);
            } else {
                $values[] = $value;
            }
        }
    };
    $walk($scaffold);

    foreach (['border', 'radius', 'shadow', 'spacing', 'gradient'] as $decorativeKey) {
        assert_true(!in_array($decorativeKey, $keys, true), "{$decorativeKey} absent");
    }
    foreach ($values as $value) {
        assert_true(
            $value === '1.6'
                || (is_string($value) && preg_match('/^var:preset\|(color|font-family|font-size)\|[a-z0-9-]+$/', $value) === 1),
            'every scaffold leaf is a preset reference or the frozen unitless line-height',
        );
    }
});

test('theme-json scaffold references only frozen preset slugs', function () {
    $json = json_encode(ThemeJsonStep::applyScaffold([]), JSON_THROW_ON_ERROR);
    preg_match_all('/var:preset\|([a-z-]+)\|([a-z0-9-]+)/', $json, $matches, PREG_SET_ORDER);
    assert_true($matches !== [], 'scaffold has preset references');

    $allowed = [
        'color' => ['base', 'contrast', 'primary', 'secondary', 'accent'],
        'font-family' => ['heading', 'body'],
        'font-size' => ['caption', 'body', 'lead', 'heading', 'section-title', 'display'],
    ];
    foreach ($matches as $match) {
        assert_true(isset($allowed[$match[1]]), "known preset type {$match[1]}");
        assert_true(in_array($match[2], $allowed[$match[1]], true), "frozen {$match[1]} slug {$match[2]}");
    }
});

test('theme-json sends no json_schema', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_no_schema_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cold-water swim club']);
    $project->writeJson('siteSpec.json', ['name' => 'Teal Valley']);
    seed_test_design_direction($project);

    $request = (new ThemeJsonStep(
        new FakeLlm(),
        new PromptRenderer(repo_path('prompts')),
    ))->requests($project)['theme-json'];

    assert_true(!array_key_exists('json_schema', $request));
    assert_eq(['prompt'], array_keys($request), 'default request contains prompt only');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json receives the front hero blueprint as focused sizing context', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_hero_context_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cold-water swim club']);
    $project->writeJson('siteSpec.json', ['name' => 'Teal Valley']);
    seed_test_design_direction($project);

    $request = (new ThemeJsonStep(
        new FakeLlm(),
        new PromptRenderer(repo_path('prompts')),
    ))->requests($project)['theme-json'];
    $prompt = $request['prompt'];

    assert_contains('FRONT-PAGE HERO BLUEPRINT (front-page type sizing context only)', $prompt);
    assert_contains('cinematic-safe-zone', $prompt);
    assert_contains('headline', strtolower($prompt));
    assert_eq(1, substr_count($prompt, 'cinematic-safe-zone'), 'recipe appears only in focused context');
    assert_true(!str_contains($prompt, 'hero_composition'), 'removed prose field is not a sizing source');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json run wires the scaffold into the written theme', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_scaffold_run_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cold-water swim club']);
    $project->writeJson('siteSpec.json', ['name' => 'Teal Valley']);
    seed_test_design_direction($project);

    $llm = new FakeLlm();
    $llm->queueJson(valid_theme_payload());
    (new ThemeJsonStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $theme = $project->readJson('theme/theme.json');
    assert_eq('var:preset|color|base', $theme['styles']['color']['background']);
    assert_eq('var:preset|font-family|heading', $theme['styles']['elements']['h2']['typography']['fontFamily']);
    assert_eq('var:preset|font-size|section-title', $theme['styles']['elements']['h2']['typography']['fontSize']);
    assert_eq('var:preset|font-size|caption', $theme['styles']['elements']['caption']['typography']['fontSize']);
    assert_eq('var:preset|font-family|body', $theme['styles']['blocks']['core/quote']['typography']['fontFamily']);
    assert_true(!array_key_exists('button', $theme['styles']['elements']), 'button stays model-authored');
    assert_eq(
        ['caption', 'body', 'lead', 'heading', 'section-title', 'display'],
        array_column($theme['settings']['typography']['fontSizes'], 'slug'),
        'the scale the scaffold references exists',
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json never fails on an empty model response', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_empty_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cold-water swim club']);
    $project->writeJson('siteSpec.json', ['name' => 'Teal Valley']);
    seed_test_design_direction($project);

    $llm = new FakeLlm();
    $llm->queueJson([]);
    (new ThemeJsonStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $theme = $project->readJson('theme/theme.json');
    assert_eq(3, $theme['version']);
    assert_eq(
        ['base', 'contrast', 'primary', 'secondary', 'accent'],
        array_column($theme['settings']['color']['palette'], 'slug'),
    );
    assert_eq(['heading', 'body'], array_column($theme['settings']['typography']['fontFamilies'], 'slug'));
    assert_eq('var:preset|color|base', $theme['styles']['color']['background'], 'scaffold still wires');
    assert_true(($project->readJson('warnings.json')['theme-json'] ?? []) !== [], 'every fill is warned');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json scaffold sets no heading color ContrastFixStep cannot see', function () {
    // ContrastFixStep models heading color as elements.heading.color.text ??
    // styles.color.text and never reads h1/h2/h3. A scaffold color on those
    // would render one color while the contrast pass reasoned about another.
    $elements = ThemeJsonStep::applyScaffold([])['styles']['elements'];
    foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $heading) {
        assert_true(
            !array_key_exists('color', $elements[$heading]),
            "{$heading} inherits the global text color",
        );
    }
    assert_eq('var:preset|color|contrast', ThemeJsonStep::applyScaffold([])['styles']['color']['text']);
});

test('theme-json representative model response survives scaffold and validates', function () {
    // A real captured Opus response. Proves the scaffold + the three preset
    // repairs leave a realistic theme structurally valid. This pre-prompt
    // response also proves forbidden context-free colors degrade safely.
    $tmp = sys_get_temp_dir() . '/builder_tj_representative_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cold-water swim club']);
    $project->writeJson('siteSpec.json', ['name' => 'Teal Valley']);
    seed_test_design_direction($project);

    $payload = json_decode(
        file_get_contents(repo_path('tests/fixtures/theme-json/representative-model-response.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $llm = new FakeLlm();
    $llm->queueJson($payload);
    (new ThemeJsonStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $theme = $project->readJson('theme/theme.json');
    $warnings = $project->exists('warnings.json')
        ? ($project->readJson('warnings.json')['theme-json'] ?? [])
        : [];
    assert_true($warnings !== [], 'legacy context-free colors are reported');
    $joined = implode(' ', $warnings);
    assert_contains('styles.elements.h1.color.text', $joined);
    assert_contains('styles.blocks.core/quote.color.text', $joined);
    assert_eq(3, $theme['version']);
    assert_eq(
        ['caption', 'body', 'lead', 'heading', 'section-title', 'display'],
        array_column($theme['settings']['typography']['fontSizes'], 'slug'),
        'the model scale is preserved, not replaced',
    );

    // Model typography and contrast-visible element colors still win.
    $el = $theme['styles']['elements'];
    assert_true(!array_key_exists('color', $el['h1']), 'context-free h1 color removed');
    assert_eq('1.05', $el['h1']['typography']['lineHeight'], 'model taste key survives');
    assert_eq('var(--wp--preset--font-size--lead)', $el['h4']['typography']['fontSize'], 'model h4 size wins over scaffold');
    assert_eq('var(--wp--preset--color--base)', $el['button']['color']['text'], 'button untouched');
    assert_eq(
        'var(--wp--preset--font-family--heading)',
        $theme['styles']['blocks']['core/quote']['typography']['fontFamily'],
        'model block wiring wins over scaffold',
    );
    foreach ($theme['styles']['blocks'] as $name => $block) {
        $color = is_array($block) ? ($block['color'] ?? null) : null;
        assert_true(
            !is_array($color) || !array_key_exists('text', $color),
            "{$name} has no direct context-free text color",
        );
    }
    // Blocks the model never mentioned still get their context-safe typography wiring.
    assert_eq(
        'var:preset|font-family|body',
        $theme['styles']['blocks']['core/list']['typography']['fontFamily'],
    );
    assert_eq(
        'var:preset|font-size|body',
        $theme['styles']['blocks']['core/list']['typography']['fontSize'],
    );
    assert_true(
        !array_key_exists('color', $theme['styles']['blocks']['core/list']),
        'scaffold does not force a text color ContrastFix cannot see',
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});
