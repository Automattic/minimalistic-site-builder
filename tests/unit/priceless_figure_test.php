<?php
declare(strict_types=1);

use Automattic\SiteBuild\Units\GeneratedMarkup;

test('a price figure without a price or a currency becomes a plain scope line (frm PR-3ae)', function () {
    $markup = '<!-- wp:paragraph {"className":"price-figure"} --><p class="price-figure">Custom pricing</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph {"className":"price-figure"} --><p class="price-figure">€2,400 / month</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph {"className":"price-figure reveal"} --><p class="price-figure reveal">Free</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph {"className":"price-figure lead"} --><p class="price-figure lead">Quote per project</p><!-- /wp:paragraph -->';
    $repairs = [];
    $warnings = [];
    $out = GeneratedMarkup::demotePricelessFigure($markup, 'page-home--pricing', $repairs, $warnings);
    assert_contains('<!-- wp:paragraph --><p>Custom pricing</p><!-- /wp:paragraph -->', $out, 'the class goes with the figure scale');
    assert_contains('<p class="price-figure">€2,400 / month</p>', $out, 'a currency keeps the figure');
    assert_contains('<p class="price-figure reveal">Free</p>', $out, 'free is a price');
    assert_contains('<!-- wp:paragraph {"className":"lead"} --><p class="lead">Quote per project</p>', $out, 'other classes stay');
    assert_eq(1, count($repairs));
    assert_eq('priceless-figure-demoted', $repairs[0]['code']);
    assert_contains('2 price figure(s) without a price', $repairs[0]['authored']);
    assert_eq(2, count($warnings));
    assert_contains('authored=price-figure "Custom pricing"; delivered=plain scope line', $warnings[0]);
    $repairs = [];
    assert_eq($out, GeneratedMarkup::demotePricelessFigure($out, 'page-home--pricing', $repairs, $warnings), 'a second pass is a fixed point');
    assert_eq([], $repairs);
});
