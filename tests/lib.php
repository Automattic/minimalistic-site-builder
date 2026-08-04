<?php
declare(strict_types=1);

/**
 * Tiny zero-dependency test harness. Test files register cases with test();
 * run.php includes them and executes. Assertions throw on failure; the runner
 * reports pass/fail counts and exits non-zero if anything failed.
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/FakeLlm.php';
require_once __DIR__ . '/doubles.php';

/** @var array<int,array{0:string,1:callable}> */
$GLOBALS['__tests'] = [];

/** Raised by skip_test() so a missing optional capability is never a false pass. */
final class TestSkipped extends RuntimeException
{
}

function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][] = [$name, $fn];
}

/** Mark the current test as explicitly skipped, with a reviewable reason. */
function skip_test(string $reason): never
{
    throw new TestSkipped($reason);
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

/** Assert the callable throws, and return the Throwable so callers can inspect it. */
function assert_throws(callable $fn, string $msg = ''): Throwable
{
    try {
        $fn();
    } catch (TestSkipped $e) {
        throw $e;
    } catch (Throwable $e) {
        return $e;
    }
    throw new RuntimeException('assert_throws failed: no exception' . ($msg !== '' ? ": {$msg}" : ''));
}

/** Recursively delete a file or directory tree; missing paths are a no-op. */
function remove_tree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    @chmod($path, 0775);
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            remove_tree($path . '/' . $name);
        }
    }
    @rmdir($path);
}

/** Run $fn($dir) with a fresh temp dir, removing the tree even when the test fails. */
function with_temp_dir(string $prefix, callable $fn): mixed
{
    $dir = sys_get_temp_dir() . '/' . $prefix . uniqid();
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Could not create temp dir: {$dir}");
    }
    try {
        return $fn($dir);
    } finally {
        remove_tree($dir);
    }
}

/** Run $fn($project, $dir) with a throwaway project in a scoped temp dir. */
function with_project(string $prefix, callable $fn): mixed
{
    return with_temp_dir($prefix, function (string $dir) use ($fn): mixed {
        return $fn((new \Automattic\SiteBuild\ProjectStore($dir))->create('demo'), $dir);
    });
}

/** Run $fn with its output buffered and discarded — even when it throws. */
function quietly(callable $fn): mixed
{
    ob_start();
    try {
        return $fn();
    } finally {
        ob_end_clean();
    }
}

/** Run all registered tests, print results, return exit code. */
function run_tests(): int
{
    $pass = 0;
    $fail = 0;
    $skip = 0;
    foreach ($GLOBALS['__tests'] as [$name, $fn]) {
        $obLevel = ob_get_level();
        try {
            $fn();
            $line = "  PASS  {$name}\n";
            $pass++;
        } catch (TestSkipped $e) {
            $line = "  SKIP  {$name}\n        {$e->getMessage()}\n";
            $skip++;
        } catch (Throwable $e) {
            $line = "  FAIL  {$name}\n        {$e->getMessage()}\n";
            $fail++;
        }
        while (ob_get_level() > $obLevel) {
            ob_end_clean();
        }
        echo $line;
    }
    echo "\n{$pass} passed, {$fail} failed, {$skip} skipped\n";
    return $fail === 0 ? 0 : 1;
}
