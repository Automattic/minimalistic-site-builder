<?php
declare(strict_types=1);

use Automattic\SiteBuild\Units\GeneratedMarkup;

test('an empty step plate loses its preset paint so the theme tints it from the band (frm PR-3ad)', function () {
    $markup = '<!-- wp:column {"width":"45%"} --><div class="wp-block-column" style="flex-basis:45%">'
        . '<!-- wp:group {"backgroundColor":"band","className":"step-plate"} --><div class="wp-block-group step-plate has-band-background-color has-background"></div><!-- /wp:group -->'
        . '</div><!-- /wp:column -->'
        . '<!-- wp:group {"backgroundColor":"band","className":"card"} --><div class="wp-block-group card has-band-background-color has-background"><p>Card</p></div><!-- /wp:group -->';
    $repairs = [];
    $out = GeneratedMarkup::stripStepPlatePaint($markup, 'page-home--process', $repairs);
    assert_contains('<!-- wp:group {"className":"step-plate"} --><div class="wp-block-group step-plate"></div><!-- /wp:group -->', $out);
    assert_contains('<div class="wp-block-group card has-band-background-color has-background"><p>Card</p>', $out, 'a painted card that is not a plate is untouched');
    assert_eq(1, count($repairs));
    assert_eq('step-plate-paint-stripped', $repairs[0]['code']);
    $repairs = [];
    assert_eq($out, GeneratedMarkup::stripStepPlatePaint($out, 'page-home--process', $repairs), 'a second pass is a fixed point');
    assert_eq([], $repairs);
});
