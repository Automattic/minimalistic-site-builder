<?php
declare(strict_types=1);

use Automattic\SiteBuild\TreeGraph\Normalize;

test('Normalize folds a flat borderColor into its per-side entries', function () {
    $tree = [
        'version' => 1,
        'epoch'   => 'fp',
        'blocks'  => [[
            'name'       => 'core/group',
            'attributes' => [
                'borderColor' => 'brass',
                'style'       => ['border' => [
                    'top'   => ['width' => '1px'],
                    'left'  => ['width' => '4px', 'style' => 'double', 'color' => 'var:preset|color|contrast'],
                ]],
            ],
        ]],
    ];
    assert_eq(1, Normalize::normalizeTreeBorders($tree));
    $border = $tree['blocks'][0]['attributes']['style']['border'];
    // The declared side inherits the folded colour and a solid default.
    assert_eq(['style' => 'solid', 'width' => '1px', 'color' => 'var:preset|color|brass'], $border['top']);
    // A side with its own style and colour keeps both.
    assert_eq('double', $border['left']['style']);
    assert_eq('var:preset|color|contrast', $border['left']['color']);
    // The flat attribute is gone: WordPress would otherwise paint the
    // undeclared sides at the browser's 3px default.
    assert_true(!array_key_exists('borderColor', $tree['blocks'][0]['attributes']));
});

test('Normalize leaves an all-sides borderColor alone and reports zero folds', function () {
    $tree = [
        'version' => 1,
        'epoch'   => 'fp',
        'blocks'  => [[
            'name'       => 'core/group',
            'attributes' => [
                'borderColor' => 'brass',
                'style'       => ['border' => ['width' => '1px']],
            ],
            'innerBlocks' => [[
                'name' => 'core/paragraph',
            ]],
        ]],
    ];
    assert_eq(0, Normalize::normalizeTreeBorders($tree));
    assert_eq('brass', $tree['blocks'][0]['attributes']['borderColor']);
    // A node without attributes gains none from the walk.
    assert_true(!array_key_exists('attributes', $tree['blocks'][0]['innerBlocks'][0]));
});

test('Normalize folds nested nodes and counts each one', function () {
    $sided = [
        'name'       => 'core/separator',
        'attributes' => [
            'borderColor' => 'accent',
            'style'       => ['border' => ['bottom' => ['width' => '2px']]],
        ],
    ];
    $tree = [
        'version' => 1,
        'epoch'   => 'fp',
        'blocks'  => [[
            'name'        => 'core/group',
            'attributes'  => ['align' => 'full'],
            'innerBlocks' => [$sided, ['name' => 'core/group', 'innerBlocks' => [$sided]]],
        ]],
    ];
    assert_eq(2, Normalize::normalizeTreeBorders($tree));
    assert_eq(
        'var:preset|color|accent',
        $tree['blocks'][0]['innerBlocks'][1]['innerBlocks'][0]['attributes']['style']['border']['bottom']['color'],
    );
});
