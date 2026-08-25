<?php
declare(strict_types=1);

use Automattic\SiteBuild\ContrastMath;
use Automattic\SiteBuild\GroundKey;
use Automattic\SiteBuild\GroundTint;

test('GroundTint::classify names the hue family a ground leans toward', function () {
    assert_eq('warm', GroundTint::classify('#F4EBDA'), 'the modal generated cream');
    assert_eq('cool', GroundTint::classify('#E8EDF5'), 'pale blue');
    assert_eq('violet', GroundTint::classify('#EDE7F4'), 'pale lilac');
    assert_eq('green', GroundTint::classify('#E7F0E8'), 'pale sage');
    assert_eq('blush', GroundTint::classify('#F7E9EA'), 'pale pink');
});

test('GroundTint::classify reads a dark ground by the same families', function () {
    // Tint is orthogonal to light/dark: a dark page still leans somewhere.
    assert_eq('cool', GroundTint::classify('#1B2233'), 'dark ink blue');
    assert_eq('violet', GroundTint::classify('#2E1B33'), 'dark aubergine');
});

test('GroundTint::classify calls a ground with almost no chroma neutral', function () {
    // HSL saturation is inflated near white — #F7F6F3 reports 0.25 there but
    // is 4/255 off grey. Measured on real builds, seven "beige" bases were
    // this faint. A near-white must not be corrected as though it were cream.
    assert_eq('neutral', GroundTint::classify('#F7F6F3'), 'warm-white, chroma 0.016');
    assert_eq('neutral', GroundTint::classify('#FFFFFF'));
    assert_eq('neutral', GroundTint::classify('#F2F2F2'));
    assert_eq('neutral', GroundTint::classify('#1A1A1A'), 'near-black');
});

test('GroundTint::classify rejects what is not a hex color', function () {
    assert_eq(null, GroundTint::classify('rebeccapurple'));
    assert_eq(null, GroundTint::classify(''));
    assert_eq(null, GroundTint::classify('#GGG'));
});

test('GroundTint::retint moves a ground into the committed family', function () {
    $cool = GroundTint::retint('#F4EBDA', 'cool');
    assert_eq('cool', GroundTint::classify($cool), 'the modal cream becomes a cool ground');

    $violet = GroundTint::retint('#F4EBDA', 'violet');
    assert_eq('violet', GroundTint::classify($violet), 'and purple is reachable, not banned');
});

test('GroundTint::retint preserves relative luminance, so contrast does not move', function () {
    // Hue carries luminance: yellow and blue at one HSL lightness differ
    // enormously. A naive rotation would silently move every ratio the
    // contrast pipeline downstream depends on.
    $before = ContrastMath::luminance(ContrastMath::hexToRgb('#F4EBDA'));
    foreach (['cool', 'violet', 'green', 'blush'] as $tint) {
        $after = ContrastMath::luminance(ContrastMath::hexToRgb(GroundTint::retint('#F4EBDA', $tint)));
        assert_true(
            abs($before - $after) < 0.005,
            "retint to {$tint} moved luminance {$before} -> {$after}",
        );
    }
});

test('GroundTint::retint holds the body-text contrast ratio within a hair', function () {
    $ink = ContrastMath::hexToRgb('#111111');
    $before = ContrastMath::ratio(ContrastMath::hexToRgb('#F4EBDA'), $ink);
    $after = ContrastMath::ratio(ContrastMath::hexToRgb(GroundTint::retint('#F4EBDA', 'cool')), $ink);
    assert_true(abs($before - $after) < 0.1, "body contrast moved {$before} -> {$after}");
});

test('GroundTint::retint leaves a ground already in its family untouched', function () {
    // Earned warmth ships unchanged: a bakery that committed to cream keeps it.
    assert_eq('#F4EBDA', GroundTint::retint('#F4EBDA', 'warm'));
});

test('GroundTint::retint to neutral strips the tint instead of rotating it', function () {
    assert_eq('neutral', GroundTint::classify(GroundTint::retint('#F4EBDA', 'neutral')));
});

test('GroundTint::retint keeps a dark ground dark', function () {
    $out = GroundTint::retint('#2E1B33', 'cool');
    assert_eq('cool', GroundTint::classify($out));
    assert_true(
        ContrastMath::luminance(ContrastMath::hexToRgb($out)) < 0.05,
        'a dark aubergine must not become a pale blue',
    );
});

test('GroundTint::retint refuses a hex or family it cannot honor', function () {
    assert_eq(null, GroundTint::retint('nope', 'cool'));
    assert_eq(null, GroundTint::retint('#F4EBDA', 'chartreuse'));
});

test('GroundTint::retint degrades impossible luminance extremes without crashing', function () {
    // A hue cannot be visible at zero or full luminance. These generated
    // endpoints remain neutral instead of taking the build down while the
    // light/dark key and tint checks execute together.
    assert_eq(null, GroundTint::retint('#000000', 'warm'));
    assert_eq(null, GroundTint::retint('#FFFFFF', 'cool'));
});

test('GroundTint::retint preserves the shared light-dark coordinate at its rounding boundary', function () {
    $retinted = GroundTint::retint('#0098E0', 'green');

    assert_eq('green', GroundTint::classify((string) $retinted));
    assert_eq('dark', GroundKey::classify((string) $retinted));
});
