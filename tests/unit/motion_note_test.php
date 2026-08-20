<?php
declare(strict_types=1);

use Automattic\SiteBuild\Motion;

test('validateNote keeps the kit classes a list names', function () {
    $note = Motion::validateNote(['stagger-children', 'hover-lift'], 'energetic');
    assert_eq(['stagger-children', 'hover-lift'], $note['classes']);
    assert_eq([], $note['dropped']);
    assert_contains('stagger-children', $note['note']);
    assert_contains('hover-lift', $note['note']);
});

test('validateNote reads one string of separated class names', function () {
    $note = Motion::validateNote('reveal-up, hover-lift', 'calm');
    assert_eq(['reveal-up', 'hover-lift'], $note['classes']);
    assert_eq([], $note['dropped']);
});

test('validateNote never reads a negation as the thing it negates', function () {
    // The substring table this replaced found "fade in" inside "do not fade
    // in" and shipped the fade.
    $note = Motion::validateNote('do not fade in', 'calm');
    assert_eq([], $note['classes']);
    assert_eq('', $note['note']);
});

test('validateNote never matches a class inside a longer word', function () {
    // "surprise" contains "rise", which used to map to reveal-up.
    foreach (['a surprise arrival', 'an expressive layout', 'impressionistic'] as $prose) {
        $note = Motion::validateNote($prose, 'calm');
        assert_eq([], $note['classes'], "prose '{$prose}' names no class");
    }
});

test('validateNote resolves the bare reveal class the phrase table missed', function () {
    $note = Motion::validateNote('reveal', 'calm');
    assert_eq(['reveal'], $note['classes']);
});

test('validateNote spends the entrance budget once, keeping stagger off reveal', function () {
    // prompts/section.md forbids stagger-children beside any reveal-* class.
    $note = Motion::validateNote(['stagger-children', 'reveal-up'], 'energetic');
    assert_eq(['stagger-children'], $note['classes']);
    assert_contains('entrance budget', implode(' ', $note['dropped']));
});

test('validateNote keeps one ambient effect for the whole page', function () {
    $note = Motion::validateNote(['ken-burns', 'gradient-shift'], 'dramatic');
    assert_eq(['ken-burns'], $note['classes']);
    assert_contains('ambient budget', implode(' ', $note['dropped']));
});

test('validateNote refuses the pairs that fight over one transform', function () {
    $drift = Motion::validateNote(['ambient-drift', 'hover-lift'], 'energetic');
    assert_eq(['ambient-drift'], $drift['classes']);
    assert_contains('same transform', implode(' ', $drift['dropped']));

    $burns = Motion::validateNote(['ken-burns', 'hover-reveal'], 'dramatic');
    assert_eq(['ken-burns'], $burns['classes']);
    assert_contains('same transform', implode(' ', $burns['dropped']));
});

test('validateNote refuses hero-entrance in a site-wide note', function () {
    // section.md treats it as hero-only, and the direction reaches every section.
    $note = Motion::validateNote(['hero-entrance', 'reveal-up'], 'dramatic');
    assert_eq(['reveal-up'], $note['classes']);
    assert_contains('hero-only', implode(' ', $note['dropped']));
});

test('validateNote gates every class on the committed profile', function () {
    $minimal = Motion::validateNote(['hover-lift', 'ken-burns'], 'minimal');
    assert_eq(['hover-lift'], $minimal['classes']);
    assert_contains('minimal profile does not ship it', implode(' ', $minimal['dropped']));

    $none = Motion::validateNote(['reveal-up'], 'none');
    assert_eq([], $none['classes']);
    assert_eq('', $none['note']);
});

test('validateNote drops a name the kit never implemented', function () {
    $note = Motion::validateNote(['reveal-left', 'a cinematic wipe nobody ships'], 'calm');
    assert_eq([], $note['classes']);
    assert_contains('not a motion-kit class', implode(' ', $note['dropped']));
});

test('validateNote treats an absent or empty note as no commitment', function () {
    foreach ([null, '', [], '   ', 42, ['x' => 1]] as $raw) {
        $note = Motion::validateNote($raw, 'calm');
        assert_eq([], $note['classes']);
        assert_eq('', $note['note']);
    }
});

test('validateNote is idempotent over its own delivered classes', function () {
    $first = Motion::validateNote(['reveal-up', 'hover-lift', 'ken-burns'], 'dramatic');
    $second = Motion::validateNote($first['classes'], 'dramatic');
    assert_eq($first['classes'], $second['classes'], 'a validated note validates unchanged');
    assert_eq([], $second['dropped']);
});
