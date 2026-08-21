<?php
declare(strict_types=1);

use Automattic\SiteBuild\BoundedChoice;
use Automattic\SiteBuild\OverlayKit;
use Automattic\SiteBuild\Steps\FinalizeThemeStep;

test('OverlayKit derives every path and handle from one folder token', function () {
    $kit = new OverlayKit('surface', '// grain');
    assert_eq('assets/surface/surface.css', $kit->themeRelPath());
    assert_eq('theme/assets/surface/surface.css', $kit->projectRelPath());
    assert_eq('demo-surface', $kit->handle('demo'));
    assert_eq('theme/assets/surface/*', $kit->declaredWrites());
});

test('the finalizer declares a write for every kit in its catalog', function () {
    // The declaration is derived, so a kit added to the catalog cannot ship
    // while writing a path the step never declared.
    $writes = (new FinalizeThemeStep())->declaration()->writes;
    foreach (FinalizeThemeStep::overlayKits() as $kit) {
        assert_true(
            in_array($kit->declaredWrites(), $writes, true),
            "declaration covers {$kit->folder}",
        );
    }
});

test('BoundedChoice::explicit accepts only a real commitment', function () {
    $allowed = ['sharp', 'soft', 'round'];
    assert_eq('soft', BoundedChoice::explicit('soft', $allowed));
    assert_eq('soft', BoundedChoice::explicit('  SOFT ', $allowed), 'case and padding are tolerated');
    foreach ([null, '', '   ', 'bouncy', 42, ['soft'], true] as $raw) {
        assert_eq(null, BoundedChoice::explicit($raw, $allowed), 'nothing was committed');
    }
});

test('BoundedChoice::normalize keeps silence for absence and warns for loss', function () {
    $allowed = ['flush', 'framed'];

    $quiet = [];
    foreach ([null, '', '   '] as $raw) {
        assert_eq('flush', BoundedChoice::normalize($raw, $allowed, 'flush', 'card_style', $quiet));
    }
    assert_eq([], $quiet, 'an absent field is the documented default, not a defect');

    $warnings = [];
    assert_eq('flush', BoundedChoice::normalize('bouncy', $allowed, 'flush', 'card_style', $warnings));
    assert_eq(1, count($warnings));
    assert_contains('field card_style', $warnings[0]);
    assert_contains('"bouncy"', $warnings[0]);
    assert_contains('delivered "flush"', $warnings[0]);
});

test('BoundedChoice::normalize lets each field keep its own disposition wording', function () {
    $warnings = [];
    BoundedChoice::normalize('bouncy', ['flush'], 'flush', 'card_style', $warnings, 'a bespoke clause');
    assert_contains('disposition a bespoke clause', $warnings[0]);
});
