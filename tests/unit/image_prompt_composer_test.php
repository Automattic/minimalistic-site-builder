<?php
declare(strict_types=1);

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
        'Image for the website “Hearth & Crumb” about artisan sourdough.'
    );
    assert_contains('menu item card in a 3-column grid', $out);
    assert_contains('Hearth & Crumb', $out);
    // The guidance is explicitly framed as non-literal so the model doesn't draw it.
    assert_contains('do not render', $out);
    // Subject is still present in full.
    assert_contains('A sourdough loaf on a board. Style: photorealistic', $out);
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

    assert_true(WpcomImageClient::estimateTokens($out) <= WpcomImageClient::MAX_PROMPT_TOKENS, 'within token cap');
    assert_contains("{$subject}. Style: photorealistic", $out);
});

test('compose injects the image grade as its own art-direction clause', function () {
    $grade = 'monochrome documentary, visible 35mm grain, charcoal midtones';
    $out = ImagePromptComposer::compose(
        'A sourdough loaf on a board',
        'menu item card',
        'photorealistic',
        'Image for the website “Hearth & Crumb”.',
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

    assert_true(WpcomImageClient::estimateTokens($out) <= WpcomImageClient::MAX_PROMPT_TOKENS, 'within token cap');
    assert_contains("{$subject}. Style: photorealistic", $out);
    assert_contains("Art direction for all site imagery: {$grade}.", $out);
});
