<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockCommentRepair;

test('BlockCommentRepair restores the root closer of a flat scalar-ended payload', function () {
    $markup = '<!-- wp:paragraph {"dropCap":true --><p>x</p><!-- /wp:paragraph -->';

    $out = BlockCommentRepair::repair($markup);

    assert_contains('<!-- wp:paragraph {"dropCap":true} -->', $out['markup']);
    assert_contains('omitted their final root closer', implode("\n", $out['notes']));
});

test('BlockCommentRepair restores the root closer of an array-ended payload', function () {
    $markup = '<!-- wp:gallery {"ids":[1,2],"columns":[3] -->'
        . '<figure></figure><!-- /wp:gallery -->';

    $out = BlockCommentRepair::repair($markup);

    assert_contains('<!-- wp:gallery {"ids":[1,2],"columns":[3]} -->', $out['markup']);
    assert_contains('omitted their final root closer', implode("\n", $out['notes']));
});

test('BlockCommentRepair restores the root closer of a mixed payload ending in a scalar', function () {
    $markup = '<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|sm"}},"align":"full" -->'
        . '<div></div><!-- /wp:group -->';

    $out = BlockCommentRepair::repair($markup);

    assert_contains(
        '<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|sm"}},"align":"full"} -->',
        $out['markup'],
    );
    assert_contains('omitted their final root closer', implode("\n", $out['notes']));
});

test('BlockCommentRepair keeps well-formed and void delimiters byte-for-byte', function () {
    $markup = '<!-- wp:group {"layout":{"type":"constrained"}} --><div>'
        . '<!-- wp:spacer {"height":"40px"} /-->'
        . '<!-- wp:paragraph --><p>Copy</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';

    $out = BlockCommentRepair::repair($markup);

    assert_eq($markup, $out['markup']);
    assert_eq([], $out['notes']);
});

test('BlockCommentRepair leaves a payload unbalanced inside a nested value untouched', function () {
    // The root closer is not the only missing token — the array is still open.
    $markup = '<!-- wp:gallery {"ids":[1,2 --><figure></figure><!-- /wp:gallery -->';

    $out = BlockCommentRepair::repair($markup);

    assert_eq($markup, $out['markup']);
    assert_eq([], $out['notes']);
});

test('BlockCommentRepair bounds premature-closer search memory on hostile payloads', function () {
    // Many distinct closer contexts, unfixable within two deletions: three
    // stray `]` need three deletions, so every candidate round stays invalid.
    // Before the incremental budget, round two materialized a full quadratic
    // candidate set (payloads x closers, each a full payload copy) before any
    // limit was checked.
    $members = [];
    for ($i = 0; $i < 150; $i++) {
        $members[] = sprintf('"k%03d":{"v":%d}', $i, $i);
    }
    $json = '{' . implode(',', $members) . ',"x":[1]]]]}';
    $markup = "<!-- wp:group {$json} --><div></div><!-- /wp:group -->";

    $before = memory_get_peak_usage(true);
    $out = BlockCommentRepair::repair($markup);
    $delta = memory_get_peak_usage(true) - $before;

    assert_eq($markup, $out['markup'], 'hostile payload is left for the strict gate');
    assert_true(
        $delta < 8 * 1024 * 1024,
        sprintf('candidate search stayed within budget (used %.1f MB)', $delta / 1048576),
    );
});
