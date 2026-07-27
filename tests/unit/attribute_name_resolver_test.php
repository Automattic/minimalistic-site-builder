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
        'settings'          => ['type' => 'object'],
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

test('a misspelling is refused, not guessed at', function () {
    // Matching is shape-only by design. Measured against every real build in
    // this repo, edit-distance matching resolved nothing the shape pass did not
    // already get, while renaming `author` onto `anchor`, `alias` onto `align`
    // and `link` onto `lock`. A near-miss is dropped instead.
    assert_eq(null, AttributeNameResolver::resolve('verticlealignment', 'top', anr_schemas()));
    assert_eq(null, AttributeNameResolver::resolve('textColour', '#fff', anr_schemas()));
    assert_eq(null, AttributeNameResolver::resolve('textColo', '#fff', anr_schemas()));

    // The specific corruptions that motivated removing the fuzzy pass.
    $groupSchemas = (new BlockRegistry())->attributes('core/group');
    foreach ([
        'author' => 'anchor',
        'alias'  => 'align',
        'link'   => 'lock',
    ] as $stray => $wouldHaveHit) {
        assert_true(
            array_key_exists($wouldHaveHit, $groupSchemas),
            "{$wouldHaveHit} is a real core/group attribute",
        );
        assert_eq(null, AttributeNameResolver::resolve($stray, 'x', $groupSchemas), $stray);
    }
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
    // Two registered names differing only in shape cannot be told apart from
    // the authored key alone.
    $shape = ['fooBar' => ['type' => 'string'], 'foo_bar' => ['type' => 'string']];
    assert_eq(null, AttributeNameResolver::resolve('FOOBAR', 'x', $shape));
});

test('shape matching applies at any name length', function () {
    // There is no length floor now that distance matching is gone: an exact
    // shape match on a short name is as certain as one on a long name.
    $schemas = ['tag' => ['type' => 'string']];
    assert_eq('tag', AttributeNameResolver::resolve('Tag', 'x', $schemas));
    assert_eq(null, AttributeNameResolver::resolve('top', 'x', $schemas));
});

test('a value the target cannot hold blocks the rename', function () {
    // Renaming here would only move the failure into the typed recreation.
    assert_eq(null, AttributeNameResolver::resolve('is_stacked', 'yes please', anr_schemas()));
    assert_eq('isStacked', AttributeNameResolver::resolve('is_stacked', true, anr_schemas()));

    assert_eq(null, AttributeNameResolver::resolve('column_count', 'three', anr_schemas()));
    assert_eq('columnCount', AttributeNameResolver::resolve('column_count', 3, anr_schemas()));

    // A schema with no declared type accepts whatever was authored.
    assert_eq('anything', AttributeNameResolver::resolve('Anything', ['a', 'b'], anr_schemas()));
});

test('objects resolve as objects however the decoder shaped them', function () {
    $object = new stdClass();
    $object->color = 'red';
    assert_eq('settings', AttributeNameResolver::resolve('Settings', $object, anr_schemas()));
    assert_eq('settings', AttributeNameResolver::resolve('Settings', ['color' => 'red'], anr_schemas()));
    // A list is not an object, so the rename is refused.
    assert_eq(null, AttributeNameResolver::resolve('Settings', ['red', 'blue'], anr_schemas()));
});

test('style and layout are never rename targets', function () {
    // SupportDomainGuard validates these against the raw comment, before any
    // rename exists, and fails closed on an unreviewed path under them. A key
    // renamed onto either would arrive after that check and ship unvalidated,
    // so `{"Style":{"background":{"bogusKey":"x"}}}` would smuggle past the one
    // family that must never pass silently.
    $groupSchemas = (new BlockRegistry())->attributes('core/group');
    foreach (['Style', 'style_', 'STYLE'] as $key) {
        assert_eq(null, AttributeNameResolver::resolve($key, ['background' => []], $groupSchemas), $key);
    }
    foreach (['Layout', 'layout_', 'LAYOUT'] as $key) {
        assert_eq(null, AttributeNameResolver::resolve($key, ['type' => 'flex'], $groupSchemas), $key);
    }
});

test('source-backed attributes are never rename targets', function () {
    // core/image.rel is sourced from the saved `figure > a` markup, so a value
    // moved onto it is discarded by the next sourcing pass. Reporting that as a
    // successful rename would claim the value survived when it did not.
    $imageSchemas = (new BlockRegistry())->attributes('core/image');
    assert_true(isset($imageSchemas['rel']['source']), 'rel is source-backed in the pinned registry');
    assert_eq(null, AttributeNameResolver::resolve('Rel', 'nofollow', $imageSchemas));
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
