<?php
declare(strict_types=1);

use Automattic\SiteBuild\ImageTransparency;

/**
 * ImageTransparency keys the flat solid background the image model was prompted to
 * render (it cannot produce real alpha) out to PNG transparency: a flood fill
 * inward from the image border, then an unconditional global key pass so
 * background-colored pockets enclosed by the subject go too, then a trim of
 * the transparent margins down to the ink (plus a small pad). It fails soft —
 * undecodable bytes or a border fill that would erase the whole image return
 * the input unchanged.
 *
 * These tests build tiny fixtures with Imagick and are skipped (registered as
 * trivial passes) when the extension is missing, matching keyOutBackground's
 * own no-imagick behavior.
 */

/** PNG bytes: a $w x $h canvas of $bg with a centered 1/3-size $fg rectangle. */
function transparency_fixture(string $bg, string $fg, int $w = 60, int $h = 60): string
{
    $im = new Imagick();
    $im->newImage($w, $h, new ImagickPixel($bg));
    $draw = new ImagickDraw();
    $draw->setFillColor(new ImagickPixel($fg));
    $draw->rectangle($w / 3, $h / 3, 2 * $w / 3, 2 * $h / 3);
    $im->drawImage($draw);
    $im->setImageFormat('png');
    return $im->getImageBlob();
}

/** The alpha (0..1) of the pixel at ($x, $y) in PNG bytes. */
function alpha_at(string $pngBytes, int $x, int $y): float
{
    $im = new Imagick();
    $im->readImageBlob($pngBytes);
    return $im->getImagePixelColor($x, $y)->getColorValue(Imagick::COLOR_ALPHA);
}

/** [r, g, b] of the pixel at ($x, $y), each 0..1. */
function rgb_at(string $pngBytes, int $x, int $y): array
{
    $im = new Imagick();
    $im->readImageBlob($pngBytes);
    $px = $im->getImagePixelColor($x, $y);
    return [
        $px->getColorValue(Imagick::COLOR_RED),
        $px->getColorValue(Imagick::COLOR_GREEN),
        $px->getColorValue(Imagick::COLOR_BLUE),
    ];
}

/** [width, height] of PNG bytes. */
function png_size(string $pngBytes): array
{
    $im = new Imagick();
    $im->readImageBlob($pngBytes);
    return [$im->getImageWidth(), $im->getImageHeight()];
}

if (!ImageTransparency::available()) {
    test('image-transparency tests skipped (imagick not loaded)', function () {});
    return;
}

test('keyOutBackground makes the white background transparent, keeps the subject', function () {
    $out = ImageTransparency::keyOutBackground(transparency_fixture('white', 'red'));

    [$w, $h] = png_size($out);
    assert_true(alpha_at($out, 0, 0) < 0.01, 'corner background is transparent');
    assert_true(alpha_at($out, $w - 1, $h - 1) < 0.01, 'opposite corner is transparent');
    assert_true(alpha_at($out, intdiv($w, 2), intdiv($h, 2)) > 0.99, 'subject center stays opaque');
});

test('keyOutBackground keys an off-white background via the fuzz', function () {
    // The model renders "pure white" with slight warmth/noise; the fuzz must
    // absorb that.
    $out = ImageTransparency::keyOutBackground(transparency_fixture('rgb(250,247,242)', 'rgb(120,40,20)'));

    [$w, $h] = png_size($out);
    assert_true(alpha_at($out, 0, 0) < 0.01, 'off-white background is keyed');
    assert_true(alpha_at($out, intdiv($w, 2), intdiv($h, 2)) > 0.99, 'dark subject stays opaque');
});

test('keyOutBackground keys background pockets enclosed by the subject', function () {
    // A small white pocket INSIDE the red rectangle is not border-connected —
    // the flood fill alone cannot reach it (the closed-curl case of a
    // flourish); the global key pass must strip it.
    $im = new Imagick();
    $im->readImageBlob(transparency_fixture('white', 'red'));
    $draw = new ImagickDraw();
    $draw->setFillColor(new ImagickPixel('white'));
    $draw->rectangle(29, 29, 31, 31);
    $im->drawImage($draw);
    $im->setImageFormat('png');

    $out = ImageTransparency::keyOutBackground($im->getImageBlob());

    // The subject is the centered 20..40 square; after trim the pocket sits at
    // the trimmed image's center and the ink just inside its pad.
    [$w, $h] = png_size($out);
    assert_true(alpha_at($out, 0, 0) < 0.01, 'background is transparent');
    assert_true(alpha_at($out, intdiv($w, 2), intdiv($h, 2)) < 0.01, 'enclosed white pocket is keyed');
    assert_true(alpha_at($out, intdiv($w, 2) - 5, intdiv($h, 2) - 5) > 0.99, 'subject stays opaque');
});

test('keyOutBackground keys pockets that outweigh the ink itself', function () {
    // A thin red frame whose interior is white — the shape of real line-art
    // flourishes, where enclosed background is most of the subject's bounding
    // area. The global pass must still key the interior (issue #77: ALL
    // background goes, no matter how large the enclosed region is).
    $im = new Imagick();
    $im->newImage(60, 60, new ImagickPixel('white'));
    $draw = new ImagickDraw();
    $draw->setFillColor(new ImagickPixel('red'));
    $draw->rectangle(10, 10, 50, 50);
    $draw->setFillColor(new ImagickPixel('white'));
    $draw->rectangle(14, 14, 46, 46);
    $im->drawImage($draw);
    $im->setImageFormat('png');

    $out = ImageTransparency::keyOutBackground($im->getImageBlob());

    [$w, $h] = png_size($out);
    $pad = max(2, intdiv(max($w, $h), 50));
    assert_true(alpha_at($out, 0, 0) < 0.01, 'border background is transparent');
    assert_true(alpha_at($out, $pad + 2, $pad + 2) > 0.99, 'frame ink stays opaque');
    assert_true(alpha_at($out, intdiv($w, 2), intdiv($h, 2)) < 0.01, 'large enclosed interior is keyed');
});

test('keyOutBackground keys a hairline divider instead of bailing', function () {
    // The thinnest real asset class: a 2px hairline rule across a large white
    // canvas (~0.5% ink). The erase-everything guard must NOT trip on it —
    // that was shipping dividers with their white background baked in.
    $im = new Imagick();
    $im->newImage(400, 200, new ImagickPixel('white'));
    $draw = new ImagickDraw();
    $draw->setFillColor(new ImagickPixel('rgb(193,94,60)'));
    $draw->rectangle(20, 99, 379, 100);
    $im->drawImage($draw);
    $im->setImageFormat('png');

    $out = ImageTransparency::keyOutBackground($im->getImageBlob());

    [$w, $h] = png_size($out);
    assert_true($h < 100, 'canvas is trimmed to the hairline');
    assert_true(alpha_at($out, intdiv($w, 2), intdiv($h, 2)) > 0.99, 'hairline ink survives');
    assert_true(alpha_at($out, 0, 0) < 0.01, 'background around the hairline is keyed');
});

test('keyOutBackground trims the empty margin around a small ornament', function () {
    // A small motif centered on a big canvas: the keyed asset must not keep
    // the full canvas, or the page reserves a huge blank band for it.
    $im = new Imagick();
    $im->newImage(300, 300, new ImagickPixel('white'));
    $draw = new ImagickDraw();
    $draw->setFillColor(new ImagickPixel('red'));
    $draw->rectangle(140, 140, 160, 160);
    $im->drawImage($draw);
    $im->setImageFormat('png');

    $out = ImageTransparency::keyOutBackground($im->getImageBlob());

    [$w, $h] = png_size($out);
    assert_true($w < 40 && $h < 40, 'canvas shrinks to the ink plus a small pad');
    assert_true(alpha_at($out, intdiv($w, 2), intdiv($h, 2)) > 0.99, 'ornament stays opaque');
});

test('keyOutBackground unmattes anti-aliased edge pixels instead of keeping them opaque', function () {
    if (!ImageTransparency::canUnmatteEdges()) {
        // ImageMagick 6 keeps the hard-keyed edges by design (see
        // ImageTransparency::canUnmatteEdges) — there is no translucency to
        // assert, and the surrounding keying is covered by the tests above.
        skip_test('edge unmatting needs ImageMagick 7');
    }
    // A pixel that blends ink 50/50 with the white background — the
    // anti-aliased edge the model renders. The binary key passes leave it fully
    // opaque with the white baked in (a white fringe on dark pages); the
    // unmatting pass must turn the white share into translucency and divide
    // the contamination out of the color. Solid ink must stay untouched.
    $im = new Imagick();
    $im->newImage(60, 60, new ImagickPixel('white'));
    $draw = new ImagickDraw();
    $draw->setFillColor(new ImagickPixel('red'));
    $draw->rectangle(20, 20, 40, 40);
    $draw->setFillColor(new ImagickPixel('rgb(255,128,128)')); // 50% red on white
    $draw->rectangle(19, 20, 19, 40);
    $im->drawImage($draw);
    $im->setImageFormat('png');

    $out = ImageTransparency::keyOutBackground($im->getImageBlob());

    $px = new Imagick();
    $px->readImageBlob($out);
    // Scan the row rather than deriving the blend column from the trim: how
    // many rows/columns the trim removes varies between ImageMagick builds, so
    // a computed offset can land on solid ink on one platform and on the blend
    // on another. Scanning asserts the same thing without the geometry
    // assumption — if the unmatting pass did not run, no translucent pixel
    // exists anywhere on the row and this still fails.
    $row = intdiv($px->getImageHeight(), 2);
    $blend = null;
    $solid = null;
    for ($x = 0; $x < $px->getImageWidth(); $x++) {
        $pixel = $px->getImagePixelColor($x, $row);
        $alpha = $pixel->getColorValue(Imagick::COLOR_ALPHA);
        if ($blend === null && $alpha > 0.5 && $alpha < 0.95) {
            $blend = $pixel;
        }
        if ($solid === null && $alpha > 0.99) {
            $solid = $pixel;
        }
    }
    assert_true($blend !== null, 'an unmatted, partially translucent edge pixel exists');
    assert_true($solid !== null, 'a fully opaque ink pixel exists');

    assert_true($blend->getColorValue(Imagick::COLOR_ALPHA) < 0.95, 'blend pixel is translucent');
    assert_true($blend->getColorValue(Imagick::COLOR_ALPHA) > 0.5, 'blend pixel keeps its ink share');
    assert_true($blend->getColorValue(Imagick::COLOR_GREEN) < 0.45, 'white contamination is divided out');
    assert_true($solid->getColorValue(Imagick::COLOR_ALPHA) > 0.99, 'solid ink stays fully opaque');
    assert_true($solid->getColorValue(Imagick::COLOR_RED) > 0.99, 'solid ink color is untouched');
});

test('keyOutBackground returns undecodable bytes unchanged', function () {
    assert_eq('NOT A PNG', ImageTransparency::keyOutBackground('NOT A PNG'));
});

test('keyOutBackground keeps the original when keying would erase everything', function () {
    // A uniform image IS all background: the fill reaches every pixel, so the
    // guard hands back the original rather than a fully invisible asset.
    $im = new Imagick();
    $im->newImage(40, 40, new ImagickPixel('white'));
    $im->setImageFormat('png');
    $bytes = $im->getImageBlob();

    assert_eq($bytes, ImageTransparency::keyOutBackground($bytes));
});

test('padToSquare centres a non-square bitmap on a transparent square at max(w, h, 512)', function () {
    $src = transparency_fixture('transparent', 'red', 40, 20);
    $out = ImageTransparency::padToSquare($src, 512);
    assert_eq([512, 512], png_size($out));
    assert_true(alpha_at($out, 0, 0) < 0.01, 'corner stays transparent');
    assert_true(alpha_at($out, 511, 511) < 0.01);
    // Native pixels are not resampled: the 40x20 canvas sits centred.
    $cx = intdiv(512 - 40, 2) + 20;
    $cy = intdiv(512 - 20, 2) + 10;
    assert_true(alpha_at($out, $cx, $cy) > 0.5, 'subject still opaque at centre');
});

test('padToSquare uses the longer side when it already exceeds minSide', function () {
    $src = transparency_fixture('transparent', 'red', 80, 600);
    $out = ImageTransparency::padToSquare($src, 512);
    assert_eq([600, 600], png_size($out));
});

test('padToSquare returns its input unchanged on undecodable bytes', function () {
    assert_eq('NOT A PNG', ImageTransparency::padToSquare('NOT A PNG'));
});

test('isKeyed is true when every corner is fully transparent', function () {
    $keyed = ImageTransparency::keyOutBackground(transparency_fixture('white', 'red', 60, 60));
    assert_true(ImageTransparency::isKeyed($keyed));
});

test('isKeyed is false for a fully opaque PNG', function () {
    assert_true(!ImageTransparency::isKeyed(transparency_fixture('white', 'red', 60, 60)));
});

test('recolorInk paints keyed ink to the header title color and keeps corners transparent', function () {
    $keyed = ImageTransparency::keyOutBackground(transparency_fixture('white', 'red', 60, 60));
    $out = ImageTransparency::recolorInk($keyed, '#ffffff');
    assert_true(ImageTransparency::isKeyed($out));
    $rgb = rgb_at($out, 30, 30);
    assert_true($rgb[0] > 0.95 && $rgb[1] > 0.95 && $rgb[2] > 0.95, 'ink follows the white title color');
    assert_true(alpha_at($out, 0, 0) < 0.01, 'transparent pad is unchanged');
});

test('recolorInk returns its input on a bad hex', function () {
    $keyed = ImageTransparency::keyOutBackground(transparency_fixture('white', 'red', 40, 40));
    assert_eq($keyed, ImageTransparency::recolorInk($keyed, 'not-a-color'));
});

test('isKeyed treats near-transparent corners as keyed', function () {
    $im = new Imagick();
    $im->newImage(60, 60, new ImagickPixel('transparent'));
    $draw = new ImagickDraw();
    $draw->setFillColor(new ImagickPixel('red'));
    $draw->rectangle(20, 20, 40, 40);
    $im->drawImage($draw);
    $dust = new ImagickPixel('transparent');
    $dust->setColorValue(Imagick::COLOR_ALPHA, 2 / 255);
    foreach ([[0, 0], [59, 0], [0, 59], [59, 59]] as [$x, $y]) {
        $im->setImagePixelColor($x, $y, $dust);
    }
    $im->setImageFormat('png');
    $bytes = $im->getImageBlob();

    assert_true(alpha_at($bytes, 0, 0) > 0.0, 'fixture corner is not exact-zero');
    assert_true(alpha_at($bytes, 0, 0) < 0.01, 'fixture corner stays inside the 0.01 epsilon');
    assert_true(ImageTransparency::isKeyed($bytes), 'PNG quantisation dust must not drop a keyed mark');
});
