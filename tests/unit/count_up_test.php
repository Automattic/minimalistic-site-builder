<?php
declare(strict_types=1);

use Automattic\SiteBuild\Motion;
use Automattic\SiteBuild\Steps\MotionSanityStep;

test('count-up is an observed entrance that spends no section budget and runs on text blocks only (frm W8b)', function () {
    assert_true(in_array('count-up', Motion::SCROLL_CLASSES, true));
    assert_true(in_array('count-up', Motion::UNBUDGETED_ENTRANCES, true));
    assert_true(Motion::looksLikeMotionClass('count-up'));
    assert_true(!in_array('count-up', Motion::allowedClasses('minimal'), true));

    $figure = static fn (string $text): string => '<!-- wp:heading {"level":3,"className":"count-up"} --><h3 class="wp-block-heading count-up">' . $text . '</h3><!-- /wp:heading -->';
    $budget = MotionSanityStep::newBudget();
    $row = '<!-- wp:group {"className":"reveal"} --><div class="wp-block-group reveal">'
        . $figure('120+') . $figure('98%') . $figure('$4.2M') . $figure('1,200')
        . '<!-- wp:paragraph {"className":"reveal-fade"} --><p class="reveal-fade">Second entrance</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    $result = MotionSanityStep::sanitize($row, 'calm', $budget);
    assert_eq(4, substr_count($result['markup'], 'class="wp-block-heading count-up"'), 'every figure counts');
    assert_contains('class="reveal-fade"', $result['markup'], 'the two-entrance budget is untouched by the figures');

    $group = MotionSanityStep::sanitize('<!-- wp:group {"className":"count-up"} --><div class="wp-block-group count-up"><!-- wp:paragraph --><p>7</p><!-- /wp:paragraph --></div><!-- /wp:group -->', 'calm', MotionSanityStep::newBudget());
    assert_true(!str_contains($group['markup'], 'count-up'), 'a group cannot count');
});

test('the driver counts a figure up from zero after it enters and the kit never hides it at rest (frm W8b)', function () {
    $js = (string) file_get_contents(repo_path('assets/motion/motion.js'));
    assert_contains('.count-up', $js);
    assert_contains('function startCountUp(target)', $js);
    assert_true(strpos($js, 'startCountUp(target);') > strpos($js, "target.classList.add('is-visible');\n            startCountUp"), 'the count starts when the target is shown');
    assert_contains('--motion-count-duration', $js);
    assert_contains("target.textContent = progress >= 1 ? original : format(finalValue * eased)", $js, 'the authored text is restored at the end');
    $css = (string) file_get_contents(repo_path('assets/motion/motion.css'));
    assert_contains('.count-up {', $css);
    assert_contains('font-variant-numeric: tabular-nums', $css);
    assert_true(!str_contains($css, '.count-up.motion-target:not(.is-visible)'), 'a figure is never hidden at rest');
    foreach (['calm', 'energetic', 'dramatic', 'minimal'] as $profile) {
        assert_contains('--motion-count-duration:', (string) file_get_contents(repo_path("assets/motion/profiles/{$profile}.css")), $profile);
    }
    assert_contains("'.count-up',", (string) file_get_contents(repo_path('bin/screenshot/screenshot.js')), 'the screenshot harness settles the figures');
});
