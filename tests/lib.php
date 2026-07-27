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
    $skip = 0;
    foreach ($GLOBALS['__tests'] as [$name, $fn]) {
        try {
            $fn();
            echo "  PASS  {$name}\n";
            $pass++;
        } catch (TestSkipped $e) {
            echo "  SKIP  {$name}\n        {$e->getMessage()}\n";
            $skip++;
        } catch (Throwable $e) {
            echo "  FAIL  {$name}\n        {$e->getMessage()}\n";
            $fail++;
        }
    }
    echo "\n{$pass} passed, {$fail} failed, {$skip} skipped\n";
    return $fail === 0 ? 0 : 1;
}

/**
 * Assert that a block the serializer cannot canonicalize is kept verbatim and
 * reported, rather than taking the file (or the run) down with it.
 *
 * @return string the reported reason
 */
function assert_block_kept_as_authored(string $markup, ?string $reasonFragment = null): string
{
    $result = (new Automattic\SiteBuild\BlockSerializer\Serializer())->transform($markup);
    assert_eq(trim($markup), trim($result->html), 'the block keeps its authored bytes verbatim');
    $kept = array_values(array_filter(
        $result->repairs,
        static fn ($r): bool => str_starts_with($r->code, 'block-kept-as-authored:'),
    ));
    assert_eq(1, count($kept), 'exactly one block was kept as authored');
    if ($reasonFragment !== null) {
        assert_contains($reasonFragment, $kept[0]->code);
    }
    return $kept[0]->code;
}
