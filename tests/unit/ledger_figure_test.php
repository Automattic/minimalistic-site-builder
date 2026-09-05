<?php
declare(strict_types=1);

use Automattic\SiteBuild\Units\GeneratedMarkup;

test('the theme owns the stat-ledger figure scale: an authored size on a figure heading is dropped and recorded (frm PR-3m)', function () {
    $figure = static fn (string $t, string $attrs, string $inline): string => '<!-- wp:column {"width":"25%"} --><div class="wp-block-column"><!-- wp:heading ' . $attrs . ' --><h3 class="wp-block-heading count-up has-heading-font-family"' . $inline . '>' . $t . '</h3><!-- /wp:heading --><!-- wp:paragraph {"fontSize":"caption"} --><p class="has-caption-font-size">Average engagement scope</p><!-- /wp:paragraph --></div><!-- /wp:column -->';
    $sized = '{"level":3,"className":"count-up","fontFamily":"heading","style":{"typography":{"fontSize":"var:preset|font-size|display","fontWeight":"500"}}}';
    $preset = '{"level":3,"className":"count-up","fontSize":"display"}';
    $plain = '{"level":3,"className":"count-up"}';
    $band = static fn (string $columns): string => '<!-- wp:group {"className":"section-composition--stat-ledger","layout":{"type":"constrained"}} --><div class="wp-block-group section-composition--stat-ledger"><!-- wp:columns {"align":"wide"} --><div class="wp-block-columns alignwide">' . $columns . '</div><!-- /wp:columns --></div><!-- /wp:group -->';

    $markup = $band(
        $figure('$4.2M', $sized, ' style="font-size:var(--wp--preset--font-size--display);font-weight:500"')
        . $figure('18', $preset . '', '')
        . $figure('12', $plain, '')
    );
    $repairs = [];
    $out = GeneratedMarkup::ownLedgerFigureScale($markup, 'page-home--numbers', 'stat-ledger', $repairs);
    assert_true(!str_contains($out, '"fontSize":"var:preset|font-size|display"'), 'the inline preset size is dropped from the comment');
    assert_contains('"fontWeight":"500"', $out, 'the weight stays');
    assert_true(!str_contains($out, '"fontSize":"display"'), 'the preset attribute is dropped');
    assert_true(!str_contains($out, 'has-display-font-size'), 'the preset class token is dropped');
    assert_contains('"fontSize":"caption"', $out, 'the label keeps its caption size');
    assert_eq(2, count($repairs));
    assert_eq('heading.figure', $repairs[0]['block']);
    assert_contains('style.typography.fontSize', (string) $repairs[0]['authored']);
    assert_contains("fontSize 'display'", (string) $repairs[1]['authored']);

    $repairs = [];
    assert_eq($markup, GeneratedMarkup::ownLedgerFigureScale($markup, 'x', 'equal-card-grid', $repairs), 'other archetypes keep their sizes');
    assert_eq([], $repairs);
    assert_eq($markup, GeneratedMarkup::ownLedgerFigureScale($markup, 'x', null, $repairs));

    $words = $band($figure('Four years', $sized, ''));
    $repairs = [];
    assert_eq($words, GeneratedMarkup::ownLedgerFigureScale($words, 'x', 'stat-ledger', $repairs), 'a heading that is not a figure keeps its size');
});
