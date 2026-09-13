<?php
declare(strict_types=1);

/**
 * @param list<array{0:string,1:string}> $cases Each as [file, name].
 * @return array<int,array{0:string,1:callable,2:string}>
 */
function harness_cases(array $cases): array
{
    return array_map(
        static fn (array $case): array => [$case[1], static fn () => null, $case[0]],
        $cases,
    );
}

function harness_registry(): array
{
    return harness_cases([
        ['content_slots_test', 'a slot is found for every block that carries text'],
        ['content_slots_test', 'a void block is not a slot'],
        ['export_bundle_test', 'navigation reaches the bundle'],
    ]);
}

/** @return list<string> */
function harness_names(array $selected): array
{
    return array_column($selected, 0);
}

test('a filter naming a file selects the cases in it', function () {
    $selected = select_tests(harness_registry(), ['content_slots']);

    assert_eq(2, count($selected));
    assert_contains('a void block is not a slot', implode('|', harness_names($selected)));
});

test('a filter naming a case selects that one', function () {
    $selected = select_tests(harness_registry(), ['navigation reaches']);

    assert_eq(['navigation reaches the bundle'], harness_names($selected));
});

test('a filter matches whatever the case was called, in any case', function () {
    assert_eq(2, count(select_tests(harness_registry(), ['CONTENT_SLOTS'])));
});

test('several filters select everything any of them names', function () {
    $selected = select_tests(harness_registry(), ['export_bundle', 'a void block']);

    assert_eq(2, count($selected));
});

/**
 * The runner turns this into a non-zero exit rather than a run. "0 passed" and
 * a zero exit is what a clean suite looks like, and a filter with a typo in it
 * would otherwise say exactly that.
 */
test('a filter that names nothing selects nothing', function () {
    assert_eq([], select_tests(harness_registry(), ['no-such-thing']));
});

/**
 * Not a detail: the cases in one file use helpers another file defines, so
 * every file is loaded and only the cases are filtered. Without the file
 * recorded at load time, a filter could only match case names.
 */
test('every registered case knows which file it came from', function () {
    foreach ($GLOBALS['__tests'] as [$name, $fn, $file]) {
        assert_true($file !== '', "case '{$name}' records no file");
    }
});
