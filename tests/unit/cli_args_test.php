<?php
declare(strict_types=1);

/** The bin/ scripts pass their real $argv, so every case starts with a script path. */
function cli_args(array $args, array $spec, int $maxPositionals = 0): array
{
    return parse_cli_args(array_merge(['bin/script.php'], $args), $spec, $maxPositionals);
}

test('parse_cli_args reads value flags and leaves the value untouched', function () {
    $args = cli_args(
        ['--slug=my-site', '--pages=Home, Menu, About'],
        ['--slug' => 'value', '--pages' => 'value']
    );

    assert_eq('my-site', $args['flags']['--slug']);
    assert_eq('Home, Menu, About', $args['flags']['--pages']);
    assert_eq(null, $args['unknown']);
    assert_eq([], $args['positionals']);
});

test('parse_cli_args splits a value on its first = and keeps the rest', function () {
    $args = cli_args(['--out=/tmp/a=b.png'], ['--out' => 'value']);

    assert_eq('/tmp/a=b.png', $args['flags']['--out']);
});

test('parse_cli_args records an empty value flag as an empty string', function () {
    $args = cli_args(['--slug='], ['--slug' => 'value']);

    assert_eq('', $args['flags']['--slug']);
    assert_eq(null, $args['unknown']);
});

test('parse_cli_args rejects a value flag written without its value', function () {
    $args = cli_args(['--slug'], ['--slug' => 'value']);

    assert_eq('--slug', $args['unknown']);
    assert_eq([], $args['flags']);
});

test('parse_cli_args records bool flags as true and absent ones not at all', function () {
    $args = cli_args(['--multi-page'], ['--multi-page' => 'bool', '--with-images' => 'bool']);

    assert_eq(true, $args['flags']['--multi-page']);
    assert_true(!isset($args['flags']['--with-images']), 'an absent bool flag is not recorded');
});

test('parse_cli_args rejects a bool flag given a value', function () {
    $args = cli_args(['--multi-page=1'], ['--multi-page' => 'bool']);

    assert_eq('--multi-page=1', $args['unknown']);
});

test('parse_cli_args reads both spellings of a toggle', function () {
    $spec = ['--serve' => 'toggle'];

    assert_eq(true, cli_args(['--serve'], $spec)['flags']['--serve']);
    assert_eq(false, cli_args(['--no-serve'], $spec)['flags']['--serve']);
});

test('parse_cli_args lets the last spelling of a toggle win', function () {
    $spec = ['--serve' => 'toggle', '--screenshot' => 'toggle'];

    assert_eq(false, cli_args(['--serve', '--no-serve'], $spec)['flags']['--serve']);
    assert_eq(true, cli_args(['--no-serve', '--serve'], $spec)['flags']['--serve']);
    assert_eq(false, cli_args(['--screenshot', '--no-screenshot'], $spec)['flags']['--screenshot']);
});

test('parse_cli_args negates only what the spec declares a toggle', function () {
    // A spec is free to declare a '--no-…' name as a plain bool: it is then
    // recorded under the name it was declared with, and the positive spelling
    // is not a flag at all. Only 'toggle' makes the pair.
    $args = cli_args(['--no-serve'], ['--no-serve' => 'bool']);
    assert_eq(true, $args['flags']['--no-serve']);

    assert_eq('--serve', cli_args(['--serve'], ['--no-serve' => 'bool'])['unknown']);
    assert_eq('--no-cache', cli_args(['--no-cache'], ['--cache' => 'bool'])['unknown']);
});

test('parse_cli_args fills positional slots in order', function () {
    $args = cli_args(['first', 'second'], [], 2);

    assert_eq(['first', 'second'], $args['positionals']);
    assert_eq(null, $args['unknown']);
});

test('parse_cli_args rejects a positional past the last slot', function () {
    $args = cli_args(['a prompt', 'a second one'], [], 1);

    assert_eq('a second one', $args['unknown']);
    assert_eq(['a prompt'], $args['positionals']);
});

test('parse_cli_args rejects every bare argument when the script takes none', function () {
    assert_eq('stray', cli_args(['stray'], ['--only' => 'value'])['unknown']);
});

test('parse_cli_args takes a positional after the flags it follows', function () {
    $args = cli_args(['--slug=x', 'a prompt', '--multi-page'], [
        '--slug'       => 'value',
        '--multi-page' => 'bool',
    ], 1);

    assert_eq(['a prompt'], $args['positionals']);
    assert_eq('x', $args['flags']['--slug']);
    assert_eq(true, $args['flags']['--multi-page']);
});

test('parse_cli_args treats a single-dash argument as a positional unless declared', function () {
    // bin/screenshot.php reads `-h` as its slug; bin/publish-playground.php
    // declares it, so there it is a flag.
    assert_eq(['-h'], cli_args(['-h'], [], 1)['positionals']);
    assert_eq(true, cli_args(['-h'], ['-h' => 'bool'], 1)['flags']['-h']);
});

test('parse_cli_args reports an undeclared flag as unknown', function () {
    $args = cli_args(['--slug=x', '--bogus', '--multi-page'], [
        '--slug'       => 'value',
        '--multi-page' => 'bool',
    ]);

    assert_eq('--bogus', $args['unknown']);
});

test('parse_cli_args gives a bare -- no special meaning', function () {
    $args = cli_args(['--', 'a prompt'], [], 1);

    assert_eq('--', $args['unknown']);
    assert_eq([], $args['positionals']);
});

test('parse_cli_args stops at the first unknown argument', function () {
    // The results describe what was understood BEFORE the bad argument and
    // nothing after it, so a caller that acts on a flag before reporting the
    // bad one still reads the line strictly left to right.
    $spec = ['--help' => 'bool', '--open' => 'bool'];

    $helpFirst = cli_args(['--help', '--bogus'], $spec);
    assert_eq(true, $helpFirst['flags']['--help']);
    assert_eq('--bogus', $helpFirst['unknown']);

    $helpAfter = cli_args(['--bogus', '--help'], $spec);
    assert_eq([], $helpAfter['flags']);
    assert_eq('--bogus', $helpAfter['unknown']);
});

test('parse_cli_args lets a repeated value flag win with its last occurrence', function () {
    $args = cli_args(['--port=9400', '--port=9500'], ['--port' => 'value']);

    assert_eq('9500', $args['flags']['--port']);
});

test('parse_cli_args returns empty results for a bare command line', function () {
    $args = cli_args([], ['--slug' => 'value'], 1);

    assert_eq([], $args['flags']);
    assert_eq([], $args['positionals']);
    assert_eq(null, $args['unknown']);
});
