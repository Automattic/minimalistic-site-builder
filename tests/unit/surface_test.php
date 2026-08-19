<?php
declare(strict_types=1);

use Automattic\SiteBuild\Surface;

test('Surface catalog is the bounded overlay list', function () {
    assert_eq(['none', 'paper', 'concrete', 'film', 'fabric'], Surface::ALL);
    assert_eq('paper', Surface::explicit(' Paper '));
    assert_eq(null, Surface::explicit('kraft'));
    assert_eq(null, Surface::explicit(['paper']));
});

test('Surface kitCss ships a fixed overlay for each real surface and nothing for none', function () {
    assert_eq(null, Surface::kitCss('none'));
    assert_eq(null, Surface::kitCss('kraft'));

    $paper = Surface::kitCss('paper');
    assert_true(is_string($paper));
    assert_contains('position: fixed', $paper);
    assert_contains('pointer-events: none', $paper);
    assert_contains('body::before', $paper);
    assert_true(!str_contains($paper, 'feTurbulence'), 'SVG filters do not paint as CSS backgrounds');
    assert_contains('repeating-linear-gradient', $paper);

    foreach (['concrete', 'film', 'fabric'] as $surface) {
        $css = Surface::kitCss($surface);
        assert_true(is_string($css), "{$surface} ships CSS");
        assert_contains('position: fixed', $css);
        assert_true(!str_contains((string) $css, 'feTurbulence'));
    }
});

test('Surface kitCss uses an overlay recipe on a dark base', function () {
    $css = Surface::kitCss('concrete', '#16181A');
    assert_true(is_string($css));
    assert_contains('mix-blend-mode: overlay', $css);
    assert_contains('dark', $css);
    assert_true(Surface::isDark('#16181A'));
    assert_true(!Surface::isDark('#EFE8DA'));
});
