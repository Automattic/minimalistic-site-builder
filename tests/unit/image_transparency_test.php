<?php
declare(strict_types=1);

use Automattic\SiteBuild\ImageTransparency;

/**
 * ImageTransparency keys the flat solid background Imagen was prompted to
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
    // Imagen renders "pure white" with slight warmth/noise; the fuzz must
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
    // A pixel that blends ink 50/50 with the white background — the
    // anti-aliased edge Imagen renders. The binary key passes leave it fully
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
    // The trim pads the 19..40 ink box; locate the blend column relative to it.
    $pad = max(2, intdiv(max($px->getImageWidth(), $px->getImageHeight()), 50));
    $blend = $px->getImagePixelColor($pad, $pad + 10);
    $solid = $px->getImagePixelColor($pad + 10, $pad + 10);

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
