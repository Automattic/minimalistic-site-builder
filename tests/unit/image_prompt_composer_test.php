<?php
declare(strict_types=1);

use Automattic\SiteBuild\ImagePromptComposer;
use Automattic\SiteBuild\GeminiImage;

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
        'A neighborhood bakery selling sourdough and pastries.'
    );
    // Page and site context read as adjacent guidance sentences.
    assert_contains(
        'Composition: menu item card in a 3-column grid. A neighborhood bakery selling sourdough and pastries.',
        $out
    );
    // The guidance is explicitly framed as non-literal so the model doesn't draw it.
    assert_contains('never depicted literally', $out);
    // Subject is still present in full.
    assert_contains('A sourdough loaf on a board. Style: photorealistic', $out);
});

test('compose frames a site context without page context as its own sentence', function () {
    $out = ImagePromptComposer::compose(
        'A sourdough loaf on a board',
        '',
        'photorealistic',
        'A neighborhood bakery selling sourdough and pastries.'
    );
    assert_contains('never depicted literally: A neighborhood bakery selling sourdough and pastries.', $out);
    assert_true(!str_contains($out, 'Composition:'), 'no composition clause without a page context');
});

test('compose recasts web-layout page context into photographic language', function () {
    $out = ImagePromptComposer::compose(
        'A dense crowd filling Plaza de Mayo at golden hour',
        'full-bleed hero cover background with the left third kept as a calm low-detail area',
        'photorealistic',
        'A minimalist portfolio of twenty years of photojournalism.'
    );

    // The design-comp idiom that triggers painted-in title blocks (BIGR-768)
    // becomes a purely photographic brief before it reaches the model.
    assert_contains(
        'Composition: full-frame wide editorial photograph with the left third kept as open, low-detail negative space.',
        $out
    );
    foreach (['hero', 'cover', 'bleed', 'website', 'used as'] as $webTerm) {
        assert_true(
            !str_contains(strtolower($out), $webTerm),
            "web-design term “{$webTerm}” never reaches the image model"
        );
    }
});

test('compose recasts named overlay copy as reserved negative space', function () {
    // The authoring rules forbid naming overlay copy in the page context, but
    // an LLM slip here is exactly the painted-wordmark trigger — catch it.
    $out = ImagePromptComposer::compose(
        'A lone figure facing a dusk demonstration crowd',
        'full-bleed hero section with the photographer name and tagline overlaid on the left',
        'photorealistic',
        ''
    );

    assert_contains(
        'Composition: full-frame wide editorial photograph with open, low-detail negative space kept on the left.',
        $out
    );
    foreach (['name and tagline', 'overlaid', 'hero'] as $trigger) {
        assert_true(!str_contains(strtolower($out), $trigger), "overlay-copy trigger “{$trigger}” recast away");
    }
});

test('compose phrases the no-text guard positively', function () {
    $out = ImagePromptComposer::compose(
        'A misty mountain range at dawn',
        'full-bleed hero with text overlay',
        'photorealistic',
        'A travel journal.'
    );

    assert_contains('Purely pictorial imagery', $out);
    // The overlay slot is described as what the region IS…
    assert_contains('full-frame wide editorial photograph with open, low-detail negative space', $out);
    // …and the guard never enumerates the forbidden text artifacts, which
    // would plant those very concepts into the prompt context (BIGR-768).
    foreach (['headline', 'watermark', 'logo', 'lettering', 'caption', 'render no', 'website'] as $artifact) {
        assert_true(!str_contains(strtolower($out), $artifact), "guard does not name “{$artifact}”");
    }
});

test('compose with no page or site context is just the subject + style', function () {
    $out = ImagePromptComposer::compose('A sourdough loaf', '', 'photorealistic', '');
    assert_eq('A sourdough loaf. Style: photorealistic', $out);
});

test('compose omits the style clause when no style is given', function () {
    $out = ImagePromptComposer::compose('A sourdough loaf', '', '', '');
    assert_eq('A sourdough loaf', $out);
});

test('compose keeps the subject in full when the site context is huge', function () {
    $subject = 'A specific sourdough loaf on a floured board, warm side light';
    $out = ImagePromptComposer::compose($subject, 'menu item card', 'photorealistic', str_repeat('context word ', 2000));

    assert_true(GeminiImage::estimateTokens($out) <= GeminiImage::MAX_PROMPT_TOKENS, 'within token cap');
    assert_contains("{$subject}. Style: photorealistic", $out);
});

test('compose injects the image grade as its own art-direction clause', function () {
    $grade = 'monochrome documentary, visible 35mm grain, charcoal midtones';
    $out = ImagePromptComposer::compose(
        'A sourdough loaf on a board',
        'menu item card',
        'photorealistic',
        'A neighborhood bakery selling sourdough and pastries.',
        $grade
    );

    assert_contains("Art direction for all site imagery: {$grade}.", $out);
    // The grade sits BEFORE the sheddable guidance so end-trimming keeps it.
    assert_true(
        strpos($out, 'Art direction for all site imagery') < strpos($out, 'Purely pictorial'),
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

    // The image model cannot render alpha, so the prompt asks for the keyable isolation
    // it does honor: the subject alone on a flat solid white background.
    assert_contains('solid pure white background', $out);
    // Like the grade, this is a render instruction — it precedes the sheddable
    // guidance so end-trimming keeps it.
    assert_true(
        strpos($out, 'solid pure white background') < strpos($out, 'Purely pictorial'),
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

    // The grade describes lighting/backdrop treatment, which the image model paints in
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

    assert_true(GeminiImage::estimateTokens($out) <= GeminiImage::MAX_PROMPT_TOKENS, 'within token cap');
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

    assert_true(GeminiImage::estimateTokens($out) <= GeminiImage::MAX_PROMPT_TOKENS, 'within token cap');
    assert_contains("{$subject}. Style: photorealistic", $out);
    assert_contains("Art direction for all site imagery: {$grade}.", $out);
});
