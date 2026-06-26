<?php
declare(strict_types=1);

/**
 * Unit test runner. Includes every tests/unit/*_test.php and runs them.
 * Usage: php tests/run.php
 */

require_once __DIR__ . '/lib.php';

$files = glob(__DIR__ . '/unit/*_test.php') ?: [];
if ($files === []) {
    echo "No unit tests found.\n";
    exit(1);
}
foreach ($files as $f) {
    require_once $f;
}

exit(run_tests());
