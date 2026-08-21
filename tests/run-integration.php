<?php
declare(strict_types=1);

/**
 * Integration test runner. Includes tests/integration/*_test.php.
 * Deterministic tests use FakeLlm.
 * Usage: php tests/run-integration.php
 */

require_once __DIR__ . '/lib.php';

$files = glob(__DIR__ . '/integration/*_test.php') ?: [];
if ($files === []) {
    fwrite(STDERR, "No integration test files matched tests/integration/*_test.php\n");
    exit(1);
}
foreach ($files as $f) {
    require_once $f;
}

exit(run_tests());
