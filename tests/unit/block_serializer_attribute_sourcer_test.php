<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockSerializer\Attributes\AttributeSourcer;

test('attribute sourcer resolves comment raw DOM and legacy sources', function () {
    $innerHtml = '<article data-id=7 hidden class=entry>'
        . '<h2>A &amp; <em>B</em><!-- note --></h2>'
        . '<a href="/x" download>Go<br/>Now</a>'
        . '<ul><li>One</li><li>Two<br>Three</li></ul>'
        . '<input checked value="yes">'
        . '</article>';
    $schemas = [
        'title' => ['type' => 'string', 'default' => 'Untitled'],
        'count' => ['type' => 'number'],
        'raw' => ['source' => 'raw', 'type' => 'string'],
        'dataId' => [
            'source' => 'attribute',
            'selector' => 'article',
            'attribute' => 'data-id',
            'type' => 'string',
        ],
        'hidden' => [
            'source' => 'attribute',
            'selector' => 'article',
            'attribute' => 'hidden',
            'type' => 'boolean',
        ],
        'disabled' => [
            'source' => 'attribute',
            'selector' => 'article',
            'attribute' => 'disabled',
            'type' => 'boolean',
        ],
        'className' => [
            'source' => 'property',
            'selector' => 'article',
            'property' => 'className',
            'type' => 'string',
        ],
        'checked' => [
            'source' => 'property',
            'selector' => 'input',
            'property' => 'checked',
            'type' => 'boolean',
        ],
        'heading' => ['source' => 'html', 'selector' => 'h2', 'type' => 'string'],
        'headingText' => ['source' => 'text', 'selector' => 'h2', 'type' => 'string'],
        'headingRich' => ['source' => 'rich-text', 'selector' => 'h2', 'type' => 'rich-text'],
        'items' => [
            'source' => 'html',
            'selector' => 'ul',
            'multiline' => 'li',
            'type' => 'string',
        ],
        'headingTag' => ['source' => 'tag', 'selector' => 'h2', 'type' => 'string'],
        'headingChildren' => ['source' => 'children', 'selector' => 'h2', 'type' => 'array'],
        'linkNode' => ['source' => 'node', 'selector' => 'a', 'type' => 'object'],
        'missingHtml' => ['source' => 'html', 'selector' => '.absent', 'type' => 'string'],
        'missingText' => [
            'source' => 'text',
            'selector' => '.absent',
            'type' => 'string',
            'default' => 'fallback',
        ],
        'missingRich' => ['source' => 'rich-text', 'selector' => '.absent', 'type' => 'rich-text'],
        'missingChildren' => ['source' => 'children', 'selector' => '.absent', 'type' => 'array'],
        'missingNode' => ['source' => 'node', 'selector' => '.absent'],
        'choice' => ['type' => 'string', 'enum' => ['one', 'two'], 'default' => 'one'],
        'invalidCount' => ['type' => 'number', 'default' => 4],
        'omitted' => ['type' => 'string'],
    ];

    $attributes = (new AttributeSourcer())->sourceAttributes(
        $schemas,
        [
            'title' => 'Example',
            'count' => 3,
            'choice' => 'invalid',
            'invalidCount' => '3',
        ],
        $innerHtml,
    );

    assert_eq('Example', $attributes['title']);
    assert_eq(3, $attributes['count']);
    assert_eq($innerHtml, $attributes['raw']);
    assert_eq('7', $attributes['dataId']);
    assert_eq(true, $attributes['hidden']);
    assert_eq(false, $attributes['disabled']);
    assert_eq('entry', $attributes['className']);
    assert_eq(true, $attributes['checked']);
    assert_eq('A &amp; <em>B</em><!-- note -->', $attributes['heading']);
    assert_eq('A & B', $attributes['headingText']);
    assert_eq('A &amp; <em>B</em><!-- note -->', $attributes['headingRich']);
    assert_eq('<li>One</li><li>Two<br>Three</li>', $attributes['items']);
    assert_eq('h2', $attributes['headingTag']);
    assert_eq([
        'A & ',
        ['type' => 'em', 'props' => ['children' => ['B']]],
    ], $attributes['headingChildren']);
    assert_eq([
        'type' => 'a',
        'props' => [
            'href' => '/x',
            'download' => '',
            'children' => [
                'Go',
                ['type' => 'br', 'props' => ['children' => []]],
                'Now',
            ],
        ],
    ], $attributes['linkNode']);
    assert_eq('', $attributes['missingHtml']);
    assert_eq('fallback', $attributes['missingText']);
    assert_eq('', $attributes['missingRich']);
    assert_eq([], $attributes['missingChildren']);
    assert_eq(null, $attributes['missingNode']);
    assert_eq('one', $attributes['choice']);
    assert_eq(4, $attributes['invalidCount']);
    assert_true(!array_key_exists('omitted', $attributes));
});

test('attribute sourcer recursively applies query matchers in document order', function () {
    $innerHtml = '<table><tbody>'
        . '<tr data-row=a><td><strong>Bread</strong></td><td data-n=2>2</td></tr>'
        . '<tr data-row=b><td>Milk</td><td data-n=3>3</td></tr>'
        . '</tbody></table>';
    $schemas = [
        'rows' => [
            'source' => 'query',
            'selector' => 'tbody tr',
            'type' => 'array',
            'query' => [
                'id' => ['source' => 'attribute', 'attribute' => 'data-row'],
                'tag' => ['source' => 'tag'],
                'cells' => [
                    'source' => 'query',
                    'selector' => 'td',
                    'query' => [
                        'content' => ['source' => 'rich-text'],
                        'text' => ['source' => 'text'],
                        'amount' => ['source' => 'attribute', 'attribute' => 'data-n'],
                    ],
                ],
            ],
        ],
    ];

    assert_eq([
        'rows' => [
            [
                'id' => 'a',
                'tag' => 'tr',
                'cells' => [
                    ['content' => '<strong>Bread</strong>', 'text' => 'Bread'],
                    ['content' => '2', 'text' => '2', 'amount' => '2'],
                ],
            ],
            [
                'id' => 'b',
                'tag' => 'tr',
                'cells' => [
                    ['content' => 'Milk', 'text' => 'Milk'],
                    ['content' => '3', 'text' => '3', 'amount' => '3'],
                ],
            ],
        ],
    ], (new AttributeSourcer())->source($schemas, [], $innerHtml));
});

test('attribute sourcer fails closed for unsupported or malformed registry shapes', function () {
    $sourcer = new AttributeSourcer();

    assert_throws(static fn () => $sourcer->source([
        'value' => ['source' => 'unknown'],
    ], [], '<p>x</p>'));
    assert_throws(static fn () => $sourcer->source([
        'value' => ['source' => 'text', 'selector' => 'p:hover'],
    ], [], '<p>x</p>'));
    assert_throws(static fn () => $sourcer->source([
        'value' => ['source' => 'property', 'selector' => 'p', 'property' => 'ownerDocument'],
    ], [], '<p>x</p>'));
    assert_throws(static fn () => $sourcer->source([
        'value' => ['source' => 'query', 'selector' => 'p'],
    ], [], '<p>x</p>'));
    assert_throws(static fn () => $sourcer->source([
        'value' => [
            'source' => 'query',
            'selector' => 'p',
            'query' => ['raw' => ['source' => 'raw']],
        ],
    ], [], '<p>x</p>'));
});
