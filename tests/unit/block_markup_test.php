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
    assert_eq(null, $doc->endOffset(0), 'a crossed closer is not a safe endpoint');
    assert_eq(null, $doc->endOffset(1), 'the crossed child is not complete either');
});

test('parse flags a nested crossed closer even when an ancestor remains open', function () {
    $doc = BlockMarkup::parse(
        '<!-- wp:group --><!-- wp:columns --><!-- wp:paragraph --><p>cut<!-- /wp:columns -->'
    );

    assert_true($doc->hasMismatchedDelimiters());
    assert_eq([0], $doc->unclosedIndices(), 'the root remains independently unclosed');
});

test('a crossed child taints an ancestor even when that ancestor later closes', function () {
    $doc = BlockMarkup::parse(
        '<!-- wp:group --><!-- wp:columns --><!-- wp:paragraph --><p>cut'
        . '<!-- /wp:columns --><!-- /wp:group -->'
    );

    assert_true($doc->hasMismatchedDelimiters());
    assert_eq([], $doc->unclosedIndices());
    assert_eq(null, $doc->endOffset(0), 'the later group closer cannot make its subtree safe');
    assert_eq(null, $doc->endOffset(1), 'the crossed columns frame is unsafe');
    assert_eq(null, $doc->endOffset(2), 'the crossed paragraph frame is unsafe');
});

test('a closer with self-closing syntax is malformed and cannot end a block', function () {
    $closer = '<!-- /wp:paragraph /-->';
    $source = '<!-- wp:paragraph --><p>Text</p>' . $closer;
    $doc = BlockMarkup::parse($source);

    assert_true($doc->hasMismatchedDelimiters());
    assert_true($doc->hasMalformedDelimiters());
    assert_eq([0], $doc->unclosedIndices());
    assert_eq(null, $doc->endOffset(0));
    assert_eq([strlen('<!-- wp:paragraph --><p>Text</p>')], $doc->malformedDelimiterOffsets());
});

test('attributes require the same suffix whitespace as the pinned parser', function () {
    $source = '<!-- wp:paragraph {"dropCap":true}-->';
    $doc = BlockMarkup::parse($source);

    assert_eq([], $doc->indices());
    assert_true($doc->hasMalformedDelimiters());
    assert_eq([0], $doc->malformedDelimiterOffsets());
});

test('a grammar-rejected delimiter makes its containing block unsafe', function () {
    $open = '<!-- wp:group -->';
    $malformed = '<!-- wp:paragraph {"broken":1 -->';
    $source = $open . '<div>' . $malformed . '</div><!-- /wp:group -->';
    $doc = BlockMarkup::parse($source);

    assert_true($doc->hasMalformedDelimiters());
    assert_eq([strlen($open . '<div>')], $doc->malformedDelimiterOffsets());
    assert_eq(null, $doc->endOffset(0), 'a malformed descendant invalidates the enclosing span');
});

test('marker-shaped text inside a consumed attribute delimiter is not malformed', function () {
    $source = '<!-- wp:paragraph {"metadata":{"name":"Use <!-- wp:group"}} -->'
        . '<p>Text</p><!-- /wp:paragraph -->';
    $doc = BlockMarkup::parse($source);

    assert_true(!$doc->hasMalformedDelimiters());
    assert_eq(strlen($source), $doc->endOffset(0));
});

test('invalid delimiter JSON cannot create a safe block span', function () {
    $source = '<!-- wp:group {"tagName":"section","broken": } -->'
        . '<div></div><!-- /wp:group -->';
    $doc = BlockMarkup::parse($source);

    assert_true($doc->hasMalformedDelimiters());
    assert_eq([0], $doc->malformedDelimiterOffsets());
    assert_eq(null, $doc->endOffset(0));
});

test('JSON.parse-compatible lone surrogates keep delimiter spans structurally safe', function () {
    $source = '<!-- wp:group --><div>'
        . '<!-- wp:paragraph {"metadata":{"name":"\\ud800"}} -->'
        . '<p>Text</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    $doc = BlockMarkup::parse($source);

    assert_true(!$doc->hasMalformedDelimiters());
    assert_true($doc->endOffset(1) !== null, 'the nested child has a safe endpoint');
    assert_eq(strlen($source), $doc->endOffset(0));

    $attrs = $doc->attrs(1);
    assert_true(is_array($attrs), 'valid JS-compatible attrs remain editable');
    $attrs['dropCap'] = true;
    $doc->setAttrs(1, $attrs);
    $rendered = $doc->render();
    assert_contains('\\ud800', $rendered, 'editing preserves the lone surrogate');
    assert_contains('"dropCap":true', $rendered);
    assert_true(!BlockMarkup::parse($rendered)->hasMalformedDelimiters());
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

test('replaceClassTokenInOwnHtml can expand one exact token without touching prefixes or text', function () {
    $src = '<!-- wp:group -->'
        . '<div class="wp-block-group has-background-dim has-background-dimmed">'
        . 'Mention has-background-dim here</div><!-- /wp:group -->';
    $doc = BlockMarkup::parse($src);
    $doc->replaceClassTokenInOwnHtml(
        0,
        'has-background-dim',
        'has-background-dim-60 has-background-dim',
    );
    $out = $doc->render();
    assert_contains('class="wp-block-group has-background-dim-60 has-background-dim has-background-dimmed"', $out);
    assert_contains('Mention has-background-dim here', $out);
});

test('bounded class-token edits ignore class-like text inside sourced attributes', function () {
    $href = "https://example.test/?class='no-border-radius'";
    $src = '<!-- wp:button -->'
        . '<div class="wp-block-button"><a class="wp-block-button__link no-border-radius wp-element-button" '
        . 'href="' . $href . '">Go</a></div>'
        . '<!-- /wp:button -->';
    $doc = BlockMarkup::parse($src);
    $ownHtml = $doc->ownHtml(0);
    $linkStart = strpos($ownHtml, '<a ');
    $linkEnd = strpos($ownHtml, '>', $linkStart);
    assert_true(is_int($linkStart) && is_int($linkEnd));

    $doc->removeClassTokenInOwnHtmlRange(0, 'no-border-radius', $linkStart, $linkEnd + 1);
    $out = $doc->render();

    assert_contains('class="wp-block-button__link wp-element-button"', $out);
    assert_contains('href="' . $href . '"', $out, 'the sourced URL stays byte-for-byte intact');
});

test('attribute edits preserve sourced empty object and empty array identity', function () {
    $src = '<!-- wp:group {"metadata":{},"allowedBlocks":[],"numericObject":{"0":"zero"},'
        . '"tagName":"section"} -->'
        . '<section class="wp-block-group"></section><!-- /wp:group -->';
    $doc = BlockMarkup::parse($src);
    $attrs = $doc->attrs(0);
    $attrs['tagName'] = 'main';
    $doc->setAttrs(0, $attrs);

    $out = $doc->render();
    assert_contains('"metadata":{}', $out);
    assert_contains('"allowedBlocks":[]', $out);
    assert_contains('"numericObject":{"0":"zero"}', $out);
    assert_contains('"tagName":"main"', $out);
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

test('endOffset exposes exact spans: past the closer, past a void delimiter, null when unclosed', function () {
    $group = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:spacer {"height":"40px"} /-->'
        . '</div><!-- /wp:group -->';
    $doc = BlockMarkup::parse($group . "\ntrailing prose");
    // Closed block: end lands just past its closing comment.
    assert_eq(strlen($group), $doc->endOffset(0));
    assert_eq($group, substr($group . "\ntrailing prose", $doc->openingOffset(0), $doc->endOffset(0)));
    // Void block: end lands just past its self-closing delimiter.
    $void = '<!-- wp:spacer {"height":"40px"} /-->';
    assert_eq($doc->openingOffset(1) + strlen($void), $doc->endOffset(1));

    // Unclosed block: no exact end.
    $open = BlockMarkup::parse('<!-- wp:group --><div class="wp-block-group">');
    assert_eq(null, $open->endOffset(0));
});

test('endOffset matches each closer for nested blocks with the same name', function () {
    $outerOpen = '<!-- wp:group -->';
    $inner = '<!-- wp:group --><div></div><!-- /wp:group -->';
    $outerClose = '<!-- /wp:group -->';
    $source = $outerOpen . $inner . $outerClose;
    $doc = BlockMarkup::parse($source);

    assert_eq(strlen($outerOpen . $inner), $doc->endOffset(1));
    assert_eq(strlen($source), $doc->endOffset(0));
});
