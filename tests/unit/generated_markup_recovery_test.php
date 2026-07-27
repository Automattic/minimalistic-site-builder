<?php
declare(strict_types=1);

use Automattic\SiteBuild\Units\GeneratedMarkup;

// Structural wrapper recovery in GeneratedMarkup::normalize(): the model
// sometimes wraps a part's block markup in reasoning prose, code fences,
// quoted delimiters or trailing comments. Recovery must extract exactly one
// balanced block document — never guess between two, never promote a nested
// child of a truncated wrapper to document root.

const GM_ROOT = '<!-- wp:group {"tagName":"section","anchor":"hero","layout":{"type":"constrained"}} -->'
    . '<section class="wp-block-group" id="hero">'
    . '<!-- wp:heading --><h2 class="wp-block-heading">Hello</h2><!-- /wp:heading -->'
    . '</section>'
    . '<!-- /wp:group -->';

test('recovery strips leading reasoning prose', function () {
    $text = "Looking at the notes, this is the contrast band. Let me build it.\n\n" . GM_ROOT;
    assert_eq(GM_ROOT, GeneratedMarkup::normalize($text, 'p'));
});

test('recovery strips a trailing code fence and prose after the document', function () {
    $text = GM_ROOT . "\n```\nDone — the section uses the asymmetric split.";
    assert_eq(GM_ROOT, GeneratedMarkup::normalize($text, 'p'));
});

test('recovery survives a preamble ahead of an opening fence', function () {
    $text = "Here is the section:\n\n```html\n" . GM_ROOT . "\n```";
    assert_eq(GM_ROOT, GeneratedMarkup::normalize($text, 'p'));
});

test('recovery ignores a delimiter quoted in the leading prose', function () {
    // The quoted opener is lexically valid but builds no container — it must
    // not become the document root (which would nest the real section and
    // retain the narrative text).
    $text = "I'll use `<!-- wp:group -->` as the root.\n\n" . GM_ROOT;
    $out = GeneratedMarkup::normalize($text, 'p');
    assert_eq(GM_ROOT, $out);
    assert_true(!str_contains($out, 'as the root'), 'must drop the narrative');
});

test('recovery drops an ordinary trailing HTML comment outside the document', function () {
    // `<!-- End generated section -->` is not a block delimiter; it must fall
    // outside the recovered span instead of being kept by a last-"-->" scan.
    $text = GM_ROOT . "\n<!-- End generated section -->\nDone.";
    assert_eq(GM_ROOT, GeneratedMarkup::normalize($text, 'p'));
});

test('recovery keeps nested same-name groups intact under the outer root', function () {
    $nested = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->'
        . '</div>'
        . '<!-- /wp:group -->';
    assert_eq($nested, GeneratedMarkup::normalize("Intro.\n" . $nested, 'p'));
});

test('recovery handles a self-closing root and a non-group root generically', function () {
    $void = '<!-- wp:pattern {"slug":"demo/x"} /-->';
    assert_eq($void, GeneratedMarkup::normalize("Use this pattern:\n" . $void . "\nDone.", 'p'));

    $heading = '<!-- wp:heading --><h2 class="wp-block-heading">Solo</h2><!-- /wp:heading -->';
    assert_eq($heading, GeneratedMarkup::normalize("A heading:\n" . $heading, 'p'));
});

test('recovery fails loud on two plausible documents instead of guessing', function () {
    $docA = '<!-- wp:paragraph --><p>first version</p><!-- /wp:paragraph -->';
    $text = $docA . "\nOr, alternatively:\n" . GM_ROOT;
    assert_throws(fn () => GeneratedMarkup::normalize($text, 'p'));
});

test('recovery preserves truncated-wrapper salvage without promoting the child', function () {
    // The outer group opened a real container (<section>) and was cut off:
    // salvage must close it — the complete heading child must NOT become the
    // document root.
    $truncated = "Building the hero.\n\n"
        . '<!-- wp:group {"tagName":"section","layout":{"type":"constrained"}} -->'
        . '<section class="wp-block-group">'
        . '<!-- wp:heading --><h2 class="wp-block-heading">Kept</h2><!-- /wp:heading -->';
    $out = GeneratedMarkup::normalize($truncated, 'p');
    assert_true(str_starts_with($out, '<!-- wp:group'), 'outer group stays the root');
    assert_contains('</section>', $out);
    assert_contains('<!-- /wp:group -->', $out);
});

test('recovery anchors salvage past a quoted opener when the payload is truncated', function () {
    // Both failure modes at once: a quoted delimiter in the prose AND a
    // truncated real root. The quoted opener cannot anchor the document; the
    // real root is salvaged.
    $text = "I'll use `<!-- wp:group -->` as the root.\n\n"
        . '<!-- wp:group {"tagName":"section","layout":{"type":"constrained"}} -->'
        . '<section class="wp-block-group">'
        . '<!-- wp:heading --><h2 class="wp-block-heading">Kept</h2><!-- /wp:heading -->';
    $out = GeneratedMarkup::normalize($text, 'p');
    assert_true(str_starts_with($out, '<!-- wp:group {"tagName":"section"'), 'real root anchors the salvage');
    assert_true(!str_contains($out, 'as the root'), 'quoted opener and prose dropped');
    assert_contains('</section>', $out);
});

test('normalize still rejects text with no block markup at all', function () {
    assert_throws(fn () => GeneratedMarkup::normalize('Just prose, no blocks here.', 'p'));
});
