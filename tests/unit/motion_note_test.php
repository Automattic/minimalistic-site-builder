<?php
declare(strict_types=1);

use Automattic\SiteBuild\Motion;

test('mapNote turns buttons press into hover-lift', function () {
    $mapped = Motion::mapNote('buttons press', 'calm');
    assert_eq(['hover-lift'], $mapped['classes']);
    assert_contains('hover-lift', $mapped['note']);
});

test('mapNote matches hyphenated kit class names in the note', function () {
    $mapped = Motion::mapNote('use hover-lift and stagger-children', 'energetic');
    assert_true(in_array('hover-lift', $mapped['classes'], true));
    assert_true(in_array('stagger-children', $mapped['classes'], true));
});

test('mapNote strips a note the kit cannot express', function () {
    $mapped = Motion::mapNote('labels press on with overshoot', 'none');
    assert_eq('', $mapped['note']);
    assert_eq([], $mapped['classes']);

    $unmapped = Motion::mapNote('a cinematic wipe nobody ships', 'calm');
    assert_eq('', $unmapped['note']);
    assert_eq([], $unmapped['classes']);
});
