<?php
declare(strict_types=1);

use Automattic\SiteBuild\SectionCopyDedupe;

/**
 * SectionCopyDedupe removes copy lines that repeat across a page's sections
 * (BIGR-783): label-styled kickers/taglines and pullquote bodies, keeping the
 * earliest occurrence and treating the shared footer as read-only canon at
 * the closing-section seam. Body prose is never compared.
 */

function dedupe_kicker(string $text, string $extra = ''): string
{
    return "<!-- wp:paragraph {\"style\":{\"typography\":{\"textTransform\":\"uppercase\"}}} -->\n"
        . "<p class=\"has-caption-font-size\" style=\"letter-spacing:0.14em;text-transform:uppercase\">{$text}</p>\n"
        . "<!-- /wp:paragraph -->";
}

function dedupe_prose(string $text): string
{
    return "<!-- wp:paragraph -->\n<p>{$text}</p>\n<!-- /wp:paragraph -->";
}

function dedupe_pullquote(string $quote, string $cite): string
{
    return "<!-- wp:pullquote -->\n<figure class=\"wp-block-pullquote\"><blockquote>"
        . "<p>{$quote}</p><cite>{$cite}</cite></blockquote></figure>\n<!-- /wp:pullquote -->";
}

function dedupe_section(string $slug, string ...$blocks): array
{
    $inner = implode("\n", $blocks);
    return [
        'slug'   => $slug,
        'markup' => "<!-- wp:group -->\n<div class=\"wp-block-group\">\n"
            . dedupe_prose('Section framing copy that stays untouched.') . "\n"
            . $inner . "\n</div>\n<!-- /wp:group -->",
    ];
}

test('an identical label line in a later section is removed, the first stays', function () {
    $result = SectionCopyDedupe::dedupe([
        dedupe_section('schedule', dedupe_kicker('Hornstull / Art District — Two Nights')),
        dedupe_section('registration', dedupe_kicker('HORNSTULL / ART DISTRICT — TWO NIGHTS')),
    ], '');

    assert_contains('Hornstull', $result['markups'][0]);
    assert_true(!str_contains($result['markups'][1], 'HORNSTULL'), 'later identical kicker removed');
    assert_eq(1, count($result['notes']));
});

test('near-duplicate label lines across sections are removed by containment', function () {
    // The lumen identity-line family: dominant shared token run, tail varies.
    $result = SectionCopyDedupe::dedupe([
        dedupe_section('statement', dedupe_kicker('Recycled bottle cullet · Copenhagen · 2026')),
        dedupe_section('collection', dedupe_kicker('Recycled bottle cullet · Copenhagen studio')),
    ], '');

    assert_contains('2026', $result['markups'][0]);
    assert_true(!str_contains($result['markups'][1], 'cullet'), 'near-duplicate identity line removed');
});

test('sharing a token run or an email domain is not an echo', function () {
    // Two label lines that merely overlap keep saying different things: a
    // shared location prefix, a shared email domain, or a longer line that
    // contains the shorter one's tokens must all survive.
    $footer = "<!-- wp:group -->\n<div class=\"wp-block-group\">\n"
        . dedupe_kicker('Buenos Aires, Argentina') . "\n"
        . dedupe_kicker('support@atlasfield.com') . "\n</div>\n<!-- /wp:group -->";
    $result = SectionCopyDedupe::dedupe([
        dedupe_section('story', dedupe_kicker('Tbilisi Old Town · Open daily')),
        dedupe_section(
            'contact',
            dedupe_kicker('Tbilisi Old Town · Georgia'),
            dedupe_kicker('Cancel any time · Works offline on site · Questions? hello@atlasfield.com'),
            dedupe_kicker('Based in Buenos Aires, Argentina — working across the whole country')
        ),
    ], $footer);

    assert_eq([], $result['notes']);
});

test('paraphrased pullquotes sharing a long opening are collapsed to one', function () {
    $result = SectionCopyDedupe::dedupe([
        dedupe_section('story', dedupe_pullquote(
            'A guest is a gift from God — and at a supra, no one eats before the toast is finished.',
            'Georgian proverb, still enforced at our tables'
        )),
        dedupe_section('tables', dedupe_pullquote(
            'A guest is a gift from God — and the table is where we unwrap it slowly.',
            'Kakhetian saying, kept at Tbilisi Tavern'
        )),
    ], '');

    assert_contains('no one eats', $result['markups'][0]);
    assert_true(!str_contains($result['markups'][1], 'unwrap it slowly'), 'paraphrased twin quote removed');
});

test('quote prefixes preserve repeated words in their authored order', function () {
    $result = SectionCopyDedupe::dedupe([
        dedupe_section('first', dedupe_pullquote(
            'We are what we are in the moments before the doors open.',
            'First speaker'
        )),
        dedupe_section('second', dedupe_pullquote(
            'We are what we are in the choices we make together.',
            'Second speaker'
        )),
    ], '');

    assert_eq(1, $result['removed']);
    assert_true(!str_contains($result['markups'][1], 'choices we make'), 'six-token repeated-word prefix matches');
});

test('a closing-section line duplicating the footer is removed on the page side', function () {
    $footer = "<!-- wp:group -->\n<div class=\"wp-block-group\">\n"
        . dedupe_kicker('Copenhagen, Denmark') . "\n"
        . dedupe_prose('© 2026') . "\n</div>\n<!-- /wp:group -->";
    $result = SectionCopyDedupe::dedupe([
        dedupe_section('collection', dedupe_kicker('Made by hand since 2019')),
        dedupe_section('enquiry', dedupe_kicker('COPENHAGEN, DENMARK')),
    ], $footer);

    assert_true(!str_contains($result['markups'][1], 'COPENHAGEN'), 'seam duplicate removed from the page');
    assert_contains('Made by hand', $result['markups'][0]);
    assert_contains("the footer's", implode(' ', $result['notes']));
});

test('the footer seam only binds the closing section', function () {
    $footer = "<!-- wp:group -->\n<div class=\"wp-block-group\">\n"
        . dedupe_kicker('Copenhagen, Denmark') . "\n"
        . dedupe_prose('© 2026') . "\n</div>\n<!-- /wp:group -->";
    $result = SectionCopyDedupe::dedupe([
        dedupe_section('statement', dedupe_kicker('Copenhagen, Denmark')),
        dedupe_section('enquiry', dedupe_kicker('Write to the studio')),
    ], $footer);

    assert_contains('Copenhagen', $result['markups'][0]);
    assert_eq([], $result['notes']);
});

test('body prose sharing words is never treated as a duplicate', function () {
    $result = SectionCopyDedupe::dedupe([
        dedupe_section('statement', dedupe_prose('Each lamp is hand-blown in our Copenhagen studio from sorted cullet.')),
        dedupe_section('process', dedupe_prose('Every lamp begins as discarded bottle glass, sorted by hand in our Copenhagen workshop.')),
    ], '');

    assert_eq([], $result['notes']);
    assert_contains('Copenhagen workshop', $result['markups'][1]);
});

test('repeats inside one section stay', function () {
    $result = SectionCopyDedupe::dedupe([
        dedupe_section(
            'schedule',
            dedupe_kicker('Doors at eight'),
            dedupe_kicker('Doors at eight')
        ),
    ], '');

    assert_eq([], $result['notes']);
});

test('two-token near-matches are not merged, exact ones are', function () {
    // "24 Market Street / Portland, Maine" must never lose its city because a
    // footer kicker also says "Portland, Maine" — containment needs 3 tokens.
    $footer = "<!-- wp:group -->\n<div class=\"wp-block-group\">\n"
        . dedupe_kicker('Portland, Maine') . "\n"
        . dedupe_prose('© 2026') . "\n</div>\n<!-- /wp:group -->";
    $near = SectionCopyDedupe::dedupe([
        dedupe_section('visit', dedupe_kicker('24 Market Street · Portland · Maine')),
    ], $footer);
    assert_eq([], $near['notes']);

    $exact = SectionCopyDedupe::dedupe([
        dedupe_section('visit', dedupe_kicker('Portland, Maine')),
    ], $footer);
    assert_eq(1, count($exact['notes']));
});

test('removing a wrapper\'s only child takes the emptied wrapper with it', function () {
    // A pullquote alone in a centering group must not leave an empty group
    // behind — the removal span widens to the ancestor that would be emptied.
    $wrapped = "<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n"
        . "<div class=\"wp-block-group\">" . dedupe_pullquote(
            'A guest is a gift from God — and the table is where we unwrap it slowly.',
            'Kakhetian saying'
        ) . "</div>\n<!-- /wp:group -->";
    $result = SectionCopyDedupe::dedupe([
        dedupe_section('story', dedupe_pullquote(
            'A guest is a gift from God — and at a supra, no one eats before the toast is finished.',
            'Georgian proverb'
        )),
        dedupe_section('tables', dedupe_prose('Framing that stays.'), $wrapped),
    ], '');

    assert_eq(1, count($result['notes']));
    assert_true(!str_contains($result['markups'][1], 'pullquote'), 'quote removed');
    assert_true(!str_contains($result['markups'][1], 'constrained'), 'emptied wrapper removed with it');
    assert_contains('Framing that stays', $result['markups'][1]);
});

test('a duplicate that widens to a whole section only anchors, never removes', function () {
    // A section whose entire content is the duplicate wrapper is the plan's
    // unit — dedupe must not delete a planned section outright.
    $soleSection = [
        'slug'   => 'banner',
        'markup' => "<!-- wp:group -->\n<div class=\"wp-block-group\">\n"
            . dedupe_kicker('Open daily until late') . "\n</div>\n<!-- /wp:group -->",
    ];
    $result = SectionCopyDedupe::dedupe([
        dedupe_section('hours', dedupe_kicker('Open daily until late')),
        $soleSection,
    ], '');

    assert_eq([], $result['notes']);
    assert_contains('Open daily', $result['markups'][1]);
});

test('a quote\'s inner paragraph never produces an overlapping removal', function () {
    $quote = "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">"
        . "<!-- wp:paragraph -->\n<p class=\"has-caption-font-size\">Slow food, said twice</p>\n<!-- /wp:paragraph -->"
        . "<!-- wp:paragraph -->\n<p>Second inner line.</p>\n<!-- /wp:paragraph -->"
        . "</blockquote>\n<!-- /wp:quote -->";
    $result = SectionCopyDedupe::dedupe([
        dedupe_section('story', dedupe_kicker('Slow food, said twice')),
        ['slug' => 'quote', 'markup' => "<!-- wp:group -->\n<div class=\"wp-block-group\">\n"
            . dedupe_prose('Framing.') . "\n" . $quote . "\n</div>\n<!-- /wp:group -->"],
    ], '');

    // The styled line inside the quote belongs to the quote, not the label
    // pass; nothing is removed and the quote survives intact.
    assert_eq([], $result['notes']);
    assert_contains('Second inner line', $result['markups'][1]);
});

test('malformed block structure is rejected instead of partially edited', function () {
    $malformed = dedupe_section('broken', dedupe_kicker('Open daily until late'))['markup']
        . '<!-- /wp:paragraph -->';
    $error = assert_throws(fn () => SectionCopyDedupe::dedupe([
        dedupe_section('first', dedupe_kicker('Open daily until late')),
        ['slug' => 'broken', 'markup' => $malformed],
    ], ''));

    assert_contains('malformed block structure', $error->getMessage());
});

test('exceeding the page safety cap preserves a fixed point and reports every residual', function () {
    $sections = [];
    foreach (range(1, 6) as $i) {
        $sections[] = dedupe_section("section-{$i}", dedupe_kicker('Open daily until late'));
    }
    $result = SectionCopyDedupe::dedupe($sections, '');

    assert_eq(0, $result['removed']);
    assert_eq([], $result['notes']);
    assert_eq(5, count($result['residuals']));
    assert_eq('section-2', $result['residuals'][0]['slug']);
    assert_eq('section-6', $result['residuals'][4]['slug']);
    assert_eq(array_column($sections, 'markup'), $result['markups'], 'page stays byte-for-byte authored');

    $again = SectionCopyDedupe::dedupe(
        array_map(
            static fn (array $section, string $markup): array => $section + ['markup' => $markup],
            $sections,
            $result['markups']
        ),
        ''
    );
    assert_eq($result, $again, 'cap degradation reaches a fixed point');
});
