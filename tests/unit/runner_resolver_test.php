<?php
declare(strict_types=1);

use Automattic\SiteBuild\RunnerResolver;
use Automattic\SiteBuild\StudioCli;

function cli_available(bool $ok): StudioCli
{
    return new StudioCli(fn (string $c, int $t): array
        => ['exitCode' => $ok ? 0 : 1, 'stdout' => $ok ? '[]' : '', 'stderr' => '']);
}

test('Studio is the default when it is available', function () {
    assert_eq('studio', RunnerResolver::resolve(null, cli_available(true), fn () => null)->name());
});

test('an unavailable Studio falls back to Playground and warns exactly once', function () {
    $warnings = [];
    $r = RunnerResolver::resolve(null, cli_available(false), function ($m) use (&$warnings) { $warnings[] = $m; });
    assert_eq('playground', $r->name());
    assert_eq(1, count($warnings), 'one note, not one per step');
});

test('--runner=studio with Studio unavailable is an error, never a silent downgrade', function () {
    $e = assert_throws(fn () => RunnerResolver::resolve('studio', cli_available(false), fn () => null));
    assert_contains('not available', $e->getMessage());
});

test('--runner=playground is honoured even when Studio is available', function () {
    assert_eq('playground', RunnerResolver::resolve('playground', cli_available(true), fn () => null)->name());
});

test('SITE_BUILD_RUNNER selects the backend when no flag is given', function () {
    putenv('SITE_BUILD_RUNNER=playground');
    assert_eq('playground', RunnerResolver::resolve(null, cli_available(true), fn () => null)->name());
    putenv('SITE_BUILD_RUNNER');
});

test('an unknown runner name is rejected', function () {
    assert_throws(fn () => RunnerResolver::resolve('lando', cli_available(true), fn () => null));
});
