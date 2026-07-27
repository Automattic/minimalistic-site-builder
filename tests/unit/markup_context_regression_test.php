<?php
declare(strict_types=1);

use Automattic\SiteBuild\MarkupSanitizer;
use Automattic\SiteBuild\Units\GeneratedMarkup;

/**
 * Regressions for the HTML lexer that backs both the sanitizer and block
 * recovery. Each case pins a browser tokenization rule the scanner has to
 * agree with: disagreeing in one direction leaks live markup past the
 * sanitizer, in the other it hides real block delimiters and fails the build.
 */

// --- Tokenizer boundaries the sanitizer depends on --------------------------

test('a less-than followed by space is text, not a tag that swallows a script', function () {
    $out = MarkupSanitizer::sanitize(
        '<!-- wp:html --><p>a < b <script>alert(document.domain)</script></p><!-- /wp:html -->'
    );
    assert_true(!str_contains($out, '<script'), 'script tag removed');
    assert_true(!str_contains($out, 'alert(document.domain)'), 'script body removed');
});

test('a less-than followed by space does not hide a base tag', function () {
    $out = MarkupSanitizer::sanitize(
        '<!-- wp:html --><p>x < y <base href="https://evil.example/">z</p><!-- /wp:html -->'
    );
    assert_true(!str_contains($out, '<base'), 'base tag removed');
});

test('CDATA outside foreign content is a bogus comment ending at the first >', function () {
    $out = MarkupSanitizer::sanitize(
        '<!-- wp:html --><div><![CDATA[><script>alert(1)</script>]]></div><!-- /wp:html -->'
    );
    assert_true(!str_contains($out, 'alert(1)'), 'live script after the bogus comment removed');
});

test('an unterminated CDATA does not leave the rest of the response unsanitized', function () {
    $out = MarkupSanitizer::sanitize(
        '<!-- wp:html --><div><![CDATA[x> <img src=x onerror=alert(1)></div><!-- /wp:html -->'
    );
    assert_true(!str_contains($out, 'onerror'), 'event handler after the bogus comment removed');
});

test('a quoted > does not extend a bogus comment over live markup', function () {
    $out = MarkupSanitizer::sanitize(
        '<!-- wp:html --><div><! " ><img src=x onerror=alert(1)> " ></div><!-- /wp:html -->'
    );
    assert_true(!str_contains($out, 'onerror'), 'event handler outside the bogus comment removed');
});

test('svg title is not raw text, so markup inside it is still sanitized', function () {
    $out = MarkupSanitizer::sanitize(
        '<!-- wp:html --><svg><title><img src=x onerror=alert(1)></title></svg><!-- /wp:html -->'
    );
    assert_true(!str_contains($out, 'onerror'), 'event handler inside svg title removed');
});

test('html title outside foreign content stays raw text', function () {
    $out = MarkupSanitizer::sanitize(
        '<!-- wp:html --><title>a <b> b</title><p>After.</p><!-- /wp:html -->'
    );
    assert_contains('<title>a <b> b</title>', $out);
});

// --- The same boundaries must not hide real block delimiters ----------------

test('a code sample containing a less-than does not break the document', function () {
    $out = GeneratedMarkup::normalize(
        '<!-- wp:code --><pre class="wp-block-code"><code>if (a < b) {}</code></pre>'
        . '<!-- /wp:code -->'
        . '<!-- wp:paragraph --><p>After.</p><!-- /wp:paragraph -->',
        'section-1'
    );
    assert_contains('<p>After.</p>', $out);
    assert_contains('if (a < b) {}', $out);
});

test('an unclosed inline code element does not swallow later blocks', function () {
    $out = GeneratedMarkup::normalize(
        '<!-- wp:paragraph --><p>Use <code>npm</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Second.</p><!-- /wp:paragraph -->',
        'section-2'
    );
    assert_contains('Second.', $out);
});

test('a self-closed svg child does not leave the wrapper unbalanced', function () {
    $out = GeneratedMarkup::normalize(
        '<!-- wp:group --><div class="wp-block-group">'
        . '<svg viewBox="0 0 4 4"><path d="M0 0"/></svg>'
        . '<!-- wp:paragraph --><p>Inside.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->',
        'section-3'
    );
    assert_contains('<p>Inside.</p>', $out);
});

test('an unterminated declaration in the preamble does not hide the document', function () {
    $out = GeneratedMarkup::normalize(
        "Note <! see below\n\n"
        . '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
        'section-4'
    );
    assert_contains('<p>Body.</p>', $out);
    assert_true(!str_contains($out, 'Note '), 'preamble stripped');
});
