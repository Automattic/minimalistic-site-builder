<?php
declare(strict_types=1);

use Automattic\SiteBuild\DirectionUtilities;

test('DirectionUtilities keeps device, card, motion, and u- tokens', function () {
    assert_true(DirectionUtilities::isKeepable('device--stamp'));
    assert_true(DirectionUtilities::isKeepable('card-style--flush'));
    assert_true(DirectionUtilities::isKeepable('u-tooth'));
    assert_true(DirectionUtilities::isKeepable('hover-lift'));
    assert_true(DirectionUtilities::isKeepable('has-accent-font-family'));
    assert_true(!DirectionUtilities::isKeepable('be-inline-geometry-abc'));
    assert_true(!DirectionUtilities::isKeepable('wp-block-group'));
});

test('DirectionUtilities restore stamps dropped device and utility classes', function () {
    $source = '<section class="u-tooth device--hairline-rule"><h2>Hours</h2></section>';
    $converted = '<!-- wp:group {"tagName":"section"} -->'
        . '<section class="wp-block-group"><h2>Hours</h2></section>'
        . '<!-- /wp:group -->';
    [$restored, $repairs] = DirectionUtilities::restore($source, $converted, 'theme/parts/home-hours.html');
    assert_contains('device--hairline-rule', $restored);
    assert_contains('u-tooth', $restored);
    assert_true($repairs !== []);
});

test('DirectionUtilities restore is a no-op when tokens already survived', function () {
    $html = '<!-- wp:group {"className":"device--stamp"} --><div class="device--stamp"></div><!-- /wp:group -->';
    [$restored, $repairs] = DirectionUtilities::restore(
        '<section class="device--stamp"></section>',
        $html,
    );
    assert_eq($html, $restored);
    assert_eq([], $repairs);
});

test('DirectionUtilities warns when convert flattens a button', function () {
    $source = '<a class="wp-element-button" href="/visit/">Book a table</a>';
    $converted = '<!-- wp:paragraph --><p>Book a table</p><!-- /wp:paragraph -->';
    $warning = DirectionUtilities::buttonLossWarning($source, $converted, 'theme/parts/home-cta.html');
    assert_true(is_string($warning));
    assert_contains('flattened a button', $warning);

    $kept = DirectionUtilities::buttonLossWarning(
        $source,
        '<!-- wp:buttons --><!-- wp:button --><a class="wp-element-button">Book a table</a><!-- /wp:button --><!-- /wp:buttons -->',
    );
    assert_eq(null, $kept);
});
