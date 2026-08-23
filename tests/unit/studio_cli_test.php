<?php
declare(strict_types=1);

use Automattic\SiteBuild\StudioCli;

/** @return \Closure a fake exec returning one canned result */
function fake_exec(int $code, string $out, string $err = ''): \Closure
{
    return fn (string $cmd, int $timeout): array
        => ['exitCode' => $code, 'stdout' => $out, 'stderr' => $err];
}

test('stripAnsi removes the escape sequences Studio writes to stderr', function () {
    $raw = "\x1b[K\x1b[?25l⠋ \x1b[1A\x1b[KNo sites found";
    $clean = StudioCli::stripAnsi($raw);
    assert_eq('⠋ No sites found', $clean);
    assert_true(!str_contains($clean, "\x1b"), 'no escape bytes survive');
});

test('redact hides an admin password so it can never reach a build log', function () {
    $json = '{"siteUrl":"http://localhost:8881/","adminPassword":"hunter2!x"}';
    $safe = StudioCli::redact($json);
    assert_true(!str_contains($safe, 'hunter2!x'), 'password is gone');
    assert_contains('siteUrl', $safe, 'the rest survives');
});

test('json returns the decoded payload when every required key is present', function () {
    $cli = new StudioCli(fake_exec(0, '{"siteUrl":"http://localhost:8881/","isOnline":true}'));
    $out = $cli->json(['status', '--format', 'json'], ['siteUrl', 'isOnline']);
    assert_eq('http://localhost:8881/', $out['siteUrl']);
});

test('json rejects a payload missing a required key, so a CLI upgrade fails loudly', function () {
    $cli = new StudioCli(fake_exec(0, '{"siteUrl":"http://localhost:8881/"}'));
    $e = assert_throws(fn () => $cli->json(['status'], ['siteUrl', 'isOnline']));
    assert_contains('isOnline', $e->getMessage());
});

test('json rejects non-JSON stdout', function () {
    $cli = new StudioCli(fake_exec(0, 'not json at all'));
    assert_throws(fn () => $cli->json(['status'], []));
});

test('json rejects a non-zero exit and reports the stderr reason', function () {
    $cli = new StudioCli(fake_exec(1, '', 'The specified directory is not added to Studio.'));
    $e = assert_throws(fn () => $cli->json(['status'], []));
    assert_contains('not added to Studio', $e->getMessage());
});

test('json never puts adminPassword into an exception message', function () {
    $payload = "Warning: studio upgrade available\n{\"siteUrl\":\"http://localhost:8881/\",\"adminPassword\":\"hunter2SECRET\"}";
    $cli = new StudioCli(fake_exec(0, $payload));
    $e = assert_throws(fn () => $cli->json(['status', '--format', 'json'], []));
    assert_true(!str_contains($e->getMessage(), 'hunter2SECRET'), 'plaintext password must not reach the exception');
});

test('json blanks adminPassword before returning to callers', function () {
    $payload = '{"siteUrl":"http://localhost:8881/","autoLoginUrl":"http://localhost:8881/wp-login.php","isOnline":true,"adminPassword":"hunter2SECRET"}';
    $cli = new StudioCli(fake_exec(0, $payload));
    $out = $cli->json(['status', '--format', 'json'], ['siteUrl', 'autoLoginUrl', 'isOnline', 'adminPassword']);
    assert_true(array_key_exists('adminPassword', $out), 'key stays so a required-key check cannot silently pass');
    assert_true($out['adminPassword'] !== 'hunter2SECRET', 'plaintext password must not leave StudioCli');
    assert_eq('', $out['adminPassword']);
    assert_eq('http://localhost:8881/', $out['siteUrl']);
});

test('run() redacts adminPassword from stdout', function () {
    $cli = new StudioCli(fake_exec(0, '{"siteUrl":"http://localhost:8881/","adminPassword":"hunter2SECRET"}'));
    $r = $cli->run(['status', '--format', 'json']);
    assert_true(!str_contains($r['stdout'], 'hunter2SECRET'), 'password is gone from run() stdout');
    assert_contains('siteUrl', $r['stdout'], 'the rest survives');
});

test('available() follows the injected exec, not the real PATH', function () {
    $ok = new StudioCli(fake_exec(0, '[]'));
    assert_true($ok->available() === true, 'fake exitCode 0 must make available() true even when studio is absent from PATH');
    $no = new StudioCli(fake_exec(1, '', 'nope'));
    assert_true($no->available() === false, 'fake exitCode 1 must make available() false');
});

test('run() lets one slow command override the instance timeout', function () {
    $seen = [];
    $cli = new StudioCli(function (string $cmd, int $t) use (&$seen): array {
        $seen[] = $t;
        return ['exitCode' => 0, 'stdout' => '', 'stderr' => ''];
    }, 120);
    $cli->run(['list']);
    $cli->run(['create', '--path', '/tmp/x'], 300);
    $cli->run(['status']);
    assert_eq([120, 300, 120], $seen, 'the override applies to that call only');
});

test('the studio process is handed an empty stdin, never the caller terminal', function () {
    // Deliberately discriminating: the outer shell pipes eight bytes into the
    // PHP process, so an inherited fd 0 would let the child read them. Studio
    // prompts for six create options whenever stdin is a terminal, and every
    // prompt is invisible once stdout is a pipe -- the command just waits.
    $script = <<<'INNER'
        require getenv('SB_BOOTSTRAP');
        $m = new ReflectionMethod(Automattic\SiteBuild\StudioCli::class, 'realExec');
        $m->setAccessible(true);
        $r = $m->invoke(null, 'wc -c', 10);
        echo (int) trim($r['stdout']);
        INNER;
    $cmd = 'printf aaaaaaaa | SB_BOOTSTRAP=' . escapeshellarg(repo_path('src/bootstrap.php'))
        . ' php -r ' . escapeshellarg($script);
    assert_eq('0', trim((string) shell_exec($cmd)), 'the child read zero bytes, not our eight');
});
