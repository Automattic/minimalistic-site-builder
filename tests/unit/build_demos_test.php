<?php
declare(strict_types=1);

require_once repo_path('bin/build-demos.php');

test('build-demos forwards motion only to motion-enabled screenshots', function () {
    $static = demo_screenshot_command('demo slug', '/tmp/demo shot.png', 9450, false);
    assert_contains(escapeshellarg('demo slug'), $static);
    assert_contains('--port=9450', $static);
    assert_contains('--out=' . escapeshellarg('/tmp/demo shot.png'), $static);
    assert_true(!str_contains($static, '--motion'), 'default keeps the race-free reduced-motion capture');

    $motion = demo_screenshot_command('demo slug', '/tmp/demo shot.png', 9450, true);
    assert_true(str_ends_with($motion, ' --motion'), 'explicit motion reaches screenshot.php');
});
