<?php
declare(strict_types=1);

use Automattic\SiteBuild\ImageBorderTrim;

/**
 * ImageBorderTrim removes a painted photo-print border from generated JPEG
 * assets (BIGR-956). These tests build tiny fixtures with Imagick and are
 * skipped when the extension is not loaded, like the transparency tests.
 */

/**
 * JPEG bytes: a checkerboard scene (per-line deviation well above the flat
 * threshold, mid brightness) with optional painted borders around it.
 */
function border_fixture(int $size = 200, int $whiteBorder = 0, int $blackKeyline = 0): string
{
    $im = new Imagick();
    $im->newPseudoImage($size, $size, 'pattern:checkerboard');
    $im->transformImageColorspace(Imagick::COLORSPACE_SRGB);
    if ($blackKeyline > 0) {
        $im->borderImage(new ImagickPixel('black'), $blackKeyline, $blackKeyline);
    }
    if ($whiteBorder > 0) {
        $im->borderImage(new ImagickPixel('white'), $whiteBorder, $whiteBorder);
    }
    $im->setImageFormat('jpeg');
    return $im->getImageBlob();
}

/** [width, height] of image bytes. */
function border_size(string $bytes): array
{
    $im = new Imagick();
    $im->readImageBlob($bytes);
    return [$im->getImageWidth(), $im->getImageHeight()];
}

if (!ImageBorderTrim::available()) {
    test('image-border-trim tests skipped (imagick not loaded)', function () {});
    return;
}

test('trimPaintedBorder crops a white print border on all four sides', function () {
    $result = ImageBorderTrim::trimPaintedBorder(border_fixture(200, 12));

    assert_true($result['trimmed'] >= 12, 'reports at least the border width');
    [$w, $h] = border_size($result['bytes']);
    assert_true($w <= 200 && $h <= 200, 'the painted border is gone');
    assert_true($w >= 170 && $h >= 170, 'the scene itself survives');
});

test('trimPaintedBorder also removes the inner dark keyline', function () {
    // The shipped tbilisi khachapuri carried a near-black keyline between the
    // white band and the scene; the trim must not stop at the white run.
    $result = ImageBorderTrim::trimPaintedBorder(border_fixture(200, 12, 3));

    assert_true($result['trimmed'] >= 15, 'white band plus keyline removed');
    $im = new Imagick();
    $im->readImageBlob($result['bytes']);
    $im->transformImageColorspace(Imagick::COLORSPACE_GRAY);
    $edge = clone $im;
    $edge->cropImage($im->getImageWidth(), 1, 0, 0);
    $mean = $edge->getImageChannelMean(Imagick::CHANNEL_GRAY)['mean'] / Imagick::getQuantum();
    assert_true($mean > 0.2 && $mean < 0.8, 'the delivered edge is scene, not border');
});

test('trimPaintedBorder leaves a borderless scene byte-identical', function () {
    $bytes = border_fixture(200);
    $result = ImageBorderTrim::trimPaintedBorder($bytes);

    assert_eq(0, $result['trimmed']);
    assert_true($result['bytes'] === $bytes, 'clean bytes pass through unchanged');
});

test('trimPaintedBorder leaves a bright flat scene alone', function () {
    // A catalog-style subject on seamless white is bright and flat out to
    // every edge, but it surrounds more of itself, not a different picture:
    // the interior-contrast requirement must hold the trim back.
    $im = new Imagick();
    $im->newImage(200, 200, new ImagickPixel('white'));
    $draw = new ImagickDraw();
    $draw->setFillColor(new ImagickPixel('rgb(235,235,235)'));
    $draw->rectangle(80, 80, 120, 120);
    $im->drawImage($draw);
    $im->setImageFormat('jpeg');
    $bytes = $im->getImageBlob();

    $result = ImageBorderTrim::trimPaintedBorder($bytes);
    assert_eq(0, $result['trimmed']);
    assert_true($result['bytes'] === $bytes, 'a legitimately bright scene is not cropped');
});

test('trimPaintedBorder ignores a border on fewer than four sides', function () {
    // A real scene can hold a bright flat band on one edge (sky, a wall); a
    // print border surrounds the picture or it is not a border.
    $im = new Imagick();
    $im->newPseudoImage(200, 200, 'pattern:checkerboard');
    $im->transformImageColorspace(Imagick::COLORSPACE_SRGB);
    $draw = new ImagickDraw();
    $draw->setFillColor(new ImagickPixel('white'));
    $draw->rectangle(0, 0, 199, 14);
    $im->drawImage($draw);
    $im->setImageFormat('jpeg');
    $bytes = $im->getImageBlob();

    $result = ImageBorderTrim::trimPaintedBorder($bytes);
    assert_eq(0, $result['trimmed']);
});

test('trimPaintedBorder fails soft on bytes that do not decode', function () {
    $result = ImageBorderTrim::trimPaintedBorder('not an image');

    assert_eq(0, $result['trimmed']);
    assert_eq('not an image', $result['bytes']);
});
