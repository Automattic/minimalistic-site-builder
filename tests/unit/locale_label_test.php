<?php
declare(strict_types=1);

use Automattic\SiteBuild\Patterns\LocaleLabel;

test('a known locale is spelled out with its code', function () {
    assert_eq('Portuguese (Brazil) (pt_BR)', LocaleLabel::describe('pt_BR'));
    assert_eq('Spanish (Spain) (es_ES)', LocaleLabel::describe('es_ES'));
});

test('an unknown locale passes through and an empty one means English', function () {
    assert_eq('tlh', LocaleLabel::describe('tlh'));
    assert_eq('English (en)', LocaleLabel::describe(''));
});
