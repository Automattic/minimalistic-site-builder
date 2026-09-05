<?php
declare(strict_types=1);

use Automattic\SiteBuild\Depth;

test('Depth exposes one canonical preset for every bounded commitment', function () {
    $expected = [
        'flat' => 'none',
        'ring' => '0 0 0 1px',
        'soft' => '0 0.75rem 2rem',
        'hard-offset' => '0.55rem 0.55rem 0',
        'inset' => 'inset 0 0 0 1px',
        'glow' => '0 0 2rem',
        'glass' => '0 0 0 1px',
    ];

    foreach ($expected as $depth => $shadowStart) {
        $preset = Depth::preset($depth);
        assert_eq('depth', $preset['slug']);
        assert_true(str_starts_with($preset['shadow'], $shadowStart), $depth);
    }
});

test('Depth kit executes the preset on cards and contained media without double-shadowing cards', function () {
    foreach (Depth::ALL as $depth) {
        $css = Depth::kitCss($depth);
        assert_contains("Committed '{$depth}' depth", $css);
        assert_contains('var(--wp--preset--shadow--depth,', $css);
        assert_contains('.card-style--flush', $css);
        assert_contains('figure.wp-block-image:not(.alignfull) > img', $css);
        assert_contains('figure.wp-block-image:not(.alignfull) > a > img', $css, 'linked images receive depth too');
        assert_contains('.wp-block-cover:not(.alignfull)', $css);
        assert_contains('.wp-block-media-text:not(.alignfull)', $css);
        assert_contains('box-shadow: none !important', $css, 'direct card media gets no second shadow');
    }
});

test('Depth rejects uncommitted values without inventing a kit or preset', function () {
    assert_eq(null, Depth::explicit('floating'));
    assert_eq(null, Depth::preset('floating'));
    assert_eq(null, Depth::kitCss('floating'));
    assert_eq('hard-offset', Depth::explicit(' Hard-Offset '));
});

test('Depth ring is one hairline with no lift and no inset edge', function () {
    $preset = Depth::preset('ring');
    assert_eq('0 0 0 1px color-mix(in srgb, var(--wp--preset--color--contrast) 12%, transparent)', $preset['shadow']);
    assert_eq('Hairline ring', $preset['name']);
    $css = Depth::kitCss('ring');
    assert_contains("Committed 'ring' depth", $css);
    assert_true(!str_contains($css, 'outline-offset'), 'the ring is a box-shadow, not the inset outline');
    assert_eq(['flat', 'ring', 'soft', 'hard-offset', 'inset', 'glow', 'glass'], Depth::ALL);
});

test('Depth inset remains visible on replaced image content', function () {
    $css = Depth::kitCss('inset');
    assert_contains('outline:', $css);
    assert_contains('outline-offset: -0.5rem', $css);
    assert_contains('figure.wp-block-image:not(.alignfull) > a > img', $css, 'linked images retain the inner edge');
    assert_contains('.wp-block-cover:not(.alignfull)', $css, 'cover pixels cannot hide the inner edge');
    assert_contains('.wp-block-media-text:not(.alignfull) > .wp-block-media-text__media', $css, 'media-text pixels cannot hide the inner edge');
    assert_true(!str_contains(Depth::kitCss('soft'), 'outline-offset'), 'other modes add no inset edge');
});

test('Depth glass frosts band-coloured card shells on a blurred page and keeps inverted cards solid (frm W4b)', function () {
    $preset = Depth::preset('glass');
    assert_eq('Glass', $preset['name']);
    assert_contains('var(--wp--preset--color--contrast) 16%', $preset['shadow'], 'one light hairline');
    assert_contains('rgb(0 0 0 / 0.35)', $preset['shadow'], 'one deep soft drop');
    $css = Depth::kitCss('glass');
    assert_contains('.has-band-background-color {', $css, 'only band-coloured shells take the fill');
    assert_contains('background-color: color-mix(in srgb, var(--wp--preset--color--band) 72%, transparent) !important;', $css);
    assert_contains('backdrop-filter: blur(14px) saturate(1.2)', $css);
    assert_contains('@media (prefers-reduced-transparency: reduce)', $css);
    assert_contains('background-color: var(--wp--preset--color--band) !important', $css, 'reduced transparency restores the solid band');
    assert_true(!str_contains($css, 'has-contrast-background-color'), 'an inverted highlight card stays solid');
    assert_true(!str_contains(Depth::kitCss('glow'), 'backdrop-filter'), 'only glass blurs');
    assert_eq('ring', Depth::GLASS_LIGHT_FALLBACK);
});

test('every bounded depth renders a direction fact, glass included (frm W4b)', function () {
    foreach (Depth::ALL as $depth) {
        $rendered = \Automattic\SiteBuild\Steps\DesignDirectionStep::format(['description' => 'x', 'depth' => $depth]);
        assert_contains("**Depth**: {$depth}", $rendered, $depth);
    }
    assert_contains('frosted panels', \Automattic\SiteBuild\Steps\DesignDirectionStep::format(['description' => 'x', 'depth' => 'glass']));
});
