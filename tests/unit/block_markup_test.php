<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockMarkup;

test('parse builds the block tree with attributes', function () {
    $doc = BlockMarkup::parse(
        '<!-- wp:group {"backgroundColor":"contrast"} -->' . "\n" .
        '<div class="wp-block-group"><!-- wp:paragraph {"textColor":"base"} -->' . "\n" .
        '<p>Hello</p>' . "\n" .
        '<!-- /wp:paragraph --><!-- wp:spacer {"height":"40px"} /--></div>' . "\n" .
        '<!-- /wp:group -->'
    );
    assert_eq(3, count($doc->indices()));
    assert_eq('group', $doc->name(0));
    assert_eq(null, $doc->parent(0));
    assert_eq('paragraph', $doc->name(1));
    assert_eq(0, $doc->parent(1));
    assert_eq('spacer', $doc->name(2));
    assert_eq(0, $doc->parent(2));
    assert_eq([1, 2], $doc->children(0));
    assert_eq('contrast', $doc->attrs(0)['backgroundColor']);
    assert_contains('<p>Hello</p>', $doc->innerHtml(1));
});

test('parse handles nested-object attrs followed by another key (WP core regex trips on this)', function () {
    $doc = BlockMarkup::parse('<!-- wp:separator {"a":{"b":1},"c":{"d":2}} --><hr/><!-- /wp:separator -->');
    assert_eq(1, count($doc->indices()));
    assert_eq(['a' => ['b' => 1], 'c' => ['d' => 2]], $doc->attrs(0));
});

test('void blocks and attr-less blocks parse', function () {
    $doc = BlockMarkup::parse('<!-- wp:page-list /--><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->');
    assert_eq('page-list', $doc->name(0));
    assert_eq(null, $doc->attrs(0));
    assert_eq('paragraph', $doc->name(1));
});

test('parse flags a closer that crosses a still-open child block', function () {
    $doc = BlockMarkup::parse(
        '<!-- wp:group --><!-- wp:paragraph --><p>cut<!-- /wp:group -->'
    );

    assert_true($doc->hasMismatchedDelimiters());
    assert_eq([], $doc->unclosedIndices(), 'the tolerant parser consumed both frames');
});

test('parse flags a nested crossed closer even when an ancestor remains open', function () {
    $doc = BlockMarkup::parse(
        '<!-- wp:group --><!-- wp:columns --><!-- wp:paragraph --><p>cut<!-- /wp:columns -->'
    );

    assert_true($doc->hasMismatchedDelimiters());
    assert_eq([0], $doc->unclosedIndices(), 'the root remains independently unclosed');
});

test('render is byte-identical without mutations', function () {
    $src = '<!-- wp:group {"align":"full"} --><div class="wp-block-group">x</div><!-- /wp:group -->';
    assert_eq($src, BlockMarkup::parse($src)->render());
});

test('setAttrs rewrites only the opening comment, HTML untouched', function () {
    $src = '<!-- wp:paragraph {"textColor":"primary"} -->' . "\n"
        . '<p class="has-primary-color has-text-color">Hi</p>' . "\n"
        . '<!-- /wp:paragraph -->';
    $doc = BlockMarkup::parse($src);
    $attrs = $doc->attrs(0);
    $attrs['textColor'] = 'contrast';
    $doc->setAttrs(0, $attrs);
    $out = $doc->render();
    assert_contains('<!-- wp:paragraph {"textColor":"contrast"} -->', $out);
    assert_contains('has-primary-color', $out, 'HTML must be left for the block fixer to re-sync');
});

test('multiple mutations apply at the right offsets', function () {
    $src = '<!-- wp:group {"a":1} --><div><!-- wp:paragraph {"b":2} --><p>x</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
    $doc = BlockMarkup::parse($src);
    $doc->setAttrs(0, ['a' => 9]);
    $doc->setAttrs(1, ['b' => 8]);
    $out = $doc->render();
    assert_contains('<!-- wp:group {"a":9} -->', $out);
    assert_contains('<!-- wp:paragraph {"b":8} -->', $out);
});

test('malformed attrs (missing closing brace) cannot swallow later blocks', function () {
    // The broken opener must simply not parse as a block; the attrs scan must
    // not run past --> to a later } and turn everything between into one node
    // whose mutation would then delete the intervening content.
    $src = '<!-- wp:group {"broken":1 --><div>x</div><!-- /wp:group -->'
        . '<!-- wp:paragraph {"textColor":"base"} --><p>Keep me</p><!-- /wp:paragraph -->';
    $doc = BlockMarkup::parse($src);
    $names = array_map(fn (int $i) => $doc->name($i), $doc->indices());
    assert_true(in_array('paragraph', $names, true), 'the healthy paragraph must still parse');
    $p = (int) array_search('paragraph', $names, true);
    $attrs = $doc->attrs($p);
    $attrs['textColor'] = 'contrast';
    $doc->setAttrs($p, $attrs);
    $out = $doc->render();
    assert_contains('<div>x</div>', $out, 'content near the malformed opener must survive');
    assert_contains('<p>Keep me</p>', $out);
    assert_contains('<!-- wp:paragraph {"textColor":"contrast"} -->', $out);
});

test('replaceInOwnHtml rewrites class attributes only, never text content', function () {
    $src = '<!-- wp:paragraph {"textColor":"secondary"} -->'
        . '<p class="has-secondary-color has-text-color">Mention has-secondary-color here</p>'
        . '<!-- /wp:paragraph -->';
    $doc = BlockMarkup::parse($src);
    $doc->replaceInOwnHtml(0, 'has-secondary-color', 'has-base-color');
    $out = $doc->render();
    assert_contains('<p class="has-base-color has-text-color">', $out);
    assert_contains('Mention has-secondary-color here', $out, 'user copy must not be rewritten');
});

test('removeClassTokenInOwnHtml tokenizes: any whitespace, exact tokens, both quote styles', function () {
    $src = '<!-- wp:group -->'
        . "<div class=\"wp-block-group\treveal\nreveal-up\"><span class='reveal x'>Mention reveal here</span></div>"
        . '<!-- /wp:group -->';
    $doc = BlockMarkup::parse($src);
    $doc->removeClassTokenInOwnHtml(0, 'reveal');
    $out = $doc->render();
    assert_contains('class="wp-block-group reveal-up"', $out, "longer token survives; separators normalized");
    assert_contains("class='x'", $out, 'single-quoted attribute handled');
    assert_contains('Mention reveal here', $out, 'text content untouched');
});

test('ownHtml covers the root tag only, not descendants', function () {
    $src = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:paragraph --><p class="inner">Hi</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    $doc = BlockMarkup::parse($src);
    assert_contains('wp-block-group', $doc->ownHtml(0));
    assert_true(!str_contains($doc->ownHtml(0), 'inner'), "children's HTML excluded");
});

test('serializeComment escapes like WP serialize_block_attributes', function () {
    $expected = '<!-- wp:paragraph {"content":"a ' . '\\u002d\\u002d b \\u003C i \\u003E"} -->';
    assert_eq($expected, BlockMarkup::serializeComment('paragraph', ['content' => 'a -- b < i >'], false));
    assert_eq('<!-- wp:spacer {"height":"40px"} /-->', BlockMarkup::serializeComment('spacer', ['height' => '40px'], true));
    assert_eq('<!-- wp:paragraph -->', BlockMarkup::serializeComment('paragraph', [], false));
});
