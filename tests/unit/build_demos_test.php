<?php
declare(strict_types=1);

require_once repo_path('bin/build-demos.php');

test('build-demos forwards motion only to motion-enabled screenshots', function () {
    // Screenshot children are spawned with an argv list, not a shell string,
    // so each element must arrive verbatim and unquoted.
    $static = demo_screenshot_argv('demo slug', '/tmp/demo shot.png', 9450, false);
    assert_true(in_array('demo slug', $static, true), 'slug travels as one unquoted element');
    assert_true(in_array('--port=9450', $static, true), 'port reaches screenshot.php');
    assert_true(in_array('--out=/tmp/demo shot.png', $static, true), 'out travels unquoted');
    assert_true(!in_array('--motion', $static, true), 'default keeps the race-free reduced-motion capture');

    $motion = demo_screenshot_argv('demo slug', '/tmp/demo shot.png', 9450, true);
    assert_eq('--motion', $motion[array_key_last($motion)], 'explicit motion reaches screenshot.php');
    assert_eq($static, array_slice($motion, 0, count($static)), 'motion adds a flag and changes nothing else');
});
