<?php
declare(strict_types=1);

use Automattic\SiteBuild\PlaygroundRunner;
use Automattic\SiteBuild\RunningSite;

test('readyUrl pulls the port out of Playground readiness line', function () {
    $log = "Ready! WordPress is running on http://127.0.0.1:9412\n";
    assert_eq('http://127.0.0.1:9412/', PlaygroundRunner::readyUrl($log));
});

test('readyUrl sees through the colour escapes the CLI adds on a TTY', function () {
    $log = "\x1b[32mReady!\x1b[0m WordPress is running on \x1b[36mhttp://127.0.0.1:9400\x1b[0m";
    assert_eq('http://127.0.0.1:9400/', PlaygroundRunner::readyUrl($log));
});

test('readyUrl returns null until the line appears', function () {
    assert_eq(null, PlaygroundRunner::readyUrl("booting…\n"));
});

test('a Playground site is not persistent, so the caller owns stopping it', function () {
    $stopped = false;
    $site = new RunningSite(
        url: 'http://127.0.0.1:9400/',
        adminUrl: 'http://127.0.0.1:9400/wp-admin/',
        persistent: false,
        stop: function () use (&$stopped) { $stopped = true; },
    );
    assert_true(!$site->persistent, 'ephemeral');
    ($site->stop)();
    assert_true($stopped, 'stop ran');
});
