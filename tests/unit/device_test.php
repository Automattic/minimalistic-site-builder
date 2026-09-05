<?php
declare(strict_types=1);

use Automattic\SiteBuild\Device;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\MotionSanityStep;

test('Device catalog is the bounded utility list', function () {
    assert_eq(['none', 'hairline-rule', 'stamp'], Device::ALL);
    // section-numeral left the catalog with BIGR-949: decorative sequence
    // numbers are banned unless the site brief asks for them.
    assert_eq(null, Device::explicit('section-numeral'));
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

    assert_eq(null, Device::kitCss('section-numeral'), 'the removed numeral device ships no CSS');

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

test('the html-first path holds the device budget it promised the model', function () {
    // prompts/homepage-design.md and prompts/inner-section-design.md both tell
    // the model the build strips a device off the hero or off a second band,
    // and FinalizeThemeStep ships the CSS in that graph. The step skips the
    // legacy motion fixup, so the device guard has to run there on its own.
    $tmp = sys_get_temp_dir() . '/builder_device_htmlfirst_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('designDirection.json', ['motion' => 'none', 'device' => 'stamp']);

    $band = static fn (string $class): string =>
        '<!-- wp:group {"className":"' . $class . '"} --><div class="wp-block-group ' . $class
        . '"><!-- wp:paragraph --><p>Band</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
    $project->writeText(
        'theme/parts/section.html',
        $band('device--stamp reveal-up') . $band('device--stamp'),
    );

    try {
        quietly(fn () => (new MotionSanityStep(htmlFirst: true))->run($project));
        $out = $project->readText('theme/parts/section.html');

        // One surviving band carries it twice: in className and in its saved
        // HTML. The second band is gone from both.
        assert_eq(2, substr_count($out, 'device--stamp'), 'exactly one band keeps the device');
        // Motion is still the new CSS path's business, not this step's: the
        // profile is 'none', so a motion pass would have taken reveal-up too.
        assert_contains('reveal-up', $out, 'the motion fixup stays skipped');

        $warnings = $project->readJson('warnings.json');
        assert_contains('device class stripped', implode(' ', $warnings['motion-sanity'] ?? []));
        assert_eq(1, count($warnings['fixup_skipped'] ?? []), 'the skip contract is still recorded');
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});


test('a kit device on a custom-motion target stays and the custom tag leaves the block (frm PR-8j)', function () {
    $block = static fn (string $class): string =>
        '<!-- wp:group {"className":"section-composition--feature-row-hairlines"} --><div class="wp-block-group section-composition--feature-row-hairlines">'
        . '<!-- wp:paragraph {"className":"' . $class . '","fontSize":"section-title"} --><p class="' . $class . ' has-section-title-font-size">More projects</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';

    $budget = MotionSanityStep::newBudget();
    $out = MotionSanityStep::sanitize($block('custom-motion marquee'), 'dramatic', $budget, false);
    assert_contains('"className":"marquee"', $out['markup'], 'the kit marquee is the request');
    assert_contains('class="marquee has-section-title-font-size"', $out['markup'], 'the saved HTML loses the custom tag too');
    assert_true(!str_contains($out['markup'], 'custom-motion'));
    assert_eq(1, count($out['notes']));
    assert_contains("dropped 'custom-motion' (the kit device 'marquee' on the block already implements the request)", $out['notes'][0]);

    // A counting figure is the other device.
    $budget = MotionSanityStep::newBudget();
    $figure = '<!-- wp:group --><div class="wp-block-group"><!-- wp:heading {"level":3,"className":"custom-motion count-up"} --><h3 class="wp-block-heading custom-motion count-up">120+</h3><!-- /wp:heading --></div><!-- /wp:group -->';
    $out = MotionSanityStep::sanitize($figure, 'dramatic', $budget, false);
    assert_contains('"className":"count-up"', $out['markup']);
    assert_true(!str_contains($out['markup'], 'custom-motion'));

    // No device beside the tag: the tag stays and preset motion is evicted as before.
    $budget = MotionSanityStep::newBudget();
    $out = MotionSanityStep::sanitize($block('custom-motion reveal-up'), 'dramatic', $budget, false);
    assert_contains('"className":"custom-motion"', $out['markup']);
    assert_true(!str_contains($out['markup'], 'reveal-up'));
    assert_contains('custom-motion target', $out['notes'][0]);

    // A profile that forbids the device evicts it and keeps the custom tag.
    $budget = MotionSanityStep::newBudget();
    $out = MotionSanityStep::sanitize($block('custom-motion marquee'), 'none', $budget, false);
    assert_contains('custom-motion', $out['markup']);
    assert_true(!str_contains($out['markup'], 'marquee'));
});
