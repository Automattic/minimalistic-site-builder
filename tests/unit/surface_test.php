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

test('Surface kitCss carries both inks so no band loses the texture', function () {
    // One blend mode picked from the page base gave the whole site one
    // recipe, and the texture vanished on every band of the opposite color.
    foreach (['#16181A', '#EFE8DA'] as $base) {
        $css = Surface::kitCss('concrete', $base);
        assert_true(is_string($css));
        assert_contains('mix-blend-mode: soft-light', $css, "one blend serves both on {$base}");
        assert_contains('rgba(40,40,40', $css, "dark ink present on {$base}");
        assert_contains('rgba(239,232,218', $css, "light ink present on {$base}");
    }
});

test('Surface kitCss still tunes opacity to the page base', function () {
    $dark = Surface::kitCss('concrete', '#16181A');
    $light = Surface::kitCss('concrete', '#EFE8DA');
    assert_contains('opacity: 0.48', (string) $dark);
    assert_contains('opacity: 0.34', (string) $light);
    assert_contains('dark', (string) $dark);
    assert_true(Surface::isDark('#16181A'));
    assert_true(!Surface::isDark('#EFE8DA'));
});

test('Surface kitCss claims body::before rather than sharing it', function () {
    // A generated `display:none` or transform on the same pseudo-element
    // would otherwise leave the texture invisible while the build reported
    // it shipped.
    $css = (string) Surface::kitCss('paper', '#FFFFFF');
    foreach (['display: block', 'visibility: visible', 'transform: none', 'clip-path: none',
        'width: auto', 'height: auto', 'margin: 0', 'filter: none'] as $reset) {
        assert_contains($reset, $css, "overlay resets {$reset}");
    }
});
