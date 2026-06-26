<?php
declare(strict_types=1);

/**
 * Tiny zero-dependency test harness. Test files register cases with test();
 * run.php includes them and executes. Assertions throw on failure; the runner
 * reports pass/fail counts and exits non-zero if anything failed.
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/FakeLlm.php';

/** @var array<int,array{0:string,1:callable}> */
$GLOBALS['__tests'] = [];

function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][] = [$name, $fn];
}

function assert_true(bool $cond, string $msg = ''): void
{
    if (!$cond) {
        throw new RuntimeException('assert_true failed' . ($msg !== '' ? ": {$msg}" : ''));
    }
}

function assert_eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            'assert_eq failed: expected ' . var_export($expected, true)
            . ' got ' . var_export($actual, true) . ($msg !== '' ? " — {$msg}" : '')
        );
    }
}

function assert_contains(string $needle, string $haystack, string $msg = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException(
            "assert_contains failed: '{$needle}' not found" . ($msg !== '' ? " — {$msg}" : '')
        );
    }
}

function assert_throws(callable $fn, string $msg = ''): void
{
    try {
        $fn();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException('assert_throws failed: no exception' . ($msg !== '' ? ": {$msg}" : ''));
}

/** Run all registered tests, print results, return exit code. */
function run_tests(): int
{
    $pass = 0;
    $fail = 0;
    foreach ($GLOBALS['__tests'] as [$name, $fn]) {
        try {
            $fn();
            echo "  PASS  {$name}\n";
            $pass++;
        } catch (Throwable $e) {
            echo "  FAIL  {$name}\n        {$e->getMessage()}\n";
            $fail++;
        }
    }
    echo "\n{$pass} passed, {$fail} failed\n";
    return $fail === 0 ? 0 : 1;
}
