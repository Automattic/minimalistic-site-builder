<?php
declare(strict_types=1);

use Automattic\SiteBuild\SiteIcon;

test('fromMark cuts a square opaque icon out of a wider mark', function () {
    $icon = SiteIcon::fromMark(gd_mark_png(1264, 848));
    assert_true($icon !== null, 'a mark yields an icon');

    [$width, $height] = array_slice((array) getimagesizefromstring((string) $icon), 0, 2);
    // WordPress serves site_icon by resizing the source, so it wants 512.
    assert_eq(SiteIcon::SIDE, $width);
    assert_eq(SiteIcon::SIDE, $height);

    $image = imagecreatefromstring((string) $icon);
    assert_true($image !== false, 'the icon is readable');
    // Opaque on purpose: iOS composites a transparent touch icon onto black.
    $corner = imagecolorsforindex($image, imagecolorat($image, 2, 2));
    assert_eq(0, $corner['alpha'], 'the ground is opaque');
    assert_eq(255, $corner['red'], 'and white');
    // The mark is centred, so the middle of the crop is ink, not ground.
    $middle = imagecolorsforindex($image, imagecolorat($image, SiteIcon::SIDE / 2, SiteIcon::SIDE / 2));
    assert_true($middle['red'] < 128, 'the centre carries the mark');
});

test('fromMark fits a wide mark rather than cutting its ends off', function () {
    // Generation does not honour the square it asks for, so a horizontal
    // lockup can span more than the render's short side. Centre-cropping to
    // that side would drop both ends, and nothing downstream would notice:
    // the middle of the mark is still ink.
    $wide = imagecreatetruecolor(1264, 848);
    imagefill($wide, 0, 0, (int) imagecolorallocate($wide, 255, 255, 255));
    imagefilledrectangle($wide, 30, 400, 1234, 460, (int) imagecolorallocate($wide, 14, 102, 114));
    ob_start();
    imagepng($wide);
    $icon = SiteIcon::fromMark((string) ob_get_clean());

    $image = imagecreatefromstring((string) $icon);
    assert_true($image !== false, 'the icon is readable');
    $first = null;
    $last = null;
    for ($x = 0; $x < SiteIcon::SIDE; $x++) {
        if (imagecolorsforindex($image, imagecolorat($image, $x, SiteIcon::SIDE / 2))['red'] < 200) {
            $first ??= $x;
            $last = $x;
        }
    }
    // Ground on both sides is the proof: a centre crop would have filled the
    // row edge to edge, having thrown the band's ends away off-canvas.
    assert_true($first !== null, 'the band survived');
    assert_true($first > 0, "the band's left end is inside the icon, not cut off at it");
    assert_true($last < SiteIcon::SIDE - 1, 'and so is its right end');
});

test('fromMark ships nothing it cannot make an icon of', function () {
    // A render with no mark on it: a white square in the tab reads worse than
    // the default WordPress one it would replace.
    $blank = imagecreatetruecolor(400, 300);
    imagefill($blank, 0, 0, (int) imagecolorallocate($blank, 255, 255, 255));
    ob_start();
    imagepng($blank);
    $blankPng = (string) ob_get_clean();

    assert_eq(null, SiteIcon::fromMark($blankPng), 'a blank render is not an icon');
    assert_eq(null, SiteIcon::fromMark('not an image'), 'unreadable bytes fail soft');
    assert_eq(null, SiteIcon::fromMark(''), 'no bytes fail soft');
});
