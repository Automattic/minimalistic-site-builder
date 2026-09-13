<?php
declare(strict_types=1);

/**
 * Unit test runner. Includes every tests/unit/*_test.php and runs them.
 *
 * Usage: php tests/run.php [filter...]
 *
 * A filter is a substring matched against the test file's name and the case's
 * name, so `php tests/run.php content_slots` runs one file's cases and
 * `php tests/run.php "slot ids"` runs the cases whose names say that. Several
 * filters select the union.
 *
 * Every file is loaded either way, and only the cases are filtered: cases in
 * one file use helpers another defines, and loading all of them costs 0.12s
 * against the 95s of running them.
 */

require_once __DIR__ . '/lib.php';

$files = glob(__DIR__ . '/unit/*_test.php') ?: [];
if ($files === []) {
    echo "No unit tests found.\n";
    exit(1);
}
foreach ($files as $f) {
    load_test_file($f);
}

exit(run_tests(array_slice($argv, 1)));
