<?php
declare(strict_types=1);

use Automattic\SiteBuild\Units\GeneratedMarkup;

test('the build owns a project tile\'s overlay and ink on every ground (frm PR-3p)', function () {
    $tile = static fn (string $attrs): string => '<!-- wp:column --><div class="wp-block-column"><!-- wp:cover ' . $attrs . ' --><div class="wp-block-cover"><img class="wp-block-cover__image-background" src="a.jpg" alt=""/><span aria-hidden="true" class="wp-block-cover__background"></span><div class="wp-block-cover__inner-container"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Atlas</h3><!-- /wp:heading --></div></div><!-- /wp:cover --></div><!-- /wp:column -->';
    $row = static fn (string $columns): string => '<!-- wp:columns {"align":"wide"} --><div class="wp-block-columns alignwide">' . $columns . '</div><!-- /wp:columns -->';
    $band = static fn (string $inner): string => '<!-- wp:group {"className":"section-composition--project-grid-2x2","layout":{"type":"constrained"}} --><div class="wp-block-group section-composition--project-grid-2x2">' . $inner . '</div><!-- /wp:group -->';
    $lightRecipe = '{"url":"a.jpg","dimRatio":70,"overlayColor":"contrast","isUserOverlayColor":true,"contentPosition":"bottom left","textColor":"base"}';
    $bare = '{"url":"a.jpg","contentPosition":"bottom left"}';

    $markup = $band($row($tile($lightRecipe) . $tile($bare)));
    $repairs = [];
    $out = GeneratedMarkup::ownProjectTileInk($markup, 'page-home--work', 'project-grid-2x2', $repairs);
    assert_true(!str_contains($out, '"overlayColor":"contrast"'), 'the palette overlay slug is dropped');
    assert_true(!str_contains($out, '"textColor":"base"'), 'the palette text slug is dropped');
    assert_eq(2, substr_count($out, '"customOverlayColor":"#0b0b0d"'), 'every tile takes the black overlay');
    assert_eq(2, substr_count($out, '"dimRatio":50'), 'every tile takes the half-strength dim');
    assert_eq(2, substr_count($out, '"text":"#ffffff"'), 'every tile takes white text');
    assert_eq(2, count($repairs));
    assert_contains('overlayColor "contrast"', (string) $repairs[0]['authored']);
    assert_eq('no tile ink', $repairs[1]['authored']);
    $again = [];
    assert_eq($out, GeneratedMarkup::ownProjectTileInk($out, 'page-home--work', 'project-grid-2x2', $again));
    assert_eq([], $again);

    $banded = $band('<!-- wp:cover {"url":"band.jpg","dimRatio":70,"overlayColor":"base","align":"full"} --><div class="wp-block-cover alignfull"><img class="wp-block-cover__image-background" src="band.jpg" alt=""/><span aria-hidden="true" class="wp-block-cover__background"></span><div class="wp-block-cover__inner-container">' . $row($tile($bare)) . '</div></div><!-- /wp:cover -->');
    $repairs = [];
    $out = GeneratedMarkup::ownProjectTileInk($banded, 'page-home--work', 'project-grid-2x2', $repairs);
    assert_contains('"overlayColor":"base","align":"full"', $out, 'the band keeps its authored overlay');
    assert_eq(1, count($repairs));

    $repairs = [];
    assert_eq($markup, GeneratedMarkup::ownProjectTileInk($markup, 'x', 'equal-card-grid', $repairs), 'other archetypes keep their covers');
    assert_eq([], $repairs);
});
