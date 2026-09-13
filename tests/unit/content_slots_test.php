<?php
declare(strict_types=1);

use Automattic\SiteBuild\Patterns\ContentSlots;

function slots_pattern(string $className = ''): string
{
    $attrs = $className === '' ? '' : ' {"className":"' . $className . '"}';
    $class = $className === '' ? '' : ' ' . $className;

    return '<!-- wp:group {"style":{"spacing":{"padding":{"top":"2rem"}}}} -->
<div class="wp-block-group" style="padding-top:2rem"><!-- wp:heading' . $attrs . ' -->
<h2 class="wp-block-heading' . $class . '">Featured speakers</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Sixty talks across five tracks.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/register">Register</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->';
}

/** @param array<string, string> $answers */
function slots_fill(string $markup, array $answers): array
{
    return ContentSlots::in($markup, 'home')->fill($answers);
}

test('every block that carries text becomes a slot', function () {
    $slots = ContentSlots::in(slots_pattern(), 'home')->request();

    assert_eq(3, count($slots));
    assert_eq(['core/heading', 'core/paragraph', 'core/button'], array_column($slots, 'block'));
});

/**
 * Big Sky names content items `md5(uniqid(mt_rand()))`, so the same page gets
 * different ids on every run and no recorded response can be replayed against
 * the blocks it was generated for.
 */
test('the same markup produces the same slot ids every time', function () {
    $first = array_column(ContentSlots::in(slots_pattern(), 'home')->request(), 'id');
    $second = array_column(ContentSlots::in(slots_pattern(), 'home')->request(), 'id');

    assert_eq($first, $second);
    assert_eq(3, count(array_unique($first)));
});

test('a block the author marked ai-ignore is not a slot', function () {
    $slots = ContentSlots::in(slots_pattern('ai-ignore'), 'home')->request();

    assert_eq(['core/paragraph', 'core/button'], array_column($slots, 'block'));
});

test('a block a binding owns is not offered to the model', function () {
    $slots = ContentSlots::in(slots_pattern('ai-bind-address'), 'home')->request();

    assert_eq(['core/paragraph', 'core/button'], array_column($slots, 'block'));
});

/**
 * The whole reason this edits markup instead of rewriting blocks from their
 * attributes. Big Sky needs `class.block-inner-html-regenerator.php` — a
 * thousand lines rebuilding each block's saved HTML — to get back what it
 * overwrote. Nothing here overwrites it, so there is nothing to rebuild, and a
 * button keeps the href a regenerator would have to go and find again.
 */
test('filling a slot changes the text and nothing else', function () {
    $markup = slots_pattern();
    $slots = ContentSlots::in($markup, 'home');
    $answers = [];
    foreach ($slots->request() as $slot) {
        $answers[$slot['id']] = 'Replaced';
    }

    $filled = $slots->fill($answers)['markup'];

    assert_contains('href="/register"', $filled);
    assert_contains('style="padding-top:2rem"', $filled);
    assert_contains('class="wp-block-heading"', $filled);
    assert_contains('<!-- wp:group {"style":{"spacing":{"padding":{"top":"2rem"}}}} -->', $filled);
    assert_eq(
        preg_replace('~>[^<]*<~', '><', $markup),
        preg_replace('~>[^<]*<~', '><', $filled),
        'only text bytes moved',
    );
});

/**
 * Big Sky continues with the original content when generation fails, which is
 * the right call — an empty heading is worse than a placeholder one. What it
 * does not do is say so, and a page that kept every placeholder reports the
 * same success as one that was written.
 */
test('a slot with no answer keeps the pattern copy and is named', function () {
    $slots = ContentSlots::in(slots_pattern(), 'home');
    $ids = array_column($slots->request(), 'id');

    $outcome = $slots->fill([$ids[0] => 'Our speakers']);

    assert_eq(1, $outcome['filled']);
    assert_eq([$ids[1], $ids[2]], $outcome['kept']);
    assert_contains('Sixty talks across five tracks.', $outcome['markup']);
});

/**
 * The model returns text, and text lands in markup. An unescaped ampersand is
 * a page that renders wrong; an unescaped angle bracket is a page whose blocks
 * no longer parse.
 */
test('text from the model is escaped before it reaches the markup', function () {
    $slots = ContentSlots::in(slots_pattern(), 'home');
    $id = $slots->request()[0]['id'];

    $filled = $slots->fill([$id => 'Food & drink <script>'])['markup'];

    assert_contains('Food &amp; drink &lt;script&gt;', $filled);
    assert_eq(false, str_contains($filled, '<script>'), 'no raw markup from the model');
});

/**
 * The pattern's own copy is the measure of how much room there is, so a button
 * drawn for one word does not come back as a sentence.
 */
test('the length allowed comes from the copy the pattern shipped with', function () {
    $slots = ContentSlots::in(slots_pattern(), 'home')->request();

    assert_eq(2, $slots[0]['max_words']);
    assert_eq(5, $slots[1]['max_words']);
    assert_eq(1, $slots[2]['max_words']);
});

/**
 * `str_word_count()` is ASCII-only: it reads "Únete ahora al club" as five
 * words. A Spanish pattern measured that way is allowed copy longer than its
 * layout has room for.
 */
test('copy in a language with accents is measured by its real length', function () {
    assert_eq(4, ContentSlots::wordCount('Únete ahora al club'));
    assert_eq(2, ContentSlots::wordCount('Más información'));
});

/**
 * A heading with no copy of its own still has to fit, so it gets the floor
 * Big Sky gave it rather than no limit at all.
 */
test('a slot the pattern left empty still gets a length', function () {
    $empty = '<!-- wp:heading -->
<h2 class="wp-block-heading"></h2>
<!-- /wp:heading -->';

    assert_eq(3, ContentSlots::in($empty, 'home')->request()[0]['max_words']);
});

/**
 * Replacing a paragraph that holds a link drops the link. Big Sky does the
 * same — it is why `ai-ignore` exists — but silently, and a pattern author
 * reading the output is the one who finds out.
 */
test('a slot holding inline markup is reported when it is flattened', function () {
    $markup = '<!-- wp:paragraph -->
<p>Read the <a href="/faq">FAQ</a> first.</p>
<!-- /wp:paragraph -->';
    $slots = ContentSlots::in($markup, 'home');

    $outcome = $slots->fill([$slots->request()[0]['id'] => 'Plain copy']);

    assert_eq([$slots->request()[0]['id']], $outcome['flattened']);
    assert_eq(false, str_contains($outcome['markup'], '/faq'));
});

test('a void block holds no text and is not a slot', function () {
    assert_eq([], ContentSlots::in('<!-- wp:spacer {"height":"2rem"} /-->', 'home')->request());
});

/**
 * Slots in the same group describe one thing, and slots sharing the start of a
 * group sit near each other. Big Sky concatenates the numbers, so "11" is both
 * the eleventh group and the first inside the first.
 */
test('slots carry where they sit so copy in one place can cohere', function () {
    $markup = '<!-- wp:group -->
<div class="wp-block-group"><!-- wp:group -->
<div class="wp-block-group"><!-- wp:heading -->
<h2 class="wp-block-heading">Day one</h2>
<!-- /wp:heading --></div>
<!-- /wp:group --></div>
<!-- /wp:group -->';

    assert_eq('1.1', ContentSlots::in($markup, 'home')->request()[0]['group']);
});
