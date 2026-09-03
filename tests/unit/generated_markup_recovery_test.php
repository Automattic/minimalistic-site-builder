<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockMarkup;
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

test('recovery ignores a complete self-closing delimiter quoted inline', function () {
    $text = "I may use `<!-- wp:spacer /-->` later.\n\n" . GM_ROOT;
    assert_eq(GM_ROOT, GeneratedMarkup::normalize($text, 'p'));
});

test('recovery rejects a complete inline example envelope', function () {
    $inner = '<!-- wp:group --><div class="wp-block-group">Example</div><!-- /wp:group -->';
    $text = "An inline example starts `<!-- wp:group -->\n"
        . $inner
        . "\n<!-- /wp:group -->` here.";

    assert_throws(
        fn () => GeneratedMarkup::normalize($text, 'p'),
        'a nested child must not be promoted out of a complete quoted example',
    );
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

test('recovery keeps adjacent top-level blocks as one document', function () {
    $heading = '<!-- wp:heading --><h2>Welcome</h2><!-- /wp:heading -->';
    $spacer = '<!-- wp:spacer {"height":"40px"} /-->';
    $document = $heading . "\n" . $spacer;

    assert_eq($document, GeneratedMarkup::normalize("Before\n{$document}\nAfter", 'p'));
});

test('recovery fails loud on two plausible documents instead of guessing', function () {
    $docA = '<!-- wp:paragraph --><p>first version</p><!-- /wp:paragraph -->';
    $text = $docA . "\nOr, alternatively:\n" . GM_ROOT;
    assert_throws(fn () => GeneratedMarkup::normalize($text, 'p'));
});

test('recovery treats a later inline block after prose as ambiguous', function () {
    $example = '<!-- wp:paragraph --><p>Example</p><!-- /wp:paragraph -->';
    $text = "Example:\n{$example}\nActual: " . GM_ROOT;

    assert_throws(
        fn () => GeneratedMarkup::normalize($text, 'p'),
        'the later block must not be silently discarded because prose shares its line',
    );
});

test('recovery rejects an unmatched illustrative wrapper before the answer', function () {
    $text = "Example only:\n"
        . "<!-- wp:group -->\n"
        . "<div class=\"wp-block-group\">This is only an example.</div>\n\n"
        . "Actual answer:\n"
        . GM_ROOT;

    assert_throws(
        fn () => GeneratedMarkup::normalize($text, 'p'),
        'an incomplete example and a later answer are ambiguous',
    );
});

test('recovery rejects a plain inline unclosed wrapper instead of promoting its child', function () {
    $text = "Actual: <!-- wp:group -->\n"
        . '<!-- wp:paragraph --><p>Do not promote me</p><!-- /wp:paragraph -->';

    assert_throws(
        fn () => GeneratedMarkup::normalize($text, 'p'),
        'an inline intended wrapper is ambiguous unless it is explicitly quoted as code',
    );
});

test('recovery rejects a fenced incomplete wrapper before the answer', function () {
    $text = "Example only:\n```html\n"
        . "<!-- wp:group -->\n<div class=\"wp-block-group\">\n```\n\n"
        . "Actual answer:\n```html\n"
        . GM_ROOT
        . "\n```";

    assert_throws(
        fn () => GeneratedMarkup::normalize($text, 'p'),
        'fences and narrative cannot become saved HTML inside a repaired example',
    );
});

test('recovery rejects a malformed intended root instead of promoting its child', function () {
    $text = "<!-- wp:group {\"tagName\":\"section\"\n"
        . "<section>Lost\n"
        . GM_ROOT
        . "\nLost</section>";

    assert_throws(
        fn () => GeneratedMarkup::normalize($text, 'p'),
        'a healthy child cannot replace a malformed intended root',
    );
});

test('recovery rejects an inline malformed intended root instead of promoting its child', function () {
    $text = "Actual: <!-- wp:group {\"tagName\":\"section\"\n"
        . "<section>Lost\n"
        . GM_ROOT
        . "\nLost</section>";

    assert_throws(fn () => GeneratedMarkup::normalize($text, 'p'));
});

test('recovery rejects a malformed or incomplete candidate after an earlier document', function () {
    $example = '<!-- wp:paragraph --><p>Example</p><!-- /wp:paragraph -->';
    $malformedActual = "<!-- wp:group {\"tagName\":\"section\"\n<section>Actual cut";
    $incompleteActual = "<!-- wp:group -->\n<div>Actual cut";

    assert_throws(
        fn () => GeneratedMarkup::normalize(
            "Example:\n{$example}\nActual:\n{$malformedActual}",
            'p',
        ),
    );
    assert_throws(
        fn () => GeneratedMarkup::normalize(
            "Example:\n{$example}\nActual:\n{$incompleteActual}",
            'p',
        ),
    );
});

test('recovery rejects a malformed delimiter inside a closed root', function () {
    $text = "<!-- wp:group -->\n<div class=\"wp-block-group\">\n"
        . "<!-- wp:paragraph {\"broken\":1 -->\n"
        . "</div>\n<!-- /wp:group -->";

    assert_throws(fn () => GeneratedMarkup::normalize($text, 'p'));
});

test('recovery restores only omitted final root attribute closers', function () {
    // golden-beacon emitted two complete attribute objects whose sole defect
    // was one missing final `}`. The nested objects are already balanced, so
    // restoring the root closer has exactly one syntactic reading.
    $text = '<!-- wp:group {"anchor":"cards","layout":{"type":"constrained"}} -->'
        . '<div>'
        . '<!-- wp:paragraph {"backgroundColor":"secondary","style":{"spacing":{"padding":{"top":"var:preset|spacing|sm"}}} -->'
        . '<p>First</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph {"fontSize":"caption","style":{"typography":{"letterSpacing":"0.14em"}} -->'
        . '<p>Second</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    $warnings = [];
    $repairs = [];

    $out = GeneratedMarkup::normalize($text, 'p', $warnings, $repairs);

    assert_contains(
        '<!-- wp:paragraph {"backgroundColor":"secondary","style":{"spacing":{"padding":{"top":"var:preset|spacing|sm"}}}} -->',
        $out,
    );
    assert_contains(
        '<!-- wp:paragraph {"fontSize":"caption","style":{"typography":{"letterSpacing":"0.14em"}}} -->',
        $out,
    );
    assert_contains('<p>First</p>', $out, 'authored text survives');
    assert_contains('<p>Second</p>', $out, 'sibling authored text survives');
    assert_eq([], $warnings, 'lossless grammar repair is not a delivered-output warning');
    assert_eq(2, count($repairs), 'each repaired delimiter is reported');

    $secondWarnings = [];
    $secondRepairs = [];
    assert_eq($out, GeneratedMarkup::normalize($out, 'p', $secondWarnings, $secondRepairs));
    assert_eq([], $secondWarnings);
    assert_eq([], $secondRepairs, 'repair reaches a fixed point');
});

test('recovery removes only a redundant typo closer after the real closer', function () {
    $text = '<!-- wp:columns --><div>'
        . '<!-- wp:column --><div>'
        . '<!-- wp:paragraph --><p>Keep me</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:column -->'
        . "\n</!-- wp:column -->\n"
        . '</div><!-- /wp:columns -->';
    $warnings = [];
    $repairs = [];

    $out = GeneratedMarkup::normalize($text, 'p', $warnings, $repairs);

    assert_contains('<p>Keep me</p>', $out);
    assert_true(!str_contains($out, '</!--'), 'redundant typo closer removed');
    assert_eq([], $warnings, 'removing a duplicate typo token loses no authored content');
    assert_eq(1, count($repairs));

    $notRedundant = '<!-- wp:columns --><div>'
        . '<!-- wp:column --><div><p>Unclosed</p></div>'
        . '</!-- wp:column -->'
        . '</div><!-- /wp:columns -->';
    assert_throws(
        fn () => GeneratedMarkup::normalize($notRedundant, 'p'),
        'a typo closer without an immediately preceding real closer remains fail-closed',
    );
});

test('grammar repair leaves malformed examples in opaque HTML contexts untouched', function () {
    $hiddenMissingBrace = '<code>'
        . '<!-- wp:paragraph {"style":{"typography":{"letterSpacing":"0.1em"}} -->'
        . '</code>';
    $hiddenTypoCloser = '<pre><!-- /wp:column -->'
        . "\n</!-- wp:column --></pre>";
    $warnings = [];
    $repairs = [];

    $out = GeneratedMarkup::normalize(
        $hiddenMissingBrace . "\n" . $hiddenTypoCloser . "\n" . GM_ROOT,
        'p',
        $warnings,
        $repairs,
    );

    assert_eq(GM_ROOT, $out);
    assert_eq([], $repairs, 'literal examples are not activated or reported as repairs');
});

test('grammar repair notes commit only when the repaired document passes every gate', function () {
    $repairable = '<!-- wp:paragraph {"style":{"typography":{"letterSpacing":"0.1em"}} -->'
        . '<p>First document</p><!-- /wp:paragraph -->';
    $second = '<!-- wp:paragraph --><p>Second document</p><!-- /wp:paragraph -->';
    $warnings = [];
    $repairs = [];

    assert_throws(
        fn () => GeneratedMarkup::normalize(
            $repairable . "\nAlternative:\n" . $second,
            'p',
            $warnings,
            $repairs,
        ),
        'a locally repairable delimiter must not make an ambiguous response publishable',
    );
    assert_eq([], $repairs, 'failed documents do not claim a committed repair');
});

test('recovery rejects attributes without the delimiter whitespace WordPress requires', function () {
    $text = '<!-- wp:paragraph {"dropCap":true}--><p>x</p><!-- /wp:paragraph -->';
    assert_throws(fn () => GeneratedMarkup::normalize($text, 'p'));
});

test('recovery rejects a closer that also uses self-closing syntax', function () {
    $text = '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph /-->';
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

test('recovery rejects a crossed child even when the outer root later closes', function () {
    $text = "<!-- wp:group -->\n<div class=\"wp-block-group\">\n"
        . "<!-- wp:heading --><h2>Do not promote me</h2><!-- /wp:heading -->\n"
        . "<!-- wp:columns --><div class=\"wp-block-columns\">\n"
        . "<!-- wp:paragraph --><p>cut off\n"
        . "<!-- /wp:columns -->\n"
        . "</div><!-- /wp:group -->";

    assert_throws(fn () => GeneratedMarkup::normalize($text, 'p'));
});

test('recovery sanitizes script bodies before looking for block candidates', function () {
    $fake = '<!-- wp:paragraph --><p>Fake</p><!-- /wp:paragraph -->';
    $doubleEscaped = "<script><!--<script></script>{$fake}</script>";

    assert_throws(fn () => GeneratedMarkup::normalize("<script>{$fake}</script>", 'p'));
    assert_throws(fn () => GeneratedMarkup::normalize("<script>{$fake}", 'p'));
    assert_throws(fn () => GeneratedMarkup::normalize($doubleEscaped, 'p'));
    assert_eq(
        GM_ROOT,
        GeneratedMarkup::normalize("<script>{$fake}</script>\n" . GM_ROOT, 'p'),
    );
    assert_eq(
        GM_ROOT,
        GeneratedMarkup::normalize($doubleEscaped . "\n" . GM_ROOT, 'p'),
    );
});

test('recovery ignores block-looking text in opaque HTML contexts', function () {
    $fake = '<!-- wp:paragraph --><p>Fake</p><!-- /wp:paragraph -->';
    foreach ([
        'style', 'textarea', 'title', 'xmp', 'code', 'pre', 'template',
        'iframe', 'object', 'applet', 'noembed', 'noframes', 'noscript',
    ] as $tag) {
        assert_throws(
            fn () => GeneratedMarkup::normalize("<{$tag}>{$fake}</{$tag}>", 'p'),
            "{$tag} content is not a block-document candidate",
        );
        assert_eq(
            GM_ROOT,
            GeneratedMarkup::normalize("<{$tag}>{$fake}</{$tag}>\n" . GM_ROOT, 'p'),
            "{$tag} example is ignored before the real document",
        );
    }
});

test('recovery ignores nested opaque examples through their outer closer', function () {
    $fake = '<!-- wp:paragraph --><p>Fake</p><!-- /wp:paragraph -->';
    foreach (['template', 'code', 'pre', 'object', 'applet'] as $tag) {
        $wrapper = "<{$tag}><{$tag}>inner</{$tag}>\n{$fake}\n</{$tag}>";
        assert_throws(fn () => GeneratedMarkup::normalize($wrapper, 'p'));
        assert_eq(GM_ROOT, GeneratedMarkup::normalize($wrapper . "\n" . GM_ROOT, 'p'));
    }
});

test('recovery ignores block-looking text inside comments declarations and attributes', function () {
    $fake = '<!-- wp:paragraph --><p>Fake</p><!-- /wp:paragraph -->';
    foreach ([
        "<!-- example {$fake} -->",
        "<![CDATA[{$fake}]]>",
        "<div data-example=\"{$fake}\"></div>",
    ] as $wrapper) {
        assert_throws(fn () => GeneratedMarkup::normalize($wrapper, 'p'));
        assert_eq(GM_ROOT, GeneratedMarkup::normalize($wrapper . "\n" . GM_ROOT, 'p'));
    }
});

test('recovery rejects hidden block delimiters retained inside a real root', function () {
    $fake = '<!-- wp:paragraph --><p>Fake</p><!-- /wp:paragraph -->';
    $text = '<!-- wp:html --><pre>' . $fake . '</pre><!-- /wp:html -->';

    assert_throws(fn () => GeneratedMarkup::normalize($text, 'p'));
});

test('recovery rejects an unclosed raw-text or comment region before a child', function () {
    $style = "<!-- wp:group -->\n<div class=\"wp-block-group\">\n"
        . "<style>.x { color: red; }\n"
        . "<!-- wp:heading --><h2>Hidden</h2><!-- /wp:heading -->";
    $comment = "<!-- wp:group -->\n<div class=\"wp-block-group\">\n"
        . "<!-- unfinished comment\n"
        . "<!-- wp:heading --><h2>Hidden</h2><!-- /wp:heading -->";

    assert_throws(fn () => GeneratedMarkup::normalize($style, 'p'));
    assert_throws(fn () => GeneratedMarkup::normalize($comment, 'p'));
});

test('recovery keeps tag-shaped attribute text lexical during salvage', function () {
    foreach (['<style>', '<!--'] as $attributeText) {
        $markup = '<!-- wp:group --><div data-x="' . $attributeText . '">'
            . '<!-- wp:paragraph --><p>Kept</p><!-- /wp:paragraph -->';
        $out = GeneratedMarkup::normalize($markup, 'p');

        assert_contains('<p>Kept</p>', $out);
        assert_true(str_ends_with($out, "</div>\n<!-- /wp:group -->"));
    }
});

test('recovery does not promote a block inside a NUL-bearing unfinished tag', function () {
    $hidden = "<foo\0bar x=\">\n"
        . '<!-- wp:paragraph --><p>Hidden</p><!-- /wp:paragraph -->';
    assert_throws(fn () => GeneratedMarkup::normalize($hidden, 'p'));
});

test('recovery rejects narrative between children of an illustrative wrapper', function () {
    $example = '<!-- wp:paragraph --><p>Example</p><!-- /wp:paragraph -->';
    $truncated = "Example only:\n<!-- wp:group -->\n<div class=\"wp-block-group\">\n"
        . $example
        . "\n</div>\n\nActual answer:\n"
        . GM_ROOT;
    $complete = "<!-- wp:group --><div class=\"wp-block-group\">\n"
        . $example
        . "\nActual answer:\n"
        . GM_ROOT
        . "\n</div><!-- /wp:group -->";

    assert_throws(fn () => GeneratedMarkup::normalize($truncated, 'p'));
    assert_throws(fn () => GeneratedMarkup::normalize($complete, 'p'));
});

test('recovery requires one strict HTML shell around structural-block children', function () {
    $a = '<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->';
    $b = '<!-- wp:paragraph --><p>B</p><!-- /wp:paragraph -->';
    foreach ([
        "<div>{$a}</div><section>{$b}</section>",
        "<div><span>{$a}</div>{$b}</span>",
        "<div>{$a}{$b}</aside></div>",
        "<div>{$a}<span/>{$b}</div>",
        "<div>{$a}<textarea>Hope this helps</textarea>{$b}</div>",
        "<div>{$a}<xmp>Hope this helps</xmp>{$b}</div>",
        "<div>{$a}<span data-x=foo\">Narrative\"></span>{$b}</div>",
        "<div>{$a}<span data-x=\"<!--\">Narrative-->\"></span>{$b}</div>",
    ] as $inner) {
        assert_throws(
            fn () => GeneratedMarkup::normalize(
                '<!-- wp:group -->' . $inner . '<!-- /wp:group -->',
                'p',
            ),
        );
    }
});

test('recovery rejects narrative at either edge of a complete wrapper child zone', function () {
    $child = '<!-- wp:heading --><h2>Heading</h2><!-- /wp:heading -->';
    $prefix = "<!-- wp:group --><div class=\"wp-block-group\">\n"
        . "Here is the answer:\n{$child}\n"
        . '</div><!-- /wp:group -->';
    $suffix = "<!-- wp:group --><div class=\"wp-block-group\">\n"
        . "{$child}\nHope this helps.\n"
        . '</div><!-- /wp:group -->';

    assert_throws(fn () => GeneratedMarkup::normalize($prefix, 'p'));
    assert_throws(fn () => GeneratedMarkup::normalize($suffix, 'p'));
});

test('recovery accepts a clean whitespace-only dynamic parent', function () {
    $markup = "<!-- wp:custom/container -->\n"
        . '<!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->'
        . "\n<!-- /wp:custom/container -->";

    assert_eq($markup, GeneratedMarkup::normalize($markup, 'p'));
});

test('recovery preserves blocks that legitimately mix owned text with children', function () {
    $quote = '<!-- wp:quote --><blockquote class="wp-block-quote">'
        . '<!-- wp:paragraph --><p>Words.</p><!-- /wp:paragraph -->'
        . '<cite>Jane Doe</cite></blockquote><!-- /wp:quote -->';
    $details = '<!-- wp:details --><details class="wp-block-details">'
        . '<summary>Read more</summary>'
        . '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->'
        . '</details><!-- /wp:details -->';
    $listItem = '<!-- wp:list-item --><li>Parent'
        . '<!-- wp:list --><ul class="wp-block-list">'
        . '<!-- wp:list-item --><li>Child</li><!-- /wp:list-item -->'
        . '</ul><!-- /wp:list -->'
        . '</li><!-- /wp:list-item -->';
    $gallery = '<!-- wp:gallery --><figure class="wp-block-gallery has-nested-images">'
        . '<!-- wp:image --><figure class="wp-block-image"><img src="one.jpg" alt="One"/>'
        . '</figure><!-- /wp:image -->'
        . '<figcaption class="blocks-gallery-caption wp-element-caption">'
        . 'Gallery <em>caption</em></figcaption></figure><!-- /wp:gallery -->';
    // A same-site video path: a media url on a foreign host is a fetch the
    // intake sanitizer removes (BIGR-975), and this test is about structure.
    $embedCover = '<!-- wp:cover {"backgroundType":"embed-video","url":"/videos/abc"} -->'
        . '<div class="wp-block-cover"><figure class="wp-block-cover__embed-background">'
        . '<div class="wp-block-embed__wrapper">https://youtu.be/abc</div></figure>'
        . '<div class="wp-block-cover__inner-container">'
        . '<!-- wp:paragraph --><p>Inner</p><!-- /wp:paragraph -->'
        . '</div></div><!-- /wp:cover -->';

    foreach ([$quote, $details, $listItem, $gallery, $embedCover] as $markup) {
        assert_eq($markup, GeneratedMarkup::normalize($markup, 'p'));
    }
});

test('recovery rejects narrative nested inside a child container', function () {
    $markup = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:group --><div class="wp-block-group">Narrative before child'
        . '<!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';

    assert_throws(fn () => GeneratedMarkup::normalize($markup, 'p'));
});

test('recovery retains an adjacent truncated top-level tail for salvage', function () {
    $kept = '<!-- wp:paragraph --><p>Kept</p><!-- /wp:paragraph -->';

    assert_eq(
        $kept,
        GeneratedMarkup::normalize($kept . "\n<!-- wp:para", 'p'),
        'a final partial delimiter is trimmed by salvage',
    );
    assert_eq(
        $kept,
        GeneratedMarkup::normalize(
            $kept . "\n<!-- wp:paragraph --><p>cut text",
            'p',
        ),
        'a final childless open block is dropped by salvage',
    );
});

test('recovery does not treat a prose-separated truncated tail as adjacent', function () {
    $kept = '<!-- wp:paragraph --><p>Kept</p><!-- /wp:paragraph -->';
    assert_throws(
        fn () => GeneratedMarkup::normalize(
            $kept . "\nAlternative:\n<!-- wp:paragraph --><p>cut text",
            'p',
        ),
    );
});

test('recovery never trims a partial closing delimiter as a truncated next block', function () {
    $kept = '<!-- wp:paragraph --><p>Kept</p><!-- /wp:paragraph -->';
    foreach (['<!-- /wp:group', '<!-- /wp:paragraph --'] as $tail) {
        assert_throws(fn () => GeneratedMarkup::normalize($kept . "\n" . $tail, 'p'));
    }
});

test('recovery accepts marker-shaped text inside a valid delimiter attribute', function () {
    $markup = '<!-- wp:paragraph {"metadata":{"name":"Use <!-- wp:group"}} -->'
        . '<p>Text</p><!-- /wp:paragraph -->';

    assert_eq($markup, GeneratedMarkup::normalize($markup, 'p'));
});

test('recovery accepts JSON.parse-compatible lone surrogate attributes', function () {
    $markup = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:paragraph {"metadata":{"name":"\\ud800"}} -->'
        . '<p>Text</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';

    assert_eq($markup, GeneratedMarkup::normalize($markup, 'p'));
});

test('recovery rejects non-HTML whitespace between adjacent roots', function () {
    $a = '<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->';
    $b = '<!-- wp:paragraph --><p>B</p><!-- /wp:paragraph -->';

    assert_throws(fn () => GeneratedMarkup::normalize($a . "\0" . $b, 'p'));
    assert_throws(fn () => GeneratedMarkup::normalize($a . "\x0B" . $b, 'p'));
    assert_eq($a . "\f" . $b, GeneratedMarkup::normalize($a . "\f" . $b, 'p'));
});

test('recovery accepts CR-only wrappers and a UTF-8 BOM', function () {
    assert_eq(GM_ROOT, GeneratedMarkup::normalize("Here:\r\r" . GM_ROOT . "\rDone.", 'p'));
    assert_eq(GM_ROOT, GeneratedMarkup::normalize("\xEF\xBB\xBF" . GM_ROOT, 'p'));
});

test('normalize still rejects text with no block markup at all', function () {
    assert_throws(fn () => GeneratedMarkup::normalize('Just prose, no blocks here.', 'p'));
});

test('recovery tolerates invisible characters between sibling blocks', function () {
    // NBSP / zero-width space are not HTML inter-element whitespace, but as
    // the gap between two children they are not prose either.
    foreach (["\u{00A0}", "\u{200B}", "\u{FEFF}"] as $gap) {
        $markup = '<!-- wp:group --><div class="wp-block-group">'
            . '<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->' . $gap
            . '<!-- wp:paragraph --><p>b</p><!-- /wp:paragraph -->'
            . '</div><!-- /wp:group -->';
        assert_eq($markup, GeneratedMarkup::normalize($markup, 'p'));
    }
});

test('recovery ignores a backticked opener quoted after the document', function () {
    $text = GM_ROOT . "\n\nI used `<!-- wp:group -->` as the root.";
    assert_eq(GM_ROOT, GeneratedMarkup::normalize($text, 'p'));
});

test('recovery reports a complete block it drops before the document', function () {
    $lead = '<!-- wp:paragraph --><p>SECTION A</p><!-- /wp:paragraph -->';
    $out = quietly(fn () => GeneratedMarkup::normalize("Here: {$lead}\n" . GM_ROOT, 'p'));

    assert_eq(GM_ROOT, $out, 'still recovers the line-standing document');
    // The drop is by design; going silent about it is what loses a section.
    $notes = [];
    \Automattic\SiteBuild\BlockDocumentRecovery::recover("Here: {$lead}\n" . GM_ROOT, $notes);
    assert_eq(1, count($notes), 'one note for the dropped block');
    assert_contains('SECTION A', $notes[0]);
});

test('recovery stays linear on a run of unclosed opaque elements', function () {
    // A model stuck in a repetition loop until the output ceiling used to
    // cost O(n^2) here: 24 KB took over six seconds.
    $text = GM_ROOT . "\n" . str_repeat('<code>', 4000);
    $start = microtime(true);
    assert_eq(GM_ROOT, GeneratedMarkup::normalize($text, 'p'));
    assert_true(microtime(true) - $start < 1.0, 'scans 24 KB of unclosed <code> in under a second');
});

test('recovery closes a delimiter attrs object missing one closing brace', function () {
    // Regression: tbilisi2 lost its whole contact-hours section (and, in
    // cascade, the hero CTA) because one nested group's attrs JSON was one
    // `}` short. The repair closes the object; the misplaced key is inert.
    $brokenAttrs = '{"style":{"spacing":{"padding":{"top":"var:preset|spacing|lg"},'
        . '"elements":{"link":{"color":{"text":"var:preset|color|base"}}}},'
        . '"layout":{"type":"constrained"}';  // <- one closing brace short
    $inner = '<!-- wp:group ' . $brokenAttrs . ' -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:paragraph --><p>Reserve a table</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    $text = '<!-- wp:group {"tagName":"section","anchor":"contact","layout":{"type":"constrained"}} -->'
        . '<section class="wp-block-group" id="contact">' . $inner . '</section>'
        . '<!-- /wp:group -->';

    $warnings = [];
    $repairs = [];
    $out = GeneratedMarkup::normalize($text, 'p', $warnings, $repairs);
    assert_contains('Reserve a table', $out);
    assert_contains($brokenAttrs . '}', $out, 'attrs object is closed in place');
    $codes = array_column($repairs, 'code');
    assert_true(in_array('delimiter-attrs-braces-closed', $codes, true), 'repair row recorded');

    // Idempotent: the repaired document passes through unchanged.
    $w2 = [];
    $again = [];
    assert_eq($out, GeneratedMarkup::normalize($out, 'p', $w2, $again));
    assert_true(!in_array('delimiter-attrs-braces-closed', array_column($again, 'code'), true));
});

test('attrs brace repair leaves other malformed attrs alone', function () {
    $notes = [];
    $text = '<!-- wp:group {"style":"unterminated string} --><div></div><!-- /wp:group -->';
    assert_eq($text, GeneratedMarkup::closeUnbalancedDelimiterAttrs($text, $notes));
    assert_eq([], $notes);
});

test('dedupeHeadlineEcho removes a text block repeating the H1 verbatim', function () {
    // Regression: pulso3 executed a "misregistered echo" signature device as
    // a second paragraph carrying the exact headline text pulled over the H1
    // with a negative margin — duplicated reading copy rendering as garble.
    $doc = '<!-- wp:group {"tagName":"section","anchor":"hero","layout":{"type":"constrained"}} -->'
        . '<section class="wp-block-group" id="hero">'
        . '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Two nights the room drops into neon</h1><!-- /wp:heading -->'
        . '<!-- wp:paragraph {"textColor":"accent","style":{"spacing":{"margin":{"top":"-1.6rem"}}}} -->'
        . '<p class="has-accent-color has-text-color" style="margin-top:-1.6rem">Two nights the room drops into neon</p>'
        . '<!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>DJ sets until dawn.</p><!-- /wp:paragraph -->'
        . '</section>'
        . '<!-- /wp:group -->';
    $repairs = [];
    $out = Automattic\SiteBuild\Units\GeneratedMarkup::dedupeHeadlineEcho($doc, 'p', $repairs);
    assert_eq(1, substr_count($out, 'Two nights the room drops into neon'), 'echo removed, H1 kept');
    assert_contains('DJ sets until dawn.', $out);
    assert_true(in_array('headline-echo-removed', array_column($repairs, 'code'), true));

    // Idempotent + leaves distinct copy alone.
    $again = [];
    assert_eq($out, Automattic\SiteBuild\Units\GeneratedMarkup::dedupeHeadlineEcho($out, 'p', $again));
    assert_eq([], $again);
});

test('dedupeHeadlineEcho never matches short repeated labels or parts without an H1', function () {
    $short = '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
        . '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Menu</h1><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Menu</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    $r = [];
    assert_eq($short, Automattic\SiteBuild\Units\GeneratedMarkup::dedupeHeadlineEcho($short, 'p', $r));
    $noH1 = '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
        . '<!-- wp:heading --><h2 class="wp-block-heading">A heading of some length</h2><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>A heading of some length</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    assert_eq($noH1, Automattic\SiteBuild\Units\GeneratedMarkup::dedupeHeadlineEcho($noH1, 'p', $r));
    assert_eq([], $r);
});

test('stripEyebrowChipChrome unboxes a filled/bordered eyebrow but not the copy container', function () {
    // Regression: pulso22 executed a "translucent veil" signature device as
    // two nested filled+bordered groups boxing the eyebrow line above the H1
    // — a caption chip that reads as UI, not typography.
    $doc = '<!-- wp:group {"tagName":"section","anchor":"hero","backgroundColor":"contrast","layout":{"type":"constrained"}} -->'
        . '<section class="wp-block-group has-contrast-background-color has-background" id="hero">'
        . '<!-- wp:group {"style":{"color":{"background":"#8ff3d626"},"border":{"radius":"2px","width":"1px","color":"#8ff3d63d"}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group has-border-color has-background" style="border-color:#8ff3d63d;border-width:1px;background-color:#8ff3d626">'
        . '<!-- wp:group {"backgroundColor":"secondary","style":{"border":{"width":"1px","color":"#c9a7ff33"}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group has-border-color has-secondary-background-color has-background" style="border-color:#c9a7ff33;border-width:1px">'
        . '<!-- wp:paragraph {"fontSize":"caption"} --><p class="has-caption-font-size">Two nights · Stockholm Art District</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group --></div><!-- /wp:group -->'
        . '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Neon Pulse Festival</h1><!-- /wp:heading -->'
        . '</section><!-- /wp:group -->';
    $repairs = [];
    $out = Automattic\SiteBuild\Units\GeneratedMarkup::stripEyebrowChipChrome($doc, 'p', $repairs);
    assert_true(!str_contains($out, '"border"'), 'chip borders stripped from attrs');
    assert_true(!str_contains($out, '"background":"#8ff3d626"'), 'chip custom background stripped from attrs');
    assert_true(!str_contains($out, '"backgroundColor":"secondary"'), 'chip preset background stripped');
    // The stale inline style survives until fix-blocks re-serializes (shared
    // convention), but the class hooks that keep the chrome visible are gone.
    assert_true(!str_contains($out, 'has-border-color'), 'border class hooks removed');
    assert_true(!str_contains($out, 'has-secondary-background-color'), 'preset background class hook removed');
    assert_contains('Two nights · Stockholm Art District', $out, 'eyebrow text untouched');
    assert_contains('"backgroundColor":"contrast"', $out, 'root surface (wraps the H1) untouched');
    assert_contains('has-contrast-background-color', $out, 'root surface classes untouched');
    assert_true(in_array('eyebrow-chip-chrome-stripped', array_column($repairs, 'code'), true));

    // Idempotent once unboxed.
    $again = [];
    assert_eq($out, Automattic\SiteBuild\Units\GeneratedMarkup::stripEyebrowChipChrome($out, 'p', $again));
    assert_eq([], $again);
});

test('stripEyebrowChipChrome ignores media/action wrappers, long text, and heroes without an H1', function () {
    // A pre-H1 group holding a button is an action cluster, not an eyebrow
    // chip; a bordered block after the H1 is composition, not an eyebrow.
    $doc = '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
        . '<!-- wp:group {"backgroundColor":"accent","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group has-accent-background-color has-background">'
        . '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button -->'
        . '<div class="wp-block-button"><a class="wp-block-button__link" href="/tickets/">Get Tickets</a></div>'
        . '<!-- /wp:button --></div><!-- /wp:buttons --></div><!-- /wp:group -->'
        . '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Neon Pulse Festival</h1><!-- /wp:heading -->'
        . '<!-- wp:group {"style":{"border":{"width":"1px","color":"#ffffff"}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group has-border-color" style="border-width:1px;border-color:#ffffff">'
        . '<!-- wp:paragraph --><p>Warehouse sets after dark.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';
    $r = [];
    assert_eq($doc, Automattic\SiteBuild\Units\GeneratedMarkup::stripEyebrowChipChrome($doc, 'p', $r));
    assert_eq([], $r);

    $noH1 = '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
        . '<!-- wp:paragraph {"backgroundColor":"accent"} --><p class="has-accent-background-color has-background">Label</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    assert_eq($noH1, Automattic\SiteBuild\Units\GeneratedMarkup::stripEyebrowChipChrome($noH1, 'p', $r));
    assert_eq([], $r);
});

test('stripHeroSeparators removes hairline rules from the hero (BIGR-775)', function () {
    // Regression: portfolio7, atlas7, and hearth7 sliced their copy stacks
    // with a wp:separator between the headline and the standfirst.
    $doc = '<!-- wp:group {"tagName":"section","anchor":"hero","layout":{"type":"constrained"}} -->'
        . '<section class="wp-block-group" id="hero">'
        . '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Bread Made Slowly</h1><!-- /wp:heading -->'
        . '<!-- wp:separator {"backgroundColor":"primary","className":"is-style-wide"} -->'
        . '<hr class="wp-block-separator has-primary-background-color has-background is-style-wide"/>'
        . '<!-- /wp:separator -->'
        . '<!-- wp:paragraph --><p>Naturally leavened loaves, baked fresh by hand.</p><!-- /wp:paragraph -->'
        . '</section><!-- /wp:group -->';
    $repairs = [];
    $warnings = [];
    $out = Automattic\SiteBuild\Units\GeneratedMarkup::stripHeroSeparators(
        $doc,
        'p',
        $repairs,
        $warnings,
    );
    assert_true(!str_contains($out, 'wp:separator'), 'separator delimiters removed');
    assert_true(!str_contains($out, 'wp-block-separator'), 'separator HTML removed');
    assert_contains('Bread Made Slowly', $out, 'headline untouched');
    assert_contains('Naturally leavened loaves', $out, 'standfirst untouched');
    assert_eq([], $repairs, 'visible separator removal is durable loss, not a semantics-preserving repair');
    assert_eq(1, count($warnings));
    foreach ([
        "file='theme/parts/p.html'",
        "block='wp:group[0] > wp:separator[0]'",
        'backgroundColor',
        'delivered=removed',
        'disposition=',
    ] as $context) {
        assert_contains($context, $warnings[0]);
    }

    // Idempotent.
    $again = [];
    $againWarnings = [];
    assert_eq(
        $out,
        Automattic\SiteBuild\Units\GeneratedMarkup::stripHeroSeparators(
            $out,
            'p',
            $again,
            $againWarnings,
        ),
    );
    assert_eq([], $again);
    assert_eq([], $againWarnings);
});

test('stripHeroSeparators removes one outer transaction when generated separators are nested', function () {
    $following = '<!-- wp:paragraph --><p>KEEP THIS SIBLING</p><!-- /wp:paragraph -->';
    $doc = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:separator {"className":"outer-rule"} --><div class="outer-rule">'
        . '<!-- wp:separator {"className":"inner-rule"} --><hr class="wp-block-separator inner-rule"/>'
        . '<!-- /wp:separator --></div><!-- /wp:separator -->'
        . $following
        . '</div><!-- /wp:group -->';
    $repairs = [];
    $warnings = [];

    $out = Automattic\SiteBuild\Units\GeneratedMarkup::stripHeroSeparators(
        $doc,
        'nested-separators',
        $repairs,
        $warnings,
    );

    assert_eq(
        '<!-- wp:group --><div class="wp-block-group">' . $following . '</div><!-- /wp:group -->',
        $out,
        'the outer separator range is removed without consuming the following sibling or root closer',
    );
    $parsed = BlockMarkup::parse($out);
    assert_true($parsed->endOffset((int) $parsed->topLevel()) !== null, 'the surviving root remains complete');
    assert_eq([], $repairs);
    assert_eq(1, count($warnings), 'the outer loss boundary covers its malformed nested separator');
    assert_contains('outer-rule', $warnings[0]);
    assert_contains('delivered=removed', $warnings[0]);
});

test('stripHeroSeparators retains a nested component that owns an image', function () {
    $image = '<!-- wp:image --><figure class="wp-block-image">'
        . '<img src="theme:./assets/keep-separator-image.jpg" alt="Keep separator image">'
        . '</figure><!-- /wp:image -->';
    $unsafeInner = '<!-- wp:separator {"className":"unsafe-inner"} --><div class="unsafe-inner">'
        . $image . '</div><!-- /wp:separator -->';
    $plainInner = '<!-- wp:separator {"className":"plain-inner"} -->'
        . '<hr class="wp-block-separator plain-inner"/><!-- /wp:separator -->';
    $outer = '<!-- wp:separator {"className":"outer-rule"} --><div class="outer-rule">'
        . $unsafeInner . $plainInner . '</div><!-- /wp:separator -->';
    $doc = '<!-- wp:group --><div class="wp-block-group">' . $outer
        . '<!-- wp:paragraph --><p>Following sibling stays.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    $repairs = [];
    $warnings = [];

    $out = GeneratedMarkup::stripHeroSeparators(
        $doc,
        'unsafe-nested-separator',
        $repairs,
        $warnings,
    );

    assert_eq($doc, $out, 'the safe-looking sibling is frozen with its unsafe nested component');
    assert_eq([], $repairs);
    assert_eq(1, count($warnings));
    foreach (['outer-rule', 'keep-separator-image.jpg', 'delivered=', 'retained byte-for-byte'] as $context) {
        assert_contains($context, $warnings[0]);
    }
    $againRepairs = [];
    $againWarnings = [];
    assert_eq(
        $out,
        GeneratedMarkup::stripHeroSeparators(
            $out,
            'unsafe-nested-separator',
            $againRepairs,
            $againWarnings,
        ),
    );
    assert_eq($warnings, $againWarnings, 'the retained warning is fixed-point stable');
});

test('stripHeroEyebrow removes tracked caption lines and minor headings above the H1 only (BIGR-775)', function () {
    // Regression: tbilisi7/naturaleza7/hearth7 opened on an uppercase tracked
    // caption line and lumen7 on a level-5 heading — three or four copy lines
    // stacked before the proposition.
    $doc = '<!-- wp:group {"tagName":"section","anchor":"hero","layout":{"type":"constrained"}} -->'
        . '<section class="wp-block-group" id="hero">'
        . '<!-- wp:paragraph {"fontSize":"caption","style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.16em"}}} -->'
        . '<p class="has-caption-font-size" style="letter-spacing:0.16em;text-transform:uppercase">Tbilisi Old Town</p>'
        . '<!-- /wp:paragraph -->'
        . '<!-- wp:heading {"level":5} --><h5 class="wp-block-heading">Recycled Glass Studio</h5><!-- /wp:heading -->'
        . '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Bread from the hearth</h1><!-- /wp:heading -->'
        . '<!-- wp:paragraph {"fontSize":"lead"} --><p class="has-lead-font-size">Home cooking of the Caucasus at long tavern tables.</p><!-- /wp:paragraph -->'
        . '</section><!-- /wp:group -->';
    $repairs = [];
    $warnings = [];
    $out = Automattic\SiteBuild\Units\GeneratedMarkup::stripHeroEyebrow($doc, 'p', $repairs, $warnings);
    assert_true(!str_contains($out, 'Tbilisi Old Town'), 'tracked caption eyebrow removed');
    assert_true(!str_contains($out, 'Recycled Glass Studio'), 'minor-heading eyebrow removed');
    assert_contains('Bread from the hearth', $out, 'headline untouched');
    assert_contains('Home cooking of the Caucasus', $out, 'standfirst untouched');
    assert_eq([], $repairs, 'removed unique copy is durable loss, not a repair');
    assert_eq(2, count($warnings));
    assert_contains('Tbilisi Old Town', implode("\n", $warnings));
    assert_contains('Recycled Glass Studio', implode("\n", $warnings));
    foreach ($warnings as $warning) {
        foreach (["file='theme/parts/p.html'", "block='wp:", 'authored=', 'delivered=removed', 'disposition='] as $context) {
            assert_contains($context, $warning);
        }
    }

    // Idempotent.
    $again = [];
    $againWarnings = [];
    assert_eq(
        $out,
        Automattic\SiteBuild\Units\GeneratedMarkup::stripHeroEyebrow(
            $out,
            'p',
            $again,
            $againWarnings,
        ),
    );
    assert_eq([], $again);
    assert_eq([], $againWarnings);

    // A plain pre-H1 paragraph without eyebrow signals is reading copy, and a
    // hero without an H1 is left alone entirely.
    $plain = '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
        . '<!-- wp:paragraph --><p>An unstyled introduction line.</p><!-- /wp:paragraph -->'
        . '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Headline</h1><!-- /wp:heading -->'
        . '</div><!-- /wp:group -->';
    $r = [];
    assert_eq($plain, Automattic\SiteBuild\Units\GeneratedMarkup::stripHeroEyebrow($plain, 'p', $r));
    assert_eq([], $r);

    $noH1 = '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
        . '<!-- wp:paragraph {"fontSize":"caption"} --><p class="has-caption-font-size">Label</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    assert_eq($noH1, Automattic\SiteBuild\Units\GeneratedMarkup::stripHeroEyebrow($noH1, 'p', $r));
    assert_eq([], $r);
});

test('stripHeroEyebrow coalesces nested candidates and removes only a dedicated empty wrapper', function () {
    $headline = '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Headline survives</h1><!-- /wp:heading -->';
    $standfirst = '<!-- wp:paragraph --><p>Sibling copy stays byte-for-byte.</p><!-- /wp:paragraph -->';
    $nested = '<!-- wp:paragraph {"fontSize":"caption"} --><div class="has-caption-font-size">'
        . '<!-- wp:paragraph {"fontSize":"caption"} --><p class="has-caption-font-size">Nested Label</p>'
        . '<!-- /wp:paragraph --></div><!-- /wp:paragraph -->';
    $wrapped = '<!-- wp:group {"backgroundColor":"accent","style":{"spacing":{"padding":{"top":"20px"}}}} -->'
        . '<div class="wp-block-group has-accent-background-color has-background">'
        . '<!-- wp:paragraph {"fontSize":"caption"} --><p class="has-caption-font-size">Wrapped Label</p>'
        . '<!-- /wp:paragraph --></div><!-- /wp:group -->';
    $doc = '<!-- wp:group --><div class="wp-block-group">'
        . $nested . $wrapped . $headline . $standfirst
        . '</div><!-- /wp:group -->';
    $repairs = [];
    $warnings = [];

    $out = Automattic\SiteBuild\Units\GeneratedMarkup::stripHeroEyebrow(
        $doc,
        'nested-eyebrows',
        $repairs,
        $warnings,
    );

    assert_eq(
        '<!-- wp:group --><div class="wp-block-group">' . $headline . $standfirst . '</div><!-- /wp:group -->',
        $out,
        'nested candidates and the eyebrow-only wrapper are removed without touching following siblings',
    );
    assert_eq([], $repairs);
    assert_eq(2, count($warnings));
    assert_contains('Nested Label', implode("\n", $warnings));
    assert_contains('Wrapped Label', implode("\n", $warnings));
    assert_contains('now-empty dedicated wrapper', implode("\n", $warnings));
    $parsed = BlockMarkup::parse($out);
    assert_true($parsed->endOffset((int) $parsed->topLevel()) !== null, 'the root delimiter remains complete');
});

test('stripHeroEyebrow retains a nested candidate component that owns a button', function () {
    $button = '<!-- wp:button --><div class="wp-block-button">'
        . '<a class="wp-block-button__link" href="/keep/">Keep nested action</a>'
        . '</div><!-- /wp:button -->';
    $unsafeInner = '<!-- wp:paragraph {"fontSize":"caption"} --><div class="has-caption-font-size">'
        . 'Unsafe inner label' . $button . '</div><!-- /wp:paragraph -->';
    $plainInner = '<!-- wp:paragraph {"fontSize":"caption"} -->'
        . '<p class="has-caption-font-size">Plain sibling label</p><!-- /wp:paragraph -->';
    $outer = '<!-- wp:paragraph {"fontSize":"caption"} --><div class="has-caption-font-size">'
        . 'Outer label' . $unsafeInner . $plainInner . '</div><!-- /wp:paragraph -->';
    $headline = '<!-- wp:heading {"level":1} --><h1>Headline stays</h1><!-- /wp:heading -->';
    $doc = '<!-- wp:group --><div class="wp-block-group">'
        . $outer . $headline . '</div><!-- /wp:group -->';
    $repairs = [];
    $warnings = [];

    $out = GeneratedMarkup::stripHeroEyebrow($doc, 'unsafe-nested-eyebrow', $repairs, $warnings);

    assert_eq($doc, $out, 'no nested candidate is edited inside the retained outer transaction');
    assert_eq([], $repairs);
    assert_eq(1, count($warnings));
    foreach (['Outer label', '/keep/', 'Keep nested action', 'retained byte-for-byte'] as $context) {
        assert_contains($context, $warnings[0]);
    }
    $againRepairs = [];
    $againWarnings = [];
    assert_eq(
        $out,
        GeneratedMarkup::stripHeroEyebrow(
            $out,
            'unsafe-nested-eyebrow',
            $againRepairs,
            $againWarnings,
        ),
    );
    assert_eq($warnings, $againWarnings, 'the retained warning is fixed-point stable');
});

test('stripHeroEyebrow treats malformed signal leaves as non-signals without PHP warnings', function () {
    $malformed = '<!-- wp:paragraph {"fontSize":["caption"],"style":{"typography":'
        . '{"textTransform":["uppercase"],"letterSpacing":{"value":"0.2em"}}}} -->'
        . '<p>Malformed signal leaves stay</p><!-- /wp:paragraph -->';
    $headline = '<!-- wp:heading {"level":1} --><h1>Real headline</h1><!-- /wp:heading -->';
    $doc = '<!-- wp:group --><div class="wp-block-group">'
        . $malformed . $headline . '</div><!-- /wp:group -->';
    $repairs = [];
    $warnings = [];

    set_error_handler(static function (int $severity, string $message): never {
        throw new ErrorException($message, 0, $severity);
    });
    try {
        $out = GeneratedMarkup::stripHeroEyebrow($doc, 'malformed-eyebrow-signals', $repairs, $warnings);
    } finally {
        restore_error_handler();
    }

    assert_eq($doc, $out);
    assert_eq([], $repairs);
    assert_eq([], $warnings);
});

test('hero copy-order repairs do not coerce malformed heading levels into the H1', function () {
    $malformed = '<!-- wp:heading {"level":[1]} --><h2>Malformed level stays</h2><!-- /wp:heading -->';
    $eyebrow = '<!-- wp:heading {"level":5} --><h5>Generated eyebrow</h5><!-- /wp:heading -->';
    $headline = '<!-- wp:heading {"level":1} --><h1>Actual headline</h1><!-- /wp:heading -->';
    $doc = '<!-- wp:group --><div class="wp-block-group">'
        . $malformed . $eyebrow . $headline
        . '</div><!-- /wp:group -->';
    $repairs = [];
    $warnings = [];

    $withoutEyebrow = GeneratedMarkup::stripHeroEyebrow($doc, 'strict-level', $repairs, $warnings);

    assert_contains($malformed, $withoutEyebrow, 'the malformed heading is retained byte-for-byte');
    assert_true(!str_contains($withoutEyebrow, $eyebrow), 'the valid H1 still bounds eyebrow detection');
    assert_contains($headline, $withoutEyebrow);
    assert_eq([], $repairs);
    assert_eq(1, count($warnings));
    assert_contains('Generated eyebrow', $warnings[0]);

    $support = '<!-- wp:paragraph --><p>Support must not move behind a fake H1.</p><!-- /wp:paragraph -->';
    $ordered = '<!-- wp:group --><div class="wp-block-group">'
        . $support . $malformed . $headline
        . '</div><!-- /wp:group -->';
    $moveRepairs = [];
    $moveWarnings = [];
    $out = GeneratedMarkup::headlineFirstHeroCopy(
        $ordered,
        'strict-level',
        $moveRepairs,
        $moveWarnings,
    );

    assert_eq($ordered, $out, 'ambiguous bytes stay intact when the earlier heading level is malformed');
    assert_eq([], $moveRepairs);
    assert_eq(1, count($moveWarnings));
    assert_contains('original pre-headline position', $moveWarnings[0]);
});

test('headlineFirstHeroCopy moves one adjacent support paragraph after the H1 byte-for-byte', function () {
    $beforeSibling = '<!-- wp:image --><figure class="wp-block-image"><img src="before.jpg" alt="" /></figure><!-- /wp:image -->';
    $paragraph = '<!-- wp:paragraph {"fontSize":"lead"} --><p class="has-lead-font-size">The exact support line.</p><!-- /wp:paragraph -->';
    $headline = '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Headline first</h1><!-- /wp:heading -->';
    $afterSibling = '<!-- wp:buttons --><div class="wp-block-buttons"></div><!-- /wp:buttons -->';
    $opening = '<!-- wp:group --><div class="wp-block-group">';
    $closing = '</div><!-- /wp:group -->';
    $doc = $opening . $beforeSibling . $paragraph . $headline . $afterSibling . $closing;
    $repairs = [];
    $warnings = [];

    $out = Automattic\SiteBuild\Units\GeneratedMarkup::headlineFirstHeroCopy(
        $doc,
        'headline-first',
        $repairs,
        $warnings,
    );

    assert_eq(
        $opening . $beforeSibling . $headline . $paragraph . $afterSibling . $closing,
        $out,
        'only the complete support-paragraph block changes position',
    );
    assert_eq([], $warnings);
    assert_eq(['hero-support-moved-after-headline'], array_column($repairs, 'code'));
    assert_contains('The exact support line.', $repairs[0]['authored']);

    $againRepairs = [];
    $againWarnings = [];
    assert_eq(
        $out,
        Automattic\SiteBuild\Units\GeneratedMarkup::headlineFirstHeroCopy(
            $out,
            'headline-first',
            $againRepairs,
            $againWarnings,
        ),
    );
    assert_eq([], $againRepairs);
    assert_eq([], $againWarnings);
});

test('headlineFirstHeroCopy retains ambiguous pre-H1 paragraphs with actionable warnings', function () {
    $first = '<!-- wp:paragraph --><p>First possible support line.</p><!-- /wp:paragraph -->';
    $second = '<!-- wp:paragraph --><p>Second possible support line.</p><!-- /wp:paragraph -->';
    $headline = '<!-- wp:heading {"level":1} --><h1>Headline</h1><!-- /wp:heading -->';
    $doc = '<!-- wp:group --><div class="wp-block-group">'
        . $first . $second . $headline
        . '</div><!-- /wp:group -->';
    $repairs = [];
    $warnings = [];

    $out = Automattic\SiteBuild\Units\GeneratedMarkup::headlineFirstHeroCopy(
        $doc,
        'ambiguous-headline-first',
        $repairs,
        $warnings,
    );

    assert_eq($doc, $out, 'ambiguous authored reading order is retained byte-for-byte');
    assert_eq([], $repairs);
    assert_eq(2, count($warnings));
    assert_contains('First possible support line.', implode("\n", $warnings));
    assert_contains('Second possible support line.', implode("\n", $warnings));
    foreach ($warnings as $warning) {
        foreach ([
            "file='theme/parts/ambiguous-headline-first.html'",
            "block='wp:group[0] > wp:paragraph",
            'authored=',
            'delivered="original pre-headline position"',
            'disposition=',
        ] as $context) {
            assert_contains($context, $warning);
        }
    }
});

test('headlineFirstHeroCopy never moves a paragraph across raw authored copy', function () {
    $paragraph = '<!-- wp:paragraph --><p>Block support line.</p><!-- /wp:paragraph -->';
    $rawCopy = '<p class="model-raw-copy">Raw copy must not be crossed.</p>';
    $headline = '<!-- wp:heading {"level":1} --><h1>Headline</h1><!-- /wp:heading -->';
    $doc = '<!-- wp:group --><div class="wp-block-group">'
        . $paragraph . $rawCopy . $headline
        . '</div><!-- /wp:group -->';
    $repairs = [];
    $warnings = [];

    $out = Automattic\SiteBuild\Units\GeneratedMarkup::headlineFirstHeroCopy(
        $doc,
        'raw-pre-headline-copy',
        $repairs,
        $warnings,
    );

    assert_eq($doc, $out, 'the whole parent stays byte-for-byte intact when raw copy makes the move ambiguous');
    assert_eq([], $repairs);
    assert_eq(1, count($warnings));
    assert_contains('Block support line.', $warnings[0]);
    assert_contains('delivered="original pre-headline position"', $warnings[0]);
});

test('centerHeroCopy aligns the H1 and buttons a model left at start on a fully centered anchor (BIGR-775)', function () {
    // Regression: pulso8 centered the standfirst and left the H1 and buttons
    // at their default start alignment inside the centered copy group.
    $doc = '<!-- wp:group {"anchor":"hero","className":"hero-composition--cinematic-safe-zone","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group hero-composition--cinematic-safe-zone" id="hero">'
        . '<!-- wp:cover {"url":"theme:./assets/x.jpg","minHeight":80,"minHeightUnit":"vh","className":"hero-composition__media","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-cover hero-composition__media" style="min-height:80vh"><div class="wp-block-cover__inner-container">'
        . '<!-- wp:group {"className":"hero-composition__copy","layout":{"type":"constrained","contentSize":"860px","justifyContent":"left"}} -->'
        . '<div class="wp-block-group hero-composition__copy">'
        . '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Neon Bloom</h1><!-- /wp:heading -->'
        . '<!-- wp:paragraph {"align":"center"} --><p class="has-text-align-center">Two nights of DJ performances.</p><!-- /wp:paragraph -->'
        . '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button -->'
        . '<div class="wp-block-button"><a class="wp-block-button__link" href="#registration">Claim Your Ticket</a></div>'
        . '<!-- /wp:button --></div><!-- /wp:buttons -->'
        . '</div><!-- /wp:group --></div></div><!-- /wp:cover --></div><!-- /wp:group -->';
    $repairs = [];
    $out = Automattic\SiteBuild\Units\GeneratedMarkup::centerHeroCopy($doc, 'center', 'ltr', 'p', $repairs);
    // Alignment must land in style.typography.textAlign — the one form the
    // block re-serializer preserves and turns into has-text-align-center.
    assert_contains('"typography":{"textAlign":"center"}', $out, 'H1 centered via typography style');
    assert_contains('"justifyContent":"center"', $out, 'buttons and copy group centered');
    assert_true(!str_contains($out, '"justifyContent":"left"'), 'copy group start alignment replaced');
    assert_true(in_array('hero-copy-centered', array_column($repairs, 'code'), true));

    // Idempotent.
    $again = [];
    assert_eq($out, Automattic\SiteBuild\Units\GeneratedMarkup::centerHeroCopy($out, 'center', 'ltr', 'p', $again));
    assert_eq([], $again);

    // A non-centered anchor leaves the composition alone.
    $r = [];
    assert_eq($doc, Automattic\SiteBuild\Units\GeneratedMarkup::centerHeroCopy($doc, 'bottom-start', 'ltr', 'p', $r));
    assert_eq([], $r);

    // Regression (lumen10): a top-level textAlign attribute is NOT accepted
    // as centered — the serializer's raw-comment overlay loses it whenever
    // other style keys are authored. The pass rewrites it into the
    // typography form and drops the legacy key.
    $legacy = '<!-- wp:group {"anchor":"hero","className":"hero-composition--cinematic-safe-zone","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group hero-composition--cinematic-safe-zone" id="hero">'
        . '<!-- wp:group {"className":"hero-composition__copy","layout":{"type":"constrained","justifyContent":"center"}} -->'
        . '<div class="wp-block-group hero-composition__copy">'
        . '<!-- wp:heading {"textAlign":"center","level":1,"style":{"typography":{"lineHeight":"1.1"}}} -->'
        . '<h1 class="wp-block-heading has-text-align-center" style="line-height:1.1">Handmade sculptural lamps</h1>'
        . '<!-- /wp:heading -->'
        . '</div><!-- /wp:group --></div><!-- /wp:group -->';
    $r2 = [];
    $rewritten = Automattic\SiteBuild\Units\GeneratedMarkup::centerHeroCopy(
        $legacy,
        'center',
        'ltr',
        'p',
        $r2,
    );
    assert_contains('"typography":{"lineHeight":"1.1","textAlign":"center"}', $rewritten, 'legacy attr folded into typography');
    assert_true(!str_contains($rewritten, '"textAlign":"center","level"'), 'legacy top-level textAlign removed');
    assert_true(in_array('hero-copy-centered', array_column($r2, 'code'), true));
});

test('centerHeroCopy isolates malformed style shapes and continues centering safe siblings', function () {
    $badHeading = '<!-- wp:heading {"level":1,"style":"oops"} -->'
        . '<h1 class="wp-block-heading">Authored heading survives</h1><!-- /wp:heading -->';
    $badParagraph = '<!-- wp:paragraph {"style":{"typography":"also-oops"}} -->'
        . '<p>Authored paragraph survives</p><!-- /wp:paragraph -->';
    $doc = '<!-- wp:group {"className":"hero-composition__copy","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group hero-composition__copy">'
        . $badHeading . $badParagraph
        . '<!-- wp:paragraph --><p>Safe sibling centers.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    $repairs = [];
    $warnings = [];

    $out = Automattic\SiteBuild\Units\GeneratedMarkup::centerHeroCopy(
        $doc,
        'center',
        'ltr',
        'malformed-style',
        $repairs,
        $warnings,
    );

    assert_contains($badHeading, $out, 'the wrong-shaped heading is retained byte-for-byte');
    assert_contains($badParagraph, $out, 'the wrong-shaped paragraph is retained byte-for-byte');
    assert_contains('Safe sibling centers.', $out);
    assert_contains('"typography":{"textAlign":"center"}', $out, 'a safe sibling still receives the repair');
    assert_true(in_array('hero-copy-centered', array_column($repairs, 'code'), true));
    assert_eq(2, count($warnings));
    assert_contains('authored style="oops"', implode("\n", $warnings));
    assert_contains('authored style.typography="also-oops"', implode("\n", $warnings));
    foreach ($warnings as $warning) {
        foreach ([
            "file='theme/parts/malformed-style.html'",
            "block='wp:group[0] > wp:",
            'delivered="original block bytes"',
            'disposition=',
        ] as $context) {
            assert_contains($context, $warning);
        }
    }

    $againRepairs = [];
    $againWarnings = [];
    assert_eq(
        $out,
        Automattic\SiteBuild\Units\GeneratedMarkup::centerHeroCopy(
            $out,
            'center',
            'ltr',
            'malformed-style',
            $againRepairs,
            $againWarnings,
        ),
        'safe repairs reach a fixed point while malformed blocks remain isolated',
    );
    assert_eq([], $againRepairs);
    assert_eq($warnings, $againWarnings, 'residual warnings remain stable for a later repair pass');
});

test('centerHeroCopy ignores non-string ancestor className values without losing the outer copy region', function () {
    $heading = '<!-- wp:heading {"level":1} --><h1>Safe nested headline</h1><!-- /wp:heading -->';
    $malformedGroupOpen = '<!-- wp:group {"className":["generated-shape"]} -->'
        . '<div class="wp-block-group">';
    $doc = '<!-- wp:group {"className":"hero-composition__copy","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group hero-composition__copy">'
        . $malformedGroupOpen . $heading . '</div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';
    $repairs = [];
    $warnings = [];
    $phpWarnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$phpWarnings): bool {
        $phpWarnings[] = [$severity, $message];
        return true;
    });
    try {
        $out = GeneratedMarkup::centerHeroCopy(
            $doc,
            'center',
            'ltr',
            'malformed-class-name',
            $repairs,
            $warnings,
        );
    } finally {
        restore_error_handler();
    }

    assert_eq([], $phpWarnings, 'generated array attributes must not cause PHP conversion warnings');
    assert_contains('"className":["generated-shape"]', $out, 'the malformed ancestor attribute is preserved');
    assert_contains('"typography":{"textAlign":"center"}', $out, 'the valid outer copy marker is still found');
    assert_true(in_array('hero-copy-centered', array_column($repairs, 'code'), true));
    assert_eq([], $warnings);
});

test('centerHeroCopy enforces the resolved physical side on the cover center row', function () {
    // Regression: lumen8's layered-poster pinned the copy block to the top of
    // an 80vh stage via contentPosition "top left", opening a dead band below.
    $doc = '<!-- wp:group {"anchor":"hero","className":"hero-composition--layered-poster","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group hero-composition--layered-poster" id="hero">'
        . '<!-- wp:cover {"url":"theme:./assets/x.jpg","minHeight":80,"minHeightUnit":"vh","contentPosition":"top right","className":"hero-composition__media","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-cover has-custom-content-position is-position-top-right hero-composition__media" style="min-height:80vh"><div class="wp-block-cover__inner-container">'
        . '<!-- wp:group {"className":"hero-composition__copy","layout":{"type":"constrained","justifyContent":"left"}} -->'
        . '<div class="wp-block-group hero-composition__copy">'
        . '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Light shaped by hand</h1><!-- /wp:heading -->'
        . '</div><!-- /wp:group --></div></div><!-- /wp:cover --></div><!-- /wp:group -->';
    $repairs = [];
    $out = Automattic\SiteBuild\Units\GeneratedMarkup::centerHeroCopy(
        $doc,
        'center-start',
        'ltr',
        'p',
        $repairs,
    );
    assert_contains('"contentPosition":"center left"', $out, 'cover rides the center row');
    assert_true(!str_contains($out, 'is-position-top-right'), 'opposite-side stale position class removed');
    assert_true(!str_contains($out, '"textAlign"'), 'start-anchored copy keeps its horizontal alignment');
    assert_contains('"justifyContent":"left"', $out, 'copy group horizontal alignment untouched');
    assert_true(in_array('hero-copy-centered', array_column($repairs, 'code'), true));

    // Idempotent.
    $again = [];
    assert_eq(
        $out,
        Automattic\SiteBuild\Units\GeneratedMarkup::centerHeroCopy(
            $out,
            'center-start',
            'ltr',
            'p',
            $again,
        ),
    );
    assert_eq([], $again);

    // A fully centered anchor collapses the position to the cover default.
    $bottom = str_replace(
        ['"contentPosition":"top left"', 'has-custom-content-position is-position-top-left '],
        ['"contentPosition":"bottom right"', 'has-custom-content-position is-position-bottom-right '],
        $doc
    );
    $r = [];
    $centered = Automattic\SiteBuild\Units\GeneratedMarkup::centerHeroCopy(
        $bottom,
        'center',
        'ltr',
        'p',
        $r,
    );
    assert_true(!str_contains($centered, 'contentPosition'), 'all-center position drops the attribute');
    assert_true(!str_contains($centered, 'has-custom-content-position'), 'custom-position marker removed');
    assert_true(!str_contains($centered, 'is-position-bottom-right'), 'stale position class removed');

    // The physical side comes from the delivery contract, not from an LTR
    // assumption or the generated position. Missing positions are repaired,
    // and center-end follows the same physical-side rule.
    $withoutPosition = str_replace(
        [
            ',"contentPosition":"top right"',
            'has-custom-content-position is-position-top-right ',
        ],
        ['', ''],
        $doc,
    );
    $rtlRepairs = [];
    $rtl = Automattic\SiteBuild\Units\GeneratedMarkup::centerHeroCopy(
        $withoutPosition,
        'center-start',
        'rtl',
        'p',
        $rtlRepairs,
    );
    assert_contains('"contentPosition":"center right"', $rtl, 'RTL logical start resolves to physical right');

    $endRepairs = [];
    $end = Automattic\SiteBuild\Units\GeneratedMarkup::centerHeroCopy(
        $withoutPosition,
        'center-end',
        'rtl',
        'p',
        $endRepairs,
    );
    assert_contains('"contentPosition":"center left"', $end, 'RTL logical end resolves to physical left');
});

test('fullBleedCoverAlignment upgrades a wide-capped cover-band hero to align:full', function () {
    // Regression: portfolio26's framed canvas capped the layered-poster cover
    // at alignwide, insetting the hero band from both viewport edges. The
    // page-opening hero is exempt from the framed mat.
    $doc = '<!-- wp:group {"tagName":"section","anchor":"hero","className":"hero-composition--layered-poster","layout":{"type":"constrained"}} -->'
        . '<section class="wp-block-group hero-composition--layered-poster" id="hero">'
        . '<!-- wp:cover {"url":"x.jpg","dimRatio":40,"minHeight":80,"minHeightUnit":"vh","align":"wide","className":"hero-composition__media","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-cover alignwide hero-composition__media" style="min-height:80vh">'
        . '<div class="wp-block-cover__inner-container">'
        . '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Twenty Years of Witness</h1><!-- /wp:heading -->'
        . '</div></div><!-- /wp:cover -->'
        . '</section><!-- /wp:group -->';
    $repairs = [];
    $out = Automattic\SiteBuild\Units\GeneratedMarkup::fullBleedCoverAlignment($doc, 'p', $repairs);
    assert_eq(2, substr_count($out, '"align":"full"'), 'root group and cover both upgraded');
    assert_true(!str_contains($out, '"align":"wide"'), 'wide cap removed from attrs');
    assert_true(!str_contains($out, 'alignwide'), 'stale alignwide class token removed');
    assert_true(in_array('hero-full-bleed-alignment', array_column($repairs, 'code'), true));

    // Idempotent: an already-full hero is untouched byte-for-byte.
    $again = [];
    assert_eq($out, Automattic\SiteBuild\Units\GeneratedMarkup::fullBleedCoverAlignment($out, 'p', $again));
    assert_eq([], $again);
});

test('wrapper repair synthesizes closers for container frames crossed at a closer', function () {
    // Regression: pulso4 lost its whole 24KB schedule section because a
    // day-grid's wp:columns/wp:column never closed and the root closer
    // crossed both frames (leaving the saved HTML two </div>s short).
    $doc = '<!-- wp:group {"tagName":"section","anchor":"schedule","layout":{"type":"constrained"}} -->' . "\n"
        . '<section class="wp-block-group" id="schedule">' . "\n"
        . '<!-- wp:columns -->' . "\n"
        . '<div class="wp-block-columns"><!-- wp:column -->' . "\n"
        . '<div class="wp-block-column"><!-- wp:paragraph --><p>Friday sets.</p><!-- /wp:paragraph -->' . "\n"
        . '</section>' . "\n"
        . '<!-- /wp:group -->';
    $notes = [];
    $out = Automattic\SiteBuild\Units\GeneratedMarkup::closeUnbalancedWrapperClosers($doc, $notes);
    assert_true($out !== $doc, 'crossed frames were repaired');
    assert_contains('<!-- /wp:column -->', $out);
    assert_contains('<!-- /wp:columns -->', $out);
    assert_eq(1, count($notes));
    Automattic\SiteBuild\BlockDocumentRecovery::assertComplete($out);

    // Idempotent: the repaired document passes through untouched.
    $again = [];
    assert_eq($out, Automattic\SiteBuild\Units\GeneratedMarkup::closeUnbalancedWrapperClosers($out, $again));
    assert_eq([], $again);
});

test('wrapper repair closes a container suffix missing its wrapper tag', function () {
    $doc = '<!-- wp:group {"layout":{"type":"constrained"}} -->' . "\n"
        . '<div class="wp-block-group"><!-- wp:paragraph --><p>Copy.</p><!-- /wp:paragraph -->' . "\n"
        . '<!-- /wp:group -->';
    $notes = [];
    $out = Automattic\SiteBuild\Units\GeneratedMarkup::closeUnbalancedWrapperClosers($doc, $notes);
    assert_contains('</div><!-- /wp:group -->', $out);
    Automattic\SiteBuild\BlockDocumentRecovery::assertComplete($out);
    assert_eq(1, count($notes));
});

test('wrapper repair leaves stray closers and content-bearing shells alone', function () {
    $stray = '<!-- wp:paragraph --><p>Text.</p><!-- /wp:paragraph --><!-- /wp:group -->';
    $n = [];
    assert_eq($stray, Automattic\SiteBuild\Units\GeneratedMarkup::closeUnbalancedWrapperClosers($stray, $n));
    assert_eq([], $n);
});

test('clampHeroTopPadding lowers xl to sm on media-led and to lg on copy-led hero roots', function () {
    // Regression: lumen4's panorama-rail (recipe since retired) root carried padding-top:xl, opening
    // a dead band under the header that pushed the whole rail below the fold.
    $mediaLed = '<!-- wp:group {"tagName":"section","anchor":"hero","className":"hero-composition--foreground-split","style":{"spacing":{"padding":{"top":"var:preset|spacing|xl","bottom":"var:preset|spacing|xl"}}},"layout":{"type":"constrained"}} -->'
        . '<section id="hero" class="wp-block-group hero-composition--foreground-split" style="padding-top:var(--wp--preset--spacing--xl);padding-bottom:var(--wp--preset--spacing--xl)">'
        . '<!-- wp:image {"className":"hero-composition__media"} --><figure class="wp-block-image hero-composition__media"><img src="theme:./assets/x.jpg" alt="AI_IMAGE: a | b | c | landscape"/></figure><!-- /wp:image -->'
        . '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">A quiet panorama headline</h1><!-- /wp:heading -->'
        . '</section><!-- /wp:group -->';
    $repairs = [];
    $out = Automattic\SiteBuild\Units\GeneratedMarkup::clampHeroTopPadding($mediaLed, 'p', $repairs);
    assert_contains('"top":"var:preset|spacing|sm"', $out);
    assert_contains('padding-top:var(--wp--preset--spacing--sm)', $out);
    assert_contains('padding-bottom:var(--wp--preset--spacing--xl)', $out, 'bottom padding untouched');
    assert_true(in_array('hero-top-padding-clamped', array_column($repairs, 'code'), true));

    // Idempotent.
    $again = [];
    assert_eq($out, Automattic\SiteBuild\Units\GeneratedMarkup::clampHeroTopPadding($out, 'p', $again));
    assert_eq([], $again);

    // Copy-led root keeps its padding.
    $copyLed = str_replace(
        ['<!-- wp:image {"className":"hero-composition__media"} --><figure class="wp-block-image hero-composition__media"><img src="theme:./assets/x.jpg" alt="AI_IMAGE: a | b | c | landscape"/></figure><!-- /wp:image -->'
            . '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">A quiet panorama headline</h1><!-- /wp:heading -->'],
        ['<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">A quiet panorama headline</h1><!-- /wp:heading -->'],
        $mediaLed
    );
    $r2 = [];
    $copyOut = Automattic\SiteBuild\Units\GeneratedMarkup::clampHeroTopPadding($copyLed, 'p', $r2);
    assert_contains('"top":"var:preset|spacing|lg"', $copyOut, 'copy-led xl clamps to lg');
    assert_contains('padding-top:var(--wp--preset--spacing--lg)', $copyOut);
    assert_true(in_array('hero-top-padding-clamped', array_column($r2, 'code'), true));

    // A copy-led root already at md keeps its padding.
    $atMd = str_replace(
        ['var:preset|spacing|xl', 'var(--wp--preset--spacing--xl)'],
        ['var:preset|spacing|md', 'var(--wp--preset--spacing--md)'],
        $copyLed
    );
    $r3 = [];
    assert_eq($atMd, Automattic\SiteBuild\Units\GeneratedMarkup::clampHeroTopPadding($atMd, 'p', $r3));
    assert_eq([], $r3);
});

test('a `>`-truncated delimiter comment is closed when its attrs fragment parses', function () {
    // Regression: pulso11 lost its workshops section to
    // `<!-- wp:paragraph {"align":"center"> <p …` — attrs lost `"}` and the
    // comment its ` -->`, swallowing real content at the HTML layer.
    $doc = '<!-- wp:group {"layout":{"type":"constrained"}} -->' . "\n"
        . '<div class="wp-block-group"><!-- wp:paragraph {"align":"center">' . "\n"
        . '<p class="has-text-align-center">A practical session.</p>' . "\n"
        . '<!-- /wp:paragraph --></div>' . "\n"
        . '<!-- /wp:group -->';
    $notes = [];
    $out = Automattic\SiteBuild\Units\GeneratedMarkup::closeTruncatedDelimiterComment($doc, $notes);
    assert_contains('<!-- wp:paragraph {"align":"center"} -->', $out);
    assert_contains('A practical session.', $out);
    assert_eq(1, count($notes));
    Automattic\SiteBuild\BlockDocumentRecovery::assertComplete($out);

    // A `>` inside a legitimate attribute string never matches.
    $legit = '<!-- wp:paragraph {"metadata":{"name":"a > b"}} --><p>x</p><!-- /wp:paragraph -->';
    $n2 = [];
    assert_eq($legit, Automattic\SiteBuild\Units\GeneratedMarkup::closeTruncatedDelimiterComment($legit, $n2));
    assert_eq([], $n2);
});

test('a surplus closing brace inside a delimiter attrs run is removed', function () {
    // Regression: atlas11 lost its generated header to
    // `"blockGap":"0"}}},"layout"` — one extra brace closed the style object
    // early and made the root opener unparseable.
    $doc = '<!-- wp:group {"className":"site-header","style":{"spacing":{"blockGap":"0"}}},"layout":{"type":"constrained"}} -->' . "\n"
        . '<div class="wp-block-group site-header"><!-- wp:paragraph --><p>Nav</p><!-- /wp:paragraph --></div>' . "\n"
        . '<!-- /wp:group -->';
    $notes = [];
    $out = Automattic\SiteBuild\Units\GeneratedMarkup::closeUnbalancedDelimiterAttrs($doc, $notes);
    assert_contains('"style":{"spacing":{"blockGap":"0"}},"layout":{"type":"constrained"}', $out);
    assert_eq(1, count($notes));
    assert_contains('surplus', $notes[0]);
    Automattic\SiteBuild\BlockDocumentRecovery::assertComplete($out);

    // Idempotent.
    $again = [];
    assert_eq($out, Automattic\SiteBuild\Units\GeneratedMarkup::closeUnbalancedDelimiterAttrs($out, $again));
    assert_eq([], $again);
});

test('an attrs-less delimiter that dropped its terminator is closed', function () {
    // Regression: pulso12 lost its ticketing section to `<!-- wp:paragraph>`
    // — the ` -->` terminator dropped entirely on an attrs-less delimiter.
    $doc = '<!-- wp:group {"layout":{"type":"constrained"}} -->' . "\n"
        . '<div class="wp-block-group"><!-- wp:paragraph> <!-- /wp:paragraph -->' . "\n"
        . '<!-- wp:paragraph --><p>790 SEK</p><!-- /wp:paragraph --></div>' . "\n"
        . '<!-- /wp:group -->';
    $notes = [];
    $out = Automattic\SiteBuild\Units\GeneratedMarkup::closeTruncatedDelimiterComment($doc, $notes);
    assert_contains('<!-- wp:paragraph --> <!-- /wp:paragraph -->', $out);
    assert_contains('790 SEK', $out);
    assert_eq(1, count($notes));
    Automattic\SiteBuild\BlockDocumentRecovery::assertComplete($out);

    // Idempotent; a legit closer never matches.
    $again = [];
    assert_eq($out, Automattic\SiteBuild\Units\GeneratedMarkup::closeTruncatedDelimiterComment($out, $again));
    assert_eq([], $again);
});

test('stripTextBlockShadow removes shadow presets from text blocks but not media', function () {
    // Regression: pulso5's theme shipped an "RGB Misregister" shadow preset
    // and the hero applied it to the H1 — the offset box-shadow rendered as
    // a cyan bar left and an orange bar right of the headline.
    $doc = '<!-- wp:group {"tagName":"section","anchor":"hero","layout":{"type":"constrained"}} -->'
        . '<section class="wp-block-group" id="hero">'
        . '<!-- wp:heading {"level":1,"style":{"typography":{"letterSpacing":"-0.01em"},"shadow":"var:preset|shadow|misregister"}} -->'
        . '<h1 class="wp-block-heading" style="letter-spacing:-0.01em;box-shadow:var(--wp--preset--shadow--misregister)">Dance the ruin awake</h1>'
        . '<!-- /wp:heading -->'
        . '<!-- wp:paragraph {"style":{"shadow":"var:preset|shadow|offset-plate"}} -->'
        . '<p style="box-shadow:var(--wp--preset--shadow--offset-plate)">Two nights of sound.</p>'
        . '<!-- /wp:paragraph -->'
        . '<!-- wp:image {"sizeSlug":"large","style":{"shadow":"var:preset|shadow|offset-plate"}} -->'
        . '<figure class="wp-block-image size-large"><img src="/a.jpg" alt="" style="box-shadow:var(--wp--preset--shadow--offset-plate)"/></figure>'
        . '<!-- /wp:image -->'
        . '</section>'
        . '<!-- /wp:group -->';
    $repairs = [];
    $out = Automattic\SiteBuild\Units\GeneratedMarkup::stripTextBlockShadow($doc, 'p', $repairs);
    assert_true(!str_contains($out, 'wp:heading {"level":1,"style":{"typography":{"letterSpacing":"-0.01em"},"shadow"'), 'heading shadow attr removed');
    assert_true(!str_contains($out, 'wp:paragraph {"style":{"shadow"'), 'paragraph shadow attr removed');
    assert_contains('wp:image {"sizeSlug":"large","style":{"shadow":"var:preset|shadow|offset-plate"}}', $out);
    assert_contains('Dance the ruin awake', $out);
    assert_true(in_array('text-block-shadow-stripped', array_column($repairs, 'code'), true));

    // Idempotent + attribute-empty style objects are dropped, not left as {}.
    $again = [];
    assert_eq($out, Automattic\SiteBuild\Units\GeneratedMarkup::stripTextBlockShadow($out, 'p', $again));
    assert_eq([], $again);
    assert_true(!str_contains($out, '"style":[]') && !str_contains($out, '"style":{}'), 'no empty style attr left behind');
});
