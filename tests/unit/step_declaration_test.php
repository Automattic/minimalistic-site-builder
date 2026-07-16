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

test('StepDeclaration allows directory globs', function () {
    $d = new StepDeclaration('s', 'S', ['theme/parts/*'], ['theme/*'], true);
    assert_eq(['theme/parts/*'], $d->reads);
    assert_eq(true, $d->concurrent);
});
