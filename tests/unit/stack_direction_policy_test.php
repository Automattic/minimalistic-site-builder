<?php
declare(strict_types=1);

test('stack direction preserves intentional section labels and user typography', function () {
    $prompt = file_get_contents(repo_path('prompts/design-direction.md'));
    assert_contains('Eyebrows are banned except for the committed section label', $prompt);
    assert_contains('Only `section-badge` or `side-label`', $prompt);
    assert_contains('hero, page-opening heading or repeated-item heading', $prompt);
    assert_contains('"image_kind"', $prompt);
    assert_contains('"heading_emphasis"', $prompt);
    assert_true(!str_contains($prompt, '`"title"`'), 'retired type treatment stays retired');
    $fonts = Automattic\SiteBuild\FontShortlist::productWeightSentence('grotesque', 'modernist');
    assert_contains('Unless the user explicitly requests', $fonts);
    assert_contains('500 and 600', $fonts);
});
