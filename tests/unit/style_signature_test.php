<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\DesignDirectionStep;
use Automattic\SiteBuild\Steps\PageStylesStep;
use Automattic\SiteBuild\Tests\FakeLlm;

test('style signatures describe image choice, palette, type and composition without decorative hooks', function () {
    $signature = 'Directional portraits, an ink and brass palette, narrow capitals and balanced composition.';
    $direction = DesignDirectionStep::normalize([
        'description' => 'A considered visual direction.',
        'style_signature' => "  {$signature}\n",
        'style_hooks' => ['design-frame', 'design-motif'],
    ]);
    assert_eq($signature, $direction['style_signature']);
    assert_true(!array_key_exists('style_hooks', $direction));
    $formatted = DesignDirectionStep::format($direction);
    assert_contains($signature, $formatted);
    assert_true(!str_contains($formatted, 'design-motif'));
    assert_true(!str_contains($formatted, 'design-frame'));
});

test('style prompts direct the image choice rather than asking for decorative shapes', function () {
    foreach (['design-direction.md', 'design-seed-judge.md', 'hero.md', 'section.md', 'page-styles.md'] as $file) {
        $prompt = file_get_contents(repo_path('prompts/' . $file));
        foreach (['design-frame', 'design-motif', 'style_hooks'] as $retired) {
            assert_true(!str_contains($prompt, $retired), "{$file} still advertises {$retired}");
        }
    }
    $images = file_get_contents(repo_path('prompts/image-generation.md'));
    assert_contains('Style signature', $images);
    assert_contains('subject and composition', $images);
    assert_contains('site-wide grade', $images);
    $direction = file_get_contents(repo_path('prompts/design-direction.md'));
    assert_true(!str_contains($direction, 'Do not promise assets this channel cannot create'));
    assert_contains('illustration', $direction);
    assert_true(!str_contains($images, 'Never emit a decorative image'));
    assert_true(!str_contains($images, 'Feature icons: use none'));
});

test('illustration subject and shared art direction survive the image prompt composer', function () {
    $prompt = \Automattic\SiteBuild\ImagePromptComposer::compose(
        'A botanical illustration of climbing vines with hand-drawn filigree around the foliage.',
        'wide feature image',
        'illustration',
        '',
        'Ink and brass illustration, precise line work and a dark green ground.',
    );
    assert_contains('hand-drawn filigree', $prompt);
    assert_contains('Style: illustration', $prompt);
    assert_contains('Art direction for all site imagery: Ink and brass illustration', $prompt);
});
