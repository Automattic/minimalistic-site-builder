<?php
declare(strict_types=1);

use Automattic\SiteBuild\Units\CardTextContract;

test('a card the theme css paints light takes contrast text on a dark band (frm PR-5k)', function () {
    $theme = ['settings' => ['color' => ['palette' => [['slug' => 'base', 'color' => '#EEF0F4'], ['slug' => 'contrast', 'color' => '#14161A']]]],
        'styles' => ['css' => '.card-style--flush{background-color:var(--wp--preset--color--base);border:1px solid #ccc;overflow:hidden}.card-style--framed{background-color:var(--wp--preset--color--contrast)}']];
    $paints = CardTextContract::paintedCardStyles($theme);
    assert_eq(['card-style--flush' => ['slug' => 'base', 'text' => 'contrast'], 'card-style--framed' => ['slug' => 'contrast', 'text' => 'base']], $paints);
    assert_eq([], CardTextContract::paintedCardStyles(['styles' => ['css' => '.card-style--flush{border:1px solid red}']]), 'a rule without a preset paint is not a surface');

    $markup = '<!-- wp:group {"tagName":"section","backgroundColor":"contrast","textColor":"base","className":"section-composition--zigzag-steps item-pattern--card"} -->'
        . '<section class="wp-block-group section-composition--zigzag-steps item-pattern--card has-base-color has-contrast-background-color has-text-color has-background">'
        . '<!-- wp:group {"className":"item-pattern__item card-style--flush","style":{"border":{"radius":"1.5rem"}}} --><div class="wp-block-group item-pattern__item card-style--flush" style="border-radius:1.5rem">'
        . '<!-- wp:heading {"level":3,"textColor":"base"} --><h3 class="wp-block-heading has-base-color has-text-color">Listening first</h3><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>We start with a long conversation.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->'
        . '<!-- wp:group {"className":"item-pattern__item card-style--flush","backgroundColor":"band"} --><div class="wp-block-group item-pattern__item card-style--flush has-band-background-color has-background"><p>Own paint</p></div><!-- /wp:group -->'
        . '</section><!-- /wp:group -->';
    $out = CardTextContract::enforce($markup, 'page-home--process', $theme);
    assert_contains('<!-- wp:group {"className":"item-pattern__item card-style\\u002d\\u002dflush","style":{"border":{"radius":"1.5rem"}},"textColor":"contrast"} --><div class="wp-block-group item-pattern__item card-style--flush has-contrast-color has-text-color" style="border-radius:1.5rem">', $out['markup']);
    assert_contains('<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Listening first</h3>', $out['markup'], 'the light heading preset yields to the card');
    assert_contains('"backgroundColor":"band"} --><div class="wp-block-group item-pattern__item card-style--flush has-band-background-color has-background"><p>Own paint</p>', $out['markup'], 'a card with its own paint is left to the card contract');
    assert_eq(1, count($out['repairs']));
    assert_eq('card-text-contract', $out['repairs'][0]['code']);
    assert_eq(1, count($out['warnings']));
    assert_contains("block='group card-style--flush'; authored=textColor inherited; delivered=\"contrast\"", $out['warnings'][0]);

    $same = CardTextContract::enforce($out['markup'], 'page-home--process', $theme);
    assert_eq($out['markup'], $same['markup'], 'a second pass is a fixed point');
    assert_eq([], $same['repairs']);
    $plain = CardTextContract::enforce($markup, 'page-home--process', ['styles' => ['css' => '']]);
    assert_eq($markup, $plain['markup'], 'no painted card style, no change');
});
