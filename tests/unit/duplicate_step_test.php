<?php
declare(strict_types=1);

use Automattic\SiteBuild\Units\GeneratedMarkup;

function dst_row(string $numeral, string $heading, string $copy, bool $media, bool $copyFirst = true): string
{
    $copyColumn = '<!-- wp:column {"width":"55%"} --><div class="wp-block-column" style="flex-basis:55%">'
        . '<!-- wp:group {"className":"item-pattern__item card-style--borderless","layout":{"type":"constrained"}} --><div class="wp-block-group item-pattern__item card-style--borderless">'
        . '<!-- wp:paragraph {"className":"step-numeral","fontSize":"caption"} --><p class="step-numeral has-caption-font-size">' . $numeral . '</p><!-- /wp:paragraph -->'
        . '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">' . $heading . '</h3><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>' . $copy . '</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group --></div><!-- /wp:column -->';
    $mediaColumn = $media
        ? '<!-- wp:column {"width":"45%"} --><div class="wp-block-column" style="flex-basis:45%"><!-- wp:image {"className":"card-media"} --><figure class="wp-block-image card-media"><img src="' . strtolower(str_replace(' ', '-', $heading)) . '.jpg" alt=""/></figure><!-- /wp:image --></div><!-- /wp:column -->'
        : '';
    return '<!-- wp:columns {"align":"wide"} --><div class="wp-block-columns alignwide">' . ($copyFirst ? $copyColumn . $mediaColumn : $mediaColumn . $copyColumn) . '</div><!-- /wp:columns -->';
}

test('a zigzag step whose heading repeats an earlier step is dropped, the unpictured repeat first, and the numerals renumber (frm PR-3ax)', function () {
    $intro = '<!-- wp:heading --><h2 class="wp-block-heading">How we work</h2><!-- /wp:heading -->';
    $markup = '<!-- wp:group {"className":"section-composition--zigzag-steps","layout":{"type":"constrained"}} --><div class="wp-block-group section-composition--zigzag-steps">' . $intro
        . dst_row('1', 'Brief and listening', 'We listen.', true)
        . dst_row('2', 'Exploration', 'We sketch.', true, false)
        . dst_row('3', 'Refinement and testing', 'Proofs go back under the loupe.', false)
        . dst_row('4', 'Refinement and testing', 'Proofs go straight back under the loupe.', true, false)
        . dst_row('5', 'Delivery and handoff', 'You leave able to run it.', true)
        . '</div><!-- /wp:group -->';
    $repairs = [];
    $out = GeneratedMarkup::dropDuplicateSteps($markup, 'page-home--process', 'zigzag-steps', $repairs);
    assert_eq(['duplicate-step-dropped', 'step-numerals-renumbered'], array_column($repairs, 'code'), json_encode($repairs));
    assert_contains('the unpictured repeat dropped', $repairs[0]['delivered']);
    assert_eq(4, substr_count($out, '<!-- wp:columns '), 'four rows remain');
    assert_eq(1, substr_count($out, 'Refinement and testing'), 'one refinement step');
    assert_contains('Proofs go straight back under the loupe.', $out, 'the pictured repeat is the one kept');
    assert_true(!str_contains($out, 'Proofs go back under the loupe.'));
    preg_match_all('/<p class="step-numeral has-caption-font-size">(\d+)<\/p>/', $out, $m);
    assert_eq(['1', '2', '3', '4'], $m[1], 'the numerals renumber in order');
    assert_contains('How we work', $out, 'the intro heading is not a step row');
    $again = [];
    assert_eq($out, GeneratedMarkup::dropDuplicateSteps($out, 'page-home--process', 'zigzag-steps', $again));
    assert_eq([], $again);
    $other = [];
    assert_eq($markup, GeneratedMarkup::dropDuplicateSteps($markup, 'page-home--process', 'equal-card-grid', $other), 'only a zigzag is a ladder of steps');
    // Two pictured repeats: the later one goes.
    $pictured = '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
        . dst_row('1', 'Discover', 'A.', true) . dst_row('2', 'Discover', 'B.', true, false) . dst_row('3', 'Deliver', 'C.', true)
        . '</div><!-- /wp:group -->';
    $late = [];
    $kept = GeneratedMarkup::dropDuplicateSteps($pictured, 'page-home--process', 'zigzag-steps', $late);
    assert_contains('<p>A.</p>', $kept);
    assert_true(!str_contains($kept, '<p>B.</p>'));
    preg_match_all('/<p class="step-numeral has-caption-font-size">(\d+)<\/p>/', $kept, $mm);
    assert_eq(['1', '2'], $mm[1]);
});
