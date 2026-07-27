<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockSerializer\Attributes\AttributeNameResolver;
use Automattic\SiteBuild\BlockSerializer\Registry\BlockRegistry;

/**
 * The resolver decides whether an unregistered comment key is a recoverable
 * misspelling or an attribute the block simply does not have. Every rename is a
 * guess applied to authored markup, so the negative cases below matter more
 * than the positive ones: a wrong rename silently changes a page, while a
 * missed one only falls through to the drop path the caller already handles.
 */

/** @return array<string,array<string,mixed>> */
function anr_schemas(): array
{
    return [
        'verticalAlignment' => ['type' => 'string'],
        'textColor'         => ['type' => 'string'],
        'isStacked'         => ['type' => 'boolean'],
        'columnCount'       => ['type' => 'number'],
        'style'             => ['type' => 'object'],
        'anything'          => [],
    ];
}

test('shape variants resolve to the registered name', function () {
    foreach ([
        'vertical_alignment',
        'vertical-alignment',
        'VerticalAlignment',
        'VERTICALALIGNMENT',
        'verticalalignment',
        'Vertical Alignment',
    ] as $key) {
        assert_eq(
            'verticalAlignment',
            AttributeNameResolver::resolve($key, 'center', anr_schemas()),
            "{$key} normalizes onto verticalAlignment",
        );
    }
});

test('near-miss spellings resolve within the distance budget', function () {
    // One inserted letter, one substitution, one British spelling.
    assert_eq('verticalAlignment', AttributeNameResolver::resolve('verticlealignment', 'top', anr_schemas()));
    assert_eq('textColor', AttributeNameResolver::resolve('textColour', '#fff', anr_schemas()));
    assert_eq('textColor', AttributeNameResolver::resolve('textColo', '#fff', anr_schemas()));
});

test('a name too far from every candidate is left alone', function () {
    // The real failure: verticalAlignment is registered on core/columns, not on
    // core/group. Nothing in the group schema is a plausible correction, so the
    // resolver declines and the caller drops the key.
    $groupSchemas = (new BlockRegistry())->attributes('core/group');
    assert_eq(null, AttributeNameResolver::resolve('verticalAlignment', 'stretch', $groupSchemas));
    assert_eq(null, AttributeNameResolver::resolve('customTextColor', '#ff0000', anr_schemas()));
});

test('an ambiguous match is refused rather than broken arbitrarily', function () {
    // Both candidates sit at distance 1; picking either would be a coin flip.
    $schemas = ['borderTop' => ['type' => 'string'], 'borderTip' => ['type' => 'string']];
    assert_eq(null, AttributeNameResolver::resolve('borderTap', '1px', $schemas));

    // Two registered names differing only in shape cannot be told apart either.
    $shape = ['fooBar' => ['type' => 'string'], 'foo_bar' => ['type' => 'string']];
    assert_eq(null, AttributeNameResolver::resolve('FOOBAR', 'x', $shape));
});

test('short names never match on distance alone', function () {
    // `tag` and `top` are one edit apart and mean nothing like each other.
    $schemas = ['tag' => ['type' => 'string']];
    assert_eq(null, AttributeNameResolver::resolve('top', 'x', $schemas));
    // Exact shape matching still applies below the length floor.
    assert_eq('tag', AttributeNameResolver::resolve('Tag', 'x', $schemas));
});

test('a value the target cannot hold blocks the rename', function () {
    // Renaming here would only move the failure into the typed recreation.
    assert_eq(null, AttributeNameResolver::resolve('isStacke', 'yes please', anr_schemas()));
    assert_eq('isStacked', AttributeNameResolver::resolve('isStacke', true, anr_schemas()));

    assert_eq(null, AttributeNameResolver::resolve('columnCoun', 'three', anr_schemas()));
    assert_eq('columnCount', AttributeNameResolver::resolve('columnCoun', 3, anr_schemas()));

    // A schema with no declared type accepts whatever was authored.
    assert_eq('anything', AttributeNameResolver::resolve('anythin', ['a', 'b'], anr_schemas()));
});

test('objects resolve as objects however the decoder shaped them', function () {
    $object = new stdClass();
    $object->color = 'red';
    assert_eq('style', AttributeNameResolver::resolve('styl', $object, anr_schemas()));
    assert_eq('style', AttributeNameResolver::resolve('styl', ['color' => 'red'], anr_schemas()));
    // A list is not an object, so the rename is refused.
    assert_eq(null, AttributeNameResolver::resolve('styl', ['red', 'blue'], anr_schemas()));
});

test('a key the comment already carries is never overwritten', function () {
    // The authored verticalAlignment is an explicit choice; a stray shape
    // variant alongside it must not clobber that value with a guess.
    assert_eq(
        null,
        AttributeNameResolver::resolve(
            'vertical_alignment',
            'top',
            anr_schemas(),
            ['verticalAlignment' => 'center'],
        ),
    );
});

test('a key that normalizes to nothing is refused', function () {
    assert_eq(null, AttributeNameResolver::resolve('---', 'x', anr_schemas()));
    assert_eq(null, AttributeNameResolver::resolve('', 'x', anr_schemas()));
});
