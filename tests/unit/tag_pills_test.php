<?php
declare(strict_types=1);

use Automattic\SiteBuild\ItemPattern;
use Automattic\SiteBuild\Steps\PagePlanStep;
use Automattic\SiteBuild\Steps\ThemeJsonStep;
use Automattic\SiteBuild\Units\GeneratedMarkup;

test('tag pills the brief states on its cards are read; a hero pill panel is not (frm PR-3al)', function () {
    assert_true(ItemPattern::statedTagPills('Light page, featured work as large image cards with tag pills, an award/quote/stat bento.'));
    assert_true(ItemPattern::statedTagPills('project tiles carrying pill tags'));
    assert_true(!ItemPattern::statedTagPills('a rounded blue panel of service pill tags top-right, a giant serif name as the hero headline'), 'the hero panel is the facts reader\'s');
    assert_true(!ItemPattern::statedTagPills('a 2x2 grid of work images with blue captions'));
    assert_true(!ItemPattern::statedTagPills('a floating pill navigation with one pill CTA'));
    assert_true(ItemPattern::statedTagPillsFor(['original_prompt' => 'work cards with tag pills', 'prompt' => 'a portfolio']));
    assert_true(!ItemPattern::statedTagPillsFor(['prompt' => 'a portfolio']));
});

test('the plan hands tag-pill markup to the work cards when the brief states pills (frm PR-3al)', function () {
    $pages = [[
        'slug' => 'home',
        'sections' => [
            ['slug' => 'hero', 'type' => 'hero', 'layout_archetype' => 'wordmark-stage', 'handoff' => 'Hero.'],
            ['slug' => 'featured-work', 'type' => 'portfolio', 'layout_archetype' => 'equal-card-grid', 'item_pattern' => 'card', 'handoff' => 'Cards with a photo each.'],
            ['slug' => 'services', 'type' => 'services', 'layout_archetype' => 'equal-card-grid', 'item_pattern' => 'card', 'handoff' => 'Three cards.'],
            ['slug' => 'awards', 'type' => 'proof', 'layout_archetype' => 'bento-grid', 'item_pattern' => 'spec-table', 'handoff' => 'Awards.'],
        ],
    ]];
    $repairs = [];
    $out = PagePlanStep::withStatedTagPills($pages, true, $repairs);
    assert_eq(1, count($repairs), json_encode($repairs));
    assert_contains('"className":"tag-pill"', $out[0]['sections'][1]['handoff']);
    assert_contains('"className":"tag-pills"', $out[0]['sections'][1]['handoff']);
    assert_true(str_starts_with($out[0]['sections'][1]['handoff'], 'Cards with a photo each. Build correction:'), 'the authored handoff stays first');
    assert_eq('Three cards.', $out[0]['sections'][2]['handoff'], 'a services section is not the work');
    assert_eq('Hero.', $out[0]['sections'][0]['handoff']);
    assert_eq('Awards.', $out[0]['sections'][3]['handoff'], 'a spec-table item pattern has no cards');
    $again = [];
    assert_eq($out, PagePlanStep::withStatedTagPills($out, true, $again));
    assert_eq([], $again);
    assert_eq($pages, PagePlanStep::withStatedTagPills($pages, false, $repairs), 'no stated pills, no change');
});

test('a tag list inside a card becomes tag-pill paragraphs and an authored pill colour is dropped (frm PR-3al)', function () {
    $card = static fn (string $tags): string => '<!-- wp:group {"className":"item-pattern__item card-style--flush","layout":{"type":"constrained"}} --><div class="wp-block-group item-pattern__item card-style--flush">'
        . $tags
        . '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Orla Ceramics</h3><!-- /wp:heading -->'
        . '</div><!-- /wp:group -->';
    $list = '<!-- wp:list {"className":"tag-pills","fontSize":"caption"} --><ul class="wp-block-list tag-pills has-caption-font-size"><!-- wp:list-item --><li>Brand</li><!-- /wp:list-item --><!-- wp:list-item --><li>Packaging &amp; print</li><!-- /wp:list-item --></ul><!-- /wp:list -->';
    $painted = '<!-- wp:paragraph {"backgroundColor":"accent","textColor":"base","className":"tag-pill","fontSize":"caption"} --><p class="tag-pill has-base-color has-accent-background-color has-text-color has-background has-caption-font-size">Web design</p><!-- /wp:paragraph -->';
    $plainList = '<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>One active workstream</li><!-- /wp:list-item --></ul><!-- /wp:list -->';
    $markup = '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">' . $card($list) . $card($painted) . $card($plainList) . '</div><!-- /wp:group -->';
    $repairs = [];
    $out = GeneratedMarkup::ownTagPills($markup, 'page-home--featured-work', $repairs);
    assert_eq(['tag-pill-ink-owned', 'tag-list-to-pills'], array_column($repairs, 'code'), json_encode($repairs));
    assert_contains('<!-- wp:group {"className":"tag-pills","layout":{"type":"flex","flexWrap":"wrap"}} --><div class="wp-block-group tag-pills">'
        . '<!-- wp:paragraph {"className":"tag-pill","fontSize":"caption"} --><p class="tag-pill has-caption-font-size">Brand</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph {"className":"tag-pill","fontSize":"caption"} --><p class="tag-pill has-caption-font-size">Packaging &amp; print</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->', $out);
    assert_true(!str_contains($out, 'wp:list {"className":"tag-pills"'), 'the tag list is gone');
    assert_contains('<!-- wp:paragraph {"className":"tag-pill","fontSize":"caption"} --><p class="tag-pill has-caption-font-size">Web design</p>', $out, 'the painted pill keeps only its class: ' . $out);
    assert_contains($plainList, $out, 'a feature list is not a tag list');
    $again = [];
    assert_eq($out, GeneratedMarkup::ownTagPills($out, 'page-home--featured-work', $again));
    assert_eq([], $again);
    // A tag list outside any item is left alone.
    $loose = '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">' . $list . '</div><!-- /wp:group -->';
    $none = [];
    assert_eq($loose, GeneratedMarkup::ownTagPills($loose, 'p', $none));
});

test('theme.json custom CSS loses a model rule on the tag-pill hook (frm PR-3al)', function () {
    $theme = ['styles' => ['css' => '.card-body{padding:1rem} .tag-pill{display:inline-block;background:var(--wp--preset--color--accent);color:var(--wp--preset--color--base)}']];
    [$out, $warnings] = ThemeJsonStep::removeEmphasisHookCustomCss($theme);
    assert_eq('.card-body{padding:1rem} .tag-pill{}', $out['styles']['css']);
    assert_eq(3, count($warnings));
    assert_contains('build-owned hook .tag-pill', $warnings[0]);
    assert_contains('the theme paints the pill', $warnings[0]);
});

test('with stated pills a project tile\'s dotted meta line splits into tag pills; unstated it stays (frm PR-3al)', function () {
    $tile = '<!-- wp:cover {"url":"a.jpg","dimRatio":50} --><div class="wp-block-cover"><div class="wp-block-cover__inner-container">'
        . '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Barro Claro</h3><!-- /wp:heading -->'
        . '<!-- wp:paragraph {"className":"project-meta","fontSize":"caption"} --><p class="project-meta has-caption-font-size">Brand identity · Packaging · 2025</p><!-- /wp:paragraph -->'
        . '</div></div><!-- /wp:cover -->';
    $markup = '<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column">' . $tile . '</div><!-- /wp:column --></div><!-- /wp:columns -->';
    $repairs = [];
    $out = GeneratedMarkup::ownTagPills($markup, 'page-home--featured-work', $repairs, true);
    assert_eq(['project-meta-to-pills'], array_column($repairs, 'code'), json_encode($repairs));
    assert_contains('<div class="wp-block-group tag-pills"><!-- wp:paragraph {"className":"tag-pill","fontSize":"caption"} --><p class="tag-pill has-caption-font-size">Brand identity</p><!-- /wp:paragraph -->', $out);
    assert_eq(3, substr_count($out, 'class="tag-pill has-caption-font-size"'));
    assert_true(!str_contains($out, 'project-meta'), 'the meta line is gone');
    $none = [];
    assert_eq($markup, GeneratedMarkup::ownTagPills($markup, 'page-home--featured-work', $none), 'unstated, the dotted meta line is the tile recipe\'s');
    $single = str_replace('Brand identity · Packaging · 2025', 'Identity', $markup);
    $one = [];
    assert_eq($single, GeneratedMarkup::ownTagPills($single, 'page-home--featured-work', $one, true), 'a one-term meta line is not a tag set');
});
