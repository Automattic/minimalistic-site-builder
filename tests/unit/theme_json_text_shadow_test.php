<?php
declare(strict_types=1);

use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\ThemeJsonStep;
use Automattic\SiteBuild\Tests\FakeLlm;

test('theme-json removes text-targeted shadows and preserves surface shadows', function () {
    $input = [
        'settings' => ['shadow' => ['presets' => [
            ['slug' => 'lift', 'name' => 'Lift', 'shadow' => '0 8px 24px #0004'],
        ]]],
        'styles' => [
            'shadow' => '0 1px 4px #0002',
            'typography' => [
                'fontWeight' => '400',
                'textShadow' => '1px 1px #f00',
            ],
            'elements' => [
                'h1' => [
                    'shadow' => 'var:preset|shadow|lift',
                    'typography' => [
                        'fontWeight' => '800',
                        'textShadow' => '2px 2px #0ff',
                    ],
                    'color' => ['background' => '#fff'],
                ],
                'link' => [
                    ':hover' => ['shadow' => '0 2px #f0f'],
                    'typography' => ['textDecoration' => 'underline'],
                ],
                'button' => [
                    'shadow' => 'var:preset|shadow|lift',
                    'typography' => [
                        'fontWeight' => '700',
                        'textShadow' => '1px 1px #000',
                    ],
                ],
            ],
            'blocks' => [
                'core/paragraph' => [
                    'shadow' => 'var:preset|shadow|lift',
                    'typography' => [
                        'lineHeight' => '1.7',
                        'textShadow' => '1px 0 #f00',
                    ],
                ],
                'core/quote' => ['shadow' => 'var:preset|shadow|lift'],
                'core/pullquote' => ['shadow' => 'var:preset|shadow|lift'],
                'core/list' => ['shadow' => 'var:preset|shadow|lift'],
                'core/group' => [
                    'shadow' => 'var:preset|shadow|lift',
                    'spacing' => ['padding' => '1rem'],
                    'typography' => ['textShadow' => '1px 1px #f00'],
                ],
                'core/image' => ['shadow' => 'var:preset|shadow|lift'],
                'core/cover' => ['shadow' => 'var:preset|shadow|lift'],
            ],
        ],
    ];

    [$theme, $warnings] = ThemeJsonStep::repairTextTargetShadows($input);

    assert_eq('0 1px 4px #0002', $theme['styles']['shadow'], 'global canvas shadow survives');
    assert_eq('400', $theme['styles']['typography']['fontWeight'], 'root typography sibling survives');
    assert_true(!array_key_exists('textShadow', $theme['styles']['typography']));
    assert_true(!array_key_exists('shadow', $theme['styles']['elements']['h1']));
    assert_eq('800', $theme['styles']['elements']['h1']['typography']['fontWeight']);
    assert_eq(['background' => '#fff'], $theme['styles']['elements']['h1']['color']);
    assert_true(!array_key_exists(':hover', $theme['styles']['elements']['link']));
    assert_eq('var:preset|shadow|lift', $theme['styles']['elements']['button']['shadow']);
    assert_eq('700', $theme['styles']['elements']['button']['typography']['fontWeight']);

    assert_true(!array_key_exists('shadow', $theme['styles']['blocks']['core/paragraph']));
    assert_eq('1.7', $theme['styles']['blocks']['core/paragraph']['typography']['lineHeight']);
    foreach (['core/quote', 'core/pullquote', 'core/list'] as $textBlock) {
        assert_true(!array_key_exists($textBlock, $theme['styles']['blocks']), "empty {$textBlock} style pruned");
    }
    foreach (['core/group', 'core/image', 'core/cover'] as $surfaceBlock) {
        assert_eq(
            'var:preset|shadow|lift',
            $theme['styles']['blocks'][$surfaceBlock]['shadow'],
            "{$surfaceBlock} surface shadow survives",
        );
    }
    assert_eq(['padding' => '1rem'], $theme['styles']['blocks']['core/group']['spacing']);
    assert_eq($input['settings']['shadow'], $theme['settings']['shadow'], 'preset definitions survive');

    assert_eq(11, count($warnings), 'one durable row is produced per removed declaration');
    $joined = implode("\n", $warnings);
    foreach ([
        'styles.typography.textShadow',
        'styles.elements.h1.shadow',
        'styles.elements.h1.typography.textShadow',
        'styles.elements.link.:hover.shadow',
        'styles.elements.button.typography.textShadow',
        'styles.blocks.core/paragraph.shadow',
        'styles.blocks.core/paragraph.typography.textShadow',
        'styles.blocks.core/quote.shadow',
        'styles.blocks.core/pullquote.shadow',
        'styles.blocks.core/list.shadow',
        'styles.blocks.core/group.typography.textShadow',
    ] as $path) {
        assert_contains("theme/theme.json {$path}: authored", $joined, "actionable path {$path}");
    }
    foreach ($warnings as $warning) {
        assert_contains('; delivered removed; disposition ', $warning, 'row carries delivered value and disposition');
    }

    [$fixedPoint, $fixedPointWarnings] = ThemeJsonStep::repairTextTargetShadows($theme);
    assert_eq($theme, $fixedPoint, 'shadow repair reaches a fixed point');
    assert_eq([], $fixedPointWarnings, 'the fixed point produces no duplicate warnings');
});

test('theme-json leaves inert shadow resets byte-identical without warnings', function () {
    $input = [
        'styles' => [
            'typography' => ['textShadow' => ' /* reset */ NONE !important '],
            'elements' => [
                'h1' => ['shadow' => ' initial '],
                'h2' => ['shadow' => 'UNSET'],
                'h3' => ['shadow' => 'revert'],
                'h4' => ['shadow' => 'revert-layer'],
                'h5' => ['shadow' => false],
                'h6' => ['shadow' => null],
                'caption' => ['shadow' => 0],
                'cite' => ['shadow' => 0.0],
                'link' => ['shadow' => ''],
            ],
            'blocks' => [
                'core/paragraph' => ['shadow' => []],
                'core/quote' => ['typography' => ['textShadow' => " \t\n"]],
                'core/pullquote' => ['typography' => ['textShadow' => 'none']],
            ],
        ],
    ];
    $encoded = json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    [$theme, $warnings] = ThemeJsonStep::repairTextTargetShadows($input);

    assert_eq($input, $theme, 'inert authored declarations retain keys, values, types, and ordering');
    assert_eq(
        $encoded,
        json_encode($theme, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'the repair leaves inert theme JSON bytes unchanged after equivalent encoding',
    );
    assert_eq([], $warnings, 'inert declarations add no warning noise');

    [$fixedPoint, $fixedPointWarnings] = ThemeJsonStep::repairTextTargetShadows($theme);
    assert_eq($theme, $fixedPoint, 'the inert input is already a fixed point');
    assert_eq([], $fixedPointWarnings, 'the inert fixed point remains warning-free');
});

test('theme-json writes text-shadow removals to warnings.json and stays fixed', function () {
    with_project('builder_tj_text_shadow_', function ($project): void {
        $project->writeJson('meta.json', ['prompt' => 'An editorial swim club']);
        $project->writeJson('siteSpec.json', ['name' => 'Breakwater']);
        seed_test_design_direction($project);

        $payload = valid_theme_payload();
        $payload['styles'] = [
            'elements' => [
                'h1' => [
                    'shadow' => 'var:preset|shadow|misregister',
                    'typography' => ['fontWeight' => '900'],
                ],
            ],
            'blocks' => [
                'core/paragraph' => [
                    'typography' => [
                        'lineHeight' => '1.65',
                        'textShadow' => '2px 0 #0ff, -2px 0 #f60',
                    ],
                ],
                'core/group' => ['shadow' => 'var:preset|shadow|plate-lift'],
                'core/image' => ['shadow' => 'var:preset|shadow|plate-lift'],
            ],
        ];
        $llm = new FakeLlm();
        $llm->queueJson($payload);
        quietly(fn () => (new ThemeJsonStep(
            $llm,
            new PromptRenderer(repo_path('prompts')),
        ))->run($project));

        $theme = $project->readJson('theme/theme.json');
        assert_true(!array_key_exists('shadow', $theme['styles']['elements']['h1']));
        assert_true(!array_key_exists(
            'textShadow',
            $theme['styles']['blocks']['core/paragraph']['typography'],
        ));
        assert_eq('900', $theme['styles']['elements']['h1']['typography']['fontWeight']);
        assert_eq('1.65', $theme['styles']['blocks']['core/paragraph']['typography']['lineHeight']);
        assert_eq(
            'var:preset|shadow|plate-lift',
            $theme['styles']['blocks']['core/group']['shadow'],
        );
        assert_eq(
            'var:preset|shadow|plate-lift',
            $theme['styles']['blocks']['core/image']['shadow'],
        );

        $durable = implode("\n", $project->readJson('warnings.json')['theme-json'] ?? []);
        assert_contains(
            'theme/theme.json styles.elements.h1.shadow: authored "var:preset|shadow|misregister"; delivered removed',
            $durable,
        );
        assert_contains(
            'theme/theme.json styles.blocks.core/paragraph.typography.textShadow: authored '
                . '"2px 0 #0ff, -2px 0 #f60"; delivered removed',
            $durable,
        );
        assert_contains('disposition removed text-targeted box shadow', $durable);
        assert_contains('disposition removed glyph shadow', $durable);

        [$fixedPoint, $fixedPointWarnings] = ThemeJsonStep::repairScaffold($theme);
        assert_eq($theme, $fixedPoint, 'persisted theme is a scaffold/shadow fixed point');
        assert_eq([], $fixedPointWarnings, 'persisted fixed point produces no warning rows');
    });
});
