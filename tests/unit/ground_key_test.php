<?php
declare(strict_types=1);

use Automattic\SiteBuild\ContrastMath;
use Automattic\SiteBuild\GroundKey;

test('GroundKey classifies a base by WCAG relative luminance', function () {
    assert_eq('light', GroundKey::classify('#F4EBDA'));
    assert_eq('light', GroundKey::classify('#8F9398'), 'the established 0.28 boundary is inclusive on light');
    assert_eq('dark', GroundKey::classify('#1B2233'));
    assert_eq(null, GroundKey::classify('navy'));
});

test('GroundKey moves a contradictory ground across the luminance boundary', function () {
    $dark = GroundKey::move('#F4EBDA', 'dark');
    $light = GroundKey::move('#1B2233', 'light');

    assert_eq('dark', GroundKey::classify((string) $dark));
    assert_eq('light', GroundKey::classify((string) $light));
    assert_true(
        ContrastMath::luminance(ContrastMath::hexToRgb((string) $dark)) < GroundKey::DARK_THRESHOLD,
    );
    assert_true(
        ContrastMath::luminance(ContrastMath::hexToRgb((string) $light)) >= GroundKey::DARK_THRESHOLD,
    );
});

test('GroundKey leaves an honored commitment untouched and rejects invalid input', function () {
    assert_eq('#F4EBDA', GroundKey::move('#F4EBDA', 'light'));
    assert_eq('#1B2233', GroundKey::move('#1B2233', 'dark'));
    assert_eq(null, GroundKey::move('nope', 'light'));
    assert_eq(null, GroundKey::move('#F4EBDA', 'dim'));
});

test('a moved page ground lands near white or near black in the authored hue, not on a mid grey (frm PR-4h)', function () {
    $light = (string) GroundKey::move('#0D0F12', 'light');
    $dark = (string) GroundKey::move('#F2F5F9', 'dark');
    assert_true(ContrastMath::luminance(ContrastMath::hexToRgb($light)) >= 0.78, "{$light} is a page-light ground, not #949596");
    assert_true(ContrastMath::luminance(ContrastMath::hexToRgb($dark)) <= 0.05, "{$dark} is a page-dark ground");
    $warm = (string) GroundKey::move('#17130F', 'light');
    $rgb = ContrastMath::hexToRgb($warm);
    assert_true($rgb[0] >= $rgb[2], "{$warm} keeps the warm cast of #17130F");
    assert_eq('#949596', GroundKey::move('#949596', 'light'), 'an authored ground already on the right side is left alone');
});
