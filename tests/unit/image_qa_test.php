<?php
declare(strict_types=1);

use Automattic\SiteBuild\ImageQa;

/**
 * ImageQa holds the pure parts of the post-generation hero check (BIGR-979):
 * which images earn a look, how the vision answer is read, and what the one
 * regeneration asks for.
 */

test('ImageQa inspects heroes and full-frame images only', function () {
    assert_true(ImageQa::applies(['filename' => 'hero-plaza.jpg', 'pageContext' => 'editorial photograph']), 'hero filename');
    assert_true(ImageQa::applies(['filename' => 'feature.jpg', 'pageContext' => 'full-bleed band behind a call to action']), 'full-bleed context');
    assert_true(ImageQa::applies(['filename' => 'band.jpg', 'pageContext' => 'background of a call-to-action band']), 'background context');
    assert_true(ImageQa::applies(['filename' => 'opening.jpg', 'pageContext' => 'editorial photograph with the left third kept as open, low-detail negative space']), 'copy reservation');
    assert_true(ImageQa::applies(['filename' => 'wide.jpg', 'pageContext' => 'feature', 'aspectRatio' => 'ultrawide']), 'authored ultrawide');

    assert_true(!ImageQa::applies(['filename' => 'loaf.jpg', 'pageContext' => 'menu item card in a 3-column grid']), 'card thumbnail');
    assert_true(!ImageQa::applies(['filename' => 'hero-mark.png', 'pageContext' => 'full-bleed']), 'transparent asset');
    assert_true(!ImageQa::applies(['filename' => 'heroic-portrait.jpg', 'pageContext' => 'team card']), 'hero prefix needs a separator');
    assert_true(!ImageQa::applies([]), 'no filename');
});

test('ImageQa reads a passing verdict', function () {
    $verdict = ImageQa::verdict('{"upright": true, "rendered_text": false, "matches_subject": true, "note": ""}');
    assert_eq(['ok' => true, 'findings' => [], 'note' => ''], $verdict);
});

test('ImageQa names every failing answer and tolerates fences and prose', function () {
    $verdict = ImageQa::verdict("Here is my answer:\n```json\n{\"upright\": false, \"rendered_text\": true, \"matches_subject\": false, \"note\": \"sky on the left\"}\n```");
    assert_eq(false, $verdict['ok']);
    assert_eq(3, count($verdict['findings']));
    assert_contains('camera not upright', $verdict['findings'][0]);
    assert_contains('rendered text', $verdict['findings'][1]);
    assert_contains('does not show the requested subject', $verdict['findings'][2]);
    assert_eq('sky on the left', $verdict['note']);
});

test('ImageQa returns no verdict for an unreadable answer', function () {
    assert_eq(null, ImageQa::verdict('I cannot see an image.'));
    assert_eq(null, ImageQa::verdict('{"upright": "yes"}'), 'non-boolean answers are no verdict');
    assert_eq(null, ImageQa::verdict('[1,2]'));
    assert_eq(null, ImageQa::verdict('{"note": "fine"}'), 'a note alone is no verdict');
});

test('ImageQa corrects the subject positively for each finding', function () {
    $verdict = ImageQa::verdict('{"upright": false, "rendered_text": true, "matches_subject": true}');
    $subject = ImageQa::correctedSubject('A dense crowd on a wide avenue at dusk.', $verdict);

    assert_true(str_starts_with($subject, 'A dense crowd on a wide avenue at dusk. '), 'authored subject leads');
    assert_contains('The camera is upright and level', $subject);
    assert_contains('plain and unmarked', $subject);
    foreach (['frame', 'letter', 'sign', 'text', 'word'] as $bad) {
        assert_true(!str_contains(strtolower($subject), $bad), "correction never names “{$bad}”");
    }
});

test('ImageQa resamples an off-subject picture with the subject unchanged', function () {
    $verdict = ImageQa::verdict('{"upright": true, "rendered_text": false, "matches_subject": false}');
    assert_eq('A misty valley at dawn.', ImageQa::correctedSubject('A misty valley at dawn.', $verdict), 'the authored subject is resampled verbatim');
});

test('ImageQa warning row carries file, subject, finding and disposition', function () {
    $row = ImageQa::warningRow('hero-plaza.jpg', 'A dense crowd at dusk', ['camera not upright (scene rotated or tilted)'], 'still failing after one regeneration');
    assert_contains('theme/assets/hero-plaza.jpg', $row);
    assert_contains('"A dense crowd at dusk"', $row);
    assert_contains('camera not upright', $row);
    assert_contains('disposition: delivered, still failing after one regeneration', $row);
});
