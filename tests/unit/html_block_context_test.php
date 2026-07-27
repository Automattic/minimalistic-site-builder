<?php
declare(strict_types=1);

use Automattic\SiteBuild\HtmlBlockContext;

test('HTML block context masks inert examples while preserving offsets', function () {
    $fake = '<!-- wp:paragraph --><p>Fake</p><!-- /wp:paragraph -->';
    $actual = '<!-- wp:group --><div>Actual</div><!-- /wp:group -->';
    $html = "<style>{$fake}</style>\n<code>{$fake}</code>\n{$actual}";
    $view = HtmlBlockContext::delimiterView($html);

    assert_eq(strlen($html), strlen($view));
    assert_eq(4, count(HtmlBlockContext::hiddenDelimiterOffsets($html, $view)));
    assert_true(!str_contains($view, '<!-- wp:paragraph -->'));
    assert_contains('<!-- wp:group -->', $view);
});

test('HTML block context masks comments declarations and tag attributes', function () {
    $fake = '<!-- wp:paragraph --><p>Fake</p><!-- /wp:paragraph -->';
    $html = "<!-- example {$fake} -->"
        . "<![CDATA[{$fake}]]>"
        . "<div data-example=\"{$fake}\"></div>";
    $view = HtmlBlockContext::delimiterView($html);

    assert_eq(strlen($html), strlen($view));
    assert_eq(5, count(HtmlBlockContext::hiddenDelimiterOffsets($html, $view)));
    assert_true(!str_contains($view, '<!-- wp:paragraph -->'));
});

test('HTML block context keeps real and malformed Gutenberg comments visible', function () {
    $real = '<!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->';
    $malformed = '<!--wp:group {"broken":1 -->';
    $html = $real . $malformed;

    $view = HtmlBlockContext::delimiterView($html);
    assert_contains('<!-- wp:paragraph -->', $view);
    assert_contains('<!-- /wp:paragraph -->', $view);
    assert_contains($malformed, $view);
    assert_eq([], HtmlBlockContext::hiddenDelimiterOffsets($html));
});

test('HTML block context masks nested opaque elements through the outer closer', function () {
    $fake = '<!-- wp:paragraph --><p>Fake</p><!-- /wp:paragraph -->';
    foreach (['template', 'code', 'pre', 'object', 'applet'] as $tag) {
        $html = "<{$tag}><{$tag}>inner</{$tag}>\n{$fake}\n</{$tag}>";
        $view = HtmlBlockContext::delimiterView($html);

        assert_eq(
            2,
            count(HtmlBlockContext::hiddenDelimiterOffsets($html, $view)),
            "the whole outer {$tag} remains opaque",
        );
        assert_true(!str_contains($view, '<!-- wp:paragraph -->'));
    }
});

test('HTML block context ignores fake opaque closers in attributes and comments', function () {
    $fake = '<!-- wp:paragraph --><p>Fake</p><!-- /wp:paragraph -->';
    foreach (['template', 'code', 'pre', 'object', 'applet'] as $tag) {
        $attribute = "<{$tag}><div data-x=\"</{$tag}>\">{$fake}</div></{$tag}>";
        $comment = "<{$tag}><!-- </{$tag}> -->{$fake}</{$tag}>";

        assert_eq(2, count(HtmlBlockContext::hiddenDelimiterOffsets($attribute)));
        assert_eq(2, count(HtmlBlockContext::hiddenDelimiterOffsets($comment)));
    }
});

test('HTML block context treats noscript content as opaque', function () {
    $fake = '<!-- wp:paragraph --><p>Fake</p><!-- /wp:paragraph -->';
    assert_eq(
        2,
        count(HtmlBlockContext::hiddenDelimiterOffsets("<noscript>{$fake}</noscript>")),
    );
});

test('HTML block context follows script double-escaped closing states', function () {
    $fake = '<!-- wp:paragraph --><p>Fake</p><!-- /wp:paragraph -->';
    $real = '<!-- wp:heading --><h2>Real</h2><!-- /wp:heading -->';
    $html = "<script><!--<script></script>{$fake}</script>\n{$real}";
    $view = HtmlBlockContext::delimiterView($html);

    assert_eq(2, count(HtmlBlockContext::hiddenDelimiterOffsets($html, $view)));
    assert_true(!str_contains($view, '<!-- wp:paragraph -->'));
    assert_contains('<!-- wp:heading -->', $view);
});

test('HTML block context does not treat a slash as closing a non-void element', function () {
    $fake = '<!-- wp:paragraph --><p>Fake</p><!-- /wp:paragraph -->';
    foreach (['script', 'template', 'object'] as $tag) {
        assert_eq(
            2,
            count(HtmlBlockContext::hiddenDelimiterOffsets("<{$tag}/>{$fake}</{$tag}>")),
            "HTML keeps a slash-terminated {$tag} open",
        );
    }
});

test('HTML block context removes arbitrary nested elements through the outer closer', function () {
    assert_eq(
        'z',
        HtmlBlockContext::removeElements('<div><div>x</div>y</div>z', ['div']),
    );
});

test('HTML block context enters quote state only for quoted attribute values', function () {
    $fake = '<!-- wp:paragraph --><p>Fake</p><!-- /wp:paragraph -->';
    foreach ([
        "<div data-x=foo\"><span x=\">\n{$fake}",
        "<div =\"><span x=\">\n{$fake}",
        "<foo_bar x=\">\n{$fake}",
        "<foo.bar x=\">\n{$fake}",
        "<foo@bar x=\">\n{$fake}",
        "<foo\0bar x=\">\n{$fake}",
    ] as $html) {
        assert_eq(
            2,
            count(HtmlBlockContext::hiddenDelimiterOffsets($html)),
            'a malformed attribute cannot end an unfinished tag early',
        );
    }
});

test('HTML block context uses the HTML whitespace set', function () {
    assert_true(HtmlBlockContext::isWhitespace(" \t\r\n\f"));
    assert_true(!HtmlBlockContext::isWhitespace("\0"));
    assert_true(!HtmlBlockContext::isWhitespace("\x0B"));
    assert_true(!HtmlBlockContext::isWhitespace("\u{00A0}"));
});
