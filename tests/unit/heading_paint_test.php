<?php
declare(strict_types=1);

use Automattic\SiteBuild\Units\GeneratedMarkup;

test('a heading loses its own background paint but keeps its text colour and size (frm PR-5j)', function () {
    $markup = '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
        . '<!-- wp:heading {"level":1,"className":"word-reveal","style":{"color":{"gradient":"var:preset|gradient|display-burn"},"typography":{"fontSize":"min(var(--wp--preset--font-size--display), 120px, 14.7vw)"}}} -->'
        . '<h1 class="wp-block-heading word-reveal has-background" style="background:var(--wp--preset--gradient--display-burn);font-size:min(var(--wp--preset--font-size--display), 120px, 14.7vw)">Brands built to hold their nerve</h1>'
        . '<!-- /wp:heading -->'
        . '<!-- wp:heading {"level":2,"backgroundColor":"accent","textColor":"base"} --><h2 class="wp-block-heading has-base-color has-accent-background-color has-text-color has-background">Painted</h2><!-- /wp:heading -->'
        . '<!-- wp:heading {"level":2,"textColor":"accent"} --><h2 class="wp-block-heading has-accent-color has-text-color">Plain</h2><!-- /wp:heading -->'
        . '</div><!-- /wp:group -->';
    $repairs = [];
    $warnings = [];
    $out = GeneratedMarkup::stripHeadingPaint($markup, 'page-home--hero', $repairs, $warnings);
    assert_contains('<!-- wp:heading {"level":1,"className":"word-reveal","style":{"typography":{"fontSize":"min(var(\\u002d\\u002dwp\\u002d\\u002dpreset\\u002d\\u002dfont-size\\u002d\\u002ddisplay), 120px, 14.7vw)"}}} -->', $out, 'the gradient leaves the attributes; the size stays');
    assert_contains('<h1 class="wp-block-heading word-reveal" style="font-size:min(var(--wp--preset--font-size--display), 120px, 14.7vw)">Brands built to hold their nerve</h1>', $out);
    assert_contains('<!-- wp:heading {"level":2,"textColor":"base"} --><h2 class="wp-block-heading has-base-color has-text-color">Painted</h2>', $out, 'a solid background leaves; the text colour stays');
    assert_contains('<h2 class="wp-block-heading has-accent-color has-text-color">Plain</h2>', $out, 'a plain heading is untouched');
    assert_eq(1, count($repairs));
    assert_eq('heading-paint-stripped', $repairs[0]['code']);
    assert_contains('2 painted heading(s)', $repairs[0]['authored']);
    assert_eq(2, count($warnings));
    assert_contains("block='heading'; authored=style.color.gradient \"var:preset|gradient|display-burn\"", $warnings[0]);

    $plain = '<!-- wp:heading --><h2 class="wp-block-heading">Plain</h2><!-- /wp:heading -->';
    $repairs = [];
    assert_eq($plain, GeneratedMarkup::stripHeadingPaint($plain, 'page-home--about', $repairs, $warnings));
    assert_eq([], $repairs);
});
