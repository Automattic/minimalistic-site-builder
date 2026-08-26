<?php
declare(strict_types=1);

use Automattic\SiteBuild\ImageCrop;

test('ImageCrop emits one deterministic ratio map per uniform commitment', function () {
    $expected = [
        'landscape' => ['3 / 2', '4 / 3', '4 / 3', '16 / 9'],
        'portrait' => ['4 / 5', '2 / 3', '3 / 4', '4 / 5'],
        'square' => ['1 / 1', '1 / 1', '1 / 1', '1 / 1'],
        'panoramic' => ['16 / 9', '3 / 2', '16 / 9', '21 / 9'],
    ];

    foreach ($expected as $crop => $ratios) {
        $css = ImageCrop::kitCss($crop);
        assert_contains("Committed '{$crop}' image crop", $css);
        foreach ($ratios as $ratio) {
            assert_contains("aspect-ratio: {$ratio}", $css, "{$crop} carries {$ratio}");
        }
        assert_contains('.feature-media img', $css);
        assert_contains('.list-thumb-flush .card-media-thumb img', $css);
        assert_contains('aspect-ratio: auto !important', $css, 'flush list rows remain text-height driven');
    }
    assert_eq(null, ImageCrop::kitCss('mixed'));
    assert_eq(null, ImageCrop::kitCss('unknown'));
});

test('ImageCrop aligns generated source ratios with the committed target role', function () {
    assert_eq('3:2', ImageCrop::generationRatio('landscape', 'square', 'product card in a grid'));
    assert_eq('4:3', ImageCrop::generationRatio('landscape', 'square', 'small row thumbnail'));
    assert_eq('4:3', ImageCrop::generationRatio('landscape', 'square', 'dominant card'));
    assert_eq('16:9', ImageCrop::generationRatio('landscape', 'square', 'wide feature band'));
    assert_eq('4:5', ImageCrop::generationRatio('portrait', 'landscape', 'team member card'));
    assert_eq('3:4', ImageCrop::generationRatio('portrait', 'landscape', 'small row thumbnail'));
    assert_eq('2:3', ImageCrop::generationRatio('portrait', 'landscape', 'dominant card'));
    assert_eq('1:1', ImageCrop::generationRatio('square', 'card-portrait', 'gallery tile'));
    assert_eq('16:9', ImageCrop::generationRatio('panoramic', 'square', 'festival card'));
    assert_eq('3:2', ImageCrop::generationRatio('panoramic', 'square', 'dominant card'));
    assert_eq('21:9', ImageCrop::generationRatio('panoramic', 'landscape', 'wide feature band'));
    assert_eq('21:9', ImageCrop::generationRatio('panoramic', 'landscape', 'full-bleed hero background'));
    assert_eq('16:9', ImageCrop::generationRatio('portrait', 'portrait', 'full-bleed hero background'));
    assert_eq('3:4', ImageCrop::generationRatio('mixed', 'card-portrait', 'team card'));
    assert_eq('16:9', ImageCrop::generationRatio(null, 'landscape', 'feature band'));
});

test('ImageCrop keeps every named background wide under a tall commitment', function () {
    // The documented example context for a viewport-spanning band. Before the
    // fix this matched only the feature words, and portrait/square requested
    // a tall or square source canvas for a wide cover slot.
    assert_eq('16:9', ImageCrop::generationRatio('portrait', 'landscape', 'background of a call-to-action band'));
    assert_eq('16:9', ImageCrop::generationRatio('square', 'landscape', 'background of a call-to-action band'));
    assert_eq('21:9', ImageCrop::generationRatio('panoramic', 'landscape', 'background of a call-to-action band'));

    // A normal contained card context still follows the committed system.
    assert_eq('4:5', ImageCrop::generationRatio('portrait', 'landscape', 'ambiance card in a dining room grid'));
    assert_eq('1:1', ImageCrop::generationRatio('square', 'landscape', 'ambiance card in a dining room grid'));
});

test('ImageCrop prompt guidance protects focal content without restating mixed', function () {
    foreach (['landscape', 'portrait', 'square', 'panoramic'] as $crop) {
        assert_contains('Site-wide crop direction:', ImageCrop::promptClause($crop));
    }
    assert_eq('', ImageCrop::promptClause('mixed'));
    assert_eq('', ImageCrop::promptClause('garbled'));
});
