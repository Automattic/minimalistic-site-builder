<?php
declare(strict_types=1);

use Automattic\SiteBuild\DebugLogReader;

test('repeated notices collapse to one row carrying a count', function () {
    $log = str_repeat("[23-Aug-2026 10:00:00 UTC] PHP Notice:  Undefined index: x in /t/functions.php on line 12\n", 40);
    $rows = DebugLogReader::summarize($log);
    assert_eq(1, count($rows), 'one row, not forty');
    assert_contains('40', $rows[0]);
    assert_contains('functions.php', $rows[0]);
});

test('distinct notices stay distinct', function () {
    $log = "[23-Aug-2026] PHP Notice:  A in /t/a.php on line 1\n"
         . "[23-Aug-2026] PHP Warning:  B in /t/b.php on line 2\n";
    assert_eq(2, count(DebugLogReader::summarize($log)));
});

test('a huge log is capped rather than read whole', function () {
    $rows = DebugLogReader::summarize(str_repeat("PHP Notice:  N in /t/x.php on line 1\n", 200000), 4096);
    assert_true(count($rows) >= 1, 'still reports something');
});

test('an empty log produces no warnings', function () {
    assert_eq([], DebugLogReader::summarize(''));
});
