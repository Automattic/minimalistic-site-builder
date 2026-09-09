<?php
declare(strict_types=1);

use Automattic\SiteBuild\TypeTreatment;

test('type treatment maps every bounded commitment to exact case and tracking leaves', function () {
    $expected = [
        'sentence' => ['textTransform' => 'none', 'letterSpacing' => '-0.01em'],
        'tight' => ['textTransform' => 'none', 'letterSpacing' => '-0.04em'],
        'title' => ['textTransform' => 'capitalize', 'letterSpacing' => '-0.02em'],
        'caps-tight' => ['textTransform' => 'uppercase', 'letterSpacing' => '-0.03em'],
        'caps-tracked' => ['textTransform' => 'uppercase', 'letterSpacing' => '0.08em'],
        'lowercase' => ['textTransform' => 'lowercase', 'letterSpacing' => '0.01em'],
    ];

    assert_eq(array_keys($expected), TypeTreatment::ALL);
    foreach ($expected as $treatment => $typography) {
        assert_eq($typography, TypeTreatment::typography($treatment));
        assert_contains($typography['letterSpacing'], TypeTreatment::meaning($treatment));
    }
});

test('the statement-lines register drops the caps of an uppercase site treatment (BIGR-1002)', function () {
    // A statement line carries a whole statement, not a label. Under caps it
    // loses the word shapes a reader recognizes and sets wide enough to wrap,
    // which breaks the archetype's one-line premise.
    foreach (['caps-tight' => '-0.02em', 'caps-tracked' => '0.01em'] as $treatment => $tracking) {
        $css = (string) TypeTreatment::kitCss($treatment);
        assert_contains(
            '.section-composition--statement-lines .wp-block-group.statement-lines > .wp-block-heading {',
            $css,
        );
        assert_contains('text-transform: none;', $css);
        assert_contains("letter-spacing: {$tracking};", $css);
    }

    // Every other treatment ships no kit: lowercase is a deliberate craft
    // voice on long lines, and the rest never set caps.
    foreach (['sentence', 'tight', 'title', 'lowercase', 'small-caps', '', null, 7] as $treatment) {
        assert_eq(null, TypeTreatment::kitCss($treatment));
    }
});

test('type treatment rejects absent and unsupported commitments without guessing', function () {
    foreach ([null, '', 'small-caps', ['title'], 7] as $value) {
        assert_eq(null, TypeTreatment::typography($value));
    }
    assert_eq('caps-tracked', TypeTreatment::explicit(' Caps-Tracked '));
});

test('type treatment prompt contract keeps sentence casing authored and block overrides absent', function () {
    $direction = (string) file_get_contents(repo_path('prompts/design-direction.md'));
    $theme = (string) file_get_contents(repo_path('prompts/theme-json.md'));
    $section = (string) file_get_contents(repo_path('prompts/section.md'));

    assert_contains('`type_treatment`', $direction);
    assert_contains('preserving the theme model\'s heading `lineHeight`', $direction);
    assert_contains('Do not emit either owned leaf', $theme);
    assert_contains('For `sentence` and `tight`, author sentence-case text', $section);
    assert_contains('`"tight"`', $direction);
    assert_contains('when the DESIGN DIRECTION\'s **Type treatment** is `tight`', $theme);
    assert_contains('Do not set either value in a `wp:heading` block', $section);
});
