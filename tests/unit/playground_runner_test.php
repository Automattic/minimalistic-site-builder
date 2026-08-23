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

test('start() takes a timeout that defaults to 240 seconds', function () {
    $params = (new ReflectionMethod(PlaygroundRunner::class, 'start'))->getParameters();
    assert_eq(2, count($params));
    assert_eq('timeoutSeconds', $params[1]->getName());
    assert_eq(240, $params[1]->getDefaultValue());
});

test('waitUntilReady throws when the deadline expires and the process is still alive', function () {
    $log = sys_get_temp_dir() . '/pg-timeout-' . getmypid() . '.log';
    file_put_contents($log, "booting…\n");
    $proc = proc_open(
        'sleep 30',
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes
    );
    assert_true(is_resource($proc), 'sleep child started');
    $t0 = microtime(true);
    $e = assert_throws(fn () => PlaygroundRunner::waitUntilReady($proc, $log, 2));
    $elapsed = microtime(true) - $t0;
    proc_terminate($proc);
    proc_close($proc);
    @unlink($log);
    assert_contains('Playground did not become ready within 2s', $e->getMessage());
    assert_true($elapsed < 5, "elapsed {$elapsed}s, want < 5");
    assert_true($elapsed >= 1.8, "elapsed {$elapsed}s, want the 2s deadline to actually wait");
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
