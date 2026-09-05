<?php
declare(strict_types=1);

use Automattic\SiteBuild\ContrastMath;
use Automattic\SiteBuild\Surface;

test('Surface catalog is the bounded overlay list', function () {
    assert_eq(['none', 'paper', 'concrete', 'film', 'fabric', 'noise', 'dot-grid'], Surface::ALL);
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
    assert_contains('html body::before', $paper);
    assert_contains('@supports (mix-blend-mode: soft-light)', $paper);
    assert_contains('prefers-reduced-transparency: reduce', $paper);
    assert_contains('@media print', $paper);
    assert_contains('z-index: 1', $paper);
    assert_true(!str_contains($paper, 'z-index: 9999'));
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
    foreach (['#16181A', '#EFE8DA'] as $base) {
        $css = Surface::kitCss('concrete', $base);
        assert_true(is_string($css));
        assert_true(str_contains($css, 'mix-blend-mode: multiply') || str_contains($css, 'mix-blend-mode: screen'), "one visible blend serves both inks on {$base}");
        assert_contains('rgba(', $css, "inks present on {$base}");
    }
});

test('Surface kitCss derives inks from the delivered palette pair', function () {
    $css = (string) Surface::kitCss('paper', '#0B1B33', '#7EC8E3');
    assert_contains('rgba(11,27,51,', $css, 'dark ink is the cooler base');
    assert_contains('rgba(126,200,227,', $css, 'light ink is the cooler contrast');
    assert_true(!str_contains($css, 'rgba(239,232,218'), 'warm paper fallback is not used');
});

test('Surface contrastFloor budgets AAA when a sheet will ship', function () {
    assert_eq(ContrastMath::NORMAL_TEXT, Surface::contrastFloor('none'));
    assert_eq(ContrastMath::NORMAL_TEXT, Surface::contrastFloor(null));
    assert_eq(7.0, Surface::contrastFloor('paper'));
    assert_eq(7.0, Surface::contrastFloor('concrete'));
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

test('Surface noise is a fine even grain and dot-grid is one faint dot every 24px in the page ink (frm W4d)', function () {
    $noise = (string) Surface::kitCss('noise', '#0F0B08', '#F4F6F8');
    assert_contains("Committed 'noise' page surface (dark)", $noise);
    assert_true(substr_count($noise, 'repeating-radial-gradient') >= 6, 'both inks, three speckle layers each');
    assert_true(!str_contains($noise, 'repeating-linear-gradient'), 'no lines in noise');
    assert_contains('background-size: auto', $noise);
    $grid = (string) Surface::kitCss('dot-grid', '#F2F5F9', '#0B0D10');
    assert_contains("Committed 'dot-grid' page surface (light)", $grid);
    assert_contains('radial-gradient(circle, rgba(11,13,16,0.55) 1px, transparent 1.4px)', $grid, 'dots in the page ink');
    assert_contains('background-size: 24px 24px', $grid);
    $darkGrid = (string) Surface::kitCss('dot-grid', '#0B0D10', '#F2F5F9');
    assert_contains('rgba(242,245,249,0.55)', $darkGrid, 'on a dark page the dots are the light ink');
    assert_eq(7.0, Surface::contrastFloor('noise'));
    assert_eq(7.0, Surface::contrastFloor('dot-grid'));
});
