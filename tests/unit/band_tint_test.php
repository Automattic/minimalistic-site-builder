<?php
declare(strict_types=1);

use Automattic\SiteBuild\BandColor;
use Automattic\SiteBuild\BandTint;
use Automattic\SiteBuild\GroundTint;
use Automattic\SiteBuild\Steps\DesignDirectionStep;
use Automattic\SiteBuild\Steps\ThemeJsonStep;

test('BandTint reads a colour word beside a surface noun and nothing else (frm PR-2ag)', function () {
    // zova: the panel hero names its colour; the page stays white.
    $zova = 'Create a clean SaaS landing page for a finance analytics product for small teams. White page, geometric sans type, with a pale blue gradient panel hero, dashboard mockup, four-column feature row separated by hairlines.';
    assert_eq(['word' => 'blue', 'tint' => 'cool'], BandTint::statedInBrief($zova));
    assert_eq(['word' => 'lavender', 'tint' => 'violet'], BandTint::statedInBrief('client quotes on a soft lavender band'));
    assert_eq(['word' => 'sage', 'tint' => 'green'], BandTint::statedInBrief('services on sage panels'));
    assert_eq(['word' => 'cream', 'tint' => 'warm'], BandTint::statedInBrief('a cream-tinted plate behind the hero'));
    // A colour on the buttons is the accent reader's; a page colour is the page tint reader's; black and white name no tint.
    assert_true(BandTint::statedInBrief('blue buttons on a white page') === null, 'buttons are not a band');
    assert_true(BandTint::statedInBrief('a cool white page with a black panel hero') === null, 'black names no tint family');
    assert_true(BandTint::statedInBrief('Dark hero, tight geometric sans type throughout.') === null);
    assert_true(BandTint::statedInBrief('a blue-white page') === null, 'a page word is not a surface noun');
    // The user's own words win over the refined brief.
    assert_eq('cool', BandTint::statedFor(['original_prompt' => 'a pale blue panel hero', 'prompt' => 'a lavender band'])['tint'] ?? null);
    assert_true(BandTint::statedFor(['prompt' => 'a white page']) === null);
});

test('BandTint::apply moves the derived band into the stated family with readable chroma and a valid lightness delta', function () {
    foreach ([['#FFFFFF', 'cool'], ['#F7F7F5', 'violet'], ['#1A1D21', 'cool'], ['#F4EBDA', 'blush'], ['#17181A', 'green']] as [$base, $tint]) {
        $band = BandTint::apply($base, $tint);
        assert_true(is_string($band), "{$base} {$tint} produces a band");
        assert_eq($tint, GroundTint::classify((string) $band), "{$base} band {$band} sits in {$tint}");
        assert_true(BandColor::valid($base, (string) $band, $tint), "{$base} and {$band} satisfy the band contract for a stated {$tint}");
        $rgb = \Automattic\SiteBuild\ContrastMath::hexToRgb((string) $band);
        assert_true($rgb !== null && GroundTint::chromaOf($rgb) >= 0.08, "{$band} reads as a colour, not a whisper");
        assert_true(abs(abs((float) BandColor::lightness($base) - (float) BandColor::lightness((string) $band)) - 0.10) < 0.02, 'ten lightness points from the base');
    }
    assert_true(!BandColor::valid('#FFFFFF', (string) BandTint::apply('#FFFFFF', 'cool')), 'on a white page the blue band fails the same-family contract, which is why the stated family is passed through');
    assert_true(BandTint::apply('#FFFFFF', 'neutral') === null, 'a neutral tint is the derived band, not a stated one');
    assert_true(BandTint::apply('nope', 'cool') === null);
});

test('a stated band colour moves the direction band and survives the theme palette repair (frm PR-2ag)', function () {
    $meta = ['prompt' => 'White page, geometric sans type, with a pale blue gradient panel hero and a dashboard mockup.'];
    $direction = [
        'palette' => ['base' => '#FFFFFF', 'contrast' => '#1A1D21', 'primary' => '#2F5D8C', 'secondary' => '#6C7783', 'accent' => '#241CE0', 'band' => '#E6E6E6'],
    ];
    $repairs = [];
    $out = DesignDirectionStep::withStatedDirection($direction, $meta, false, $repairs);
    $band = $out['palette']['band'];
    assert_eq('cool', GroundTint::classify($band), 'the band moves into the stated family: ' . $band);
    assert_true(count(array_filter($repairs, static fn (string $r): bool => str_contains($r, 'palette.band') && str_contains($r, 'names its band colour (blue)'))) === 1, implode("\n", $repairs));
    // Idempotent: a band already in the family is left alone.
    $again = [];
    $twice = DesignDirectionStep::withStatedDirection($out, $meta, false, $again);
    assert_eq($band, $twice['palette']['band']);
    assert_true(array_filter($again, static fn (string $r): bool => str_contains($r, 'palette.band')) === []);
    // Without the stated words the grey band stands.
    $plain = [];
    $none = DesignDirectionStep::withStatedDirection($direction, ['prompt' => 'White page with a dashboard mockup.'], false, $plain);
    assert_eq('#E6E6E6', $none['palette']['band']);

    // The theme palette repair keeps the stated band; unstated, the same band is replaced by the grey one.
    $theme = ['settings' => ['color' => ['palette' => [
        ['slug' => 'base', 'color' => '#FFFFFF', 'name' => 'Base'],
        ['slug' => 'contrast', 'color' => '#1A1D21', 'name' => 'Contrast'],
        ['slug' => 'primary', 'color' => '#2F5D8C', 'name' => 'Primary'],
        ['slug' => 'secondary', 'color' => '#6C7783', 'name' => 'Secondary'],
        ['slug' => 'accent', 'color' => '#241CE0', 'name' => 'Accent'],
        ['slug' => 'band', 'color' => $band, 'name' => 'Band'],
    ]]]];
    [$kept] = ThemeJsonStep::repairColors($theme, $out['palette'], 'cool');
    $keptBand = array_values(array_filter($kept['settings']['color']['palette'], static fn (array $e): bool => $e['slug'] === 'band'))[0]['color'];
    assert_eq($band, $keptBand, 'the stated band survives the palette repair');
    [$reset] = ThemeJsonStep::repairColors($theme, $out['palette']);
    $resetBand = array_values(array_filter($reset['settings']['color']['palette'], static fn (array $e): bool => $e['slug'] === 'band'))[0]['color'];
    assert_eq('neutral', GroundTint::classify($resetBand), 'without the stated family the same-family contract replaces a blue band on a white page');
});
