<?php
declare(strict_types=1);

use Automattic\SiteBuild\TreeGraph\Instance;

test('Instance pickPattern filters content-context patterns and picks deterministically', function () {
    $patterns = [
        ['name' => 'b-hero', 'title' => 'Big Hero', 'categories' => ['banner'], 'parsed' => [['blockName' => 'core/cover']]],
        ['name' => 'a-hero', 'title' => 'Hero A', 'categories' => [], 'parsed' => [['blockName' => 'core/group']]],
        // A query-loop pattern is never a section idiom, whatever its name says.
        ['name' => 'aa-hero', 'title' => 'Hero Query', 'categories' => [], 'parsed' => [['blockName' => 'core/group', 'innerBlocks' => [['blockName' => 'core/query']]]]],
    ];
    $picked = Instance::pickPattern($patterns, 'hero');
    assert_eq('a-hero', $picked['name'], 'alphabetically-first among the first matching term');

    // First query term with matches wins: 'cover' never gets a look when
    // 'hero' already matched, and an unmatched role returns null.
    assert_eq(null, Instance::pickPattern($patterns, 'pricing'));

    // parsed_tree is accepted as the field name too.
    $legacy = [['name' => 'x-footer', 'title' => 'Footer', 'categories' => [], 'parsed_tree' => [['blockName' => 'core/group']]]];
    assert_eq('x-footer', Instance::pickPattern($legacy, 'footer')['name']);
});

test('Instance sliceManifest keeps only the role families and their schema keys', function () {
    $blocks = [
        'core/heading'   => ['title' => 'Heading', 'attributes' => ['level' => ['type' => 'number']], 'supports' => ['align' => true], 'parent' => null, 'styles' => [], 'variations' => []],
        'core/paragraph' => ['title' => 'Paragraph', 'attributes' => []],
        'core/gallery'   => ['attributes' => ['columns' => ['type' => 'number']], 'supports' => []],
    ];
    $slice = Instance::sliceManifest($blocks, ['role' => 'gallery']);
    assert_eq(['core/gallery', 'core/heading'], array_keys($slice['blocks']));
    // The title is picker chrome, not generation vocabulary — dropped.
    assert_eq(['attributes', 'supports', 'parent', 'styles', 'variations'], array_keys($slice['blocks']['core/heading']));

    // An unknown role falls back to the generic section family.
    $fallback = Instance::sliceManifest($blocks, ['role' => 'mystery']);
    assert_eq(['core/heading', 'core/paragraph'], array_keys($fallback['blocks']));

    // The furniture slice carries the identity/navigation families.
    $furniture = Instance::furnitureSlice($blocks);
    assert_eq(['core/paragraph', 'core/heading'], array_keys($furniture));
});

test('Instance toTreeIrBlocks converts parse shape to clean TreeIR nodes', function () {
    $parsed = [
        ['blockName' => null, 'attrs' => [], 'innerHTML' => "\n\n"],
        [
            'blockName'   => 'core/group',
            'attrs'       => ['align' => 'full'],
            'innerHTML'   => '<div></div>',
            'innerBlocks' => [
                ['blockName' => 'core/heading', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '<h2></h2>'],
            ],
        ],
    ];
    assert_eq([[
        'name'        => 'core/group',
        'attributes'  => ['align' => 'full'],
        'innerBlocks' => [['name' => 'core/heading']],
    ]], Instance::toTreeIrBlocks($parsed));

    // TreeIR-shaped input passes through unchanged.
    assert_eq(
        [['name' => 'core/cover', 'attributes' => ['url' => 'x']]],
        Instance::toTreeIrBlocks([['name' => 'core/cover', 'attributes' => ['url' => 'x']]]),
    );
    assert_eq([], Instance::toTreeIrBlocks(null));
});
