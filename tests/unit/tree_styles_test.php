<?php
declare(strict_types=1);

use Automattic\SiteBuild\TreeGraph\Styles;

test('Styles: rosters load with the expected shape', function () {
    $styles = Styles::load();
    assert_true(count($styles['artistic']) > 100, 'artistic roster is populated');
    assert_true(count($styles['ui']) > 100, 'ui roster is populated');
    assert_true(is_string($styles['artistic'][0]['name']));
    assert_true(is_array($styles['artistic'][0]['cues']));
});

test('Styles: normalize strips diacritics, case, and punctuation', function () {
    assert_eq('art deco', Styles::normalize('Art-Deco'));
    assert_eq('art deco', Styles::normalize('  art   deco  '));
    if (class_exists(Normalizer::class)) {
        assert_eq('sosaku hanga', Styles::normalize('Sōsaku-hanga'));
    }
});

test('Styles: seededShuffle is deterministic per seed and varies across seeds', function () {
    $items = array_map(static fn (int $i): string => "item-{$i}", range(1, 20));
    $a = Styles::seededShuffle($items, 'a cozy bakery:artistic');
    $b = Styles::seededShuffle($items, 'a cozy bakery:artistic');
    assert_eq($a, $b, 'same seed, same order');

    $permutation = $a;
    sort($permutation);
    $sorted = $items;
    sort($sorted);
    assert_eq($sorted, $permutation, 'a permutation, nothing lost');

    $c = Styles::seededShuffle($items, 'a cozy bakery:ui');
    $d = Styles::seededShuffle($items, 'a different prompt entirely:artistic');
    assert_true($a !== $c || $a !== $d, 'different seeds do not all collapse to one order');
});

test('Styles: matchPinnedStyles pins a named artistic style', function () {
    $pins = Styles::matchPinnedStyles('a bauhaus bakery in Lisbon');
    assert_eq('Bauhaus', $pins['artistic']);
    assert_eq(null, $pins['ui']);
    assert_eq(null, $pins['flexible']);
    assert_eq([], $pins['also_named']);
});

test('Styles: a longer name consumes its span so a shorter alias cannot re-match', function () {
    // "Art Deco" carries the alias "deco"; the two-word match must consume
    // the span so the alias does not fire again inside it.
    $pins = Styles::matchPinnedStyles('an art deco hotel bar');
    assert_eq('Art Deco', $pins['artistic']);
    assert_eq([], $pins['also_named'], 'the alias inside the consumed span never re-pins');
});

test('Styles: aliases pin their canonical entry', function () {
    $pins = Styles::matchPinnedStyles('a jugendstil florist');
    assert_eq('Art Nouveau', $pins['artistic']);
});

test('Styles: a second artistic mention lands in also_named', function () {
    $pins = Styles::matchPinnedStyles('bauhaus meets brutalist architecture');
    assert_eq('Bauhaus', $pins['artistic'], 'earliest mention wins the slot');
    assert_true(in_array('Brutalist', $pins['also_named'], true));
});

test('Styles: styleChecks rejects a name outside the roster and suggests near-matches', function () {
    $brief = ['style' => ['artistic' => 'Totally Invented Style', 'ui' => 'Flat Design', 'rationale' => 'x']];
    $issues = Styles::styleChecks($brief);
    assert_eq(1, count($issues));
    assert_eq('/style/artistic', $issues[0]['path']);
    assert_contains('is not in the artistic styles list', $issues[0]['message']);

    $near = ['style' => ['artistic' => 'art-deco', 'ui' => 'Flat Design', 'rationale' => 'x']];
    $issues = Styles::styleChecks($near);
    assert_eq(1, count($issues));
    assert_contains('write it exactly as "Art Deco"', $issues[0]['message']);
});

test('Styles: styleChecks enforces a pinned style', function () {
    $pins = Styles::matchPinnedStyles('a bauhaus bakery');
    $brief = ['style' => ['artistic' => 'Art Deco', 'ui' => 'Flat Design', 'rationale' => 'x']];
    $issues = Styles::styleChecks($brief, null, $pins);
    assert_eq(1, count($issues));
    assert_eq('/style/artistic', $issues[0]['path']);
    assert_contains('set in stone', $issues[0]['message']);
});

test('Styles: renderStyleNote is empty without a combo and cites cues with one', function () {
    assert_eq('', Styles::renderStyleNote(null));
    assert_eq('', Styles::renderStyleNote(['artistic' => 'Bauhaus']));

    $note = Styles::renderStyleNote([
        'artistic'  => 'Bauhaus',
        'ui'        => 'Flat Design',
        'rationale' => 'clarity',
    ]);
    assert_contains('THE STYLE COMBO', $note);
    assert_contains('- Artistic style: Bauhaus — palette:', $note);
    assert_contains('- UI design style: Flat Design — palette:', $note);
    assert_contains('- Why this combo: clarity', $note);
});

test('Styles: renderPinNote covers the free-choice and pinned wordings', function () {
    $free = Styles::renderPinNote(['artistic' => null, 'ui' => null, 'flexible' => null, 'also_named' => []]);
    assert_contains('names no style from either list', $free);

    $pinned = Styles::renderPinNote(['artistic' => 'Bauhaus', 'ui' => null, 'flexible' => null, 'also_named' => ['Brutalist']]);
    assert_contains('SET IN STONE', $pinned);
    assert_contains('Also mentioned, not binding', $pinned);
});
