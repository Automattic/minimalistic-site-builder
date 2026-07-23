<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockSerializer\Json\JsonNumber;
use Automattic\SiteBuild\BlockSerializer\Json\JsonObject;
use Automattic\SiteBuild\BlockSerializer\Parser\BlockNode;
use Automattic\SiteBuild\BlockSerializer\Parser\DefaultParser;
use Automattic\SiteBuild\BlockSerializer\Parser\FreeformNode;

test('default parser preserves freeform around void blocks in document order', function () {
    $source = 'before<!-- wp:image {"id":7} /-->between<!-- wp:separator /-->after';
    $document = DefaultParser::parse($source);
    $nodes = $document->nodes();
    assert_eq(5, count($nodes));
    assert_true($nodes[0] instanceof FreeformNode && $nodes[0]->content === 'before');
    assert_true($nodes[1] instanceof BlockNode && $nodes[1]->name === 'core/image' && $nodes[1]->void);
    assert_true($nodes[2] instanceof FreeformNode && $nodes[2]->content === 'between');
    assert_true($nodes[3] instanceof BlockNode && $nodes[3]->name === 'core/separator');
    assert_true($nodes[4] instanceof FreeformNode && $nodes[4]->content === 'after');
    assert_eq('<!-- wp:image {"id":7} /-->', $nodes[1]->rawSource);
    assert_eq($source, implode('', array_map(fn ($node): string => $node->rawSource(), $nodes)));
    $id = $nodes[1]->attributes?->get('id');
    assert_true($id instanceof JsonNumber && $id->value === 7.0);
});

test('default parser preserves nested innerHTML, innerContent placeholders, and raw slices', function () {
    $source = 'lead<!-- wp:group {"a":{"b":1},"c":2} --><div>A'
        . '<!-- wp:paragraph --><p>X</p><!-- /wp:paragraph -->B</div>'
        . '<!-- /wp:group -->tail';
    $nodes = DefaultParser::parse($source)->nodes();
    assert_eq(3, count($nodes));
    $group = $nodes[1];
    assert_true($group instanceof BlockNode);
    assert_eq('core/group', $group->name);
    assert_eq('<div>AB</div>', $group->innerHTML);
    assert_eq(['<div>A', null, 'B</div>'], $group->innerContent);
    assert_eq(1, count($group->innerBlocks));
    $paragraph = $group->innerBlocks[0];
    assert_eq('<p>X</p>', $paragraph->innerHTML);
    assert_eq(['<p>X</p>'], $paragraph->innerContent);
    assert_eq(
        '<!-- wp:paragraph --><p>X</p><!-- /wp:paragraph -->',
        $paragraph->rawSource
    );
    assert_eq(substr($source, $group->sourceStart, $group->sourceEnd - $group->sourceStart), $group->rawSource);
    assert_eq('<!-- wp:group {"a":{"b":1},"c":2} -->', $group->openingDelimiter);
    assert_eq('<!-- /wp:group -->', $group->closingDelimiter);
    assert_true($group->attributes instanceof JsonObject);
    assert_true($group->attributes->get('a') instanceof JsonObject, 'nested comment JSON survives tokenization');
});

test('default parser follows stack position for mismatched closing names', function () {
    $source = '<!-- wp:group --><div>A<!-- wp:paragraph --><p>X</p>'
        . '<!-- /wp:not-paragraph -->B</div><!-- /wp:not-group -->';
    $group = DefaultParser::parse($source)->nodes()[0];
    assert_true($group instanceof BlockNode);
    assert_eq(1, count($group->innerBlocks));
    assert_eq('core/paragraph', $group->innerBlocks[0]->name);
    assert_eq('<!-- /wp:not-paragraph -->', $group->innerBlocks[0]->closingDelimiter);
    assert_eq('<!-- /wp:not-group -->', $group->closingDelimiter);
    assert_eq('<div>AB</div>', $group->innerHTML);
});

test('default parser makes a stray closer and all following input freeform', function () {
    $source = 'before<!-- /wp:paragraph -->after<!-- wp:image /-->';
    $nodes = DefaultParser::parse($source)->nodes();
    assert_eq(1, count($nodes));
    assert_true($nodes[0] instanceof FreeformNode);
    assert_eq($source, $nodes[0]->content);
});

test('default parser implicitly closes a single unclosed block at EOF', function () {
    $source = 'lead<!-- wp:paragraph {"drop":-0} --><p>unfinished';
    $nodes = DefaultParser::parse($source)->nodes();
    assert_eq(2, count($nodes));
    assert_true($nodes[0] instanceof FreeformNode && $nodes[0]->content === 'lead');
    $paragraph = $nodes[1];
    assert_true($paragraph instanceof BlockNode);
    assert_eq('<p>unfinished', $paragraph->innerHTML);
    assert_eq(null, $paragraph->closingDelimiter);
    assert_eq(strlen($source), $paragraph->sourceEnd);
    assert_eq('<!-- wp:paragraph {"drop":-0} --><p>unfinished', $paragraph->rawSource);
    $drop = $paragraph->attributes?->get('drop');
    assert_true($drop instanceof JsonNumber && $drop->isNegativeZero());
});

test('default parser reproduces pinned unclosed nested-stack collapse', function () {
    $source = 'lead<!-- wp:group -->A<!-- wp:paragraph -->B';
    $nodes = DefaultParser::parse($source)->nodes();
    assert_eq(4, count($nodes));
    assert_true($nodes[0] instanceof FreeformNode && $nodes[0]->content === 'A');
    assert_true($nodes[1] instanceof BlockNode && $nodes[1]->name === 'core/paragraph');
    assert_eq('B', $nodes[1]->innerHTML);
    assert_true($nodes[2] instanceof FreeformNode && $nodes[2]->content === 'lead');
    assert_true($nodes[3] instanceof BlockNode && $nodes[3]->name === 'core/group');
    assert_eq('A<!-- wp:paragraph -->B', $nodes[3]->innerHTML);
    assert_eq([], $nodes[3]->innerBlocks, 'the pinned collapse does not invent nesting');
});

test('default parser distinguishes invalid comment JSON from absent attributes', function () {
    $source = '<!-- wp:paragraph {"x":} --><p>x</p><!-- /wp:paragraph -->'
        . '<!-- wp:image /-->';
    $nodes = DefaultParser::parse($source)->nodes();
    assert_eq(2, count($nodes));
    assert_true($nodes[0] instanceof BlockNode && $nodes[0]->attributes === null);
    assert_true($nodes[1] instanceof BlockNode && $nodes[1]->attributes instanceof JsonObject);
    assert_eq(0, count($nodes[1]->attributes));
});

test('default parser treats an opener missing its JSON brace as freeform fallback', function () {
    $source = '<!-- wp:paragraph {"x":1 --><p>x</p><!-- /wp:paragraph -->'
        . '<!-- wp:image /-->';
    $nodes = DefaultParser::parse($source)->nodes();
    assert_eq(1, count($nodes));
    assert_true($nodes[0] instanceof FreeformNode);
    assert_eq($source, $nodes[0]->content);
});

test('default parser gives malformed closer-void syntax the pinned void precedence', function () {
    $nodes = DefaultParser::parse('<!-- /wp:paragraph /-->')->nodes();
    assert_eq(1, count($nodes));
    assert_true($nodes[0] instanceof BlockNode);
    assert_eq('core/paragraph', $nodes[0]->name);
    assert_true($nodes[0]->void);
    assert_true($nodes[0]->attributes instanceof JsonObject);
});

test('default parser preserves the pinned nested empty-content distinction', function () {
    $source = '<!-- wp:group --><!-- wp:paragraph --><!-- /wp:paragraph --><!-- /wp:group -->';
    $group = DefaultParser::parse($source)->nodes()[0];
    assert_true($group instanceof BlockNode);
    assert_eq([null], $group->innerContent);
    assert_eq([''], $group->innerBlocks[0]->innerContent, 'nested close appends its empty trailing chunk');
});
