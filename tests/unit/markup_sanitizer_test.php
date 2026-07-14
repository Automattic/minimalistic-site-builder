<?php
declare(strict_types=1);

use Automattic\SiteBuild\MarkupSanitizer;
use Automattic\SiteBuild\Steps\SectionsStep;

test('sanitize removes script elements with their bodies', function () {
    $out = MarkupSanitizer::sanitize(
        '<!-- wp:html --><script type="text/javascript">alert(document.cookie)</script><!-- /wp:html -->'
        . '<!-- wp:paragraph --><p>Kept.</p><!-- /wp:paragraph -->'
    );
    assert_true(!str_contains($out, '<script'), 'script tag removed');
    assert_true(!str_contains($out, 'alert('), 'script body removed');
    assert_contains('<p>Kept.</p>', $out);
});

test('sanitize removes the embed-element family but keeps inner text', function () {
    $out = MarkupSanitizer::sanitize(
        '<iframe src="https://evil.test/"></iframe><object data="x"><embed src="y">fallback text</object><base href="https://evil.test/">'
    );
    foreach (['<iframe', '<object', '<embed', '<base'] as $tag) {
        assert_true(!str_contains($out, $tag), "{$tag} removed");
    }
    assert_contains('fallback text', $out);
});

test('sanitize strips inline event handlers but never prose', function () {
    $out = MarkupSanitizer::sanitize(
        '<div class="wp-block-group" onclick="alert(1)" onmouseover=alert(2) data-x="k">'
        . '<p>Carry on writing = fine, even on days like this.</p></div>'
    );
    assert_true(!str_contains($out, 'onclick'), 'quoted handler removed');
    assert_true(!str_contains($out, 'onmouseover'), 'bare handler removed');
    assert_contains('data-x="k"', $out, 'other attributes kept');
    assert_contains('Carry on writing = fine, even on days like this.', $out);
});

test('sanitize neutralizes executable URL schemes and keeps real links', function () {
    $out = MarkupSanitizer::sanitize(
        '<a href="javascript:alert(1)">a</a>'
        . '<a href=\'JAVASCRIPT : alert(2)\'>b</a>'
        . '<img src="data:text/html;base64,PHNjcmlwdD4=">'
        . '<a href="/menu/#breads">menu</a><a href="https://example.com">ext</a>'
    );
    assert_true(stripos($out, 'javascript') === false, 'javascript: URLs neutralized');
    assert_true(!str_contains($out, 'data:text/html'), 'data: URLs neutralized');
    assert_contains('href="/menu/#breads"', $out);
    assert_contains('href="https://example.com"', $out);
});

test('sanitize leaves well-formed block markup byte-identical', function () {
    $markup = '<!-- wp:group {"layout":{"type":"constrained"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|50"}}}} -->'
        . '<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--50)">'
        . '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button -->'
        . '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/visit/">Visit us</a></div>'
        . '<!-- /wp:button --></div><!-- /wp:buttons --></div><!-- /wp:group -->';
    assert_eq($markup, MarkupSanitizer::sanitize($markup));
});

test('SectionsStep::markup sanitizes the part at intake', function () {
    $out = SectionsStep::markup(
        '<!-- wp:html --><script>alert(1)</script><!-- /wp:html --><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
        'section-test'
    );
    assert_true(!str_contains($out, '<script'), 'script stripped at the intake choke point');
    assert_contains('<!-- wp:paragraph -->', $out);
});
