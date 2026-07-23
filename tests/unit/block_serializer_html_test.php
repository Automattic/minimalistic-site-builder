<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment;
use Automattic\SiteBuild\BlockSerializer\Html\RichText;
use Automattic\SiteBuild\BlockSerializer\Html\Selector;

test('HTML fragment retains source spans and exposes canonical DOM-like HTML', function () {
    $source = "lead<!-- note --><DIV id=x class='card featured' disabled data-kind=hero>"
        . '<span>A&amp;B&nbsp;</span><BR/>tail</DIV>end';
    $fragment = HtmlFragment::parse($source);
    $div = $fragment->querySelector('div.card[data-kind="hero"]');

    assert_true($div !== null);
    assert_eq(
        "<DIV id=x class='card featured' disabled data-kind=hero><span>A&amp;B&nbsp;</span><BR/>tail</DIV>",
        $div->rawHtml(),
    );
    assert_eq('<span>A&amp;B&nbsp;</span><BR/>tail', $div->rawInnerHtml());
    assert_eq(
        '<div id="x" class="card featured" disabled="" data-kind="hero">'
        . '<span>A&amp;B&nbsp;</span><br>tail</div>',
        $div->outerHtml(),
    );
    assert_eq("A&B\u{00A0}tail", $div->textContent());
    assert_eq($div->rawHtml(), substr(
        $source,
        $div->startOffset(),
        $div->endOffset() - $div->startOffset(),
    ));
    assert_eq($source, $fragment->rawHtml());
    assert_contains('<!-- note -->', $fragment->innerHtml());
});

test('closed selector grammar supports groups combinators compounds attributes and not', function () {
    $fragment = HtmlFragment::parse(
        '<section id="root">'
        . '<figure class="card featured"><a href="/one" download><img src="one.jpg"></a>'
        . '<figcaption class="caption">One</figcaption></figure>'
        . '<figure class="card"><div><a href="/two" data-role="cta-primary">Two</a></div></figure>'
        . '</section>'
    );

    assert_eq(1, count($fragment->querySelectorAll('figure > a[download]')));
    assert_eq('/one', $fragment->querySelector('figure > a[download]')?->attribute('href'));
    assert_eq('/two', $fragment->querySelector('figure a:not([download])')?->attribute('href'));
    assert_eq(2, count($fragment->querySelectorAll('figure.card')));
    assert_eq(1, count($fragment->querySelectorAll('a[data-role^="cta-"]')));
    assert_eq(1, count($fragment->querySelectorAll('a[href$="two"]')));

    $grouped = $fragment->querySelectorAll('.featured figcaption, a[data-role*="primary"]');
    assert_eq(['figcaption', 'a'], array_map(static fn ($node) => $node->tagName(), $grouped));
    assert_eq(2, count($fragment->querySelectorAll('a, figure a')), 'group overlap is de-duplicated');

    assert_throws(static fn () => Selector::compile('figure + figure'));
    assert_throws(static fn () => Selector::compile('a:hover'));
    assert_throws(static fn () => Selector::compile('a['));
});

test('HTML fragment applies bounded optional-close and malformed nesting recovery', function () {
    $fragment = HtmlFragment::parse('<ul><li>one<li>two</ul><p>tail');

    assert_eq(
        '<ul><li>one</li><li>two</li></ul><p>tail</p>',
        $fragment->innerHtml(),
    );
    assert_eq(['one', 'two'], array_map(
        static fn ($node): string => $node->textContent(),
        $fragment->querySelectorAll('ul > li'),
    ));

    $mismatched = HtmlFragment::parse('<div><span>x</div>after');
    assert_eq('<div><span>x</span></div>after', $mismatched->innerHtml());
});

test('RichText keeps inline semantics while canonicalizing entities NBSP attributes and br', function () {
    $nbsp = "\u{00A0}";
    $input = "<STRONG data-X='A&amp;B'>A&#160;B{$nbsp}C &amp; D</STRONG>"
        . "<br/><em title='&quot;'>E</em>";

    assert_eq(
        '<strong data-x="A&amp;B">A&nbsp;B&nbsp;C &amp; D</strong>'
        . '<br><em title="&quot;">E</em>',
        RichText::normalize($input),
    );
    assert_eq("A\u{00A0}B\u{00A0}C & DE", RichText::plainText($input));

    $whitespace = "  A\n  <span> B </span>  ";
    assert_eq($whitespace, RichText::normalize($whitespace));
    assert_eq($whitespace, RichText::normalize($whitespace, true));
    assert_eq("A😀≂̸Z", RichText::normalize('A&#x1f600;&NotEqualTilde;Z'));
});

test('RichText plain-string recreation emits literal NBSP like Gutenberg', function () {
    $nbsp = "\u{00A0}";
    assert_eq(
        "<strong data-x=\"A&amp;B\">A{$nbsp}B{$nbsp}C &amp; D</strong><br>next",
        RichText::fromHtmlString(
            "<STRONG data-X='A&amp;B'>A&#160;B{$nbsp}C &amp; D</STRONG><BR>next"
        ),
    );
});

test('closed selector grammar compiles every selector in the frozen registered universe', function () {
    $snapshot = require __DIR__ . '/../../src/BlockSerializer/Registry/generated-registry.php';
    $selectors = [];
    $collect = static function (array $schema) use (&$collect, &$selectors): void {
        if (isset($schema['selector'])) {
            assert_true(is_string($schema['selector']));
            $selectors[$schema['selector']] = true;
        }
        if (isset($schema['query'])) {
            assert_true(is_array($schema['query']));
            foreach ($schema['query'] as $subSchema) {
                assert_true(is_array($subSchema));
                $collect($subSchema);
            }
        }
    };

    foreach ($snapshot['registered'] as $block) {
        foreach ($block['attributes'] ?? [] as $schema) {
            $collect($schema);
        }
    }
    foreach (array_keys($selectors) as $selector) {
        Selector::compile($selector);
    }

    // 32 = the 31 oracle-frozen selectors plus `button`, introduced by the
    // core/tab-list `tabs` query source (Tabs-family amendment).
    assert_eq(32, count($selectors), 'snapshot selector inventory changed');
});
