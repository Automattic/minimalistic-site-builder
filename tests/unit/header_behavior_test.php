<?php
declare(strict_types=1);

use Automattic\SiteBuild\ContrastMath;
use Automattic\SiteBuild\HeaderBehavior;

test('smooth sticky surface selection never crosses an unreadable midpoint', function () {
    $pages = [
        ['slug' => 'home', 'sections' => [['slug' => 'hero']]],
        ['slug' => 'about', 'sections' => [['slug' => 'intro']]],
    ];
    $palette = [
        'base' => '#FFFFFF',
        'contrast' => '#000000',
        // This gray passes against both endpoints but becomes the exact
        // foreground color midway through a white-to-black interpolation.
        'primary' => '#767676',
    ];

    $smooth = HeaderBehavior::resolve(
        $pages,
        HeaderBehavior::MODE_STACKED,
        $palette,
        null,
        HeaderBehavior::TRANSITION_SMOOTH,
        'base',
        'primary',
    );
    assert_eq(HeaderBehavior::STICKY_SOFT, $smooth['behavior']);
    assert_eq('base', $smooth['topSurface']);
    assert_eq('base', $smooth['scrolledSurface'], 'shadow-only change is the safe smooth fallback');

    $foreground = ContrastMath::hexToRgb($palette['primary']);
    $white = ContrastMath::hexToRgb($palette['base']);
    $black = ContrastMath::hexToRgb($palette['contrast']);
    assert_true($foreground !== null && $white !== null && $black !== null);
    assert_true(ContrastMath::ratio($foreground, $white) >= ContrastMath::NORMAL_TEXT);
    assert_true(ContrastMath::ratio($foreground, $black) >= ContrastMath::NORMAL_TEXT);
    assert_true(
        !HeaderBehavior::transitionIsSafe($foreground, $white, $black),
        'endpoint contrast alone cannot prove an animated path',
    );

    $instant = HeaderBehavior::resolve(
        $pages,
        HeaderBehavior::MODE_STACKED,
        $palette,
        null,
        HeaderBehavior::TRANSITION_INSTANT,
        'base',
        'primary',
    );
    assert_eq('contrast', $instant['scrolledSurface'], 'instant changes have no interpolated midpoint');
});

test('smooth transition safety preserves readable mixed-channel hue changes', function () {
    $pages = [
        ['slug' => 'home', 'sections' => [['slug' => 'hero']]],
        ['slug' => 'about', 'sections' => [['slug' => 'intro']]],
    ];
    $palette = [
        'base' => '#FFFF00',
        'contrast' => '#000000',
        'primary' => '#00FFFF',
    ];

    $artifact = HeaderBehavior::resolve(
        $pages,
        HeaderBehavior::MODE_STACKED,
        $palette,
        null,
        HeaderBehavior::TRANSITION_SMOOTH,
        'base',
        'contrast',
    );
    assert_eq('primary', $artifact['scrolledSurface'], 'safe hue transition is retained');

    $foreground = ContrastMath::hexToRgb($palette['contrast']);
    $yellow = ContrastMath::hexToRgb($palette['base']);
    $cyan = ContrastMath::hexToRgb($palette['primary']);
    assert_true($foreground !== null && $yellow !== null && $cyan !== null);
    assert_true(HeaderBehavior::transitionIsSafe($foreground, $yellow, $cyan));
});
