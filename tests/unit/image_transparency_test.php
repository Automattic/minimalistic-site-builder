<?php
declare(strict_types=1);

/**
 * ImageTransparency keys the flat solid background Imagen was prompted to
 * render (it cannot produce real alpha) out to PNG transparency, flood-filling
 * inward from the image border so background-colored pixels inside the subject
 * survive. It fails soft — undecodable bytes or a keying that would erase the
 * whole image return the input unchanged.
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

if (!ImageTransparency::available()) {
    test('image-transparency tests skipped (imagick not loaded)', function () {});
    return;
}

test('keyOutBackground makes the white background transparent, keeps the subject', function () {
    $out = ImageTransparency::keyOutBackground(transparency_fixture('white', 'red'));

    assert_true(alpha_at($out, 0, 0) < 0.01, 'corner background is transparent');
    assert_true(alpha_at($out, 59, 59) < 0.01, 'opposite corner is transparent');
    assert_true(alpha_at($out, 30, 30) > 0.99, 'subject center stays opaque');
});

test('keyOutBackground keys an off-white background via the fuzz', function () {
    // Imagen renders "pure white" with slight warmth/noise; the fuzz must
    // absorb that.
    $out = ImageTransparency::keyOutBackground(transparency_fixture('rgb(250,247,242)', 'rgb(120,40,20)'));

    assert_true(alpha_at($out, 0, 0) < 0.01, 'off-white background is keyed');
    assert_true(alpha_at($out, 30, 30) > 0.99, 'dark subject stays opaque');
});

test('keyOutBackground keeps background-colored pixels inside the subject', function () {
    // A white dot INSIDE the red rectangle is not border-connected — a global
    // color replace would strip it; the flood fill must not.
    $im = new Imagick();
    $im->readImageBlob(transparency_fixture('white', 'red'));
    $draw = new ImagickDraw();
    $draw->setFillColor(new ImagickPixel('white'));
    $draw->rectangle(29, 29, 31, 31);
    $im->drawImage($draw);
    $im->setImageFormat('png');

    $out = ImageTransparency::keyOutBackground($im->getImageBlob());

    assert_true(alpha_at($out, 0, 0) < 0.01, 'background is transparent');
    assert_true(alpha_at($out, 30, 30) > 0.99, 'enclosed white pixel stays opaque');
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
