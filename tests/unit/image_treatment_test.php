<?php
declare(strict_types=1);

use Automattic\SiteBuild\ImageTreatment;

test('ImageTreatment owns one palette-derived duotone preset and natural removes it', function () {
    $theme = ['settings' => ['color' => [
        'palette' => [
            ['slug' => 'base', 'name' => 'Base', 'color' => '#151515'],
            ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#F4F0E8'],
            ['slug' => 'primary', 'name' => 'Primary', 'color' => '#B43C67'],
        ],
        'duotone' => [['slug' => 'model-authored', 'colors' => ['#000000', '#FFFFFF']]],
    ]]];

    $duotone = ImageTreatment::applyThemeJson($theme, 'duotone');
    $presets = $duotone['settings']['color']['duotone'];
    assert_eq(1, count($presets));
    assert_eq(ImageTreatment::PRESET_SLUG, $presets[0]['slug']);
    assert_eq(['#151515', '#F4F0E8'], $presets[0]['colors'], 'dark theme colors stay shadow-to-highlight');

    $lightTheme = $theme;
    $lightTheme['settings']['color']['palette'][0]['color'] = '#F4F0E8';
    $lightTheme['settings']['color']['palette'][1]['color'] = '#151515';
    $lightPreset = ImageTreatment::applyThemeJson($lightTheme, 'duotone');
    assert_eq(['#151515', '#F4F0E8'], $lightPreset['settings']['color']['duotone'][0]['colors']);

    foreach (['natural', 'tinted-overlay', 'high-key-bw', 'garbled'] as $treatment) {
        $untreated = ImageTreatment::applyThemeJson($theme, $treatment);
        assert_true(!isset($untreated['settings']['color']['duotone']), "{$treatment} removes duotone catalog");
    }
});

test('ImageTreatment emits bounded palette tint and high-key kits only', function () {
    $tint = ImageTreatment::kitCss('tinted-overlay', [
        'base' => '#D5DCE0',
        'primary' => '#8C3B2A',
    ]);
    assert_contains('background: #8C3B2A', $tint);
    assert_contains('opacity: 0.14', $tint);
    assert_contains('figure.card-media', $tint);
    assert_contains('.wp-block-cover > .wp-block-cover__background::after', $tint);
    assert_contains('z-index: 2', $tint, 'cover copy/captions stay above the tint');

    $highKey = ImageTreatment::kitCss('high-key-bw');
    assert_contains('grayscale(1)', $highKey);
    assert_contains('brightness(1.12)', $highKey);
    assert_contains('.wp-block-cover__image-background', $highKey);
    assert_contains('!important', $highKey);

    assert_eq(null, ImageTreatment::kitCss('natural'));
    assert_eq(null, ImageTreatment::kitCss('unknown'));
});

test('ImageTreatment duotone kit covers only the unsupported media-text half through the preset property', function () {
    $css = ImageTreatment::kitCss('duotone');
    assert_contains('.wp-block-media-text__media img', $css);
    assert_contains('var(--wp--preset--duotone--' . ImageTreatment::PRESET_SLUG . ', none)', $css);
    assert_true(!str_contains($css, '.wp-block-image'), 'image blocks stay on Core block support');
    assert_true(!str_contains($css, '.wp-block-cover'), 'cover blocks stay on Core block support');
    assert_true(!str_contains($css, 'grayscale'), 'no CSS approximation of the duotone map');
});
