<?php
declare(strict_types=1);

use Automattic\SiteBuild\DesignDocument;

/**
 * DesignDocument: the shared load/locate/sanitize boundary for untrusted design
 * HTML, used by island-pages and transform-chrome so neither re-implements it.
 *
 * These tests are the ACCEPTANCE CONTRACT for the slice. They were written by
 * the architect before the implementation exists and are frozen: the builder
 * makes them pass, and does not edit them.
 *
 * Every structural case below is drawn from a measurement of the 255 real
 * design pages under projects/*\/design/, not invented:
 *   - 1314 <section> direct children of <main>, but 70 NON-section children
 *   - some pages wrap every section in a single <div class="wrap"|"pg-wrap">
 *   - 4 nested <section> elements
 *   - 0 files with no <main>, 0 with significant text nodes directly in <main>
 *   - 0 files with a tag mismatch, but ALL 255 emit benign libxml notices
 * That last pair is the trap this class exists to survive: a naive
 * libxml_get_errors() check reports all 255 real pages as broken.
 */

function dd_page(string $body, string $style = 'body{margin:0}'): string
{
    return "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
        . "<title>t</title>\n<style>{$style}</style>\n</head>\n<body>\n{$body}\n</body>\n</html>\n";
}

test('parse returns a document for a well-formed design page', function () {
    $doc = DesignDocument::parse(dd_page('<main><section id="hero"><h1>Hi</h1></section></main>'));
    assert_true($doc !== null, 'well-formed page must parse');
});

test('benign HTML5 and processing-instruction notices are NOT structural errors', function () {
    // Every one of the 255 real design pages emits libxml notices for HTML5
    // elements libxml does not know. Reporting those as structural would
    // degrade every page in the corpus.
    $errors = [];
    $doc = DesignDocument::parse(dd_page(
        '<main><section><article><figure><figcaption>c</figcaption></figure>'
        . '<aside>a</aside><nav>n</nav></article></section></main>'
    ), $errors);
    assert_true($doc !== null, 'HTML5 landmarks must parse');
    assert_eq([], $errors, 'benign notices must not be reported as structural');
});

test('a real tag mismatch IS reported as a structural error', function () {
    $errors = [];
    DesignDocument::parse(dd_page('<main><section><div><p>unclosed</section></main>'), $errors);
    assert_true($errors !== [], 'a genuine mismatch must be reported');
});

test('main() finds the main element', function () {
    $doc = DesignDocument::parse(dd_page('<main><section id="a">x</section></main>'));
    $main = $doc->main();
    assert_true($main !== null, 'main must be found');
    assert_eq('main', strtolower($main->nodeName));
});

test('main() returns null without crashing when the document has none', function () {
    $doc = DesignDocument::parse(dd_page('<div><section>x</section></div>'));
    assert_true($doc !== null, 'a page with no main still parses');
    assert_eq(null, $doc->main(), 'absent main is null, not an exception');
});

test('header() and footer() find the top-level chrome landmarks', function () {
    $doc = DesignDocument::parse(dd_page(
        '<header><nav>n</nav></header><main><section>s</section></main><footer>f</footer>'
    ));
    assert_true($doc->header() !== null, 'header must be found');
    assert_true($doc->footer() !== null, 'footer must be found');
    assert_eq('nav', strtolower($doc->header()->firstElementChild->nodeName));
});

test('footer() ignores a footer nested inside main', function () {
    // Two shipped bugs came from counting nested attribution footers as the
    // site footer (aae0f1c, 7a1db0e). Only a top-level landmark counts.
    $doc = DesignDocument::parse(dd_page(
        '<main><section><footer>attribution</footer></section></main><footer>real</footer>'
    ));
    $footer = $doc->footer();
    assert_true($footer !== null, 'the real footer must still be found');
    assert_eq('real', trim($footer->textContent), 'must not return the nested attribution footer');
});

test('footer() is null when the only footer is nested inside main', function () {
    $doc = DesignDocument::parse(dd_page(
        '<main><section><footer>attribution</footer></section></main>'
    ));
    assert_eq(null, $doc->footer(), 'a nested-only footer is not the site footer');
});

test('html() round-trips an element to markup', function () {
    $doc = DesignDocument::parse(dd_page('<main><section id="hero"><h1>Hi</h1></section></main>'));
    $out = $doc->html($doc->main()->firstElementChild);
    assert_contains('<section', $out);
    assert_contains('id="hero"', $out);
    assert_contains('<h1>Hi</h1>', $out);
});

test('sanitizedHtml strips script-capable markup and records a warning', function () {
    $warnings = [];
    $doc = DesignDocument::parse(dd_page(
        '<main><section id="x"><p onclick="evil()">t</p><script>bad()</script></section></main>'
    ));
    $out = $doc->sanitizedHtml($doc->main()->firstElementChild, 'design/home.html', 'section x', $warnings);
    assert_true(!str_contains($out, '<script'), $out);
    assert_true(!str_contains(strtolower($out), 'onclick'), $out);
    assert_true($warnings !== [], 'a strip that changed delivered output must warn');
});

test('sanitizedHtml leaves clean markup byte-identical and warns nothing', function () {
    $warnings = [];
    $doc = DesignDocument::parse(dd_page('<main><section id="x"><p>plain</p></section></main>'));
    $section = $doc->main()->firstElementChild;
    $before = $doc->html($section);
    $after = $doc->sanitizedHtml($section, 'design/home.html', 'section x', $warnings);
    assert_eq($before, $after, 'clean markup must not be rewritten');
    assert_eq([], $warnings, 'an untouched fragment must not warn');
});

test('styles() returns the inline style block contents', function () {
    $doc = DesignDocument::parse(dd_page('<main><section>s</section></main>', '.a{color:red}'));
    assert_contains('.a{color:red}', $doc->styles());
});

test('styles() returns an empty string when the page has no style block', function () {
    $html = "<!doctype html>\n<html><head><title>t</title></head><body>"
        . "<main><section>s</section></main></body></html>";
    $doc = DesignDocument::parse($html);
    assert_eq('', $doc->styles(), 'no style block means empty, not null or a crash');
});

test('parse survives an empty document without throwing', function () {
    $errors = [];
    $doc = DesignDocument::parse('', $errors);
    assert_eq(null, $doc, 'an empty document yields null, not an exception');
});

test('a wrapper-only main is still located, so the splitter can descend', function () {
    // projects/*/design/contact.html: <main> holds ONE <div class="pg-wrap">
    // wrapping every section. main() must return the <main>; deciding how to
    // descend belongs to island-pages, not here.
    $doc = DesignDocument::parse(dd_page(
        '<main><div class="pg-wrap"><section>a</section><section>b</section></div></main>'
    ));
    $main = $doc->main();
    assert_true($main !== null, 'wrapper-only main must still be found');
    assert_eq('div', strtolower($main->firstElementChild->nodeName));
});
