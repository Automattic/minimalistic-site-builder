<?php
declare(strict_types=1);

use Automattic\SiteBuild\StepDeclaration;

test('StepDeclaration stores id label reads writes concurrent', function () {
    $d = new StepDeclaration(
        id: 'site-spec',
        label: 'Generate site spec',
        reads: ['meta.json'],
        writes: ['siteSpec.json'],
        concurrent: false,
    );
    assert_eq('site-spec', $d->id);
    assert_eq('Generate site spec', $d->label);
    assert_eq(['meta.json'], $d->reads);
    assert_eq(['siteSpec.json'], $d->writes);
    assert_eq(false, $d->concurrent);
});

test('StepDeclaration rejects empty id', function () {
    assert_throws(fn () => new StepDeclaration('', 'x', [], [], false));
});

test('StepDeclaration rejects empty path strings', function () {
    assert_throws(fn () => new StepDeclaration('a', 'A', [''], [], false));
    assert_throws(fn () => new StepDeclaration('a', 'A', [], [''], false));
});

test('StepDeclaration rejects absolute or parent paths', function () {
    assert_throws(fn () => new StepDeclaration('a', 'A', ['/tmp/x'], [], false));
    assert_throws(fn () => new StepDeclaration('a', 'A', ['foo/../bar'], [], false));
    assert_throws(fn () => new StepDeclaration('a', 'A', ['../bar'], [], false));
});

test('StepDeclaration rejects non-canonical aliases and unsupported globs', function () {
    $invalid = [
        './meta.json',
        'theme/parts/./header.html',
        'theme//parts/header.html',
        'theme/parts/',
        'theme\\parts\\header.html',
        '*',
        'theme/*/header.html',
        'theme/parts/header*.html',
        'theme/parts/**',
    ];

    foreach ($invalid as $path) {
        assert_throws(
            fn () => new StepDeclaration('a', 'A', [$path], [], false),
            "expected invalid declaration read to be rejected: {$path}",
        );
        assert_throws(
            fn () => new StepDeclaration('a', 'A', [], [$path], false),
            "expected invalid declaration write to be rejected: {$path}",
        );
    }
});

test('StepDeclaration allows directory globs', function () {
    $d = new StepDeclaration('s', 'S', ['theme/parts/*'], ['theme/*'], true);
    assert_eq(['theme/parts/*'], $d->reads);
    assert_eq(true, $d->concurrent);
});
