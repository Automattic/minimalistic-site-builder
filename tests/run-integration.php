<?php
declare(strict_types=1);

/**
 * Integration test runner. Includes tests/integration/*_test.php.
 * Deterministic tests use FakeLlm; tests whose filename ends in _live_test.php
 * hit the real API and are only included when RUN_LIVE=1.
 * Usage: php tests/run-integration.php   (add RUN_LIVE=1 for live tests)
 */

require_once __DIR__ . '/lib.php';

$files = glob(__DIR__ . '/integration/*_test.php') ?: [];
$live = getenv('RUN_LIVE') === '1';
foreach ($files as $f) {
    if (str_ends_with($f, '_live_test.php') && !$live) {
        continue;
    }
    require_once $f;
}

exit(run_tests());
