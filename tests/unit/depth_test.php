<?php
declare(strict_types=1);

use Automattic\SiteBuild\Depth;

test('Depth exposes one canonical preset for every bounded commitment', function () {
    $expected = [
        'flat' => 'none',
        'soft' => '0 0.75rem 2rem',
        'hard-offset' => '0.55rem 0.55rem 0',
        'inset' => 'inset 0 0 0 1px',
        'glow' => '0 0 2rem',
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

test('Depth inset remains visible on replaced image content', function () {
    $css = Depth::kitCss('inset');
    assert_contains('outline:', $css);
    assert_contains('outline-offset: -0.5rem', $css);
    assert_contains('figure.wp-block-image:not(.alignfull) > a > img', $css, 'linked images retain the inner edge');
    assert_contains('.wp-block-cover:not(.alignfull)', $css, 'cover pixels cannot hide the inner edge');
    assert_contains('.wp-block-media-text:not(.alignfull) > .wp-block-media-text__media', $css, 'media-text pixels cannot hide the inner edge');
    assert_true(!str_contains(Depth::kitCss('soft'), 'outline-offset'), 'other modes add no inset edge');
});
