<?php
declare(strict_types=1);

use Automattic\SiteBuild\ImageKind;
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

test('a product screen takes an interface text correction, not the photographic one', function () {
    $verdict = ImageQa::verdict('{"upright": true, "rendered_text": true, "matches_subject": true}');
    $authored = 'A light-mode analytics screen with a load-curve chart panel and a settlement rate table.';

    // The photographic correction says every surface is plain and unmarked. An
    // interface is MADE of marked panels, so that sentence steers a screen
    // nowhere: a generated page kept "Load-curve chart", "Settlement rate" and
    // "6.3K" through the one regeneration and shipped with a warning.
    $screen = ImageQa::correctedSubject($authored, $verdict, 'ui-mockup');
    assert_true(str_starts_with($screen, $authored . ' '), 'authored subject leads');
    assert_contains(ImageKind::SCREEN_NO_TEXT, $screen);
    assert_contains('plain rounded placeholder bar with no glyphs', $screen);
    assert_contains('identify the layout for you', $screen, 'the panel names are not label copy');
    assert_true(!str_contains($screen, 'plain and unmarked'), 'the photographic wording is replaced, not appended');

    // The first-pass prompt and the correction quote one constant, so a screen
    // is told the same rule twice in the same words.
    assert_contains(ImageKind::SCREEN_NO_TEXT, ImageKind::promptClause('ui-mockup'));

    // Every other kind keeps the photographic correction and its taboo.
    foreach (['photo', '3d-object', 'line-illustration', 'abstract-gradient', ''] as $kind) {
        $other = ImageQa::correctedSubject('A misty valley at dawn.', $verdict, $kind);
        assert_contains('plain and unmarked', $other, $kind);
        foreach (['letter', 'sign', 'text', 'word', 'glyph'] as $bad) {
            assert_true(!str_contains(strtolower($other), $bad), "{$kind} correction never names “{$bad}”");
        }
    }
    assert_eq('', ImageKind::screenTextCorrection('photo'));
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


test('ImageQa retains rotation failures after the prompt permits the image kind tilt', function () {
    $answer = '{"upright": false, "rendered_text": false, "matches_subject": true, "note": "the screen is upside down"}';
    $verdict = ImageQa::verdict($answer);
    assert_eq(false, $verdict['ok']);
    assert_contains('camera not upright', $verdict['findings'][0]);
    assert_contains('camera is upright and level', ImageQa::correctedSubject('A dashboard.', $verdict, 'ui-mockup'));
    $tilt = ImageQa::verdict('{"upright": true, "rendered_text": false, "matches_subject": true, "note": "the screen has a gentle tilt"}');
    assert_eq(true, $tilt['ok']);
});

test('ImageQa excludes light and crop from subject failure', function () {
    $verdict = ImageQa::verdict('{"upright":true,"rendered_text":false,"matches_subject":false,"subject_difference":"none","note":"The lamp glow and position differ."}');
    assert_eq(true, $verdict['ok']);
    $verdict = ImageQa::verdict('{"upright":true,"rendered_text":true,"matches_subject":false,"subject_difference":"none"}');
    assert_eq(['rendered text or lettering in the picture'], $verdict['findings']);
    foreach (['main_subject', 'vantage'] as $difference) {
        $verdict = ImageQa::verdict(json_encode(['matches_subject' => false, 'subject_difference' => $difference]));
        assert_eq(false, $verdict['ok']);
    }
});
