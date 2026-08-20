<?php
declare(strict_types=1);

use Automattic\SiteBuild\Device;
use Automattic\SiteBuild\Steps\MotionSanityStep;

test('Device catalog is the bounded utility list', function () {
    assert_eq(['none', 'hairline-rule', 'section-numeral', 'stamp'], Device::ALL);
    assert_eq('stamp', Device::explicit(' Stamp '));
    assert_eq(null, Device::explicit('twine'));
    assert_eq(null, Device::className('none'));
    assert_eq('device--hairline-rule', Device::className('hairline-rule'));
});

test('Device kitCss is class-gated and absent for none', function () {
    assert_eq(null, Device::kitCss('none'));
    assert_eq(null, Device::kitCss('twine'));

    $rule = Device::kitCss('hairline-rule');
    assert_true(is_string($rule));
    assert_contains('.device--hairline-rule', $rule);
    assert_contains('box-shadow: inset 0 1px 0 0', $rule);

    $numeral = Device::kitCss('section-numeral');
    assert_true(is_string($numeral));
    assert_contains('counter-increment', $numeral);

    $stamp = Device::kitCss('stamp');
    assert_true(is_string($stamp));
    assert_contains('rotate(-8deg)', $stamp);
});

test('the device budget survives the markup the model actually wrote', function () {
    // prompts/page-plan.md budgets the device to ONE non-hero band. Nothing
    // checked that after generation, so a build could use it twice, put it on
    // the hero, or invent a variant with no CSS behind it.
    $band = static fn (string $class): string =>
        '<!-- wp:group {"className":"' . $class . '"} --><div class="wp-block-group '
        . $class . '"><!-- wp:paragraph --><p>Band</p><!-- /wp:paragraph --></div><!-- /wp:group -->';

    $budget = MotionSanityStep::newBudget();
    $first = MotionSanityStep::sanitize($band('device--stamp'), 'calm', $budget, false, 'device--stamp');
    assert_contains('device--stamp', $first['markup'], 'the first non-hero band keeps it');
    assert_eq([], $first['notes']);

    // Second band on the same page: over budget.
    $second = MotionSanityStep::sanitize($band('device--stamp'), 'calm', $budget, false, 'device--stamp');
    assert_true(!str_contains($second['markup'], 'device--stamp'), 'the second band loses it');
    assert_contains('one band per page already carries it', implode(' ', $second['notes']));
});

test('the hero never keeps the device', function () {
    $markup = '<!-- wp:group {"className":"device--hairline-rule"} --><div class="wp-block-group '
        . 'device--hairline-rule"><!-- wp:paragraph --><p>Hero</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
    $budget = MotionSanityStep::newBudget();
    $out = MotionSanityStep::sanitize($markup, 'calm', $budget, true, 'device--hairline-rule');
    assert_true(!str_contains($out['markup'], 'device--hairline-rule'));
    assert_contains('the hero never carries the device', implode(' ', $out['notes']));
    assert_eq(0, $budget['device'], 'a hero drop does not spend the page budget');
});

test('a device class the direction never committed is stripped', function () {
    $markup = '<!-- wp:group {"className":"device--stamp"} --><div class="wp-block-group device--stamp">'
        . '<!-- wp:paragraph --><p>Band</p><!-- /wp:paragraph --></div><!-- /wp:group -->';

    $none = MotionSanityStep::newBudget();
    $out = MotionSanityStep::sanitize($markup, 'calm', $none, false, null);
    assert_true(!str_contains($out['markup'], 'device--stamp'));
    assert_contains('the direction committed no device', implode(' ', $out['notes']));

    $other = MotionSanityStep::newBudget();
    $mismatch = MotionSanityStep::sanitize($markup, 'calm', $other, false, 'device--hairline-rule');
    assert_true(!str_contains($mismatch['markup'], 'device--stamp'));
    assert_contains('not the committed device', implode(' ', $mismatch['notes']));
});

test('an invented device variant with no CSS behind it is stripped', function () {
    $markup = '<!-- wp:group {"className":"device--stamp-huge"} --><div class="wp-block-group '
        . 'device--stamp-huge"><!-- wp:paragraph --><p>Band</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
    $budget = MotionSanityStep::newBudget();
    $out = MotionSanityStep::sanitize($markup, 'calm', $budget, false, 'device--stamp');
    assert_true(!str_contains($out['markup'], 'device--stamp-huge'));
});

test('the device budget also catches a class living only in saved HTML', function () {
    // The block fixer rescues HTML-only classes back into className, which is
    // why the motion pass reads both. The device pass has to as well.
    $markup = '<!-- wp:group --><div class="wp-block-group device--stamp">'
        . '<!-- wp:paragraph --><p>Band</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
    $budget = MotionSanityStep::newBudget();
    $out = MotionSanityStep::sanitize($markup, 'calm', $budget, true, 'device--stamp');
    assert_true(!str_contains($out['markup'], 'device--stamp'), 'hero drop reaches the saved HTML');
});
