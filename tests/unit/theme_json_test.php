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

test('repairShapeWiring installs every explicit corner language and leaves a missing commitment alone', function () {
    [$soft, $repairs] = ThemeJsonStep::repairShapeWiring([], 'soft');
    assert_eq('0.5rem', $soft['styles']['blocks']['core/image']['border']['radius']);
    assert_eq('0.5rem', $soft['styles']['elements']['button']['border']['radius']);
    assert_eq([], $repairs);
    [$round, $repairs] = ThemeJsonStep::repairShapeWiring([], 'round');
    assert_eq('1.25rem', $round['styles']['blocks']['core/image']['border']['radius']);
    assert_eq('9999px', $round['styles']['elements']['button']['border']['radius']);
    assert_eq([], $repairs);

    [$sharp, $repairs] = ThemeJsonStep::repairShapeWiring([], 'sharp');
    assert_true(!isset($sharp['styles']['blocks']['core/image']), 'sharp does not emit an image radius');
    assert_eq('0', $sharp['styles']['elements']['button']['border']['radius']);
    assert_eq([], $repairs);

    $legacy = ['styles' => [
        'blocks' => ['core/image' => ['border' => ['radius' => '2px']]],
        'elements' => ['button' => ['border' => ['radius' => '3px']]],
    ]];
    [$none, $repairs] = ThemeJsonStep::repairShapeWiring($legacy, '');
    assert_eq($legacy, $none, 'a direction persisted before the shape field is a complete no-op');
    assert_eq([], $repairs);
});

test('repairShapeWiring overrides conflicts, reports repairs, preserves siblings, and reaches a fixed point', function () {
    $authored = ['styles' => [
        'blocks' => ['core/image' => [
            'border' => ['radius' => '2px', 'color' => '#123456'],
            'typography' => ['fontSize' => 'var:preset|font-size|caption'],
        ], 'core/button' => [
            'border' => ['radius' => '4px', 'color' => '#654321'],
            ':active' => ['border' => ['radius' => '6px', 'color' => '#abcdef']],
        ]],
        'elements' => ['button' => [
            'border' => ['radius' => '3px', 'width' => '1px'],
            'color' => ['background' => 'var:preset|color|accent'],
            ':hover' => ['border' => ['radius' => '42px', 'color' => '#fedcba']],
            ':focus-visible' => ['border' => ['radius' => '7px', 'width' => '2px']],
        ]],
    ]];

    [$round, $repairs] = ThemeJsonStep::repairShapeWiring($authored, 'round');
    assert_eq('1.25rem', $round['styles']['blocks']['core/image']['border']['radius']);
    assert_eq('#123456', $round['styles']['blocks']['core/image']['border']['color']);
    assert_eq('var:preset|font-size|caption', $round['styles']['blocks']['core/image']['typography']['fontSize']);
    assert_true(!isset($round['styles']['blocks']['core/button']['border']['radius']));
    assert_eq('#654321', $round['styles']['blocks']['core/button']['border']['color']);
    assert_true(!isset($round['styles']['blocks']['core/button'][':active']['border']['radius']));
    assert_eq('#abcdef', $round['styles']['blocks']['core/button'][':active']['border']['color']);
    assert_eq('9999px', $round['styles']['elements']['button']['border']['radius']);
    assert_eq('1px', $round['styles']['elements']['button']['border']['width']);
    assert_eq('var:preset|color|accent', $round['styles']['elements']['button']['color']['background']);
    assert_true(!isset($round['styles']['elements']['button'][':hover']['border']['radius']));
    assert_eq('#fedcba', $round['styles']['elements']['button'][':hover']['border']['color']);
    assert_true(!isset($round['styles']['elements']['button'][':focus-visible']['border']['radius']));
    assert_eq('2px', $round['styles']['elements']['button'][':focus-visible']['border']['width']);
    assert_eq(6, count($repairs));
    $joined = implode(' ', $repairs);
    assert_contains('theme/theme.json styles.blocks.core/image.border.radius: authored "2px"; delivered "1.25rem"', $joined);
    assert_contains('theme/theme.json styles.blocks.core/button.border.radius: authored "4px"; delivered removed', $joined);
    assert_contains('theme/theme.json styles.blocks.core/button.:active.border.radius: authored "6px"; delivered removed', $joined);
    assert_contains('theme/theme.json styles.elements.button.border.radius: authored "3px"; delivered "9999px"', $joined);
    assert_contains('theme/theme.json styles.elements.button.:hover.border.radius: authored "42px"; delivered removed', $joined);
    assert_contains('theme/theme.json styles.elements.button.:focus-visible.border.radius: authored "7px"; delivered removed', $joined);
    assert_contains('disposition replaced conflicting radius to enforce committed round shape', $joined);
    [$fixedRound, $fixedWarnings] = ThemeJsonStep::repairShapeWiring($round, 'round');
    assert_eq($round, $fixedRound);
    assert_eq([], $fixedWarnings, 'fixed point produces no warnings');

    [$sharp, $repairs] = ThemeJsonStep::repairShapeWiring($authored, 'sharp');
    assert_true(!isset($sharp['styles']['blocks']['core/image']['border']['radius']));
    assert_eq('#123456', $sharp['styles']['blocks']['core/image']['border']['color']);
    assert_true(!isset($sharp['styles']['blocks']['core/button']['border']['radius']));
    assert_eq('#654321', $sharp['styles']['blocks']['core/button']['border']['color']);
    assert_true(!isset($sharp['styles']['blocks']['core/button'][':active']['border']['radius']));
    assert_eq('#abcdef', $sharp['styles']['blocks']['core/button'][':active']['border']['color']);
    assert_eq('0', $sharp['styles']['elements']['button']['border']['radius']);
    assert_eq('1px', $sharp['styles']['elements']['button']['border']['width']);
    assert_true(!isset($sharp['styles']['elements']['button'][':hover']['border']['radius']));
    assert_eq('#fedcba', $sharp['styles']['elements']['button'][':hover']['border']['color']);
    assert_true(!isset($sharp['styles']['elements']['button'][':focus-visible']['border']['radius']));
    assert_eq('2px', $sharp['styles']['elements']['button'][':focus-visible']['border']['width']);
    assert_eq(6, count($repairs));
    $joined = implode(' ', $repairs);
    assert_contains('theme/theme.json styles.blocks.core/image.border.radius: authored "2px"; delivered removed', $joined);
    assert_contains('theme/theme.json styles.blocks.core/button.border.radius: authored "4px"; delivered removed', $joined);
    assert_contains('theme/theme.json styles.blocks.core/button.:active.border.radius: authored "6px"; delivered removed', $joined);
    assert_contains('theme/theme.json styles.elements.button.border.radius: authored "3px"; delivered "0"', $joined);
    assert_contains('theme/theme.json styles.elements.button.:hover.border.radius: authored "42px"; delivered removed', $joined);
    assert_contains('theme/theme.json styles.elements.button.:focus-visible.border.radius: authored "7px"; delivered removed', $joined);
    assert_contains('disposition removed conflicting radius to enforce committed sharp shape', $joined);
    [$fixedSharp, $fixedWarnings] = ThemeJsonStep::repairShapeWiring($sharp, 'sharp');
    assert_eq($sharp, $fixedSharp);
    assert_eq([], $fixedWarnings, 'fixed point produces no warnings');
});

test('repairShapeWiring removes responsive variation and nested button/image radius overrides', function () {
    $authored = ['styles' => [
        'blocks' => [
            'core/image' => [
                'border' => ['radius' => '2px', 'color' => '#111111'],
                'elements' => ['caption' => ['border' => ['radius' => '6px', 'width' => '1px']]],
                '@mobile' => ['border' => ['radius' => '8px', 'width' => '1px']],
                'variations' => ['default' => [
                    'border' => ['radius' => '7px', 'color' => '#222222'],
                ]],
            ],
            'core/button' => [
                'border' => ['radius' => '4px', 'color' => '#333333'],
                '@tablet' => ['border' => ['radius' => '5px', 'width' => '2px']],
                'variations' => ['outline' => [
                    'border' => ['radius' => '6px', 'style' => 'solid'],
                    ':hover' => ['border' => ['radius' => '9px', 'color' => '#444444']],
                ]],
            ],
            'core/buttons' => ['elements' => ['button' => [
                'border' => ['radius' => '10px', 'width' => '3px'],
                ':focus' => ['border' => ['radius' => '11px', 'color' => '#555555']],
                '@mobile' => ['border' => ['radius' => '12px', 'style' => 'dashed']],
            ]]],
            'core/group' => ['variations' => ['framed' => ['blocks' => [
                'core/button' => ['border' => ['radius' => '13px', 'color' => '#666666']],
                'core/image' => ['border' => ['radius' => '14px', 'width' => '4px']],
            ]]]],
        ],
        'elements' => ['button' => [
            'border' => ['radius' => '3px', 'width' => '5px'],
            '@mobile' => ['border' => ['radius' => '15px', 'color' => '#777777']],
        ]],
    ]];

    [$repaired, $repairs, $warnings] = ThemeJsonStep::repairShapeWiring($authored, 'soft');

    assert_eq('0.5rem', $repaired['styles']['blocks']['core/image']['border']['radius']);
    assert_eq('#111111', $repaired['styles']['blocks']['core/image']['border']['color']);
    assert_eq(
        '6px',
        $repaired['styles']['blocks']['core/image']['elements']['caption']['border']['radius'],
        'caption geometry does not inherit image ownership',
    );
    assert_eq('0.5rem', $repaired['styles']['elements']['button']['border']['radius']);
    assert_eq('5px', $repaired['styles']['elements']['button']['border']['width']);
    foreach ([
        ['blocks', 'core/image', '@mobile'],
        ['blocks', 'core/image', 'variations', 'default'],
        ['blocks', 'core/button'],
        ['blocks', 'core/button', '@tablet'],
        ['blocks', 'core/button', 'variations', 'outline'],
        ['blocks', 'core/button', 'variations', 'outline', ':hover'],
        ['blocks', 'core/buttons', 'elements', 'button'],
        ['blocks', 'core/buttons', 'elements', 'button', ':focus'],
        ['blocks', 'core/buttons', 'elements', 'button', '@mobile'],
        ['blocks', 'core/group', 'variations', 'framed', 'blocks', 'core/button'],
        ['blocks', 'core/group', 'variations', 'framed', 'blocks', 'core/image'],
        ['elements', 'button', '@mobile'],
    ] as $path) {
        $node = $repaired['styles'];
        foreach ($path as $key) {
            $node = $node[$key];
        }
        assert_true(!isset($node['border']['radius']), implode('.', $path) . ' radius removed');
    }
    assert_eq('1px', $repaired['styles']['blocks']['core/image']['@mobile']['border']['width']);
    assert_eq('solid', $repaired['styles']['blocks']['core/button']['variations']['outline']['border']['style']);
    assert_eq('#555555', $repaired['styles']['blocks']['core/buttons']['elements']['button'][':focus']['border']['color']);
    assert_eq('4px', $repaired['styles']['blocks']['core/group']['variations']['framed']['blocks']['core/image']['border']['width']);
    assert_eq([], $warnings, 'structured authoritative repairs stay out of warnings.json');
    $joined = implode(' ', $repairs);
    foreach ([
        'styles.blocks.core/image.@mobile.border.radius',
        'styles.blocks.core/button.variations.outline.:hover.border.radius',
        'styles.blocks.core/buttons.elements.button.border.radius',
        'styles.blocks.core/group.variations.framed.blocks.core/image.border.radius',
        'styles.elements.button.@mobile.border.radius',
    ] as $path) {
        assert_contains($path, $joined);
    }

    [$fixed, $fixedRepairs, $fixedWarnings] = ThemeJsonStep::repairShapeWiring($repaired, 'soft');
    assert_eq($repaired, $fixed);
    assert_eq([], $fixedRepairs);
    assert_eq([], $fixedWarnings);
});

test('repairShapeWiring removes only custom CSS that reaches image/button corners', function () {
    $authored = ['styles' => [
        'css' => '.card { border-radius: 2rem; color: red; } '
            . '.wp-block-image img { all: var(--shape-reset, initial) !important; filter: none; } '
            . '.plain { margin: 0; }',
        'blocks' => [
            'core/button' => ['css' => '& { border-radius: 4px; color: currentColor; }'],
            'core/image' => [
                'css' => 'animation-name: image-shape; '
                    . '&:not(:has(.excluded)) { all: initial; filter: none; } '
                    . '& figcaption { animation-name: caption-shape; } '
                    . '@keyframes image-shape { to { border-radius: 4rem; transform: none; } } '
                    . '@keyframes caption-shape { to { border-radius: 5rem; opacity: 1; } }',
                'variations' => ['default' => [
                    'css' => '& img { border-start-start-radius: 6px; filter: none; } '
                        . '& figcaption { border-radius: 5px; padding: 2px; }',
                ]],
            ],
            'core/group' => ['css' => '& { border-radius: 8px; padding: 1rem; } '
                . '& .wp-block-button__link { border-radius: 1px; color: inherit; }'],
        ],
    ]];

    [$repaired, $repairs, $warnings] = ThemeJsonStep::repairShapeWiring($authored, 'round');

    assert_contains('.card { border-radius: 2rem', $repaired['styles']['css']);
    assert_true(!str_contains($repaired['styles']['css'], 'all: var('));
    assert_contains('color: red', $repaired['styles']['css']);
    assert_contains('filter: none', $repaired['styles']['css']);
    assert_contains('margin: 0', $repaired['styles']['css']);
    assert_contains('color: currentColor', $repaired['styles']['blocks']['core/button']['css']);
    $baseImageCss = $repaired['styles']['blocks']['core/image']['css'];
    assert_true(!str_contains($baseImageCss, 'all: initial'), 'balanced implicit-root reset is repaired');
    assert_contains('filter: none', $baseImageCss, 'implicit-root declaration sibling survives');
    assert_true(!str_contains($baseImageCss, 'border-radius: 4rem'), 'owned image keyframe is repaired');
    assert_contains('transform: none', $baseImageCss, 'non-corner image motion survives');
    assert_contains('border-radius: 5rem', $baseImageCss, 'caption-only keyframe geometry survives');
    $imageCss = $repaired['styles']['blocks']['core/image']['variations']['default']['css'];
    assert_true(!str_contains($imageCss, 'border-start-start-radius'));
    assert_contains('filter: none', $imageCss);
    assert_contains('figcaption { border-radius: 5px', $imageCss, 'caption rule survives');
    $groupCss = $repaired['styles']['blocks']['core/group']['css'];
    assert_contains('& { border-radius: 8px', $groupCss, 'generic group geometry survives');
    assert_contains('padding: 1rem', $groupCss);
    assert_true(!str_contains($groupCss, 'border-radius: 1px'), 'descendant button override removed');
    assert_contains('color: inherit', $groupCss);
    $joinedRepairs = implode(' ', $repairs);
    assert_contains('styles.css', $joinedRepairs);
    assert_contains('styles.blocks.core/button.css', $joinedRepairs);
    assert_contains('styles.blocks.core/image.variations.default.css', $joinedRepairs);
    assert_contains('styles.blocks.core/group.css', $joinedRepairs);
    assert_eq([], $warnings, 'exact owned-selector repairs do not lose unrelated geometry');

    [$fixed, $fixedRepairs, $fixedWarnings] = ThemeJsonStep::repairShapeWiring($repaired, 'round');
    assert_eq($repaired, $fixed);
    assert_eq([], $fixedRepairs);
    assert_eq([], $fixedWarnings);
});

test('repairShapeWiring removes structurally unsafe owned CSS with durable context', function () {
    $authored = ['styles' => [
        'css' => '.wp-block-image img { color:red; border-radius:99px',
        'blocks' => [
            'core/image' => ['css' => '& img { all:initial!important'],
            'core/group' => ['css' => '& { border-radius:8px'],
        ],
    ]];

    [$repaired, $repairs, $warnings] = ThemeJsonStep::repairShapeWiring($authored, 'soft');

    assert_true(!isset($repaired['styles']['css']), 'unsafe global image override is isolated');
    assert_true(!isset($repaired['styles']['blocks']['core/image']['css']), 'unsafe scoped image override is isolated');
    assert_eq(
        '& { border-radius:8px',
        $repaired['styles']['blocks']['core/group']['css'],
        'malformed generic group geometry remains outside shape ownership',
    );
    assert_eq(2, count($warnings));
    $joined = implode(' ', $warnings);
    assert_contains('theme/theme.json styles.css', $joined);
    assert_contains('theme/theme.json styles.blocks.core/image.css', $joined);
    assert_contains('authored', $joined);
    assert_contains('delivered removed', $joined);
    assert_contains('structurally malformed', $joined);
    assert_contains('could not be isolated safely', $joined);

    [$fixed, $fixedRepairs, $fixedWarnings] = ThemeJsonStep::repairShapeWiring($repaired, 'soft');
    assert_eq($repaired, $fixed);
    assert_eq([], $fixedRepairs);
    assert_eq([], $fixedWarnings);
});

test('repairShapeWiring isolates malformed radius containers with actionable repair notes', function () {
    $malformed = ['styles' => [
        'blocks' => ['core/image' => ['border' => 'rounded']],
        'elements' => ['button' => ['border' => [
            'radius' => ['topLeft' => '2px'],
            'width' => '1px',
        ]]],
    ]];
    [$repaired, $repairNotes] = ThemeJsonStep::repairShapeWiring($malformed, 'soft');
    assert_eq('0.5rem', $repaired['styles']['blocks']['core/image']['border']['radius']);
    assert_eq('0.5rem', $repaired['styles']['elements']['button']['border']['radius']);
    assert_eq('1px', $repaired['styles']['elements']['button']['border']['width']);
    assert_eq(2, count($repairNotes));
    $joined = implode(' ', $repairNotes);
    assert_contains('styles.blocks.core/image.border: authored "rounded"; delivered {"radius":"0.5rem"}', $joined);
    assert_contains('styles.elements.button.border.radius: authored {"topLeft":"2px"}; delivered "0.5rem"', $joined);
    assert_contains('disposition replaced malformed container to enforce committed soft shape', $joined);
    [$fixed, $fixedWarnings] = ThemeJsonStep::repairShapeWiring($repaired, 'soft');
    assert_eq($repaired, $fixed);
    assert_eq([], $fixedWarnings, 'fixed point produces no warnings');
});

test('theme-json wires the direction-committed shape into the written theme', function () {
    $tmp = sys_get_temp_dir() . '/builder_tjshape_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy neighborhood bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    seed_test_design_direction($project, 'cinematic-safe-zone', ['shape' => 'round']);

    $payload = valid_theme_payload();
    $payload['styles'] = [
        'blocks' => ['core/image' => ['border' => ['radius' => '2px', 'color' => '#123456']]],
        'elements' => ['button' => ['border' => ['radius' => '3px', 'width' => '1px']]],
    ];
    $llm = new FakeLlm();
    $llm->queueJson($payload);
    (new ThemeJsonStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $theme = $project->readJson('theme/theme.json');
    assert_eq('1.25rem', $theme['styles']['blocks']['core/image']['border']['radius']);
    assert_eq('#123456', $theme['styles']['blocks']['core/image']['border']['color']);
    assert_eq('9999px', $theme['styles']['elements']['button']['border']['radius']);
    assert_eq('1px', $theme['styles']['elements']['button']['border']['width']);
    $report = $project->readText('logs/theme-json-shape.txt');
    assert_contains('styles.blocks.core/image.border.radius: authored "2px"; delivered "1.25rem"', $report);
    assert_contains('styles.elements.button.border.radius: authored "3px"; delivered "9999px"', $report);
    $warnings = implode(' ', $project->readJson('warnings.json')['theme-json'] ?? []);
    assert_true(!str_contains($warnings, 'border.radius'), 'successful shape repairs stay out of warnings.json');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json wires no image radius without a shape commitment', function () {
    $tmp = sys_get_temp_dir() . '/builder_tjnoshape_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy neighborhood bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    seed_test_design_direction($project);

    $llm = new FakeLlm();
    $llm->queueJson(valid_theme_payload());
    (new ThemeJsonStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $theme = $project->readJson('theme/theme.json');
    assert_true(!isset($theme['styles']['blocks']['core/image']['border']), 'direction predates the field, no radius');
    assert_true(!isset($theme['styles']['elements']['button']['border']), 'direction predates the field, no button radius');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json safely leaves a garbled persisted shape uncommitted', function () {
    $tmp = sys_get_temp_dir() . '/builder_tjbadshape_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy neighborhood bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    seed_test_design_direction($project, 'cinematic-safe-zone', ['shape' => ['round']]);

    $llm = new FakeLlm();
    $llm->queueJson(valid_theme_payload());
    (new ThemeJsonStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $theme = $project->readJson('theme/theme.json');
    assert_true(!isset($theme['styles']['blocks']['core/image']['border']), 'invalid shape does not guess a radius');
    assert_true(!isset($theme['styles']['elements']['button']['border']), 'invalid shape does not guess button geometry');
    exec('rm -rf ' . escapeshellarg($tmp));
});

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
    assert_eq(['xs', 'sm', 'md', 'lg', 'xl', 'xxl'], array_column($theme['settings']['spacing']['spacingSizes'], 'slug'));
    assert_eq(false, $theme['settings']['color']['defaultPalette']);
    assert_eq(false, $theme['settings']['color']['defaultGradients']);
    assert_eq(false, $theme['settings']['color']['defaultDuotone']);
    assert_eq(false, $theme['settings']['typography']['defaultFontSizes']);
    assert_eq(false, $theme['settings']['spacing']['defaultSpacingSizes']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json deterministically enforces the direction font families', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A literary journal']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    seed_test_design_direction($project, overrides: [
        'description' => 'Editorial typography.',
        'type' => [
            'heading' => [
                'family' => 'Fraunces',
                'weights' => [700, 900],
                'italic' => false,
                'axes' => ['opsz' => ['min' => 9.0, 'max' => 144.0]],
            ],
            'body' => [
                'family' => 'Source Serif 4',
                'weights' => [400, 600],
                'italic' => true,
                'axes' => [],
            ],
        ],
    ]);

    $payload = valid_theme_payload();
    $payload['settings']['typography']['fontFamilies'] = [
        ['slug' => 'heading', 'fontFamily' => '"Oswald", sans-serif', 'name' => 'Heading'],
        ['slug' => 'body', 'fontFamily' => '"Lora", serif', 'name' => 'Body'],
    ];
    $llm = new FakeLlm();
    $llm->queueJson($payload);

    (new ThemeJsonStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $families = array_column(
        $project->readJson('theme/theme.json')['settings']['typography']['fontFamilies'],
        'fontFamily',
        'slug',
    );
    assert_eq('"Fraunces", sans-serif', $families['heading']);
    assert_eq('"Source Serif 4", serif', $families['body']);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json delivers a deterministic base theme when repaired model JSON is still malformed', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy neighborhood bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    seed_test_design_direction($project, 'cinematic-safe-zone', ['shape' => 'sharp']);

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
    assert_true(!isset($theme['styles']['blocks']['core/image']['border']['radius']));
    assert_eq('0', $theme['styles']['elements']['button']['border']['radius']);
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
        ['slug' => 'xs', 'name' => 'Extra Small', 'size' => 'clamp(0.25rem, 0.5vw, 0.5rem)'],
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
    assert_eq(['xs', 'sm', 'md', 'lg', 'xl', 'xxl'], array_column($spacing['spacingSizes'], 'slug'));
    assert_eq('clamp(5rem, 7vw, 7rem)', $spacing['spacingSizes'][5]['size']);
    assert_eq(['rem'], $spacing['units'], 'unrelated spacing settings preserved');
    assert_eq(['custom' => false], $theme['settings']['color'], 'non-spacing settings preserved');
    assert_eq(['color' => ['text' => '#123456']], $theme['styles'], 'styles preserved');

    $theme = ThemeJsonStep::normalizeSpacingSettings(['settings' => ['spacing' => 'invalid']]);
    assert_eq(true, $theme['settings']['spacing']['blockGap']);
    assert_eq(6, count($theme['settings']['spacing']['spacingSizes']));
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
    // The direction committed an accent hex that clears the 4.5:1 floor its
    // slug carries on this base — the fill honors it.
    $project->writeJson('designDirection.json', [
        'description' => 'Warm hearth tones.',
        'palette'     => ['accent' => '#B4541E'],
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
    assert_eq('#B4541E', $bySlug['accent'], 'the direction hex fills the gap');
    $joined = implode(' ', $project->readJson('warnings.json')['theme-json'] ?? []);
    assert_contains("palette missing slug 'accent'", $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('repairAccentCaption wires captions to the accent family when one shipped', function () {
    [$theme] = ThemeJsonStep::repairAccentCaption([
        'settings' => ['typography' => ['fontFamilies' => [
            ['slug' => 'heading', 'fontFamily' => 'Oswald, sans-serif'],
            ['slug' => 'body', 'fontFamily' => 'Source Sans 3, sans-serif'],
            ['slug' => 'accent', 'fontFamily' => '"Caveat", cursive'],
        ]]],
    ]);
    assert_eq(
        'var:preset|font-family|accent',
        $theme['styles']['elements']['caption']['typography']['fontFamily'],
    );
    assert_eq(
        'var:preset|font-family|accent',
        $theme['styles']['blocks']['core/image']['typography']['fontFamily'],
    );
});

test('repairAccentCaption stays quiet when no accent family shipped', function () {
    [$theme] = ThemeJsonStep::repairAccentCaption([
        'settings' => ['typography' => ['fontFamilies' => [
            ['slug' => 'heading', 'fontFamily' => 'Oswald, sans-serif'],
            ['slug' => 'body', 'fontFamily' => 'Source Sans 3, sans-serif'],
        ]]],
    ]);
    assert_eq(null, $theme['styles']['elements']['caption']['typography']['fontFamily'] ?? null);
});

test('repairAccentCaption records the caption family it overrides', function () {
    // The direction's accent wins over a model-authored caption face, but the
    // override is a repair like any other and has to reach warnings.json.
    $theme = [
        'settings' => ['typography' => ['fontFamilies' => [
            ['slug' => 'heading', 'fontFamily' => 'Oswald, sans-serif'],
            ['slug' => 'body', 'fontFamily' => 'Source Sans 3, sans-serif'],
            ['slug' => 'accent', 'fontFamily' => '"Caveat", cursive'],
        ]]],
        'styles' => ['elements' => ['caption' => ['typography' => [
            'fontFamily' => 'var:preset|font-family|body',
            'fontSize' => 'var:preset|font-size|caption',
        ]]]],
    ];
    [$repaired, $warnings] = ThemeJsonStep::repairAccentCaption($theme);
    assert_eq(1, count($warnings), 'the overridden caption family is recorded');
    $joined = implode(' ', $warnings);
    assert_contains('styles.elements.caption.typography.fontFamily: authored', $joined);
    assert_contains('var:preset|font-family|body', $joined);
    assert_contains('delivered var:preset|font-family|accent', $joined);
    assert_eq(
        'var:preset|font-size|caption',
        $repaired['styles']['elements']['caption']['typography']['fontSize'],
        'sibling caption typography survives the override',
    );

    // A build that authored nothing there is not an override, so it stays out
    // of the ledger — and a second pass never re-reports the first one.
    [$again, $repeat] = ThemeJsonStep::repairAccentCaption($repaired);
    assert_eq($repaired, $again, 'the override reaches a fixed point');
    assert_eq([], $repeat);
});

test('theme-json repairs malformed caption containers before accent wiring', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_accent_shape_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A neighborhood bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    seed_test_design_direction($project, 'cinematic-safe-zone', [
        'description' => 'Hand-lettered flavor cards.',
        'type' => [
            'accent' => [
                'family' => 'Caveat',
                'weights' => [400],
                'italic' => false,
                'axes' => [],
                'character' => 'hand labels',
            ],
        ],
    ]);

    $payload = valid_theme_payload();
    $payload['settings']['typography']['fontFamilies'][] = [
        'slug' => 'accent',
        'name' => 'Accent',
        'fontFamily' => '"Caveat", cursive',
    ];
    $payload['styles']['elements'] = ['bad'];
    $payload['styles']['blocks'] = ['bad'];
    $llm = new FakeLlm();
    $llm->queueJson($payload);
    (new ThemeJsonStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $theme = $project->readJson('theme/theme.json');
    assert_true(!array_key_exists(0, $theme['styles']['elements']), 'malformed elements list removed');
    assert_true(!array_key_exists(0, $theme['styles']['blocks']), 'malformed blocks list removed');
    assert_eq(
        'var:preset|font-family|accent',
        $theme['styles']['elements']['caption']['typography']['fontFamily'],
    );
    assert_eq(
        'var:preset|font-family|accent',
        $theme['styles']['blocks']['core/image']['typography']['fontFamily'],
    );
    $warnings = implode(' ', $project->readJson('warnings.json')['theme-json'] ?? []);
    assert_contains('theme/theme.json styles.elements: authored ["bad"]', $warnings);
    assert_contains('theme/theme.json styles.blocks: authored ["bad"]', $warnings);

    [$fixed, $scaffoldWarnings] = ThemeJsonStep::repairScaffold($theme);
    [$fixed, $accentWarnings] = ThemeJsonStep::repairAccentCaption($fixed);
    assert_eq($theme, $fixed, 'repair order reaches a fixed point');
    assert_eq([], array_merge($scaffoldWarnings, $accentWarnings));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('repairFonts drops a third family the direction never committed', function () {
    // prompts/theme-json.md:71 asks for exactly heading and body unless the
    // direction names an accent, so a model-invented third face ships a font
    // nobody chose — and repairAccentCaption would put it on every caption.
    [$theme, $warnings] = ThemeJsonStep::repairFonts(
        ['settings' => ['typography' => ['fontFamilies' => [
            ['slug' => 'heading', 'fontFamily' => '"Oswald", sans-serif', 'name' => 'Heading'],
            ['slug' => 'body', 'fontFamily' => '"Inter", sans-serif', 'name' => 'Body'],
            ['slug' => 'accent', 'fontFamily' => '"Caveat", cursive', 'name' => 'Accent'],
        ]]]],
        [
            'heading' => ['family' => 'Oswald'],
            'body' => ['family' => 'Inter'],
        ],
    );
    $slugs = array_column($theme['settings']['typography']['fontFamilies'], 'slug');
    assert_eq(['heading', 'body'], $slugs, 'only the two committed families ship');
    $joined = implode(' ', $warnings);
    assert_contains("fontFamilies slug 'accent'", $joined);
    assert_contains('Caveat', $joined);
    assert_contains('committed no type.accent.family', $joined);

    [$again, $repeat] = ThemeJsonStep::repairFonts($theme, [
        'heading' => ['family' => 'Oswald'],
        'body' => ['family' => 'Inter'],
    ]);
    assert_eq($theme, $again, 'the drop reaches a fixed point');
    assert_eq([], $repeat);
});

test('repairFonts keeps a model accent the direction did commit', function () {
    [$theme, $warnings] = ThemeJsonStep::repairFonts(
        ['settings' => ['typography' => ['fontFamilies' => [
            ['slug' => 'heading', 'fontFamily' => '"Oswald", sans-serif', 'name' => 'Heading'],
            ['slug' => 'body', 'fontFamily' => '"Inter", sans-serif', 'name' => 'Body'],
            ['slug' => 'accent', 'fontFamily' => '"Caveat", cursive', 'name' => 'Accent'],
        ]]]],
        [
            'heading' => ['family' => 'Oswald'],
            'body' => ['family' => 'Inter'],
            'accent' => ['family' => 'Caveat'],
        ],
    );
    $bySlug = array_column($theme['settings']['typography']['fontFamilies'], 'fontFamily', 'slug');
    assert_contains('Caveat', $bySlug['accent']);
    assert_eq([], $warnings);
});

test('the accent caption wiring survives the HTML-first typography strip', function () {
    // HTML-first authoring prompts never write fontFamily presets, so
    // styles.elements.caption is the whole mechanism that references the
    // accent face on that graph. removeGeneratedControlTypography runs one
    // line earlier and must not take it with the nav/button/link typography.
    $tmp = sys_get_temp_dir() . '/builder_tj_accent_htmlfirst_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A neighborhood bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $project->writeText('design/site.css', ':root{--x:1}body{color:#111;background:#fff}');
    seed_test_design_direction($project, 'cinematic-safe-zone', [
        'description' => 'Hand-lettered flavor cards.',
        'type' => [
            'accent' => [
                'family' => 'Caveat',
                'weights' => [400],
                'italic' => false,
                'axes' => [],
                'character' => 'hand labels',
            ],
        ],
    ]);

    $payload = valid_theme_payload();
    $payload['settings']['typography']['fontFamilies'][] = [
        'slug' => 'accent',
        'name' => 'Accent',
        'fontFamily' => '"Caveat", cursive',
    ];
    $payload['styles']['elements']['button']['typography'] = ['fontFamily' => 'var:preset|font-family|heading'];
    $payload['styles']['elements']['link']['typography'] = ['fontFamily' => 'var:preset|font-family|heading'];
    $llm = new FakeLlm();
    $llm->queueJson($payload);
    (new ThemeJsonStep($llm, new PromptRenderer(repo_path('prompts')), htmlFirst: true))->run($project);

    $theme = $project->readJson('theme/theme.json');
    assert_eq(
        'var:preset|font-family|accent',
        $theme['styles']['elements']['caption']['typography']['fontFamily'],
        'HTML-first captions still reference the accent family',
    );
    assert_true(
        !isset($theme['styles']['elements']['button']['typography']),
        'the control typography strip still ran',
    );
    assert_true(
        !isset($theme['styles']['elements']['link']['typography']),
        'the control typography strip still ran',
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('repairFonts adds an optional accent family from the direction', function () {
    [$theme, $warnings] = ThemeJsonStep::repairFonts(
        ['settings' => ['typography' => ['fontFamilies' => [
            ['slug' => 'heading', 'fontFamily' => 'Oswald, sans-serif', 'name' => 'Heading'],
            ['slug' => 'body', 'fontFamily' => 'Source Sans 3, sans-serif', 'name' => 'Body'],
        ]]]],
        ['accent' => ['family' => 'Caveat', 'weights' => [400], 'italic' => false, 'axes' => [], 'character' => '']],
    );
    $bySlug = array_column($theme['settings']['typography']['fontFamilies'], 'fontFamily', 'slug');
    assert_contains('Caveat', $bySlug['accent']);
    assert_contains("missing slug 'accent'", implode(' ', $warnings));
});

test('repairFonts does not invent an accent family when the direction left it empty', function () {
    [$theme] = ThemeJsonStep::repairFonts(
        ['settings' => ['typography' => ['fontFamilies' => [
            ['slug' => 'heading', 'fontFamily' => 'Oswald, sans-serif', 'name' => 'Heading'],
            ['slug' => 'body', 'fontFamily' => 'Source Sans 3, sans-serif', 'name' => 'Body'],
        ]]]],
        ['accent' => ['family' => '', 'weights' => [], 'italic' => false, 'axes' => [], 'character' => '']],
    );
    $slugs = array_column($theme['settings']['typography']['fontFamilies'], 'slug');
    assert_true(!in_array('accent', $slugs, true));
});

test('repairColors overwrites a drifted hex with the direction value', function () {
    // Both direction hexes clear the floor prompts/theme-json.md states for
    // their slug on this base, so neither is held back by the contrast gate.
    $preferred = ['secondary' => '#8A5A2B', 'accent' => '#B4541E'];
    [$theme, $warnings, $repairs] = ThemeJsonStep::repairColors(
        ['settings' => ['color' => ['palette' => [
            ['slug' => 'base', 'color' => '#FFFFFF', 'name' => 'Base'],
            ['slug' => 'contrast', 'color' => '#111111', 'name' => 'Contrast'],
            ['slug' => 'primary', 'color' => '#111111', 'name' => 'Primary'],
            ['slug' => 'secondary', 'color' => '#00FF00', 'name' => 'Secondary'],
            ['slug' => 'accent', 'color' => '#0000FF', 'name' => 'Accent'],
        ]]]],
        $preferred,
    );
    $bySlug = array_column($theme['settings']['color']['palette'], 'color', 'slug');
    assert_eq('#8A5A2B', $bySlug['secondary']);
    assert_eq('#B4541E', $bySlug['accent']);
    assert_eq([], $warnings, 'an applied writeback is not a delivered defect');
    $joined = implode(' ', $repairs);
    assert_contains("palette slug 'secondary'", $joined);
    assert_contains('hue distance exceeded 30 degrees', $joined);

    [$again, $repeatWarnings, $repeatRepairs] = ThemeJsonStep::repairColors($theme, $preferred);
    assert_eq($theme, $again, 'palette drift repair reaches a fixed point');
    assert_eq([], $repeatWarnings, 'fixed palette emits no repeat repair warnings');
    assert_eq([], $repeatRepairs, 'fixed palette emits no repeat repair receipts');
});

test('repairColors keeps a model hex the direction would make unreadable', function () {
    // prompts/theme-json.md lets the model nudge a hex to clear WCAG. Here it
    // darkened secondary to 7.46:1; the direction's own hex scores 4.48:1,
    // under the 4.5 floor that file states for secondary on base.
    $preferred = ['secondary' => '#777777'];
    [$theme, $warnings, $repairs] = ThemeJsonStep::repairColors(
        ['settings' => ['color' => ['palette' => [
            ['slug' => 'base', 'color' => '#FFFFFF', 'name' => 'Base'],
            ['slug' => 'contrast', 'color' => '#111111', 'name' => 'Contrast'],
            ['slug' => 'primary', 'color' => '#111111', 'name' => 'Primary'],
            ['slug' => 'secondary', 'color' => '#555555', 'name' => 'Secondary'],
            ['slug' => 'accent', 'color' => '#B4541E', 'name' => 'Accent'],
        ]]]],
        $preferred,
    );
    $bySlug = array_column($theme['settings']['color']['palette'], 'color', 'slug');
    assert_eq('#555555', $bySlug['secondary'], 'the readable model hex survives the writeback');
    assert_eq([], $repairs, 'a rejected writeback is not a repair');
    $joined = implode(' ', $warnings);
    assert_contains("palette slug 'secondary'", $joined);
    assert_contains('kept the model hex', $joined);
    assert_contains('#777777', $joined);
    assert_contains('4.48:1', $joined);
    assert_contains('7.46:1', $joined);

    [$again] = ThemeJsonStep::repairColors($theme, $preferred);
    assert_eq($theme, $again, 'a rejected writeback reaches a fixed point too');
});

test('repairColors will not fill a missing slug with an unreadable direction hex', function () {
    // The gate the writeback applies is not skippable by omitting the slug:
    // #777777 scores 4.48:1 on white, under the 4.5 floor secondary carries,
    // so the neutral default ships instead of an unreadable caption color.
    [$theme, $warnings, $repairs] = ThemeJsonStep::repairColors(
        ['settings' => ['color' => ['palette' => [
            ['slug' => 'base', 'color' => '#FFFFFF', 'name' => 'Base'],
            ['slug' => 'contrast', 'color' => '#111111', 'name' => 'Contrast'],
            ['slug' => 'primary', 'color' => '#111111', 'name' => 'Primary'],
            ['slug' => 'accent', 'color' => '#B4541E', 'name' => 'Accent'],
        ]]]],
        ['secondary' => '#777777'],
    );
    $bySlug = array_column($theme['settings']['color']['palette'], 'color', 'slug');
    assert_eq('#444444', $bySlug['secondary'], 'the neutral default outranks an unreadable direction hex');
    assert_eq([], $repairs, 'a gated fill is not a writeback receipt');
    $joined = implode(' ', $warnings);
    assert_contains("palette missing slug 'secondary'", $joined);
    assert_contains('4.48:1', $joined);
    assert_contains('below the 4.5:1 floor', $joined);
});

test('repairColors never fills a gap with a hex that reads worse than the one it replaced', function () {
    // The neutral defaults are tuned for a light page. On a dark delivered base
    // #444444 scores 1.94:1, so a gate that always fell back to it would make
    // the slug less readable, not more. A readable direction hex is taken
    // outright; an unreadable one still wins when the neutral is worse.
    [$readable] = ThemeJsonStep::repairColors(
        ['settings' => ['color' => ['palette' => [
            ['slug' => 'contrast', 'color' => '#FFFFFF', 'name' => 'Contrast'],
            ['slug' => 'primary', 'color' => '#EEEEEE', 'name' => 'Primary'],
            ['slug' => 'accent', 'color' => '#FFB4A2', 'name' => 'Accent'],
        ]]]],
        ['base' => '#111111', 'secondary' => '#C9C4BC'],
    );
    $bySlug = array_column($readable['settings']['color']['palette'], 'color', 'slug');
    assert_eq('#111111', $bySlug['base']);
    assert_eq('#C9C4BC', $bySlug['secondary'], '10.89:1 clears the floor and is taken outright');

    // #6A6A6A fails the 4.5 floor at 3.49:1, but the neutral it would be
    // swapped for is worse still, so the direction hex has to survive.
    [$degraded, $warnings] = ThemeJsonStep::repairColors(
        ['settings' => ['color' => ['palette' => [
            ['slug' => 'contrast', 'color' => '#FFFFFF', 'name' => 'Contrast'],
            ['slug' => 'primary', 'color' => '#EEEEEE', 'name' => 'Primary'],
            ['slug' => 'accent', 'color' => '#FFB4A2', 'name' => 'Accent'],
        ]]]],
        ['base' => '#111111', 'secondary' => '#6A6A6A'],
    );
    $bySlug = array_column($degraded['settings']['color']['palette'], 'color', 'slug');
    assert_eq('#6A6A6A', $bySlug['secondary'], 'the better-reading hex survives even when it fails the floor');
    $joined = implode(' ', $warnings);
    assert_contains('3.49:1', $joined);
    assert_contains('below the 4.5:1 floor', $joined);
});

test('repairColors reports hue drift on the branch where the named color is lost', function () {
    // The writeback is rejected, so the delivered hex stays far from the color
    // the direction named — that is the case worth naming in warnings.json.
    [, $warnings] = ThemeJsonStep::repairColors(
        ['settings' => ['color' => ['palette' => [
            ['slug' => 'base', 'color' => '#FFFFFF', 'name' => 'Base'],
            ['slug' => 'contrast', 'color' => '#111111', 'name' => 'Contrast'],
            ['slug' => 'primary', 'color' => '#111111', 'name' => 'Primary'],
            ['slug' => 'secondary', 'color' => '#1B5E20', 'name' => 'Secondary'],
            ['slug' => 'accent', 'color' => '#B4541E', 'name' => 'Accent'],
        ]]]],
        ['secondary' => '#E88AA0'],
    );
    $joined = implode(' ', $warnings);
    assert_contains('kept the model hex', $joined);
    assert_contains('hue distance exceeded 30 degrees', $joined);
});

test('repairColors judges each slug against the floor its own role carries', function () {
    // accent is judged by "base on accent >= 4.5:1" (button labels), so a
    // direction accent that clears 4.5 lands even though it would fail the
    // 7:1 that contrast carries.
    [$theme, $warnings] = ThemeJsonStep::repairColors(
        ['settings' => ['color' => ['palette' => [
            ['slug' => 'base', 'color' => '#FFFFFF', 'name' => 'Base'],
            ['slug' => 'contrast', 'color' => '#111111', 'name' => 'Contrast'],
            ['slug' => 'primary', 'color' => '#111111', 'name' => 'Primary'],
            ['slug' => 'secondary', 'color' => '#555555', 'name' => 'Secondary'],
            ['slug' => 'accent', 'color' => '#0000FF', 'name' => 'Accent'],
        ]]]],
        ['accent' => '#B4541E'],
    );
    $bySlug = array_column($theme['settings']['color']['palette'], 'color', 'slug');
    assert_eq('#B4541E', $bySlug['accent'], '4.97:1 clears the accent floor');
    assert_eq([], $warnings);
});

test('repairColors measures against the base the writeback itself delivers', function () {
    // The direction moves the page to a dark base, so the model's near-black
    // secondary stops being readable and its own secondary is the right call.
    [$theme, $warnings] = ThemeJsonStep::repairColors(
        ['settings' => ['color' => ['palette' => [
            ['slug' => 'base', 'color' => '#FFFFFF', 'name' => 'Base'],
            ['slug' => 'contrast', 'color' => '#111111', 'name' => 'Contrast'],
            ['slug' => 'primary', 'color' => '#111111', 'name' => 'Primary'],
            ['slug' => 'secondary', 'color' => '#222222', 'name' => 'Secondary'],
            ['slug' => 'accent', 'color' => '#B4541E', 'name' => 'Accent'],
        ]]]],
        ['base' => '#111111', 'secondary' => '#C9C4BC'],
    );
    $bySlug = array_column($theme['settings']['color']['palette'], 'color', 'slug');
    assert_eq('#111111', $bySlug['base']);
    assert_eq('#C9C4BC', $bySlug['secondary'], 'judged on the delivered base, not the authored one');
    assert_eq([], $warnings);
});

test('repairFonts writes the direction family back when the primary face drifted', function () {
    $preferred = [
        'heading' => ['family' => 'Oswald', 'weights' => [700], 'italic' => false, 'axes' => [], 'character' => ''],
        'body' => ['family' => 'Source Sans 3', 'weights' => [400], 'italic' => false, 'axes' => [], 'character' => ''],
    ];
    [$theme, $warnings, $repairs] = ThemeJsonStep::repairFonts(
        ['settings' => ['typography' => ['fontFamilies' => [
            ['slug' => 'heading', 'fontFamily' => '"Fraunces", serif', 'name' => 'Heading'],
            ['slug' => 'body', 'fontFamily' => '"Inter", sans-serif', 'name' => 'Body'],
        ]]]],
        $preferred,
    );
    $bySlug = array_column($theme['settings']['typography']['fontFamilies'], 'fontFamily', 'slug');
    assert_contains('Oswald', $bySlug['heading']);
    assert_contains('Source Sans 3', $bySlug['body']);
    assert_eq([], $warnings, 'an applied writeback is not a delivered defect');
    $joined = implode(' ', $repairs);
    assert_contains("fontFamilies slug 'heading'", $joined);
    assert_contains('wrote the design-direction family back', $joined);

    [$again, $repeatWarnings, $repeatRepairs] = ThemeJsonStep::repairFonts($theme, $preferred);
    assert_eq($theme, $again, 'font drift repair reaches a fixed point');
    assert_eq([], $repeatWarnings, 'fixed fonts emit no repeat repair warnings');
    assert_eq([], $repeatRepairs, 'fixed fonts emit no repeat repair receipts');
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

test('theme-json provisions a root inline gutter when the model omits root padding', function () {
    // SectionLayoutStep strips each section's own inline left/right padding on
    // the expectation that the theme root gutter owns the inline axis. Without a
    // provisioned gutter, useRootPaddingAwareAlignments stays off and every
    // constrained section butts the viewport edge with no side inset.
    $tmp = sys_get_temp_dir() . '/builder_tj_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy neighborhood bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    seed_test_design_direction($project);

    $llm = new FakeLlm();
    $llm->queueJson(valid_theme_payload()); // no styles.spacing.padding anywhere
    (new ThemeJsonStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $theme = $project->readJson('theme/theme.json');
    $padding = $theme['styles']['spacing']['padding'] ?? [];
    assert_eq('var:preset|spacing|md', $padding['left'] ?? null, 'left gutter provisioned from the spacing scale');
    assert_eq('var:preset|spacing|md', $padding['right'] ?? null, 'right gutter provisioned from the spacing scale');
    assert_eq('0', $padding['top'] ?? null, 'root vertical padding stays zero — sections own the rhythm');
    assert_eq('0', $padding['bottom'] ?? null, 'root vertical padding stays zero — sections own the rhythm');
    assert_eq(true, $theme['settings']['useRootPaddingAwareAlignments'], 'the provisioned gutter is aware-aligned');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json keeps a model-authored root gutter instead of double-padding it', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy neighborhood bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    seed_test_design_direction($project);

    $payload = valid_theme_payload();
    $payload['styles'] = ['spacing' => ['padding' => ['left' => '3rem', 'right' => '3rem']]];

    $llm = new FakeLlm();
    $llm->queueJson($payload);
    (new ThemeJsonStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $padding = $project->readJson('theme/theme.json')['styles']['spacing']['padding'] ?? [];
    assert_eq('3rem', $padding['left'] ?? null, 'a real authored gutter is preserved');
    assert_eq('3rem', $padding['right'] ?? null, 'a real authored gutter is preserved');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('normalizeRootPadding synthesizes side gutters when the model omits them', function () {
    // No styles at all — md gutters synthesized, flag on. Without this, every
    // section without its own padding renders flush to the 390px screen edge.
    $theme = ThemeJsonStep::normalizeRootPadding([]);
    assert_eq('var:preset|spacing|md', $theme['styles']['spacing']['padding']['left'], 'no styles: left');
    assert_eq('var:preset|spacing|md', $theme['styles']['spacing']['padding']['right'], 'no styles: right');
    assert_eq('0', $theme['styles']['spacing']['padding']['top'], 'no styles: top');
    assert_eq(true, $theme['settings']['useRootPaddingAwareAlignments'], 'no styles: flag');

    // Zero-valued side padding (any unit) is the same mobile defect — replaced.
    $theme = ThemeJsonStep::normalizeRootPadding(
        ['styles' => ['spacing' => ['padding' => ['left' => '0px', 'right' => '0']]]]
    );
    assert_eq('var:preset|spacing|md', $theme['styles']['spacing']['padding']['left'], 'zero padding: left');
    assert_eq(true, $theme['settings']['useRootPaddingAwareAlignments'], 'zero padding: flag');

    // Vertical-only padding — vertical zeroed, sides synthesized.
    $theme = ThemeJsonStep::normalizeRootPadding(
        ['styles' => ['spacing' => ['padding' => ['top' => '2rem', 'bottom' => '2rem']]]]
    );
    assert_eq('0', $theme['styles']['spacing']['padding']['top'], 'vertical only: top');
    assert_eq('var:preset|spacing|md', $theme['styles']['spacing']['padding']['left'], 'vertical only: left');

    // An authored non-zero side survives; the missing side is filled.
    $theme = ThemeJsonStep::normalizeRootPadding(
        ['styles' => ['spacing' => ['padding' => ['right' => '1.5rem']]]]
    );
    assert_eq('1.5rem', $theme['styles']['spacing']['padding']['right'], 'authored side kept');
    assert_eq('var:preset|spacing|md', $theme['styles']['spacing']['padding']['left'], 'missing side filled');
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

    // No padding stanza at all — the BIGR-780 case (atlas/hearth/pulso
    // authored only blockGap): gutters synthesized, blockGap untouched.
    $theme = ThemeJsonStep::normalizeRootPadding(['styles' => ['spacing' => ['blockGap' => '1rem']]]);
    assert_eq('var:preset|spacing|md', $theme['styles']['spacing']['padding']['left'], 'gutters synthesized');
    assert_eq('1rem', $theme['styles']['spacing']['blockGap'], 'blockGap untouched');
});

test('normalizeRootPadding repairs malformed shapes and side values itself', function () {
    // Scalar styles.spacing — must not fatal on the string offset write; the
    // full stanza is synthesized regardless of caller-side shape repairs.
    $theme = ThemeJsonStep::normalizeRootPadding(['styles' => ['spacing' => '2rem']]);
    assert_eq('var:preset|spacing|md', $theme['styles']['spacing']['padding']['left'], 'scalar spacing: left');
    assert_eq('0', $theme['styles']['spacing']['padding']['top'], 'scalar spacing: top');
    assert_eq(true, $theme['settings']['useRootPaddingAwareAlignments'], 'scalar spacing: flag');

    // Scalar styles — same guarantee one level up.
    $theme = ThemeJsonStep::normalizeRootPadding(['styles' => 'oops']);
    assert_eq('var:preset|spacing|md', $theme['styles']['spacing']['padding']['left'], 'scalar styles: left');

    // Non-scalar side values would serialize as garbage in theme.json —
    // synthesized instead of copied through.
    $theme = ThemeJsonStep::normalizeRootPadding(
        ['styles' => ['spacing' => ['padding' => ['left' => ['1rem'], 'right' => '1.5rem']]]]
    );
    assert_eq('var:preset|spacing|md', $theme['styles']['spacing']['padding']['left'], 'array side replaced');
    assert_eq('1.5rem', $theme['styles']['spacing']['padding']['right'], 'scalar sibling kept');

    // Numeric zero is still the zero-gutter defect; numeric non-zero survives.
    $theme = ThemeJsonStep::normalizeRootPadding(
        ['styles' => ['spacing' => ['padding' => ['left' => 0, 'right' => 16]]]]
    );
    assert_eq('var:preset|spacing|md', $theme['styles']['spacing']['padding']['left'], 'numeric zero replaced');
    assert_eq(16, $theme['styles']['spacing']['padding']['right'], 'numeric non-zero kept');
});

test('normalizeGroupBlockPadding removes recursive vertical defaults and preserves siblings', function () {
    $theme = ['styles' => ['blocks' => [
        'core/group' => [
            'spacing' => [
                'padding' => [
                    'top' => 'var:preset|spacing|xl',
                    'right' => 'var:preset|spacing|md',
                    'bottom' => 'var:preset|spacing|lg',
                    'left' => '2rem',
                ],
                'blockGap' => 'var:preset|spacing|sm',
            ],
            'border' => ['radius' => '4px'],
        ],
        'core/image' => ['border' => ['radius' => '2px']],
    ]]];

    [$normalized, $rows] = ThemeJsonStep::repairGroupBlockPadding($theme);
    $group = $normalized['styles']['blocks']['core/group'];
    assert_true(!isset($group['spacing']['padding']['top']));
    assert_true(!isset($group['spacing']['padding']['bottom']));
    assert_eq('var:preset|spacing|md', $group['spacing']['padding']['right']);
    assert_eq('2rem', $group['spacing']['padding']['left']);
    assert_eq('var:preset|spacing|sm', $group['spacing']['blockGap']);
    assert_eq(['radius' => '4px'], $group['border']);
    assert_eq(['radius' => '2px'], $normalized['styles']['blocks']['core/image']['border']);
    assert_eq($normalized, ThemeJsonStep::normalizeGroupBlockPadding($normalized), 'fixed point');

    // The deletion of authored padding is recorded durably, per removed side,
    // in the same grammar as every sibling repair.
    $joined = implode(' ', $rows);
    assert_eq(2, count($rows), 'one row per removed vertical side');
    assert_contains(
        'theme/theme.json styles.blocks.core/group.spacing.padding.top: authored "var:preset|spacing|xl"; delivered removed',
        $joined,
    );
    assert_contains(
        'theme/theme.json styles.blocks.core/group.spacing.padding.bottom: authored "var:preset|spacing|lg"; delivered removed',
        $joined,
    );
    assert_contains('disposition removed recursive vertical Group padding', $joined);
    assert_eq([], ThemeJsonStep::repairGroupBlockPadding($normalized)[1], 'a fixed point records no rows');

    $onlyVertical = ThemeJsonStep::normalizeGroupBlockPadding(['styles' => ['blocks' => [
        'core/group' => ['spacing' => ['padding' => [
            'top' => 'var:preset|spacing|xl',
            'bottom' => 'var:preset|spacing|xl',
        ]]],
    ]]]);
    assert_true(!isset($onlyVertical['styles']['blocks']['core/group']), 'empty block style pruned');

    [$scalar, $scalarRows] = ThemeJsonStep::repairGroupBlockPadding(['styles' => ['blocks' => [
        'core/group' => ['spacing' => ['padding' => 'var:preset|spacing|md']],
    ]]]);
    assert_eq(
        ['left' => 'var:preset|spacing|md', 'right' => 'var:preset|spacing|md'],
        $scalar['styles']['blocks']['core/group']['spacing']['padding'],
        'four-side shorthand keeps only its horizontal intent',
    );
    assert_eq(1, count($scalarRows), 'the scalar rewrite is recorded once');
    assert_contains(
        'theme/theme.json styles.blocks.core/group.spacing.padding: authored "var:preset|spacing|md"; '
            . 'delivered {"left":"var:preset|spacing|md","right":"var:preset|spacing|md"}',
        $scalarRows[0],
    );
    assert_contains('disposition rewrote the four-side shorthand to horizontal longhands', $scalarRows[0]);

    $missing = ['styles' => ['spacing' => ['blockGap' => '1rem']]];
    assert_eq([$missing, []], ThemeJsonStep::repairGroupBlockPadding($missing), 'missing path untouched, no rows');
});

test('theme-json write removes Atlas-style global Group vertical padding', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_group_padding_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A field operations landing page']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    seed_test_design_direction($project);

    $payload = valid_theme_payload();
    $payload['styles']['blocks']['core/group'] = [
        'spacing' => [
            'padding' => [
                'top' => 'var:preset|spacing|xl',
                'bottom' => 'var:preset|spacing|xl',
            ],
            'blockGap' => 'var:preset|spacing|md',
        ],
        'border' => ['radius' => '2px'],
    ];
    $llm = new FakeLlm();
    $llm->queueJson($payload);
    (new ThemeJsonStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $group = $project->readJson('theme/theme.json')['styles']['blocks']['core/group'];
    assert_true(!isset($group['spacing']['padding']), 'recursive vertical padding removed');
    assert_eq('var:preset|spacing|md', $group['spacing']['blockGap']);
    assert_eq(['radius' => '2px'], $group['border']);

    // The silent-deletion regression: the removal must leave a durable row,
    // like every sibling repair in the write path.
    $joined = implode(' ', $project->readJson('warnings.json')['theme-json'] ?? []);
    assert_contains('styles.blocks.core/group.spacing.padding.top: authored "var:preset|spacing|xl"', $joined);
    assert_contains('styles.blocks.core/group.spacing.padding.bottom: authored "var:preset|spacing|xl"', $joined);
    assert_contains('delivered removed', $joined);
    assert_contains('disposition removed recursive vertical Group padding', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
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
    assert_eq(
        ['theme/theme.json', 'logs/theme-json-shape.txt', 'logs/theme-json-direction-bind.txt', 'warnings.json'],
        $step->declaration()->writes,
    );
});

test('theme-json declares design CSS only for the HTML-first graph', function () {
    $llm = new FakeLlm();
    $renderer = new PromptRenderer(repo_path('prompts'));

    assert_eq(
        ['meta.json', 'siteSpec.json', 'designDirection.json'],
        (new ThemeJsonStep($llm, $renderer))->declaration()->reads,
        'legacy graph declaration stays byte-for-byte unchanged',
    );
    assert_eq(
        ['meta.json', 'siteSpec.json', 'designDirection.json', 'design/site.css'],
        (new ThemeJsonStep($llm, $renderer, htmlFirst: true))->declaration()->reads,
        'HTML-first graph declares the CSS token source produced by design-preview',
    );
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

test('theme-json default graph preserves generated navigation, button and link typography', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_default_control_type_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A coaching studio']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    seed_test_design_direction($project);

    $payload = valid_theme_payload();
    $payload['styles']['blocks']['core/navigation'] = [
        'typography' => [
            'fontWeight' => '600',
            'letterSpacing' => '0.14em',
            'textTransform' => 'uppercase',
        ],
        'elements' => ['link' => ['typography' => ['textDecoration' => 'none']]],
    ];
    $payload['styles']['elements']['button'] = [
        'typography' => [
            'fontWeight' => '700',
            'letterSpacing' => '0.08em',
            'textTransform' => 'uppercase',
        ],
        'color' => ['background' => 'var:preset|color|accent'],
    ];
    $payload['styles']['elements']['link'] = [
        'color' => ['text' => 'var:preset|color|primary'],
        'typography' => ['fontWeight' => '700', 'textDecoration' => 'underline'],
        ':hover' => [
            'color' => ['text' => 'var:preset|color|accent'],
            'typography' => ['textDecoration' => 'none'],
        ],
    ];
    $llm = new FakeLlm();
    $llm->queueJson($payload);

    (new ThemeJsonStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $theme = $project->readJson('theme/theme.json');
    assert_eq(
        '600',
        $theme['styles']['blocks']['core/navigation']['typography']['fontWeight'] ?? null,
        'default graph keeps generated navigation typography',
    );
    assert_eq(
        '700',
        $theme['styles']['elements']['button']['typography']['fontWeight'] ?? null,
        'default graph keeps generated button typography',
    );
    assert_eq(
        '700',
        $theme['styles']['elements']['link']['typography']['fontWeight'] ?? null,
        'default graph keeps generated link typography',
    );
    assert_eq(
        'none',
        $theme['styles']['blocks']['core/navigation']['elements']['link']['typography']['textDecoration'] ?? null,
        'default graph keeps navigation sibling styles',
    );
    assert_eq(
        'var:preset|color|accent',
        $theme['styles']['elements']['button']['color']['background'] ?? null,
        'default graph keeps button sibling styles',
    );
    assert_eq(
        'var:preset|color|primary',
        $theme['styles']['elements']['link']['color']['text'] ?? null,
        'default graph keeps link color',
    );
    assert_eq(
        'none',
        $theme['styles']['elements']['link'][':hover']['typography']['textDecoration'] ?? null,
        'default graph keeps link hover styles',
    );

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json HTML-first graph removes generated navigation, button and link typography', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_html_control_type_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A coaching studio']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    seed_test_design_direction($project);
    $project->writeText(
        'design/site.css',
        file_get_contents(repo_path('tests/fixtures/design/tokens-rich.css')) ?: '',
    );

    $payload = valid_theme_payload();
    $payload['styles']['blocks']['core/navigation'] = [
        'typography' => [
            'fontWeight' => '600',
            'letterSpacing' => '0.14em',
            'textTransform' => 'uppercase',
        ],
        'elements' => ['link' => ['typography' => ['textDecoration' => 'none']]],
    ];
    $payload['styles']['elements']['button'] = [
        'typography' => [
            'fontWeight' => '700',
            'letterSpacing' => '0.08em',
            'textTransform' => 'uppercase',
        ],
        'color' => ['background' => 'var:preset|color|accent'],
    ];
    $payload['styles']['elements']['link'] = [
        'color' => ['text' => 'var:preset|color|primary'],
        'typography' => ['fontWeight' => '700', 'textDecoration' => 'underline'],
        ':hover' => [
            'color' => ['text' => 'var:preset|color|accent'],
            'typography' => ['textDecoration' => 'none'],
        ],
    ];
    $llm = new FakeLlm();
    $llm->queueJson($payload);

    (new ThemeJsonStep(
        $llm,
        new PromptRenderer(repo_path('prompts')),
        htmlFirst: true,
    ))->run($project);

    $theme = $project->readJson('theme/theme.json');
    assert_true(
        !array_key_exists('typography', $theme['styles']['blocks']['core/navigation'] ?? []),
        'HTML-first navigation inherits from carried design CSS',
    );
    assert_true(
        !array_key_exists('typography', $theme['styles']['elements']['button'] ?? []),
        'HTML-first buttons inherit from carried design CSS',
    );
    assert_true(
        !array_key_exists('typography', $theme['styles']['elements']['link'] ?? []),
        'HTML-first links inherit from carried design CSS',
    );
    assert_eq(
        'none',
        $theme['styles']['blocks']['core/navigation']['elements']['link']['typography']['textDecoration'] ?? null,
        'HTML-first keeps navigation sibling styles',
    );
    assert_eq(
        'var:preset|color|accent',
        $theme['styles']['elements']['button']['color']['background'] ?? null,
        'HTML-first keeps button sibling styles',
    );
    assert_eq(
        'var:preset|color|primary',
        $theme['styles']['elements']['link']['color']['text'] ?? null,
        'HTML-first keeps link color',
    );
    assert_eq(
        'none',
        $theme['styles']['elements']['link'][':hover']['typography']['textDecoration'] ?? null,
        'HTML-first keeps link hover styles',
    );

    exec('rm -rf ' . escapeshellarg($tmp));
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

test('theme-json scaffold removes unsupported text-wrap leaves and preserves typography siblings', function () {
    $empty = ThemeJsonStep::applyScaffold([]);
    assert_true(!array_key_exists('textWrap', $empty['styles']['typography']));

    $overwritten = ThemeJsonStep::applyScaffold([
        'styles' => [
            'typography' => [
                'fontFamily' => 'var:preset|font-family|body',
                'textWrap' => 'balance',
            ],
            'elements' => ['h1' => [
                'typography' => [
                    'textWrapStyle' => 'balance',
                    'fontWeight' => '700',
                ],
                'css' => 'text-wrap-mode: nowrap !important; font-style: italic;',
            ]],
            'css' => 'h1{text-wrap:nowrap!important;color:inherit}',
            'blocks' => ['core/paragraph' => [
                'css' => '&{text-wrap-style:balance;display:block}',
            ]],
        ],
    ]);
    assert_true(!array_key_exists('textWrap', $overwritten['styles']['typography']));
    assert_true(!array_key_exists('textWrapStyle', $overwritten['styles']['elements']['h1']['typography']));
    assert_eq(
        'var:preset|font-family|body',
        $overwritten['styles']['typography']['fontFamily'],
        'unrelated typography siblings survive',
    );
    assert_eq('700', $overwritten['styles']['elements']['h1']['typography']['fontWeight']);
    assert_eq('h1{color:inherit}', $overwritten['styles']['css']);
    assert_eq(
        '&{display:block}',
        $overwritten['styles']['blocks']['core/paragraph']['css'],
        'nested custom CSS keeps unrelated declarations',
    );
    assert_eq(
        ' font-style: italic;',
        $overwritten['styles']['elements']['h1']['css'],
        'bare custom declaration lists are repaired too',
    );

    [$fixedPoint, $warnings] = ThemeJsonStep::repairScaffold($overwritten);
    assert_eq($overwritten, $fixedPoint, 'unsupported-leaf repair reaches a fixed point');
    assert_eq([], $warnings);
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

test('theme-json prompt carries authoritative tokens extracted from design CSS', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_css_tokens_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cold-water swim club']);
    $project->writeJson('siteSpec.json', ['name' => 'Teal Valley']);
    seed_test_design_direction($project);
    $project->writeText(
        'design/site.css',
        file_get_contents(repo_path('tests/fixtures/design/tokens-rich.css')) ?: '',
    );

    $request = (new ThemeJsonStep(
        new FakeLlm(),
        new PromptRenderer(repo_path('prompts')),
        htmlFirst: true,
    ))->requests($project)['theme-json'];

    assert_contains('#123456', $request['prompt']);
    assert_contains('"Source Sans 3", Arial, sans-serif', $request['prompt']);
    assert_contains('2rem', $request['prompt']);
    assert_contains('or restate them in custom CSS', $request['prompt']);
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

test('theme-json absent CSS prompt matches the recorded legacy bytes', function () {
    $expectation = json_decode(
        file_get_contents(repo_path('tests/fixtures/theme-json/legacy-prompt-expectation.json')) ?: '',
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $tmp = sys_get_temp_dir() . '/builder_tj_legacy_prompt_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', $expectation['meta']);
    $project->writeJson('siteSpec.json', $expectation['siteSpec']);
    seed_test_design_direction($project);

    $prompt = (new ThemeJsonStep(
        new FakeLlm(),
        new PromptRenderer(repo_path('prompts')),
    ))->requests($project)['theme-json']['prompt'];

    assert_eq($expectation['bytes'], strlen($prompt));
    assert_eq($expectation['sha256'], hash('sha256', $prompt));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json legacy mode ignores stale design CSS bytes', function () {
    $expectation = json_decode(
        file_get_contents(repo_path('tests/fixtures/theme-json/legacy-prompt-expectation.json')) ?: '',
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $tmp = sys_get_temp_dir() . '/builder_tj_legacy_stale_css_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', $expectation['meta']);
    $project->writeJson('siteSpec.json', $expectation['siteSpec']);
    seed_test_design_direction($project);
    $project->writeText(
        'design/site.css',
        '.stale { color: #C0FFEE; font-family: "Stale Font", sans-serif; padding: 2rem; }',
    );

    $prompt = (new ThemeJsonStep(
        new FakeLlm(),
        new PromptRenderer(repo_path('prompts')),
    ))->requests($project)['theme-json']['prompt'];

    assert_eq($expectation['bytes'], strlen($prompt));
    assert_eq($expectation['sha256'], hash('sha256', $prompt));
    assert_true(!str_contains($prompt, '#C0FFEE'));
    assert_true(!str_contains($prompt, 'Stale Font'));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json sparse CSS keeps direction prompt and writes actionable warning', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_sparse_tokens_' . uniqid();
    $store = new ProjectStore($tmp);
    $legacy = $store->create('legacy');
    $sparse = $store->create('sparse');
    $direction = [
        'concept' => 'Polar editorial',
        'palette' => [
            'base' => '#F5FBFF',
            'contrast' => '#061A24',
            'primary' => '#0C5B78',
            'secondary' => '#315D6D',
            'accent' => '#B63A1E',
        ],
        'type_pairing' => 'Fraunces with Source Sans 3',
        'hero_blueprint' => \Automattic\SiteBuild\HeroBlueprint::defaultFor('cinematic-safe-zone'),
    ];
    foreach ([$legacy, $sparse] as $project) {
        $project->writeJson('meta.json', ['prompt' => 'A cold-water swim club']);
        $project->writeJson('siteSpec.json', ['name' => 'Teal Valley']);
        $project->writeJson('designDirection.json', $direction);
    }
    $sparse->writeText(
        'design/site.css',
        file_get_contents(repo_path('tests/fixtures/design/tokens-sparse.css')) ?: '',
    );
    $legacyStep = new ThemeJsonStep(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $htmlFirstStep = new ThemeJsonStep(
        new FakeLlm(),
        new PromptRenderer(repo_path('prompts')),
        htmlFirst: true,
    );

    $legacyPrompt = $legacyStep->requests($legacy)['theme-json']['prompt'];
    $sparsePrompt = $htmlFirstStep->requests($sparse)['theme-json']['prompt'];

    assert_eq($legacyPrompt, $sparsePrompt, 'sparse extraction uses unchanged design-direction prompt');
    $warnings = implode(' ', $sparse->readJson('warnings.json')['theme-json'] ?? []);
    assert_contains('sparse_tokens', $warnings);
    assert_contains('design/site.css', $warnings);
    assert_contains('authored', $warnings);
    assert_contains('delivered design-direction values', $warnings);
    assert_contains('disposition', $warnings);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json html-first resume does not abort when design css was never written', function () {
    // --from can resume mid-graph on a project that never ran design-preview.
    // readText() throws on a missing file; this must degrade like sparse tokens.
    $tmp = sys_get_temp_dir() . '/builder_tj_absent_css_' . getmypid() . '_' . uniqid('', true);
    $store = new ProjectStore($tmp);
    $legacy = $store->create('legacy');
    $absent = $store->create('absent');
    foreach ([$legacy, $absent] as $project) {
        $project->writeJson('meta.json', ['prompt' => 'A cold-water swim club']);
        $project->writeJson('siteSpec.json', ['name' => 'Teal Valley']);
        seed_test_design_direction($project);
    }

    $legacyStep = new ThemeJsonStep(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $htmlFirstStep = new ThemeJsonStep(
        new FakeLlm(),
        new PromptRenderer(repo_path('prompts')),
        htmlFirst: true,
    );

    assert_true(!$absent->exists('design/site.css'), 'fixture must have no design css');

    $legacyPrompt = $legacyStep->requests($legacy)['theme-json']['prompt'];
    $absentPrompt = $htmlFirstStep->requests($absent)['theme-json']['prompt'];

    assert_eq($legacyPrompt, $absentPrompt, 'absent design css falls back to the direction prompt');
    $warnings = implode(' ', $absent->readJson('warnings.json')['theme-json'] ?? []);
    assert_contains('sparse_tokens', $warnings);
    assert_contains('delivered design-direction values', $warnings);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json invalid UTF-8 CSS keeps direction prompt and writes sparse warning', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_invalid_utf8_tokens_' . uniqid();
    $store = new ProjectStore($tmp);
    $legacy = $store->create('legacy');
    $invalid = $store->create('invalid');
    $direction = [
        'concept' => 'Polar editorial',
        'palette' => [
            'base' => '#F5FBFF',
            'contrast' => '#061A24',
            'primary' => '#0C5B78',
            'secondary' => '#315D6D',
            'accent' => '#B63A1E',
        ],
        'type_pairing' => 'Fraunces with Source Sans 3',
        'hero_blueprint' => \Automattic\SiteBuild\HeroBlueprint::defaultFor('cinematic-safe-zone'),
    ];
    foreach ([$legacy, $invalid] as $project) {
        $project->writeJson('meta.json', ['prompt' => 'A cold-water swim club']);
        $project->writeJson('siteSpec.json', ['name' => 'Teal Valley']);
        $project->writeJson('designDirection.json', $direction);
    }
    $invalid->writeText(
        'design/site.css',
        "body { color: #112233; font-family: \"Bad\xC3\", serif; padding: 1rem; }",
    );
    $legacyStep = new ThemeJsonStep(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $htmlFirstStep = new ThemeJsonStep(
        new FakeLlm(),
        new PromptRenderer(repo_path('prompts')),
        htmlFirst: true,
    );

    $legacyPrompt = $legacyStep->requests($legacy)['theme-json']['prompt'];
    $invalidPrompt = $htmlFirstStep->requests($invalid)['theme-json']['prompt'];

    assert_eq($legacyPrompt, $invalidPrompt, 'invalid token bytes use unchanged design-direction prompt');
    $warnings = implode(' ', $invalid->readJson('warnings.json')['theme-json'] ?? []);
    assert_contains('sparse_tokens', $warnings);
    assert_contains('design/site.css', $warnings);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json request makes both committed shape radii build-owned', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_shape_prompt_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cold-water swim club']);
    $project->writeJson('siteSpec.json', ['name' => 'Teal Valley']);
    seed_test_design_direction($project, 'cinematic-safe-zone', ['shape' => 'round']);

    $request = (new ThemeJsonStep(
        new FakeLlm(),
        new PromptRenderer(repo_path('prompts')),
    ))->requests($project)['theme-json'];
    $prompt = $request['prompt'];

    assert_contains('**Shape**: round', $prompt);
    assert_contains('`sharp` removes the `core/image` radius and gives buttons `0`', $prompt);
    assert_contains('`soft` gives both `0.5rem`', $prompt);
    assert_contains('`round` gives `core/image` `1.25rem` and buttons `9999px`', $prompt);
    assert_contains('Never restate or reset any build-owned radius', $prompt);
    assert_contains('in a theme.json `css` string or structured style', $prompt);
    assert_contains('block variations, and responsive or interaction states', $prompt);

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

test('repairShapeWiring repairs authored cover and media-text corner styles without adding a base leaf', function () {
    $authored = ['styles' => ['blocks' => [
        'core/cover' => ['border' => ['radius' => '8px', 'color' => '#111111']],
        'core/media-text' => [
            'border' => ['radius' => '6px'],
            'css' => '& .wp-block-media-text__media img { border-radius: 24px; } '
                . '& .wp-block-media-text__content { color: #222222; }',
        ],
    ]]];

    foreach (['soft', 'round', 'sharp'] as $shape) {
        [$theme, $repairs, $warnings] = ThemeJsonStep::repairShapeWiring($authored, $shape);
        assert_true(!isset($theme['styles']['blocks']['core/cover']['border']['radius']), $shape);
        assert_eq('#111111', $theme['styles']['blocks']['core/cover']['border']['color']);
        assert_true(!isset($theme['styles']['blocks']['core/media-text']['border']), $shape);
        $css = $theme['styles']['blocks']['core/media-text']['css'] ?? '';
        assert_true(!str_contains($css, 'border-radius'), $shape);
        assert_contains('color: #222222', $css);
        // The kit stylesheet owns these corners; theme.json gains no cover or
        // media-text radius leaf of its own.
        assert_true(!isset($theme['styles']['blocks']['core/cover']['border']['radius']));
        $joined = implode(' ', $repairs);
        assert_contains('styles.blocks.core/cover.border.radius: authored "8px"; delivered removed', $joined);
        assert_contains('styles.blocks.core/media-text.border.radius: authored "6px"; delivered removed', $joined);
        assert_eq([], $warnings, $shape);

        [$fixed, $fixedRepairs, $fixedWarnings] = ThemeJsonStep::repairShapeWiring($theme, $shape);
        assert_eq($theme, $fixed, "fixed point for {$shape}");
        assert_eq([], $fixedRepairs, "fixed point reports nothing for {$shape}");
        assert_eq([], $fixedWarnings);
    }
});

test('W6 theme-json normalizes unitless layout widths', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_unitless_width_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    try {
        $project->writeJson('meta.json', ['prompt' => 'A cold-water swim club']);
        $project->writeJson('siteSpec.json', ['name' => 'Teal Valley']);
        seed_test_design_direction($project);

        $payload = valid_theme_payload();
        $payload['settings']['layout'] = ['contentSize' => '860px', 'wideSize' => 1320];
        $llm = new FakeLlm();
        $llm->queueJson($payload);
        (new ThemeJsonStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

        assert_eq(
            ['contentSize' => '860px', 'wideSize' => '1320px'],
            $project->readJson('theme/theme.json')['settings']['layout'] ?? null,
        );
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('W6 theme-json keeps content width pending final markup and reconciles wide custom property', function () {
    $tmp = sys_get_temp_dir() . '/builder_tj_design_width_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    try {
        $project->writeJson('meta.json', ['prompt' => 'A cold-water swim club']);
        $project->writeJson('siteSpec.json', ['name' => 'Teal Valley']);
        seed_test_design_direction($project);
        $project->writeText(
            'design/site.css',
            ':root{--content-size:800px;--wide-size:1280px}'
                . 'body{color:#111;background:#fff;font-family:system-ui,sans-serif}',
        );

        $payload = valid_theme_payload();
        $payload['settings']['layout'] = ['contentSize' => '860px', 'wideSize' => '1320px'];
        $llm = new FakeLlm();
        $llm->queueJson($payload);
        (new ThemeJsonStep(
            $llm,
            new PromptRenderer(repo_path('prompts')),
            htmlFirst: true,
        ))->run($project);

        assert_eq(
            ['contentSize' => '860px', 'wideSize' => '1280px'],
            $project->readJson('theme/theme.json')['settings']['layout'] ?? null,
        );
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

$themeLayoutWidthCases = [
    [
        'unitless integer content width',
        ['settings' => ['layout' => ['contentSize' => 720, 'wideSize' => '1200px']]],
        null,
        ['contentSize' => '720px', 'wideSize' => '1200px'],
        null,
    ],
    [
        'unitless integer wide width',
        ['settings' => ['layout' => ['contentSize' => '800px', 'wideSize' => 1280]]],
        null,
        ['contentSize' => '800px', 'wideSize' => '1280px'],
        null,
    ],
    [
        'numeric strings',
        ['settings' => ['layout' => ['contentSize' => '860', 'wideSize' => '1320']]],
        null,
        ['contentSize' => '860px', 'wideSize' => '1320px'],
        null,
    ],
    [
        'decimal numbers',
        ['settings' => ['layout' => ['contentSize' => 720.5, 'wideSize' => 1200.25]]],
        null,
        ['contentSize' => '720.5px', 'wideSize' => '1200.25px'],
        null,
    ],
    [
        'authored rem values',
        ['settings' => ['layout' => ['contentSize' => '48rem', 'wideSize' => '80rem']]],
        null,
        ['contentSize' => '48rem', 'wideSize' => '80rem'],
        null,
    ],
    [
        'authored CSS function',
        ['settings' => ['layout' => ['contentSize' => 'clamp(40rem, 70vw, 50rem)', 'wideSize' => '90vw']]],
        null,
        ['contentSize' => 'clamp(40rem, 70vw, 50rem)', 'wideSize' => '90vw'],
        null,
    ],
    [
        'negative content width',
        ['settings' => ['layout' => ['contentSize' => -20, 'wideSize' => '1200px']]],
        null,
        ['contentSize' => '800px', 'wideSize' => '1200px'],
        'settings.layout.contentSize',
    ],
    [
        'array wide width',
        ['settings' => ['layout' => ['contentSize' => '800px', 'wideSize' => ['1320px']]]],
        null,
        ['contentSize' => '800px', 'wideSize' => '1280px'],
        'settings.layout.wideSize',
    ],
    [
        'malformed layout container',
        ['settings' => ['layout' => 'wide']],
        null,
        ['contentSize' => '800px', 'wideSize' => '1280px'],
        'settings.layout: authored "wide"',
    ],
    [
        'absent layout',
        ['settings' => []],
        null,
        null,
        null,
    ],
    [
        'design root overrides wide width but not dead content token without markup',
        ['settings' => ['layout' => ['contentSize' => '860px', 'wideSize' => '1320px']]],
        '/* tokens */ :root { --content-size: 800px; --wide-size: 1280px; }',
        ['contentSize' => '860px', 'wideSize' => '1280px'],
        null,
    ],
    [
        'partial design root ignores dead content and invalid unitless wide values',
        ['settings' => ['layout' => ['contentSize' => '860px', 'wideSize' => '1320px']]],
        ':root{--content-size:810px;--wide-size:1260}',
        ['contentSize' => '860px', 'wideSize' => '1320px'],
        null,
    ],
];

foreach ($themeLayoutWidthCases as [$name, $authored, $css, $expected, $warningFragment]) {
    test("theme-json layout width: {$name}", function () use (
        $authored,
        $css,
        $expected,
        $warningFragment,
    ) {
        [$theme, $warnings] = ThemeJsonStep::normalizeLayoutWidths($authored, $css);
        $layout = $theme['settings']['layout'] ?? null;
        assert_eq($expected, $layout);
        if ($warningFragment === null) {
            assert_eq([], $warnings);
        } else {
            assert_contains($warningFragment, implode(' ', $warnings));
        }
    });
}

test('C5 theme-json derives bounded content width from the carrier inner box at 1366px', function () {
    $theme = ['settings' => ['layout' => ['contentSize' => '860px', 'wideSize' => '1320px']]];
    $css = ':root{--content-size:800px;--wide-size:1280px}'
        . '*,*::before,*::after{box-sizing:border-box}'
        . '.shell{width:100%;max-width:var(--wide-size);margin:0 auto;'
        . 'padding:0 clamp(20px,5vw,48px)}';
    $html = '<main>'
        . '<section id="hero"><div class="shell"><h1>Hero</h1></div></section>'
        . '<section id="services"><div class="shell"><h2>Services</h2></div></section>'
        . '</main>';

    [$normalized, $warnings] = ThemeJsonStep::normalizeLayoutWidths($theme, $css, $html);

    assert_eq(
        ['contentSize' => '1184px', 'wideSize' => '1280px'],
        $normalized['settings']['layout'] ?? null,
        '1280px border box minus the resolved 48px carrier padding on both sides',
    );
    assert_eq([], $warnings);
});

test('C5 theme-json derives fluid content width from the main gutter at 1366px', function () {
    $theme = ['settings' => ['layout' => ['contentSize' => '860px', 'wideSize' => '1320px']]];
    $css = ':root{--content-size:800px;--wide-size:1280px;'
        . '--gutter:clamp(1.25rem,5vw,5.5rem)}main{padding:0 var(--gutter)}';
    $html = '<main>'
        . '<section id="hero"><h1>Hero</h1></section>'
        . '<section id="services"><h2>Services</h2></section>'
        . '</main>';

    [$normalized, $warnings] = ThemeJsonStep::normalizeLayoutWidths($theme, $css, $html);

    assert_eq(
        ['contentSize' => '1230px', 'wideSize' => '1280px'],
        $normalized['settings']['layout'] ?? null,
        '1366px reference viewport minus the resolved 5vw gutter on both sides',
    );
    assert_eq([], $warnings);
});

test('C5 theme-json releases the root gutter for a viewport-fluid carrier', function () {
    $theme = [
        'settings' => [
            'layout' => ['contentSize' => '800px', 'wideSize' => '1280px'],
            'useRootPaddingAwareAlignments' => true,
        ],
        'styles' => ['spacing' => ['padding' => [
            'top' => '0',
            'bottom' => '0',
            'left' => 'var:preset|spacing|md',
            'right' => 'var:preset|spacing|md',
        ]]],
    ];
    $css = ':root{--content-size:800px;--wide-size:1280px}main{display:block}';
    $html = '<main>'
        . '<section id="hero"><div class="hero-inner"><h1>Hero</h1></div></section>'
        . '<section class="section"><div class="section-inner"><h2>Services</h2></div></section>'
        . '<section class="section"><div class="section-inner"><h2>About</h2></div></section>'
        . '<section class="section"><div class="section-inner"><h2>Contact</h2></div></section>'
        . '</main>';

    [$normalized, $warnings] = ThemeJsonStep::normalizeLayoutWidths($theme, $css, $html);

    assert_eq('1366px', $normalized['settings']['layout']['contentSize'] ?? null);
    assert_eq('0', $normalized['styles']['spacing']['padding']['left'] ?? null);
    assert_eq('0', $normalized['styles']['spacing']['padding']['right'] ?? null);
    assert_eq([], $warnings);
});

/**
 * The scaffold may wire families and roles, but a font SIZE for a block the
 * design left unstyled is an aesthetic choice, not wiring. Measured: assigning
 * core/quote the `lead` preset rendered quotes at 22px where the design's own
 * render was 18px, and six of eight corpus designs author no quote size at all.
 */
test('theme-json scaffold does not assign core/quote a font size', function () {
    $theme = ThemeJsonStep::applyScaffold([]);
    $quote = $theme['styles']['blocks']['core/quote'] ?? [];

    assert_eq(
        'var:preset|font-family|body',
        $quote['typography']['fontFamily'] ?? null,
        'family wiring still ships',
    );
    assert_true(
        !array_key_exists('fontSize', $quote['typography'] ?? []),
        'quotes inherit the body size the design gave them instead of a scaffold preset',
    );
    // Guard the mechanism, not just the absence: a model that DOES author a
    // quote size must still win, which is the path that keeps this removable.
    $authored = ThemeJsonStep::applyScaffold([
        'styles' => ['blocks' => ['core/quote' => ['typography' => [
            'fontSize' => 'var:preset|font-size|lead',
        ]]]],
    ]);
    assert_eq(
        'var:preset|font-size|lead',
        $authored['styles']['blocks']['core/quote']['typography']['fontSize'],
        'an explicitly authored quote size survives',
    );
});
