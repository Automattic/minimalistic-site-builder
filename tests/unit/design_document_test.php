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

/**
 * ROUND 2 — added by the architect after an independent review showed the
 * contract above was incomplete. The rules below are copied from the guards
 * this class is meant to consolidate, not from recollection:
 *   SpliceHomeDesignStep.php:286-306 — exactly one <main>, exactly one
 *     top-level footer (parent === null), and NO footer under a footer or
 *     address ancestor.
 *   InnerPagesDesignStep.php:1343-1356 — same, plus mainCount > 1 rejects.
 * The first round tested only footer-in-main, which is one third of the rule,
 * so the guard regressed while the tests stayed green.
 */

test('footer() ignores a footer nested in an address element', function () {
    // aae0f1c rejected footer-in-address explicitly. Round 1 missed it.
    $doc = DesignDocument::parse(dd_page(
        '<main><section>s</section></main>'
        . '<address><footer>attribution</footer></address><footer>REAL</footer>'
    ));
    $footer = $doc->footer();
    assert_true($footer !== null, 'the real footer must be found');
    assert_eq('REAL', trim($footer->textContent), 'a footer under <address> is not the site footer');
});

test('footer() ignores a footer nested in another footer', function () {
    $doc = DesignDocument::parse(dd_page(
        '<main><section>s</section></main>'
        . '<footer>outer<footer>inner attribution</footer></footer>'
    ));
    $footer = $doc->footer();
    assert_true($footer !== null, 'the outer footer is the site footer');
    assert_contains('outer', $footer->textContent);
    assert_true(
        strtolower($footer->parentNode->nodeName) === 'body',
        'the returned footer must be a direct child of body'
    );
});

test('header() ignores a header nested in a non-main wrapper', function () {
    // Rejecting only descendants of <main> is not "top level".
    $doc = DesignDocument::parse(dd_page(
        '<div class="wrap"><section><header>section head</header></section></div>'
        . '<header class="site">REAL</header><main><section>s</section></main>'
    ));
    $header = $doc->header();
    assert_true($header !== null, 'the site header must be found');
    assert_eq('REAL', trim($header->textContent), 'a header inside a wrapper is not the site header');
});

test('main() fails closed when the document has more than one main', function () {
    // Both prior guards reject this outright. Returning the first silently
    // drops the second page body.
    $errors = [];
    $doc = DesignDocument::parse(dd_page(
        '<main><section>A</section></main><main><section>B</section></main>'
    ), $errors);
    assert_true($doc !== null, 'the document still parses');
    assert_eq(null, $doc->main(), 'more than one main must not resolve to the first');
    assert_true($errors !== [], 'more than one main must be reported as structural');
});

test('main() does not return a main nested inside a wrapper', function () {
    $doc = DesignDocument::parse(dd_page('<div class="wrap"><main><section>s</section></main></div>'));
    assert_eq(null, $doc->main(), 'a main that is not a page root is not the page main');
});

test('a truncated document is reported as structurally damaged', function () {
    // max_tokens truncation is this repo's most-hit generation failure. libxml
    // auto-closes at EOF without raising anything, so a code-only check passes
    // a half-page as healthy.
    $errors = [];
    $truncated = "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
        . "<title>t</title>\n</head>\n<body>\n<main><section id=\"hero\"><h1>Hi</h1><p>lorem ips";
    DesignDocument::parse($truncated, $errors);
    assert_true($errors !== [], 'a document that ends mid-content must be reported');
});

test('sanitizedHtml strips a PHP processing instruction in the body and warns', function () {
    // Design markup reaches theme patterns/*.php. A <?php run in the body is
    // executable there, and document-level PI stripping never sees it.
    $warnings = [];
    $doc = DesignDocument::parse(dd_page(
        '<main><section id="x"><?php echo $evil; ?><p>a</p></section></main>'
    ));
    $out = $doc->sanitizedHtml($doc->main()->firstElementChild, 'design/home.html', 'section x', $warnings);
    assert_true(!str_contains($out, '<?php'), $out);
    assert_true(!str_contains($out, '<?'), $out);
    assert_true($warnings !== [], 'stripping executable markup must warn');
});

test('parse populates structuralErrors even when it returns null', function () {
    // A caller logging why it degraded must not get null plus an empty list.
    $errors = [];
    $doc = DesignDocument::parse('', $errors);
    assert_eq(null, $doc);
    assert_true($errors !== [], 'a refused document must say why');
});

/**
 * ROUND 3 — the architect's C4 spec said the <main> must be a direct child of
 * <body>. That rule came from SpliceHomeDesignStep, which only ever parses
 * design/home.html, a full document. It is wrong for inner pages, and applying
 * it broke 163 of the 255 real design artifacts. The correct rule is below.
 */

test('main() resolves in a bare inner-page fragment', function () {
    // 163 of 255 real design pages are fragments with no doctype:
    //   <style data-page-css>...</style><main>...</main>
    // Opening with <style> keeps libxml in head mode, so the <main> parses
    // under <head>. Requiring <body> parentage rejects every one of them.
    $fragment = '<style data-page-css>.a{color:red}</style>'
        . '<main><section id="s"><h2>x</h2></section></main>';
    $errors = [];
    $doc = DesignDocument::parse($fragment, $errors);
    assert_true($doc !== null, 'a bare fragment must parse');
    assert_true(
        $doc->main() !== null,
        'main must resolve wherever libxml places it in a fragment, not only under body'
    );
    assert_eq('section', strtolower($doc->main()->firstElementChild->nodeName));
});

test('styles() still reads the page CSS from a bare fragment', function () {
    $doc = DesignDocument::parse(
        '<style data-page-css>.b{color:blue}</style><main><section>s</section></main>'
    );
    assert_contains('.b{color:blue}', $doc->styles());
});

/**
 * ROUND 4 — the content-ancestor DENYLIST from round 3 was leaky by
 * construction: anything not on a 12-name list silently counted as page level.
 * Verified leaks included <figure>, <blockquote>, <th> (while <td> was on the
 * list), and <dd> (while <li> was). The rule is now an ALLOWLIST: a page-level
 * landmark may have only html/head/body ancestors. Validated over the 255 real
 * design pages at 255 accepted / 0 rejected before being frozen here.
 */

test('main() rejects a main wrapped in figure, not just the listed tags', function () {
    $doc = DesignDocument::parse(dd_page('<figure><main><section>s</section></main></figure>'));
    assert_eq(null, $doc->main(), 'an allowlist must reject every wrapper, not 12 named ones');
});

test('footer() ignores a blockquote attribution footer', function () {
    // <blockquote><p>q</p><footer>- Jane</footer></blockquote> is the canonical
    // MDN attribution pattern and is the aae0f1c / 7a1db0e regression class.
    $doc = DesignDocument::parse(dd_page(
        '<main><section>s</section></main>'
        . '<blockquote><p>q</p><footer id="attrib">- Jane</footer></blockquote>'
        . '<footer id="site">f</footer>'
    ));
    $footer = $doc->footer();
    assert_true($footer !== null, 'the site footer must be found');
    assert_eq('site', $footer->getAttribute('id'), 'a blockquote attribution footer is not the site footer');
});

test('header() ignores a header inside a table cell', function () {
    // <td> was on the denylist and <th> was not, so one character of difference
    // in the source produced opposite results.
    $doc = DesignDocument::parse(dd_page(
        '<table><tr><th><header id="cell">c</header></th></tr></table>'
        . '<header id="site">s</header><main><section>s</section></main>'
    ));
    $header = $doc->header();
    assert_true($header !== null, 'the site header must be found');
    assert_eq('site', $header->getAttribute('id'), 'a header in a table cell is not the site header');
});

test('footer() fails closed when there is more than one page-level footer', function () {
    // SpliceHomeDesignStep.php:288-297 rejects when count($topLevelFooters) !== 1.
    // Returning the first is how the two graphs drift apart.
    $errors = [];
    $doc = DesignDocument::parse(dd_page(
        '<main><section>s</section></main><footer id="one">1</footer><footer id="two">2</footer>'
    ), $errors);
    assert_eq(null, $doc->footer(), 'ambiguous page-level footers must not resolve to the first');
    assert_true($errors !== [], 'more than one page-level footer must be reported as structural');
});
