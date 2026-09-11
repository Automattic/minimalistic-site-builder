<?php
declare(strict_types=1);

use Automattic\SiteBuild\TypographicHygiene;

const TH_NBSP = "\u{00A0}";

function th_heading(string $text, string $attrs = '{"level":2}', string $tag = 'h2'): string
{
    return '<!-- wp:heading ' . $attrs . ' -->'
        . "\n<{$tag}>{$text}</{$tag}>\n"
        . '<!-- /wp:heading -->';
}

function th_paragraph(string $text, string $attrs = ''): string
{
    $comment = $attrs === '' ? '<!-- wp:paragraph -->' : '<!-- wp:paragraph ' . $attrs . ' -->';
    return $comment . "\n<p>{$text}</p>\n" . '<!-- /wp:paragraph -->';
}

// ---------------------------------------------------------------- widows

test('widow binding joins the last two words of a wrapping heading', function () {
    $result = TypographicHygiene::apply(th_heading('Small batch roasted every morning'));
    assert_contains('every' . TH_NBSP . 'morning', $result['markup']);
    assert_eq(1, $result['widows']);
    // Only the last space moves.
    assert_contains('<h2>Small batch roasted every' . TH_NBSP . 'morning</h2>', $result['markup']);
});

test('widow binding leaves a heading too short to strand a word', function () {
    $markup = th_heading('Roasted every morning');
    $result = TypographicHygiene::apply($markup);
    assert_eq($markup, $result['markup'], 'three words cannot widow');
    assert_eq(0, $result['widows']);
});

test('widow binding declines a last pair long enough to overflow the measure', function () {
    $markup = th_heading('We build extraordinary transformations');
    $result = TypographicHygiene::apply($markup);
    assert_eq($markup, $result['markup'], 'bound, that pair behaves like one 38-char word');
    assert_eq(0, $result['widows']);
});

test('widow binding skips the masthead H1 that HeroHeadlineFit owns', function () {
    $markup = th_heading(
        'Coffee roasted in small batches',
        '{"level":1,"fontSize":"display"}',
        'h1',
    );
    $result = TypographicHygiene::apply($markup);
    assert_eq($markup, $result['markup']);
    assert_eq(0, $result['widows']);
});

test('widow binding still sets a non-masthead h1', function () {
    $result = TypographicHygiene::apply(
        th_heading('Coffee roasted in small batches', '{"level":1}', 'h1'),
    );
    assert_contains('small' . TH_NBSP . 'batches', $result['markup']);
    assert_eq(1, $result['widows']);
});

test('widow binding finds the last rendered space inside a trailing emphasis span', function () {
    $result = TypographicHygiene::apply(
        th_heading('Built for <span class="emph">working farms</span>'),
    );
    assert_contains('working' . TH_NBSP . 'farms', $result['markup']);
    assert_contains('<span class="emph">', $result['markup']);
    assert_eq(1, $result['widows']);
});

test('widow binding finds the last rendered space after a closing tag', function () {
    $result = TypographicHygiene::apply(
        th_heading('A <span class="emph">quietly</span> precise roast'),
    );
    assert_contains('precise' . TH_NBSP . 'roast', $result['markup']);
    assert_eq(1, $result['widows']);
});

test('widow binding measures the rendered pair, not the encoded one', function () {
    // "&amp;" is one character to the reader; counted as five it would push
    // this pair past the cap and refuse a binding that fits.
    $result = TypographicHygiene::apply(th_heading('Fresh bread &amp; sandwiches'));
    assert_contains('&amp;' . TH_NBSP . 'sandwiches', $result['markup']);
    assert_eq(1, $result['widows']);
});

test('widow binding reaches a fixed point', function () {
    $once = TypographicHygiene::apply(th_heading('Small batch roasted every morning'));
    $twice = TypographicHygiene::apply($once['markup']);
    assert_eq($once['markup'], $twice['markup'], 'a repair pass must be idempotent');
    assert_eq(0, $twice['widows']);
});

test('widow binding ignores paragraphs', function () {
    $markup = th_paragraph('We roast in small batches every single morning here.');
    $result = TypographicHygiene::apply($markup);
    assert_eq(0, $result['widows']);
});

// ----------------------------------------------------------- punctuation

test('straight quotes become curly, opening and closing', function () {
    $result = TypographicHygiene::apply(th_paragraph('She called it "the best in town" and left.'));
    assert_contains('“the best in town”', $result['markup']);
    assert_true(!str_contains($result['markup'], '"the'), 'no straight quote survives in prose');
});

test('apostrophes are curled inside words and after a possessive plural', function () {
    $result = TypographicHygiene::apply(th_paragraph("Don't miss the farmers' market."));
    assert_contains('Don’t', $result['markup']);
    assert_contains('farmers’ market', $result['markup']);
});

test('a year range takes an en dash', function () {
    $result = TypographicHygiene::apply(th_paragraph('Open 1998-2024, every season.'));
    assert_contains('1998–2024', $result['markup']);
});

test('a grouped phone number keeps its hyphens', function () {
    $markup = th_paragraph('Call 03-1234-1999 to book.');
    $result = TypographicHygiene::apply($markup);
    assert_contains('03-1234-1999', $result['markup'], 'hard facts come from the spec verbatim');
    assert_eq(0, $result['punctuation']);
});

test('a figure keeps its unit on the same line', function () {
    $result = TypographicHygiene::apply(th_paragraph('A 20 km loop climbing 400 m, on 12 ha.'));
    assert_contains('20' . TH_NBSP . 'km', $result['markup']);
    assert_contains('400' . TH_NBSP . 'm', $result['markup']);
    assert_contains('12' . TH_NBSP . 'ha', $result['markup']);
});

test('a figure followed by an ordinary word is left alone', function () {
    $markup = th_paragraph('We seat 40 guests and open at 7 pm.');
    $result = TypographicHygiene::apply($markup);
    assert_eq($markup, $result['markup'], 'only unambiguous unit abbreviations bind');
});

test('block comment JSON is structurally out of reach', function () {
    $result = TypographicHygiene::apply(
        '<!-- wp:paragraph {"className":"text-action","style":{"typography":{"fontStyle":"normal"}}} -->'
        . "\n" . '<p>Read "the story" here.</p>' . "\n"
        . '<!-- /wp:paragraph -->',
    );
    assert_contains('{"className":"text-action"', $result['markup'], 'attribute JSON untouched');
    assert_contains('“the story”', $result['markup']);
});

test('href values and class lists are never repunctuated', function () {
    $result = TypographicHygiene::apply(
        th_paragraph('Our <a href="/our-story-1998-2024/" class="text-action">story</a> so far.'),
    );
    assert_contains('href="/our-story-1998-2024/"', $result['markup'], 'a URL is not prose');
});

test('code content keeps typewriter punctuation', function () {
    $markup = th_paragraph('Set <code>margin: 0 20 px; label="x"</code> there.');
    $result = TypographicHygiene::apply($markup);
    assert_contains('label="x"', $result['markup']);
});

test('punctuation reaches a fixed point', function () {
    $once = TypographicHygiene::apply(th_paragraph('She said "hi" in 1998-2024 over 20 km.'));
    $twice = TypographicHygiene::apply($once['markup']);
    assert_eq($once['markup'], $twice['markup']);
    assert_eq(0, $twice['punctuation']);
});

// --------------------------------------------------------------- justify

test('a class-only justification is unset too, since fix-blocks rescues className', function () {
    $result = TypographicHygiene::apply(
        '<!-- wp:paragraph {"className":"has-text-align-justify text-action"} -->'
        . "\n" . '<p class="has-text-align-justify text-action">Class only.</p>' . "\n"
        . '<!-- /wp:paragraph -->',
    );
    assert_true(!str_contains($result['markup'], 'has-text-align-justify'), 'className token removed');
    assert_contains('text-action', $result['markup'], 'sibling class survives');
    assert_eq(1, $result['justify']);
});

test('justified alignment is unset in attributes and saved HTML', function () {
    $result = TypographicHygiene::apply(
        '<!-- wp:paragraph {"align":"justify"} -->'
        . "\n" . '<p class="has-text-align-justify">Rivers open at this measure.</p>' . "\n"
        . '<!-- /wp:paragraph -->',
    );
    assert_true(!str_contains($result['markup'], '"align":"justify"'), 'attribute removed');
    assert_true(!str_contains($result['markup'], 'has-text-align-justify'), 'saved class removed');
    assert_eq(1, $result['justify']);
});

test('other alignments survive', function () {
    $markup = th_paragraph('Centred on purpose.', '{"align":"center"}');
    $result = TypographicHygiene::apply($markup);
    assert_eq(0, $result['justify']);
    assert_contains('"align":"center"', $result['markup']);
});

// ------------------------------------------- review findings (BIGR-1012)

test('a heading that is both justified and widow-prone gets both repairs', function () {
    // The two edits target one node's own HTML, which BlockMarkup forbids
    // overlapping: the splice used to be dropped while the count still
    // claimed it. Separate parse cycles are what make both land.
    $result = TypographicHygiene::apply(
        '<!-- wp:heading {"level":2,"align":"justify"} -->'
        . "\n" . '<h2 class="has-text-align-justify">Small batch roasted every morning</h2>' . "\n"
        . '<!-- /wp:heading -->',
    );
    assert_contains('every' . TH_NBSP . 'morning', $result['markup'], 'binding survives the class edit');
    assert_true(!str_contains($result['markup'], 'justify'), 'justification still cleared');
    assert_eq(1, $result['widows']);
    assert_eq(1, $result['justify']);
});

test('clearing a class-only justification leaves a band its layout alignment', function () {
    $result = TypographicHygiene::apply(
        '<!-- wp:group {"align":"full","className":"has-text-align-justify band"} -->'
        . "\n" . '<div class="alignfull band">x</div>' . "\n" . '<!-- /wp:group -->',
    );
    assert_contains('"align":"full"', $result['markup'], 'align also spells full and wide');
    assert_true(!str_contains($result['markup'], 'has-text-align-justify'));
    assert_eq(1, $result['justify']);
});

test('textAlign justify is cleared, not only align', function () {
    // Core spells it textAlign on heading, columns and media-text.
    $result = TypographicHygiene::apply(
        '<!-- wp:heading {"level":2,"textAlign":"justify"} -->'
        . "\n" . '<h2 class="has-text-align-justify">Rivers run through this heading here</h2>' . "\n"
        . '<!-- /wp:heading -->',
    );
    assert_true(!str_contains($result['markup'], 'justify'), 'attribute and class both gone');
    assert_eq(1, $result['justify']);
});

test('unit binding never changes wrapping inside a heading', function () {
    // The widow pass is the one owner of heading wrapping, which is why it
    // excludes the masthead; the punctuation pass must not reach in behind it.
    $markup = th_heading('Trails up to 20 km', '{"level":1,"fontSize":"display"}', 'h1');
    $result = TypographicHygiene::apply($markup);
    assert_eq($markup, $result['markup']);
    assert_eq(0, $result['punctuation']);
});

test('a heading whose last separator is a newline binds that separator', function () {
    $result = TypographicHygiene::apply(
        "<!-- wp:heading {\"level\":2} -->\n<h2>Designed for the way you actually\nwork</h2>\n<!-- /wp:heading -->",
    );
    assert_contains('actually' . TH_NBSP . 'work', $result['markup'], 'the measured pair is the bound pair');
    assert_true(!str_contains($result['markup'], 'you' . TH_NBSP), 'not an earlier space');
    assert_eq(1, $result['widows']);
});

test('an entity-spelled non-breaking space already counts as bound', function () {
    // HtmlNode writes U+00A0 back out as &nbsp;, so a resumed build presents
    // an already-bound heading this way. Binding again glues three words.
    $markup = th_heading('We do it by the&nbsp;sea');
    $result = TypographicHygiene::apply($markup);
    assert_eq($markup, $result['markup']);
    assert_eq(0, $result['widows']);
});

test('an elision apostrophe is left straight rather than half-curled', function () {
    $result = TypographicHygiene::apply(
        th_paragraph("Rock 'n' roll since '90s. Don't miss the farmers' market."),
    );
    assert_contains("Rock 'n' roll", $result['markup'], 'both halves stay straight');
    assert_contains("'90s", $result['markup']);
    assert_contains('Don’t', $result['markup'], 'in-word apostrophes still curl');
    assert_contains('farmers’ market', $result['markup'], 'the possessive plural still curls');
});

// ----------------------------------------------------------- robustness

test('malformed delimiters leave the part untouched', function () {
    $threw = false;
    try {
        TypographicHygiene::apply('<!-- wp:heading --><h2>Never closed at all here</h2>');
    } catch (\RuntimeException) {
        $threw = true;
    }
    assert_true($threw, 'the step catches this and delivers the part as authored');
});

test('an empty part is a no-op', function () {
    $result = TypographicHygiene::apply('');
    assert_eq('', $result['markup']);
    assert_eq(0, $result['widows']);
});
