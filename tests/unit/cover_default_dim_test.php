<?php
declare(strict_types=1);

use Automattic\SiteBuild\Units\GeneratedMarkup;

test('a picture cover without a dim ratio takes the default instead of core\'s solid overlay (frm PR-2aj)', function () {
    $bare = '<!-- wp:cover {"url":"work-printed-identity-sheets.jpg","contentPosition":"bottom left"} --><div class="wp-block-cover"><img class="wp-block-cover__image-background" src="work-printed-identity-sheets.jpg"/><div class="wp-block-cover__inner-container"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Kreuzberg Papierwerk</h3><!-- /wp:heading --></div></div><!-- /wp:cover -->';
    $dimmed = '<!-- wp:cover {"url":"a.jpg","dimRatio":50} --><div class="wp-block-cover"><div class="wp-block-cover__inner-container"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Halle</h3><!-- /wp:heading --></div></div><!-- /wp:cover -->';
    $plain = '<!-- wp:cover {"overlayColor":"contrast"} --><div class="wp-block-cover"><div class="wp-block-cover__inner-container"><!-- wp:heading --><h2 class="wp-block-heading">Band</h2><!-- /wp:heading --></div></div><!-- /wp:cover -->';
    $markup = '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">' . $bare . $dimmed . $plain . '</div><!-- /wp:group -->';
    $repairs = [];
    $out = GeneratedMarkup::defaultCoverDim($markup, 'page-home--portfolio', $repairs);
    assert_eq(1, count($repairs), json_encode($repairs));
    assert_eq('cover-dim-default', $repairs[0]['code']);
    assert_contains('"url":"work-printed-identity-sheets.jpg","contentPosition":"bottom left","dimRatio":40}', $out);
    assert_contains('"url":"a.jpg","dimRatio":50}', $out, 'an authored dim stands');
    assert_contains('{"overlayColor":"contrast"}', $out, 'a cover without a picture is a colour band and keeps core\'s default');
    $again = [];
    assert_eq($out, GeneratedMarkup::defaultCoverDim($out, 'page-home--portfolio', $again));
    assert_eq([], $again);
});

test('a project tile wrapped in a card group is still a tile for the build-owned ink (frm PR-2aj)', function () {
    $tile = static fn (string $name): string => '<!-- wp:column {"width":"50%"} --><div class="wp-block-column" style="flex-basis:50%">'
        . '<!-- wp:group {"className":"item-pattern__item card-style--borderless","layout":{"type":"constrained"}} --><div class="wp-block-group item-pattern__item card-style--borderless">'
        . '<!-- wp:cover {"url":"' . $name . '.jpg","contentPosition":"bottom left"} --><div class="wp-block-cover"><div class="wp-block-cover__inner-container"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">' . $name . '</h3><!-- /wp:heading --></div></div><!-- /wp:cover -->'
        . '</div><!-- /wp:group --></div><!-- /wp:column -->';
    $markup = '<!-- wp:group {"className":"section-composition--project-grid-2x2","layout":{"type":"constrained"}} --><div class="wp-block-group section-composition--project-grid-2x2">'
        . '<!-- wp:columns {"align":"wide"} --><div class="wp-block-columns alignwide">' . $tile('a') . $tile('b') . '</div><!-- /wp:columns -->'
        . '</div><!-- /wp:group -->';
    $repairs = [];
    $out = GeneratedMarkup::ownProjectTileInk($markup, 'page-home--portfolio', 'project-grid-2x2', $repairs);
    assert_eq(2, count($repairs), json_encode($repairs));
    assert_eq(2, substr_count($out, '"dimRatio":' . GeneratedMarkup::PROJECT_TILE_DIM), $out);
    // A cover outside any column is the section's own band and is left alone.
    $band = '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"><!-- wp:cover {"url":"band.jpg"} --><div class="wp-block-cover"><div class="wp-block-cover__inner-container"><!-- wp:heading --><h2 class="wp-block-heading">Band</h2><!-- /wp:heading --></div></div><!-- /wp:cover --></div><!-- /wp:group -->';
    $none = [];
    assert_eq($band, GeneratedMarkup::ownProjectTileInk($band, 'page-home--portfolio', 'project-grid-2x2', $none));
    assert_eq([], $none);
});
