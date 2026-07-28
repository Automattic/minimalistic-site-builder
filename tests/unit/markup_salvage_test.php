<?php
declare(strict_types=1);

use Automattic\SiteBuild\MarkupSalvage;
use Automattic\SiteBuild\SectionRhythm;

test('MarkupSalvage returns a complete document untouched', function () {
    $markup = <<<HTML
<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group">
<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->
<!-- wp:spacer {"height":"40px"} /-->
</div>
<!-- /wp:group -->
HTML;

    $out = MarkupSalvage::repair($markup);

    assert_eq($markup, $out['markup'], 'a well-formed part is byte-for-byte untouched');
    assert_eq([], $out['notes']);
});

test('MarkupSalvage repairs a response cut off mid-attribute-JSON (BIGR-716 shape)', function () {
    // The portfolio2 failure: the stream ended inside a paragraph's comment
    // JSON, leaving the root group and an inner group without closers.
    $markup = <<<HTML
<!-- wp:group {"metadata":{"name":"Gallery"},"layout":{"type":"constrained"}} -->
<div class="wp-block-group">
<!-- wp:heading --><h2 class="wp-block-heading">Events</h2><!-- /wp:heading -->
<!-- wp:group {"layout":{"type":"flex"}} -->
<div class="wp-block-group">
<!-- wp:paragraph --><p>One</p><!-- /wp:paragraph -->
<!-- wp:paragraph {"style":{"typography":{"textTransform":"
HTML;

    $out = MarkupSalvage::repair($markup);
    $salvaged = $out['markup'];

    assert_true(!str_contains($salvaged, 'textTransform'), 'the dangling half-written delimiter is trimmed');
    assert_contains('<p>One</p>', $salvaged, 'the last complete block is kept');
    assert_eq(
        substr_count($salvaged, '<!-- wp:group'),
        substr_count($salvaged, '<!-- /wp:group -->'),
        'every group opener has a closer',
    );
    assert_eq(substr_count($salvaged, '<div'), substr_count($salvaged, '</div>'), 'the div stack is rebalanced');
    assert_eq(1, count($out['notes']));
    assert_contains('salvaged truncated markup', $out['notes'][0]);

    // The end-to-end point of the salvage: the part now passes the
    // section-rhythm gate that aborted the portfolio2 build.
    $result = SectionRhythm::rewrite([[
        'slug'       => 'gallery-events',
        'markup'     => $salvaged,
        'density'    => 'standard',
        'background' => 'base',
    ]]);
    assert_eq(1, count($result['markups']));
});

test('MarkupSalvage drops a block truncated mid-sentence instead of closing it', function () {
    $markup = <<<HTML
<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group">
<!-- wp:paragraph --><p>Complete thought.</p><!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p>This sentence was cut o
HTML;

    $out = MarkupSalvage::repair($markup);

    assert_true(!str_contains($out['markup'], 'cut o'), 'the half-written paragraph is dropped, not published');
    assert_contains('<p>Complete thought.</p>', $out['markup']);
    assert_contains('dropped 1 incomplete trailing block(s)', $out['notes'][0]);
    assert_contains('closed 1 unclosed block(s)', $out['notes'][0]);
});

test('MarkupSalvage closes an unclosed container whose children are all complete', function () {
    $markup = <<<HTML
<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group">
<!-- wp:columns -->
<div class="wp-block-columns">
<!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>A</p><!-- /wp:paragraph --></div><!-- /wp:column -->
<!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>B</p><!-- /wp:paragraph --></div><!-- /wp:column -->
HTML;

    $out = MarkupSalvage::repair($markup);

    assert_contains('<p>A</p>', $out['markup']);
    assert_contains('<p>B</p>', $out['markup'], 'both complete columns survive');
    assert_contains('<!-- /wp:columns -->', $out['markup']);
    assert_contains('<!-- /wp:group -->', $out['markup']);
    assert_eq(substr_count($out['markup'], '<div'), substr_count($out['markup'], '</div>'));
});

test('MarkupSalvage closes wrapper elements opened between retained children', function () {
    $markup = "<!-- wp:group -->\n<div>\n"
        . '<!-- wp:heading --><h2>Heading</h2><!-- /wp:heading -->'
        . "\n<span>\n"
        . '<!-- wp:paragraph --><p>Paragraph</p><!-- /wp:paragraph -->';

    $out = MarkupSalvage::repair($markup)['markup'];

    assert_contains('<p>Paragraph</p>', $out);
    assert_eq(1, substr_count($out, '<span>'));
    assert_eq(1, substr_count($out, '</span>'));
    assert_eq(1, substr_count($out, '<div>'));
    assert_eq(1, substr_count($out, '</div>'));
});

test('MarkupSalvage rebuilds the multi-tag stack a cover block leaves open', function () {
    $markup = <<<HTML
<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group">
<!-- wp:cover {"url":"x.jpg"} -->
<div class="wp-block-cover"><span aria-hidden="true" class="wp-block-cover__background"></span><img class="wp-block-cover__image-background" src="x.jpg"/><div class="wp-block-cover__inner-container">
<!-- wp:heading --><h2 class="wp-block-heading">Title</h2><!-- /wp:heading -->
HTML;

    $out = MarkupSalvage::repair($markup);

    assert_contains('<!-- /wp:cover -->', $out['markup']);
    assert_eq(
        substr_count($out['markup'], '<div'),
        substr_count($out['markup'], '</div>'),
        'the cover closes both its outer wrapper and inner container (span/img need no closers)',
    );
    assert_true(!str_contains($out['markup'], '</span>' . '</span>'), 'the already-closed span is not closed again');
});

test('MarkupSalvage trims a dangling delimiter after a fully closed document', function () {
    $markup = "<!-- wp:group -->\n<div class=\"wp-block-group\"></div>\n<!-- /wp:group -->\n<!-- wp:para";

    $out = MarkupSalvage::repair($markup);

    assert_true(str_ends_with($out['markup'], '<!-- /wp:group -->'));
    assert_contains('trimmed an incomplete trailing delimiter', $out['notes'][0]);
});

test('MarkupSalvage throws when nothing complete remains to keep', function () {
    $markup = '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"><p>partial tex';

    assert_throws(fn () => MarkupSalvage::repair($markup));
});

test('MarkupSalvage rejects an outer closer that crosses an unclosed paragraph', function () {
    $markup = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:paragraph --><p>cut off'
        . '<!-- /wp:group -->';

    assert_throws(fn () => MarkupSalvage::repair($markup));
});

test('MarkupSalvage rejects a columns closer that crosses an unclosed paragraph at EOF', function () {
    $markup = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:columns --><div class="wp-block-columns">'
        . '<!-- wp:paragraph --><p>cut off'
        . '<!-- /wp:columns -->';

    assert_throws(fn () => MarkupSalvage::repair($markup));
});

test('MarkupSalvage rejects a closer that also uses self-closing syntax', function () {
    $markup = '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph /-->';
    assert_throws(fn () => MarkupSalvage::repair($markup));
});

test('MarkupSalvage ignores tags mentioned in comments and raw-text element bodies', function () {
    $html = '<div class="wp-block-group">'
        . '<!-- future <span> wrapper -->'
        . '<style>.x::before { content: "<aside>"; }</style>';

    assert_eq(['div'], MarkupSalvage::openElements($html));
    assert_true(MarkupSalvage::isContainerPrefix($html));
});

test('MarkupSalvage HTML stacks follow ancestor-close and non-void slash semantics', function () {
    assert_eq(
        [],
        MarkupSalvage::openElements('<div><span></div>'),
        'closing an ancestor implicitly closes its descendants',
    );
    assert_eq(
        ['div', 'span'],
        MarkupSalvage::openElements('<div><span/>'),
        'a slash does not self-close a non-void HTML element',
    );
});

test('strict wrapper stacks reject replacement roots crossed tags and stray closers', function () {
    assert_eq(
        null,
        MarkupSalvage::advanceStrictWrapperStack('<div></div><section>', [], false),
    );
    assert_eq(
        null,
        MarkupSalvage::advanceStrictWrapperStack('<div><span></div>', [], false),
    );
    assert_eq(
        null,
        MarkupSalvage::advanceStrictWrapperStack('<div></aside>', [], false),
    );
});

test('MarkupSalvage never synthesizes closers for tags mentioned in comments or styles', function () {
    $markup = "<!-- wp:group -->\n"
        . "<div class=\"wp-block-group\">\n"
        . "<!-- future <span> wrapper -->\n"
        . "<style>.x::before { content: \"<aside>\"; }</style>\n"
        . "<!-- wp:heading --><h2>Kept</h2><!-- /wp:heading -->\n"
        . "<!-- wp:paragraph --><p>cut";

    $out = MarkupSalvage::repair($markup)['markup'];

    assert_contains('<h2>Kept</h2>', $out);
    assert_true(!str_contains($out, '</span>'), 'comment example did not enter the tag stack');
    assert_true(!str_contains($out, '</aside>'), 'style text did not enter the tag stack');
    assert_true(str_ends_with($out, "</div>\n<!-- /wp:group -->"));
});

test('MarkupSalvage does not treat attribute text as comments or raw elements', function () {
    foreach (['<style>', '<!--'] as $attributeText) {
        $markup = '<!-- wp:group --><div data-x="' . $attributeText . '">'
            . '<!-- wp:paragraph --><p>Kept</p><!-- /wp:paragraph -->';
        $out = MarkupSalvage::repair($markup)['markup'];

        assert_contains('<p>Kept</p>', $out);
        assert_true(str_ends_with($out, "</div>\n<!-- /wp:group -->"));
    }
});

test('MarkupSalvage rejects children swallowed by an unclosed raw-text or comment region', function () {
    $style = "<!-- wp:group -->\n<div class=\"wp-block-group\">\n"
        . "<style>.x { color: red; }\n"
        . "<!-- wp:heading --><h2>Hidden</h2><!-- /wp:heading -->";
    $comment = "<!-- wp:group -->\n<div class=\"wp-block-group\">\n"
        . "<!-- unfinished\n"
        . "<!-- wp:heading --><h2>Hidden</h2><!-- /wp:heading -->";

    assert_throws(fn () => MarkupSalvage::repair($style));
    assert_throws(fn () => MarkupSalvage::repair($comment));
    assert_throws(fn () => MarkupSalvage::openElements('<div><style>unfinished'));
});

test('MarkupSalvage trims an unclosed raw-text tail after the last visible child', function () {
    $markup = "<!-- wp:group -->\n<div class=\"wp-block-group\">\n"
        . "<!-- wp:heading --><h2>Kept</h2><!-- /wp:heading -->\n"
        . "<style>.x { color: red; }\n"
        . "<!-- wp:paragraph --><p>Hidden</p><!-- /wp:paragraph -->";

    $out = MarkupSalvage::repair($markup)['markup'];

    assert_contains('<h2>Kept</h2>', $out);
    assert_true(!str_contains($out, '<style>'));
    assert_true(!str_contains($out, 'Hidden'));
    assert_true(str_ends_with($out, "</div>\n<!-- /wp:group -->"));
});
