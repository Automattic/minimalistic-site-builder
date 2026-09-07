<?php
declare(strict_types=1);

use Automattic\SiteBuild\AccentHue;
use Automattic\SiteBuild\PaletteFloor;

test('an accent hue the brief states is read in so many words (frm PR-4y)', function () {
    $orange = AccentHue::statedInBrief('Warm off-white page, a single orange accent on the buttons, a trusted-by logo row.');
    assert_eq('orange', $orange['word'] ?? null);
    assert_eq(28.0, $orange['hue'] ?? null);
    assert_eq('cobalt', AccentHue::statedInBrief('one cobalt accent for links')['word'] ?? null);
    assert_eq('red', AccentHue::statedInBrief('a red CTA on a dark hero')['word'] ?? null);
    assert_eq('violet', AccentHue::statedInBrief('violet pill buttons throughout')['word'] ?? null);
    assert_eq('green', AccentHue::statedInBrief('an accent of green on a white page')['word'] ?? null);
    assert_eq(null, AccentHue::statedInBrief('three service cards with one highlighted in violet'), 'a highlighted card names no accent');
    assert_eq(null, AccentHue::statedInBrief('a black pill CTA and a pastel blue highlight'), 'black is not a hue and the blue names no accent');
    assert_eq(null, AccentHue::statedInBrief('a three-line display headline with a red-to-cream gradient'), 'a gradient is not the accent');
    assert_eq(null, AccentHue::statedInBrief('Create a website for a Georgian restaurant in Tbilisi.'));
    assert_eq('orange', AccentHue::statedFor(['original_prompt' => 'one orange accent', 'prompt' => 'a landing page'])['word'] ?? null);
    assert_eq('cobalt', AccentHue::statedFor(['prompt' => 'cobalt buttons', 'original_prompt' => ''])['word'] ?? null);
    assert_eq(null, AccentHue::statedFor([]));
});

test('a hex is in a stated family by hue arc and chroma, and moves onto it at its own lightness (frm PR-4y)', function () {
    $orange = AccentHue::statedInBrief('one orange accent');
    assert_true(AccentHue::inFamily('#E2712A', $orange), 'the authored parley orange');
    assert_true(AccentHue::inFamily('#F2891E', $orange), 'the reference orange');
    assert_true(!AccentHue::inFamily('#E2C42A', $orange), 'the floor-rotated yellow');
    assert_true(!AccentHue::inFamily('#E8D35C', $orange), 'the seed yellow');
    assert_true(!AccentHue::inFamily('#8A8A8A', $orange), 'a grey has no hue');
    assert_true(!AccentHue::inFamily('nope', $orange));

    $red = AccentHue::statedInBrief('a red accent');
    assert_true(AccentHue::inFamily('#F94137', $red), 'the arc crosses zero: 4 degrees');
    assert_true(AccentHue::inFamily('#D5142A', $red), 'the arc crosses zero: 353 degrees');
    assert_true(!AccentHue::inFamily('#F2891E', $red));

    $moved = AccentHue::toFamily('#E2C42A', $orange);
    assert_true(is_string($moved) && AccentHue::inFamily($moved, $orange), 'the yellow moves into orange');
    assert_true(abs((PaletteFloor::hue($moved) ?? 0.0) - 28.0) < 2.0, 'onto the representative hue');
    assert_true(abs((PaletteFloor::luminance($moved) ?? 0.0) - (PaletteFloor::luminance('#E2C42A') ?? 0.0)) < 0.25, 'lightness held in HSL terms');
    assert_eq(null, AccentHue::toFamily('#8A8A8A', $orange), 'a grey has no hue to move');
});
