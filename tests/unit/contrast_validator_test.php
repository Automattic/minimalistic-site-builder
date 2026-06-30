<?php
declare(strict_types=1);

test('contrast ratio: black on white is 21:1', function () {
    assert_eq(21.0, round(ContrastValidator::ratio('#000000', '#ffffff'), 2));
    // Order-independent.
    assert_eq(21.0, round(ContrastValidator::ratio('#ffffff', '#000000'), 2));
});

test('parseHex accepts #RGB, #RRGGBB and rejects var refs', function () {
    assert_eq([255, 255, 255], ContrastValidator::parseHex('#fff'));
    assert_eq([17, 34, 51], ContrastValidator::parseHex('#112233'));
    assert_eq(null, ContrastValidator::parseHex('var(--wp--preset--color--base)'));
    assert_eq(null, ContrastValidator::parseHex('rebeccapurple'));
});

test('validate passes a high-contrast palette', function () {
    $theme = ['settings' => ['color' => ['palette' => [
        ['slug' => 'base', 'color' => '#ffffff'],
        ['slug' => 'contrast', 'color' => '#111111'],
        ['slug' => 'primary', 'color' => '#1f4d2e'],
        ['slug' => 'secondary', 'color' => '#444444'],
        ['slug' => 'accent', 'color' => '#b3471a'],
    ]]]];
    assert_eq([], ContrastValidator::validate($theme));
});

test('validate flags a low-contrast muted slug and a too-light button', function () {
    $theme = ['settings' => ['color' => ['palette' => [
        ['slug' => 'base', 'color' => '#ffffff'],
        ['slug' => 'contrast', 'color' => '#111111'],
        ['slug' => 'primary', 'color' => '#2f6b4f'],
        ['slug' => 'secondary', 'color' => '#a7c4a0'], // light green on white → fails
        ['slug' => 'accent', 'color' => '#d98c3f'],     // base label on it → fails
    ]]]];
    $v = ContrastValidator::validate($theme);
    assert_true($v !== [], 'expected violations');
    $joined = implode("\n", $v);
    assert_contains('secondary', $joined);
    assert_contains('button label', $joined);
});

test('validate resolves the actual button element colors when present', function () {
    // Button wired as contrast-on-base (both dark+light, fine).
    $theme = ['settings' => ['color' => ['palette' => [
        ['slug' => 'base', 'color' => '#ffffff'],
        ['slug' => 'contrast', 'color' => '#111111'],
        ['slug' => 'primary', 'color' => '#1f4d2e'],
        ['slug' => 'secondary', 'color' => '#444444'],
        ['slug' => 'accent', 'color' => '#ffe08a'], // pale: base label on it would fail…
    ]]],
        'styles' => ['elements' => ['button' => ['color' => [
            'background' => 'var:preset|color|accent',
            'text' => 'var(--wp--preset--color--contrast)', // …but dark label on pale accent passes
        ]]]],
    ];
    assert_eq([], ContrastValidator::validate($theme));
});
