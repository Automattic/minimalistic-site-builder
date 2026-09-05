<?php
declare(strict_types=1);

use Automattic\SiteBuild\Units\GeneratedMarkup;

function hero_cover_dim_markup(int $dim, string $dimClass, string $url = '/wp-content/themes/x/assets/hero.jpg'): string
{
    $urlAttr = $url === '' ? '' : '"url":"' . $url . '",';
    return '<!-- wp:group {"className":"hero-composition--metadata-corners","align":"full","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group alignfull hero-composition--metadata-corners">'
        . '<!-- wp:cover {' . $urlAttr . '"dimRatio":' . $dim . ',"overlayColor":"base","isUserOverlayColor":true,"align":"full","className":"hero-composition__media"} -->'
        . '<div class="wp-block-cover alignfull hero-composition__media">'
        . '<span aria-hidden="true" class="wp-block-cover__background has-base-background-color ' . $dimClass . 'has-background-dim"></span>'
        . ($url === '' ? '' : '<img class="wp-block-cover__image-background" alt="" src="' . $url . '" data-object-fit="cover"/>')
        . '<div class="wp-block-cover__inner-container"><!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Design cut sharp</h1><!-- /wp:heading --></div>'
        . '</div><!-- /wp:cover --></div><!-- /wp:group -->';
}

test('a hero cover dim above the ceiling is capped and its numbered class swapped (frm PR-2l)', function () {
    $repairs = [];
    $warnings = [];
    $out = GeneratedMarkup::capHeroCoverDim(hero_cover_dim_markup(80, 'has-background-dim-80 '), 'page-home--hero', $repairs, $warnings);
    assert_contains('"dimRatio":60', $out);
    assert_true(!str_contains($out, '"dimRatio":80'));
    assert_contains('has-background-dim-60', $out, 'the numbered class follows the attribute');
    assert_true(!str_contains($out, 'has-background-dim-80'), 'the stale numbered class is gone');
    assert_contains('has-background-dim"', $out, 'the generic token stays');
    assert_eq(1, count($repairs));
    assert_eq('hero-cover-dim-capped', $repairs[0]['code']);
    assert_eq('dimRatio 80', $repairs[0]['authored']);
    assert_eq(1, count($warnings));
    assert_contains('authored=dimRatio 80; delivered=dimRatio 60', $warnings[0]);
    assert_contains('buries the picture', $warnings[0]);
});

test('a hero cover at or under the ceiling, or without a picture, is left as authored (frm PR-2l)', function () {
    foreach ([[60, 'has-background-dim-60 '], [50, ''], [40, 'has-background-dim-40 ']] as [$dim, $class]) {
        $repairs = [];
        $warnings = [];
        $markup = hero_cover_dim_markup($dim, $class);
        assert_eq($markup, GeneratedMarkup::capHeroCoverDim($markup, 'page-home--hero', $repairs, $warnings), "dim {$dim} stays");
        assert_eq([], $repairs);
        assert_eq([], $warnings);
    }
    $repairs = [];
    $warnings = [];
    $solid = hero_cover_dim_markup(90, 'has-background-dim-90 ', '');
    assert_eq($solid, GeneratedMarkup::capHeroCoverDim($solid, 'page-home--hero', $repairs, $warnings), 'a solid cover has no picture to bury');
    assert_eq([], $repairs);
});
