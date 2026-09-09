<?php
declare(strict_types=1);

use Automattic\SiteBuild\ContrastMath;
use Automattic\SiteBuild\Surface;

function surface_test_svg(string $css): string
{
    preg_match('/data:image\/svg\+xml,([^"]+)/', $css, $match);
    return rawurldecode($match[1] ?? '');
}

test('Surface catalog accepts optional textures and rejects unknown values', function () {
    assert_eq(['none', 'paper', 'concrete', 'film', 'fabric', 'noise', 'dot-grid'], Surface::ALL);
    assert_eq('paper', Surface::explicit(' Paper '));
    assert_eq(null, Surface::explicit('kraft'));
    assert_eq(null, Surface::explicit(['paper']));
    assert_eq(null, Surface::kitCss('none'));
    assert_eq(null, Surface::kitCss('kraft'));
    assert_eq(null, Surface::className('none'));
    assert_eq('surface--noise', Surface::className('noise'));
});

test('Surface tiles are deterministic and need no SVG filter or external resource', function () {
    $tiles = [];
    foreach (array_slice(Surface::ALL, 1) as $surface) {
        $css = Surface::kitCss($surface, '#16181A', '#EFE8DA');
        assert_eq($css, Surface::kitCss($surface, '#16181A', '#EFE8DA'));
        $svg = surface_test_svg($css);
        assert_contains('<svg ', $svg);
        assert_contains('#16181a', $svg);
        assert_contains('#efe8da', $svg);
        assert_true(!str_contains($svg, '<filter'));
        assert_true(!str_contains($svg, 'href='));
        $tiles[] = $svg;
    }
    assert_eq(6, count(array_unique($tiles)), 'each texture has a distinct tile');
});

test('Surface CSS confines the texture below one marked section and its content', function () {
    $css = Surface::kitCss('paper');
    assert_contains('.wp-block-post-content', $css);
    assert_contains('.editor-styles-wrapper .is-root-container', $css);
    assert_contains('> .surface--paper', $css);
    assert_contains('position: absolute', $css);
    assert_contains('isolation: isolate', $css);
    assert_contains('z-index: -1', $css);
    assert_contains('pointer-events: none', $css);
    assert_contains('mix-blend-mode: normal', $css);
    assert_contains('prefers-reduced-transparency: reduce', $css);
    assert_contains(', print', $css);
    assert_true(!str_contains($css, 'body::before'));
    assert_true(!str_contains($css, 'position: fixed'));
});

test('Surface noise uses irregular marks instead of concentric gradient rings', function () {
    $css = Surface::kitCss('noise', '#0B1B33', '#7EC8E3');
    $svg = surface_test_svg($css);
    assert_eq(900, substr_count($svg, '<ellipse'));
    assert_contains('#0b1b33', $svg);
    assert_contains('#7ec8e3', $svg);
    assert_true(!str_contains($css, 'gradient('));
    preg_match_all('/<ellipse cx="([^"]+)" cy="([^"]+)"/', $svg, $marks);
    assert_eq(900, count(array_unique($marks[0])), 'dark and light marks occupy different positions');
    assert_contains('background-size: 24px 24px', Surface::kitCss('dot-grid'));
});

test('Surface contrast reserve survives either extreme ink at maximum opacity', function () {
    assert_eq(ContrastMath::NORMAL_TEXT, Surface::contrastFloor('none'));
    assert_eq(7.0, Surface::contrastFloor('noise'));
    assert_contains('opacity: 0.12', Surface::kitCss('noise'));
    $checked = 0;
    for ($background = 0; $background <= 255; $background += 5) {
        for ($foreground = 0; $foreground <= 255; $foreground += 5) {
            $lum = static fn (int $v): float => ContrastMath::luminance([$v, $v, $v]);
            $ratio = static fn (float $a, float $b): float => (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
            if ($ratio($lum($background), $lum($foreground)) < 7.0) {
                continue;
            }
            foreach ([0, 255] as $ink) {
                $delivered = (int) round($background * 0.88 + $ink * 0.12);
                assert_true($ratio($lum($delivered), $lum($foreground)) >= 4.5, "{$background}/{$foreground}, ink {$ink}");
                $checked++;
            }
        }
    }
    assert_true($checked > 500);
});
