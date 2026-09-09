<?php
declare(strict_types=1);

use Automattic\SiteBuild\{AccentHue, BandTint, ColorEconomy, ConceptSeeds, GroundTint};

test('brief readers reject negative and unrelated choices', function () {
    assert_eq(null, AccentHue::statedInBrief('avoid orange buttons'));
    assert_eq(null, ColorEconomy::statedInBrief('avoid a monochrome look'));
    assert_eq('warm', GroundTint::statedInBrief('not a white page, a cream one'));
    assert_eq(null, BandTint::statedInBrief('avoid blue panels'));
    assert_eq(null, BandTint::statedInBrief('a headline with a blue gradient'));
    assert_eq(null, ColorEconomy::statedInBrief('a white page with one blue button in the hero'));
    assert_eq('single-accent', ColorEconomy::statedInBrief('a single colour accent on the buttons'));
    assert_eq('grotesque', ConceptSeeds::statedTypeRegister('clean sans serif headings'));
    assert_eq('transitional', ConceptSeeds::statedTypeRegister('bold sans body copy with classic serif headings'));
});

test('a hero wordmark has its own case and leaves other headings unchanged', function () {
    $markup = '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Studio</h1><!-- /wp:heading -->'
        . '<!-- wp:heading --><h2 class="wp-block-heading">Our work</h2><!-- /wp:heading -->';
    $repairs = [];
    $out = \Automattic\SiteBuild\Units\GeneratedMarkup::withHeroWordmarkCase($markup, 'uppercase', 'hero', $repairs);
    assert_contains('hero-wordmark--upper', $out);
    assert_contains('<h2 class="wp-block-heading">Our work</h2>', $out);
    assert_eq($out, \Automattic\SiteBuild\Units\GeneratedMarkup::withHeroWordmarkCase($out, 'uppercase', 'hero', $repairs));
});

test('a stated band tint preserves the authored band through direction normalization', function () {
    $band = BandTint::apply('#FFFFFF', 'cool');
    $repairs = [];
    $direction = \Automattic\SiteBuild\Steps\DesignDirectionStep::normalize(
        ['description' => 'x', 'palette' => ['base' => '#FFFFFF', 'band' => $band]],
        repairs: $repairs, statedBandTint: 'cool',
    );
    assert_eq($band, $direction['palette']['band']);
    assert_true(!str_contains(implode(' ', $repairs), 'palette.band'));
});
