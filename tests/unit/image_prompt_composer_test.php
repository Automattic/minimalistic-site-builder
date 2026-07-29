<?php
declare(strict_types=1);

use Automattic\SiteBuild\ImagePromptComposer;
use Automattic\SiteBuild\Imagen;

/**
 * ImagePromptComposer turns the structured AI_IMAGE fields (subject | page-context
 * | style) plus the site context into the single text prompt sent to the endpoint.
 * The subject leads and is the priority; page/site context is appended as labelled
 * guidance and trimmed first to fit the model's token budget.
 */

test('compose leads with the subject and appends the style', function () {
    $out = ImagePromptComposer::compose('A sourdough loaf on a board', 'menu item card', 'photorealistic', '');
    assert_contains('A sourdough loaf on a board. Style: photorealistic', $out);
});

test('compose includes the page context and site context as labelled guidance', function () {
    $out = ImagePromptComposer::compose(
        'A sourdough loaf on a board',
        'menu item card in a 3-column grid',
        'photorealistic',
        'the website “Hearth & Crumb”. A neighborhood bakery selling sourdough and pastries.'
    );
    // Page and site context fold into one grammatical sentence.
    assert_contains(
        'This image is used as menu item card in a 3-column grid on the website “Hearth & Crumb”.',
        $out
    );
    assert_contains('A neighborhood bakery selling sourdough and pastries.', $out);
    // The guidance is explicitly framed as non-literal so the model doesn't draw it.
    assert_contains('do not render', $out);
    // Subject is still present in full.
    assert_contains('A sourdough loaf on a board. Style: photorealistic', $out);
});

test('compose frames a site context without page context as its own sentence', function () {
    $out = ImagePromptComposer::compose(
        'A sourdough loaf on a board',
        '',
        'photorealistic',
        'the website “Hearth & Crumb”.'
    );
    assert_contains('This image appears on the website “Hearth & Crumb”.', $out);
    assert_true(!str_contains($out, 'used as'), 'no page-context clause without a page context');
});

test('compose with no page or site context is the subject + style + no-text rule', function () {
    $out = ImagePromptComposer::compose('A sourdough loaf', '', 'photorealistic', '');
    // The no-text rule ships with EVERY prompt (BIGR-738 follow-up): the
    // generator otherwise typesets the brand name into poster-like frames.
    assert_eq("A sourdough loaf. Style: photorealistic\n\n"
        . 'The image must contain no text of any kind: no words, letters, numerals, wordmarks, logos, or typography — in any language or script — and no surface treated as a sign or label.', $out);
});

test('compose omits the style clause when no style is given', function () {
    $out = ImagePromptComposer::compose('A sourdough loaf', '', '', '');
    assert_eq("A sourdough loaf\n\n"
        . 'The image must contain no text of any kind: no words, letters, numerals, wordmarks, logos, or typography — in any language or script — and no surface treated as a sign or label.', $out);
});

test('compose keeps the subject in full when the site context is huge', function () {
    $subject = 'A specific sourdough loaf on a floured board, warm side light';
    $out = ImagePromptComposer::compose($subject, 'menu item card', 'photorealistic', str_repeat('context word ', 2000));

    assert_true(Imagen::estimateTokens($out) <= Imagen::MAX_PROMPT_TOKENS, 'within token cap');
    assert_contains("{$subject}. Style: photorealistic", $out);
});

test('compose injects the image grade as its own art-direction clause', function () {
    $grade = 'monochrome documentary, visible 35mm grain, charcoal midtones';
    $out = ImagePromptComposer::compose(
        'A sourdough loaf on a board',
        'menu item card',
        'photorealistic',
        'the website “Hearth & Crumb”.',
        $grade
    );

    assert_contains("Art direction for all site imagery: {$grade}.", $out);
    // The grade sits BEFORE the sheddable guidance so end-trimming keeps it.
    assert_true(
        strpos($out, 'Art direction for all site imagery') < strpos($out, 'Context to guide'),
        'grade clause precedes the guidance'
    );
});

test('compose omits the grade clause when no grade is given', function () {
    $out = ImagePromptComposer::compose('A sourdough loaf', 'menu item card', 'photorealistic', '', '');
    assert_true(!str_contains($out, 'Art direction'), 'no grade clause without a grade');
});

test('compose asks for a flat white background for transparent assets', function () {
    $out = ImagePromptComposer::compose(
        'A small symmetrical grapevine flourish, thin gold linework',
        'decorative accent beneath a section subheading',
        'illustration',
        '',
        '',
        true
    );

    // Imagen cannot render alpha, so the prompt asks for the keyable isolation
    // it does honor: the subject alone on a flat solid white background.
    assert_contains('solid pure white background', $out);
    // Like the grade, this is a render instruction — it precedes the sheddable
    // guidance so end-trimming keeps it.
    assert_true(
        strpos($out, 'solid pure white background') < strpos($out, 'Context to guide'),
        'isolation clause precedes the guidance'
    );
});

test('compose omits the photographic grade for transparent assets', function () {
    $out = ImagePromptComposer::compose(
        'A small symmetrical grapevine flourish, thin gold linework',
        'decorative accent beneath a section subheading',
        'illustration',
        '',
        'warm chiaroscuro color, candlelit low-key lighting, deep shadow falloff',
        true
    );

    // The grade describes lighting/backdrop treatment, which Imagen paints in
    // as a background scene — exactly what the white-background keying must
    // avoid, so transparent assets drop it.
    assert_true(!str_contains($out, 'Art direction'), 'no grade clause for transparent assets');
    assert_contains('solid pure white background', $out);
});

test('compose omits the transparency clause by default', function () {
    $out = ImagePromptComposer::compose('A sourdough loaf', 'menu item card', 'photorealistic', '');
    assert_true(!str_contains($out, 'transparent'), 'no transparency clause for opaque assets');
});

test('compose sheds the site context under token pressure but keeps transparency', function () {
    $out = ImagePromptComposer::compose(
        'A small grapevine flourish',
        'decorative accent',
        'illustration',
        str_repeat('blurb context word ', 2000), // far over budget — gets shed
        '',
        true
    );

    assert_true(Imagen::estimateTokens($out) <= Imagen::MAX_PROMPT_TOKENS, 'within token cap');
    assert_contains('solid pure white background', $out);
});

test('compose sheds the site context under token pressure but keeps the grade', function () {
    $subject = 'A specific sourdough loaf on a floured board, warm side light';
    $grade   = 'monochrome documentary, visible 35mm grain, charcoal midtones';
    $out = ImagePromptComposer::compose(
        $subject,
        'menu item card',
        'photorealistic',
        str_repeat('blurb context word ', 2000), // far over budget — gets shed
        $grade
    );

    assert_true(Imagen::estimateTokens($out) <= Imagen::MAX_PROMPT_TOKENS, 'within token cap');
    assert_contains("{$subject}. Style: photorealistic", $out);
    assert_contains("Art direction for all site imagery: {$grade}.", $out);
});

test('screen-subject lint flags legible app screens and passes defused ones (BIGR-738)', function () {
    // atlas1's actual failing subject (plugin/pages/home.html:38) — flagged.
    $bad = 'A smartphone held upright showing a construction crew scheduling app day view '
        . 'with stacked job cards, times and crew assignments, screen filling most of the frame';
    assert_true(ImagePromptComposer::screenSubjectWarning($bad) !== null, 'legible app screen flagged');

    // atlas3's foreman-with-phone subject — the phone is a prop, not a UI render.
    $prop = 'A foreman in a hi-vis vest and hard hat standing beside a rebar mat, '
        . 'thumbing a phone held at chest height, framed tall from a slightly low vantage';
    assert_eq(null, ImagePromptComposer::screenSubjectWarning($prop));

    // The sanctioned oblique/defocused alternative passes.
    $defused = 'A tablet on a workbench at an oblique angle, screen content abstract and out of focus, '
        . 'showing only a soft glow over the workshop';
    assert_eq(null, ImagePromptComposer::screenSubjectWarning($defused));

    // No device vocabulary at all — never flagged.
    assert_eq(null, ImagePromptComposer::screenSubjectWarning('A misty valley at dawn showing distant peaks'));
});

test('text-prop lint flags focal banners and passes distant/defocused ones (BIGR-738)', function () {
    // portfolio7's actual hero subject: banners as the focal element produced
    // large mirrored pseudo-writing across the photo.
    $bad = 'A dense crowd fills a wide avenue at dusk, seen from a low vantage between raised arms, '
        . 'hand-painted banners catching hard light while the buildings behind fall into deep shadow';
    assert_true(ImagePromptComposer::textPropSubjectWarning($bad) !== null, 'focal banners flagged');

    // The sanctioned form: the prop pushed away and said so.
    $defused = 'A dense crowd on a wide avenue at dusk, banners soft and unreadable in the background';
    assert_eq(null, ImagePromptComposer::textPropSubjectWarning($defused));

    // No text-bearing prop vocabulary at all.
    assert_eq(null, ImagePromptComposer::textPropSubjectWarning(
        'A tall grilled provoleta on dark slate lit by a single hard raking key light'
    ));
});
