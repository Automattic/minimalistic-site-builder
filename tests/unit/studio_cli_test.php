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
