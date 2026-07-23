<?php
declare(strict_types=1);

use Automattic\SiteBuild\Package;

test('Package::root resolves to the package base dir regardless of CWD', function () {
    $expected = dirname(__DIR__, 2); // tests/unit -> package root
    assert_eq($expected, Package::root());
    assert_eq($expected . '/prompts', Package::promptsDir());
    assert_true(is_file(Package::promptsDir() . '/site-spec.md'), 'a known prompt exists at the resolved dir');
});
