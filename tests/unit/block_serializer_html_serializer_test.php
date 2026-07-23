<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockSerializer\Html\HtmlSerializer;
use Automattic\SiteBuild\BlockSerializer\Save\ElementNode;
use Automattic\SiteBuild\BlockSerializer\Save\RawNode;
use Automattic\SiteBuild\BlockSerializer\Save\TextNode;

test('HtmlSerializer centralizes escaping, booleans, styles, raw RichText and void tags', function () {
    $tree = new ElementNode('div', [
        'className' => 'x',
        'aria-hidden' => true,
        'hidden' => true,
        'data-label' => "cook's choice",
        'style' => ['paddingTop' => '1rem', 'lineHeight' => 1.2, '--custom' => 'a&b'],
    ], [
        new TextNode('A & <B>'),
        new RawNode('<strong>raw&nbsp;text</strong>'),
        new ElementNode('img', ['src' => 'a&b', 'alt' => '"quoted"']),
    ]);
    assert_eq(
        '<div class="x" aria-hidden="true" hidden data-label="cook\'s choice" style="padding-top:1rem;line-height:1.2;--custom:a&amp;b">A &amp; &lt;B&gt;<strong>raw&nbsp;text</strong><img src="a&amp;b" alt="&quot;quoted&quot;"/></div>',
        (new HtmlSerializer())->serialize($tree)
    );
});

test('HtmlSerializer adds px only to non-unitless nonzero numeric styles', function () {
    $html = (new HtmlSerializer())->serialize(new ElementNode('div', [
        'style' => ['width' => 12, 'height' => 0, 'opacity' => 0.5, 'zIndex' => 2],
    ]));
    assert_eq('<div style="width:12px;height:0;opacity:0.5;z-index:2"></div>', $html);
});

test('HtmlSerializer uses JavaScript shortest numbers for CSS values', function () {
    $html = (new HtmlSerializer())->serialize(new ElementNode('div', [
        'style' => ['minHeight' => 33.33333333333333, 'lineHeight' => 1.2345678901234567],
    ]));
    assert_eq(
        '<div style="min-height:33.33333333333333px;line-height:1.2345678901234567"></div>',
        $html,
    );
});
