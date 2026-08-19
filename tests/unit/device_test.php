<?php
declare(strict_types=1);

use Automattic\SiteBuild\Device;

test('Device catalog is the bounded utility list', function () {
    assert_eq(['none', 'hairline-rule', 'section-numeral', 'stamp'], Device::ALL);
    assert_eq('stamp', Device::explicit(' Stamp '));
    assert_eq(null, Device::explicit('twine'));
    assert_eq(null, Device::className('none'));
    assert_eq('device--hairline-rule', Device::className('hairline-rule'));
});

test('Device kitCss is class-gated and absent for none', function () {
    assert_eq(null, Device::kitCss('none'));
    assert_eq(null, Device::kitCss('twine'));

    $rule = Device::kitCss('hairline-rule');
    assert_true(is_string($rule));
    assert_contains('.device--hairline-rule', $rule);
    assert_contains('box-shadow: inset 0 3px 0 0', $rule);

    $numeral = Device::kitCss('section-numeral');
    assert_true(is_string($numeral));
    assert_contains('counter-increment', $numeral);

    $stamp = Device::kitCss('stamp');
    assert_true(is_string($stamp));
    assert_contains('rotate(-8deg)', $stamp);
});
