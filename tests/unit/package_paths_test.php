<?php
declare(strict_types=1);

use Automattic\SiteBuild\Package;

test('Package::root resolves to the package base dir regardless of CWD', function () {
    $expected = dirname(__DIR__, 2); // tests/unit -> package root
    assert_eq($expected, Package::root());
    assert_eq($expected . '/prompts', Package::promptsDir());
    assert_true(is_file(Package::promptsDir() . '/site-spec.md'), 'a known prompt exists at the resolved dir');
    assert_eq($expected . '/schemas/site-spec.schema.json', Package::siteSpecSchemaPath());
    assert_true(is_file(Package::siteSpecSchemaPath()), 'the siteSpec contract ships with the package');
    assert_eq($expected . '/examples/site-spec.json', Package::siteSpecExamplePath());
    assert_true(is_file(Package::siteSpecExamplePath()), 'the canonical siteSpec example ships with the package');
    assert_eq($expected . '/schemas/pattern-inputs.schema.json', Package::patternInputsSchemaPath());
    assert_true(is_file(Package::patternInputsSchemaPath()), 'the pattern input contract ships with the package');
    assert_eq($expected . '/examples/pattern-inputs.json', Package::patternInputsExamplePath());
    assert_true(is_file(Package::patternInputsExamplePath()), 'the pattern input example ships with the package');
    assert_eq($expected . '/schemas/content-bundle.schema.json', Package::contentBundleSchemaPath());
    assert_true(is_file(Package::contentBundleSchemaPath()), 'the content bundle contract ships with the package');
});
