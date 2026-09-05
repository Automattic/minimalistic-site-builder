<?php
declare(strict_types=1);

use Automattic\SiteBuild\HeadingEmphasis;
use Automattic\SiteBuild\Steps\DesignDirectionStep;

test('heading emphasis is a closed vocabulary whose kit paints only the emph hook inside headings (frm W5a)', function () {
    assert_eq(['none', 'two-tone', 'italic-word', 'highlight'], HeadingEmphasis::ALL);
    assert_eq(null, HeadingEmphasis::kitCss('none'));
    assert_eq(null, HeadingEmphasis::kitCss(null));
    assert_eq(null, HeadingEmphasis::kitCss('neon-glow'));

    $twoTone = (string) HeadingEmphasis::kitCss('two-tone');
    assert_contains('.wp-block-heading .emph', $twoTone);
    assert_contains('color-mix(in srgb, currentColor 70%, transparent)', $twoTone);

    $italic = (string) HeadingEmphasis::kitCss(' Italic-Word ');
    assert_contains('font-style: italic', $italic);
    assert_contains('var(--wp--preset--font-family--accent, inherit)', $italic, 'the accent face is optional');

    $highlight = (string) HeadingEmphasis::kitCss('highlight');
    assert_contains('var(--wp--preset--color--accent', $highlight);
    assert_contains('box-decoration-break: clone', $highlight);

    foreach ([$twoTone, $italic, $highlight] as $css) {
        assert_true(!str_contains($css, '!important'), 'no importance fights with core or the theme');
        assert_true(!preg_match('/^\s*\.emph\s*\{/m', $css), 'every rule is scoped to a heading block');
    }
});

test('the direction normalizes, persists, formats and reads heading_emphasis (frm W5a)', function () {
    $repairs = [];
    $warnings = [];
    $direction = DesignDirectionStep::normalize(
        ['description' => 'x', 'heading_emphasis' => ' Two-Tone '],
        'cinematic-safe-zone',
        'seed',
        $repairs,
        $warnings,
    );
    assert_eq('two-tone', $direction['heading_emphasis']);
    $emphasisWarnings = static fn (array $list): array => array_values(array_filter(
        $list,
        static fn (string $w): bool => str_contains($w, 'heading_emphasis'),
    ));
    assert_eq([], $emphasisWarnings($warnings), 'a valid value needs no durable warning');

    $stray = DesignDirectionStep::normalize(
        ['description' => 'x', 'heading_emphasis' => 'sparkle'],
        'cinematic-safe-zone',
        'seed',
        $repairs,
        $warnings,
    );
    assert_eq('none', $stray['heading_emphasis']);
    assert_eq(1, count($emphasisWarnings($warnings)));
    assert_contains('unsupported heading emphasis replaced by none', $emphasisWarnings($warnings)[0]);

    $absent = DesignDirectionStep::normalize(['description' => 'x'], 'cinematic-safe-zone');
    assert_eq('none', $absent['heading_emphasis']);
    assert_eq('none', DesignDirectionStep::fallbackDirection('seed', 'cinematic-safe-zone')['heading_emphasis']);

    $fact = DesignDirectionStep::format(['description' => 'x', 'heading_emphasis' => 'italic-word']);
    assert_contains('**Heading emphasis**: italic-word', $fact);
    assert_contains('<span class="emph">', $fact);
    assert_contains('at most ONE clause per heading', $fact);
    assert_true(
        !str_contains(DesignDirectionStep::format(['description' => 'x', 'heading_emphasis' => 'none']), 'Heading emphasis'),
        'none states no fact, so the prompts never teach the span',
    );

    with_project('frm-emphasis', function ($project): void {
        assert_eq('none', DesignDirectionStep::headingEmphasisFor($project));
        $project->writeJson('designDirection.json', ['description' => 'x', 'heading_emphasis' => 'highlight']);
        assert_eq('highlight', DesignDirectionStep::headingEmphasisFor($project));
    });
});
