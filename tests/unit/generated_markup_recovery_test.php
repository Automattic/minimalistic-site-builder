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
    $embedCover = '<!-- wp:cover {"backgroundType":"embed-video","url":"https://youtu.be/abc"} -->'
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
    ob_start();
    $out = GeneratedMarkup::normalize("Here: {$lead}\n" . GM_ROOT, 'p');
    ob_end_clean();

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

test('clampHeroTopPadding lowers xl to sm on media-led and to md on copy-led hero roots', function () {
    // Regression: lumen4's panorama-rail root carried padding-top:xl, opening
    // a dead band under the header that pushed the whole rail below the fold.
    $mediaLed = '<!-- wp:group {"tagName":"section","anchor":"hero","className":"hero-composition--panorama-rail","style":{"spacing":{"padding":{"top":"var:preset|spacing|xl","bottom":"var:preset|spacing|xl"}}},"layout":{"type":"constrained"}} -->'
        . '<section id="hero" class="wp-block-group hero-composition--panorama-rail" style="padding-top:var(--wp--preset--spacing--xl);padding-bottom:var(--wp--preset--spacing--xl)">'
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
    assert_contains('"top":"var:preset|spacing|md"', $copyOut, 'copy-led xl clamps to md');
    assert_contains('padding-top:var(--wp--preset--spacing--md)', $copyOut);
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
