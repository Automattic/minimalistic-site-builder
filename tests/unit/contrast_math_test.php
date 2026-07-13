<?php
declare(strict_types=1);

use Automattic\SiteBuild\ContrastMath;

test('hexToRgb parses 6- and 3-digit hex, rejects junk', function () {
    assert_eq([255, 255, 255], ContrastMath::hexToRgb('#FFFFFF'));
    assert_eq([0, 0, 0], ContrastMath::hexToRgb('#000'));
    assert_eq([46, 33, 26], ContrastMath::hexToRgb('2E211A'), 'leading # optional');
    assert_eq(null, ContrastMath::hexToRgb('var:preset|color|base'));
    assert_eq(null, ContrastMath::hexToRgb('#12345'));
});

test('luminance endpoints: black 0, white 1', function () {
    assert_true(abs(ContrastMath::luminance([0, 0, 0])) < 1e-9);
    assert_true(abs(ContrastMath::luminance([255, 255, 255]) - 1.0) < 1e-9);
});

test('contrast ratio is 21 for black/white, 1 for identical, symmetric', function () {
    assert_true(abs(ContrastMath::ratio([0, 0, 0], [255, 255, 255]) - 21.0) < 0.01);
    assert_true(abs(ContrastMath::ratio([120, 40, 200], [120, 40, 200]) - 1.0) < 1e-9);
    $a = ContrastMath::ratio([200, 30, 30], [250, 250, 250]);
    $b = ContrastMath::ratio([250, 250, 250], [200, 30, 30]);
    assert_true(abs($a - $b) < 1e-9, 'ratio must not depend on argument order');
});

test('known WCAG reference pair: #777777 on white is ~4.48', function () {
    $r = ContrastMath::ratio([119, 119, 119], [255, 255, 255]);
    assert_true(abs($r - 4.48) < 0.01, "got {$r}");
});

test('compositeOver blends by alpha', function () {
    assert_eq([0, 0, 0], ContrastMath::compositeOver([0, 0, 0], 1.0, [255, 255, 255]));
    assert_eq([255, 255, 255], ContrastMath::compositeOver([0, 0, 0], 0.0, [255, 255, 255]));
    assert_eq([128, 128, 128], ContrastMath::compositeOver([0, 0, 0], 0.5, [255, 255, 255]));
});

test('parseCssColors extracts hex and rgba stops with alpha, in source order', function () {
    $stops = ContrastMath::parseCssColors(
        'linear-gradient(160deg, rgba(46,33,26,0.15) 0%, #FF0000 50%, rgb(1, 2, 3) 100%)'
    );
    assert_eq(3, count($stops));
    assert_eq([46, 33, 26], $stops[0]['rgb'], 'first stop first, notation must not reorder');
    assert_true(abs($stops[0]['alpha'] - 0.15) < 1e-9);
    assert_eq(['rgb' => [255, 0, 0], 'alpha' => 1.0], $stops[1]);
    assert_eq(['rgb' => [1, 2, 3], 'alpha' => 1.0], $stops[2]);
});

test('gradientStops inserts the interpolated midpoint between adjacent stops', function () {
    $stops = ContrastMath::gradientStops('linear-gradient(180deg, #000000 0%, rgba(255,255,255,0.5) 100%)');
    assert_eq(3, count($stops));
    assert_eq([0, 0, 0], $stops[0]['rgb'], 'endpoints must stay in place');
    assert_eq([128, 128, 128], $stops[1]['rgb'], 'the mid-grey the gradient renders must be checked');
    assert_true(abs($stops[1]['alpha'] - 0.75) < 1e-9);
    assert_eq([255, 255, 255], $stops[2]['rgb']);
    // Single-color values gain nothing to interpolate.
    assert_eq(1, count(ContrastMath::gradientStops('#123456')));
});
