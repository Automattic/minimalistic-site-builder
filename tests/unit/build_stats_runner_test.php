<?php
declare(strict_types=1);

test('build.php accepts --runner and hands the value through', function () {
    $args = parse_cli_args(
        ['bin/build.php', 'A corner bakery', '--runner=playground'],
        ['--runner' => 'value'],
        maxPositionals: 1
    );
    assert_eq(null, $args['unknown'], 'the flag is recognised');
    assert_eq('playground', $args['flags']['--runner']);
    assert_eq('A corner bakery', $args['positionals'][0]);
});

test('an unknown runner value is caught before a build starts', function () {
    $args = parse_cli_args(['bin/build.php', 'x', '--runner=lando'], ['--runner' => 'value'], maxPositionals: 1);
    assert_eq('lando', $args['flags']['--runner'], 'parsing accepts it; RunnerResolver rejects it');
});

test('build.php parse spec recognises --runner next to --slug', function () {
    $spec = ['--runner' => 'value', '--slug' => 'value', '--serve' => 'toggle'];
    $args = parse_cli_args(
        ['bin/build.php', 'prompt here', '--slug=serve-check', '--runner=studio'],
        $spec,
        maxPositionals: 1
    );
    assert_eq(null, $args['unknown']);
    assert_eq('studio', $args['flags']['--runner']);
    assert_eq('serve-check', $args['flags']['--slug']);
});
