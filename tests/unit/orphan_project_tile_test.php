<?php
declare(strict_types=1);

use Automattic\SiteBuild\Units\GeneratedMarkup;

function opt_tile(string $name): string
{
    return '<!-- wp:cover {"url":"' . strtolower(str_replace(' ', '-', $name)) . '.jpg","dimRatio":40,"className":"project-tile"} --><div class="wp-block-cover project-tile">'
        . '<div class="wp-block-cover__inner-container"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">' . $name . '</h3><!-- /wp:heading --></div>'
        . '</div><!-- /wp:cover -->';
}

function opt_row(array $tiles, string $width): string
{
    $columns = '';
    foreach ($tiles as $tile) {
        $columns .= '<!-- wp:column {"width":"' . $width . '"} --><div class="wp-block-column" style="flex-basis:' . $width . '">' . opt_tile($tile) . '</div><!-- /wp:column -->';
    }
    return '<!-- wp:columns {"align":"wide"} --><div class="wp-block-columns alignwide">' . $columns . '</div><!-- /wp:columns -->';
}

test('an orphan project tile after a two-column row spans the full row (frm PR-3au)', function () {
    $intro = '<!-- wp:columns {"align":"wide"} --><div class="wp-block-columns alignwide">'
        . '<!-- wp:column {"width":"25%"} --><div class="wp-block-column" style="flex-basis:25%"><!-- wp:paragraph --><p>Work</p><!-- /wp:paragraph --></div><!-- /wp:column -->'
        . '<!-- wp:column {"width":"75%"} --><div class="wp-block-column" style="flex-basis:75%"><!-- wp:heading --><h2 class="wp-block-heading">Selected work</h2><!-- /wp:heading --></div><!-- /wp:column -->'
        . '</div><!-- /wp:columns -->';
    $markup = '<!-- wp:group {"anchor":"portfolio","className":"section-composition--project-grid-2x2","layout":{"type":"constrained"}} --><div id="portfolio" class="wp-block-group section-composition--project-grid-2x2">'
        . $intro . opt_row(['Halide Optics', 'Meridian Press'], '50%') . opt_row(['Vantage Tower'], '50%')
        . '</div><!-- /wp:group -->';
    $repairs = [];
    $out = GeneratedMarkup::widenOrphanProjectTile($markup, 'page-home--portfolio', 'project-grid-2x2', $repairs);
    assert_eq(1, count($repairs), json_encode($repairs));
    assert_eq('orphan-project-tile-widened', $repairs[0]['code']);
    assert_eq("width '50%'", $repairs[0]['authored']);

    assert_true(preg_match('/<!-- wp:column \{"width":"100%","className":"project-tile\\\\u002d\\\\u002dwide"\} -->\s*<div class="wp-block-column" style="flex-basis:50%">' . preg_quote(opt_tile('Vantage Tower'), '/') . '/', $out) === 1, $out);

    $serialized = (new \Automattic\SiteBuild\BlockSerializer\Serializer())->transform($out)->html;
    assert_true(preg_match('/<!-- wp:column \{"width":"100%","className":"project-tile\\\\u002d\\\\u002dwide"\} -->\s*<div class="wp-block-column project-tile--wide" style="flex-basis:100%">/', $serialized) === 1, $serialized);
    assert_eq(2, substr_count($serialized, 'flex-basis:50%'), 'the pair keeps its halves after serialization');
    assert_eq(2, substr_count($out, '"width":"50%"'), 'the full first row keeps its halves');
    assert_true(str_contains($out, 'flex-basis:25%') && str_contains($out, 'flex-basis:75%'), 'the side-label intro row is not a tile row');

    $again = [];
    assert_eq($out, GeneratedMarkup::widenOrphanProjectTile($out, 'page-home--portfolio', 'project-grid-2x2', $again));
    assert_eq([], $again);
    $other = [];
    assert_eq($markup, GeneratedMarkup::widenOrphanProjectTile($markup, 'page-home--portfolio', 'equal-card-grid', $other));
    $single = '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">' . opt_row(['Only One'], '50%') . '</div><!-- /wp:group -->';
    $lone = [];
    assert_eq($single, GeneratedMarkup::widenOrphanProjectTile($single, 'page-home--portfolio', 'project-grid-2x2', $lone), 'a lone first row is the recipe checker\'s business');
    $full = '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">' . opt_row(['A', 'B'], '50%') . opt_row(['C', 'D'], '50%') . '</div><!-- /wp:group -->';
    $none = [];
    assert_eq($full, GeneratedMarkup::widenOrphanProjectTile($full, 'page-home--portfolio', 'project-grid-2x2', $none));
});
