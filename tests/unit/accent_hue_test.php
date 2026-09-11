<?php
declare(strict_types=1);

use Automattic\SiteBuild\AccentHue;
use Automattic\SiteBuild\PaletteFloor;
use Automattic\SiteBuild\Steps\DesignDirectionStep;

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
    $gray = AccentHue::toFamily('#8A8A8A', $orange);
    assert_true(is_string($gray) && AccentHue::inFamily($gray, $orange), 'a neutral accent receives the requested hue');
});

test('a colour stated for the palette reaches the palette (frm PR-4y)', function () {
    // The accent reader only fires next to accent/button/cta/pill, so the
    // commonest phrasing — the colour as the palette's own — reached nothing.
    assert_eq('green', AccentHue::statedPaletteFamily('Use a deep forest green and warm cream palette')['word'] ?? null);
    assert_eq('cobalt', AccentHue::statedPaletteFamily('a palette of cobalt and bone')['word'] ?? null);
    assert_eq('orange', AccentHue::statedPaletteFamily('an orange colour palette')['word'] ?? null);
    assert_eq('terracotta', AccentHue::statedPaletteFamily('a colour scheme around terracotta')['word'] ?? null);

    // The colour word has to sit in the palette's own noun phrase. A business
    // that happens to be green is not a design instruction.
    assert_eq(null, AccentHue::statedPaletteFamily('green energy consultancy with a bold palette'));
    // An accent request is the other reader's, not this one's.
    assert_eq(null, AccentHue::statedPaletteFamily('a single orange accent on the buttons'));
    // A refusal states nothing.
    assert_eq(null, AccentHue::statedPaletteFamily('avoid a green palette'));
});

test('a stated palette colour moves the brand role and stays a fixed point (frm PR-4y)', function () {
    // The delivered palette from the review build for exactly this brief: no
    // green anywhere, and a red accent.
    $palette = [
        'base' => '#242017', 'contrast' => '#EFE6D4', 'primary' => '#EFE6D4',
        'secondary' => '#C6BCA6', 'accent' => '#C52810', 'band' => '#433C2B',
    ];
    $meta = ['prompt' => 'A ceramics studio. Use a deep forest green and warm cream palette.'];
    $repairs = [];
    $warnings = [];
    $out = DesignDirectionStep::withStatedDirection(['palette' => $palette], $meta, false, $repairs, $warnings);

    $family = AccentHue::statedPaletteFamily((string) $meta['prompt']);
    assert_true(AccentHue::inFamily($out['palette']['primary'], $family), 'the brand role carries the stated colour');
    assert_eq('#C52810', $out['palette']['accent'], 'a role the brief did not name is left alone');
    assert_eq('#242017', $out['palette']['base'], 'and so is the ground');
    assert_eq(1, count(array_filter($repairs, fn (string $r): bool => str_contains($r, 'palette.primary'))));

    // Idempotent: a role already in the family satisfies the brief.
    $again = [];
    assert_eq($out, DesignDirectionStep::withStatedDirection($out, $meta, false, $again, $warnings));
    assert_eq([], $again);

    // A palette that already carries the colour is not touched at all.
    $green = ['base' => '#242017', 'contrast' => '#EFE6D4', 'primary' => '#2F5D3A', 'accent' => '#C52810'];
    $none = [];
    assert_eq(
        $green,
        DesignDirectionStep::withStatedDirection(['palette' => $green], $meta, false, $none, $warnings)['palette'],
    );
    assert_eq([], $none);
});

