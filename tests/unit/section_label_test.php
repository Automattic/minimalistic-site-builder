<?php
declare(strict_types=1);

use Automattic\SiteBuild\DesignFloor;
use Automattic\SiteBuild\SectionLabel;
use Automattic\SiteBuild\Steps\DesignDirectionStep;

function section_label_part(array $badges, string $after = ''): string
{
    $out = '<!-- wp:group {"layout":{"type":"constrained"}} -->' . "\n" . '<div class="wp-block-group">' . "\n";
    foreach ($badges as $text) {
        $out .= '<!-- wp:paragraph {"className":"section-badge","fontSize":"caption"} -->' . "\n"
            . '<p class="section-badge has-caption-font-size">' . $text . '</p>' . "\n"
            . '<!-- /wp:paragraph -->' . "\n";
    }
    $out .= '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Use cases that pay</h2><!-- /wp:heading -->' . "\n"
        . $after . '</div>' . "\n" . '<!-- /wp:group -->';
    return $out;
}

test('the section-badge kit paints the marked paragraph as a pill with a dot (frm W6a)', function () {
    assert_eq(['none', 'section-badge', 'side-label'], SectionLabel::ALL);
    assert_eq(null, SectionLabel::kitCss('none'));
    assert_eq(null, SectionLabel::kitCss(null));
    $css = (string) SectionLabel::kitCss(' Section-Badge ');
    assert_contains('p.section-badge {', $css);
    assert_contains('border-radius: 9999px', $css, 'The badge uses the selected shape radius.');
    assert_contains('p.section-badge::before', $css);
    assert_contains('var(--wp--preset--color--accent, currentColor)', $css, 'only the dot takes the accent');
    assert_contains('text-transform: none', $css, 'the badge is never tracked uppercase');
    assert_contains('p.section-badge.has-text-align-center', $css, 'a centered stack centers its badge');
    assert_contains('p.section-badge:has(+ .wp-block-heading.has-text-align-center)', $css, 'so does a centered heading right after it');
});

test('the delivery boundary keeps one committed badge and removes every other (frm W6a)', function () {
    $one = section_label_part(['Use cases']);
    $kept = SectionLabel::normalize($one, 'section-badge', 'page-home--features');
    assert_eq($one, $kept['markup']);
    assert_eq([], $kept['warnings']);

    $two = section_label_part(['Use cases', 'Again']);
    $capped = SectionLabel::normalize($two, 'section-badge', 'page-home--features');
    assert_eq(1, substr_count($capped['markup'], 'section-badge has-caption'));
    assert_contains('>Use cases<', $capped['markup'], 'the first badge survives');
    assert_eq(1, count($capped['warnings']));
    assert_contains('at most one badge', $capped['warnings'][0]);
    assert_contains("file='theme/parts/page-home--features.html'", $capped['warnings'][0]);

    $uncommitted = SectionLabel::normalize($one, 'none', 'page-home--features');
    assert_true(!str_contains($uncommitted['markup'], 'section-badge'), 'no commitment: the eyebrow ban applies');
    assert_contains('committed no section label', $uncommitted['warnings'][0]);
    assert_contains('<h2 class="wp-block-heading">Use cases that pay</h2>', $uncommitted['markup'], 'the heading is untouched');

    $opening = SectionLabel::normalize($one, 'section-badge', 'page-about--intro', true);
    assert_true(!str_contains($opening['markup'], 'section-badge'), 'a page opening never carries a badge');
    assert_contains('page opening', $opening['warnings'][0]);

    // HTML-only marker (attribute lost) is still a badge.
    $htmlOnly = str_replace('{"className":"section-badge","fontSize":"caption"}', '{"fontSize":"caption"}', $one);
    $stripped = SectionLabel::normalize($htmlOnly, null, 'page-home--features');
    assert_true(!str_contains($stripped['markup'], 'section-badge'));

    // A part without badges is a byte-for-byte no-op.
    $plain = section_label_part([]);
    assert_eq($plain, SectionLabel::normalize($plain, 'none', 'x')['markup']);
    assert_eq($capped['markup'], SectionLabel::normalize($capped['markup'], 'section-badge', 'x')['markup'], 'fixed point');
});

test('the design floor does not read a committed badge as a kicker (frm W6a)', function () {
    $badge = section_label_part(['Use cases']);
    $found = json_encode(DesignFloor::check($badge, []));
    assert_true(!str_contains($found, 'kicker'), 'no kicker finding for the committed badge: ' . $found);

    $eyebrow = str_replace('"className":"section-badge",', '', $badge);
    $eyebrow = str_replace('class="section-badge has-caption-font-size"', 'class="has-caption-font-size"', $eyebrow);
    assert_contains('kicker', json_encode(DesignFloor::check($eyebrow, [])), 'an unmarked caption line above a heading is still a kicker');
});

test('the direction normalizes, persists, formats and reads section_label (frm W6a)', function () {
    $repairs = [];
    $warnings = [];
    $direction = DesignDirectionStep::normalize(
        ['description' => 'x', 'section_label' => ' Section-Badge '],
        'cinematic-safe-zone',
        'seed',
        $repairs,
        $warnings,
    );
    assert_eq('section-badge', $direction['section_label']);
    $labelWarnings = static fn (array $list): array => array_values(array_filter(
        $list,
        static fn (string $w): bool => str_contains($w, 'section_label'),
    ));
    assert_eq([], $labelWarnings($warnings));

    $stray = DesignDirectionStep::normalize(
        ['description' => 'x', 'section_label' => 'ribbon'],
        'cinematic-safe-zone',
        'seed',
        $repairs,
        $warnings,
    );
    assert_eq('none', $stray['section_label']);
    assert_eq(1, count($labelWarnings($warnings)));
    assert_eq('none', DesignDirectionStep::fallbackDirection('seed', 'cinematic-safe-zone')['section_label']);

    $fact = DesignDirectionStep::format(['description' => 'x', 'section_label' => 'section-badge']);
    assert_contains('**Section label**: section-badge', $fact);
    assert_contains('"className":"section-badge"', $fact);
    assert_contains('Never in the hero', $fact);
    assert_true(!str_contains(DesignDirectionStep::format(['description' => 'x']), 'Section label'));

    with_project('frm-label', function ($project): void {
        assert_eq('none', DesignDirectionStep::sectionLabelFor($project));
        $project->writeJson('designDirection.json', ['description' => 'x', 'section_label' => 'section-badge']);
        assert_eq('section-badge', DesignDirectionStep::sectionLabelFor($project));
    });
});

function side_label_split(string $labelText = 'Process', bool $leading = true): string
{
    $label = '<!-- wp:paragraph {"className":"side-label","fontSize":"caption"} -->' . "\n"
        . '<p class="side-label has-caption-font-size">' . $labelText . '</p>' . "\n"
        . '<!-- /wp:paragraph -->' . "\n";
    $content = '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">How the studio works</h2><!-- /wp:heading -->' . "\n"
        . '<!-- wp:paragraph --><p>Three moves, one room.</p><!-- /wp:paragraph -->' . "\n";
    $first = $leading ? $label : $content;
    $second = $leading ? $content : $label;
    return '<!-- wp:group {"layout":{"type":"constrained"}} -->' . "\n" . '<div class="wp-block-group">' . "\n"
        . '<!-- wp:columns {"align":"wide"} --><div class="wp-block-columns alignwide">' . "\n"
        . '<!-- wp:column {"width":"25%"} --><div class="wp-block-column" style="flex-basis:25%">' . "\n" . $first . '</div><!-- /wp:column -->' . "\n"
        . '<!-- wp:column {"width":"75%"} --><div class="wp-block-column" style="flex-basis:75%">' . "\n" . $second . '</div><!-- /wp:column -->' . "\n"
        . '</div><!-- /wp:columns -->' . "\n"
        . '</div>' . "\n" . '<!-- /wp:group -->';
}

test('the side-label kit paints a quiet uppercase caption that stays in view in its column (frm W6b)', function () {
    $css = (string) SectionLabel::kitCss('side-label');
    assert_contains('p.side-label {', $css);
    assert_contains('text-transform: uppercase', $css);
    assert_contains('letter-spacing: 0.08em', $css);
    assert_contains('p.side-label::before', $css);
    assert_contains('var(--wp--preset--color--accent, currentColor)', $css, 'only the dot takes the accent');
    assert_contains('.wp-block-column:has(> p.side-label)', $css);
    assert_contains('position: sticky', $css);
    assert_true(!str_contains($css, 'p.section-badge'), 'the side-label kit does not paint the badge');
    assert_contains('leading column', SectionLabel::meaning('side-label'));
});

test('the delivery boundary keeps one committed side label in the leading column and removes every other form (frm W6b)', function () {
    $split = side_label_split();
    $kept = SectionLabel::normalize($split, 'side-label', 'page-home--process');
    assert_eq($split, $kept['markup']);
    assert_eq([], $kept['warnings']);

    // The label above the heading (not in the leading column) is an eyebrow even when the device is committed.
    $above = section_label_part([]);
    $above = str_replace(
        '<!-- wp:heading {"level":2} -->',
        '<!-- wp:paragraph {"className":"side-label","fontSize":"caption"} --><p class="side-label has-caption-font-size">Process</p><!-- /wp:paragraph -->' . "\n" . '<!-- wp:heading {"level":2} -->',
        $above,
    );
    $stripped = SectionLabel::normalize($above, 'side-label', 'page-home--process');
    assert_true(!str_contains($stripped['markup'], 'side-label'), 'a side label above a heading is removed');
    assert_eq(1, count($stripped['warnings']));
    assert_contains('leading column of a split', $stripped['warnings'][0]);

    // A label sharing the leading column with the heading stack is an eyebrow with extra steps.
    $shared = str_replace(
        '<!-- /wp:paragraph -->' . "\n" . '</div><!-- /wp:column -->',
        '<!-- /wp:paragraph -->' . "\n" . '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Shared</h2><!-- /wp:heading -->' . "\n" . '</div><!-- /wp:column -->',
        $split,
    );
    assert_true(str_contains($shared, '>Shared<'), 'fixture holds the heading beside the label');
    $sharedOut = SectionLabel::normalize($shared, 'side-label', 'page-home--process');
    assert_true(!str_contains($sharedOut['markup'], 'side-label'), 'a label beside the heading stack in its column is removed');
    assert_contains('alone in the leading column', $sharedOut['warnings'][0]);

    // The label in the trailing column is not the device either.
    $trailing = SectionLabel::normalize(side_label_split('Process', false), 'side-label', 'page-home--process');
    assert_true(!str_contains($trailing['markup'], 'side-label'));

    // A page opening never carries one; an uncommitted direction removes it; the other device is not a substitute.
    assert_true(!str_contains(SectionLabel::normalize($split, 'side-label', 'page-home--hero', true)['markup'], 'side-label'));
    assert_true(!str_contains(SectionLabel::normalize($split, 'none', 'page-home--process')['markup'], 'side-label'));
    $crossed = SectionLabel::normalize($split, 'section-badge', 'page-home--process');
    assert_true(!str_contains($crossed['markup'], 'side-label'), 'a badge commitment does not admit a side label');
    assert_contains('committed section-badge, not side-label', $crossed['warnings'][0]);
    $badgeUnderSide = SectionLabel::normalize(section_label_part(['Use cases']), 'side-label', 'page-home--features');
    assert_true(!str_contains($badgeUnderSide['markup'], 'section-badge'), 'a side-label commitment does not admit a badge');

    // Only the first proven side label survives when a section holds two splits.
    preg_match('/<!-- wp:columns.*<!-- \/wp:columns -->/s', side_label_split('Again'), $second);
    $two = str_replace('</div><!-- /wp:columns -->', '</div><!-- /wp:columns -->' . "\n" . $second[0], $split);
    $capped = SectionLabel::normalize($two, 'side-label', 'page-home--process');
    assert_eq(1, substr_count($capped['markup'], 'class="side-label'));
    assert_contains('>Process<', $capped['markup']);
    assert_contains('at most one side label', $capped['warnings'][0]);

    // Two labels in one column are neither alone: both go.
    $stacked = str_replace(
        '<p class="side-label has-caption-font-size">Process</p>' . "\n" . '<!-- /wp:paragraph -->',
        '<p class="side-label has-caption-font-size">Process</p>' . "\n" . '<!-- /wp:paragraph -->' . "\n"
            . '<!-- wp:paragraph {"className":"side-label","fontSize":"caption"} --><p class="side-label has-caption-font-size">Again</p><!-- /wp:paragraph -->',
        $split,
    );
    assert_eq(0, substr_count(SectionLabel::normalize($stacked, 'side-label', 'page-home--process')['markup'], 'class="side-label'));
});

test('section badges use the committed pill radius without another token kit', function () {
    assert_contains('border-radius: 0', SectionLabel::kitCss('section-badge', 'sharp'));
    foreach (['soft' => '0.5rem', 'round' => '9999px'] as $shape => $radius) {
        assert_contains('border-radius: ' . $radius, SectionLabel::kitCss('section-badge', $shape));
    }
});

test('a badge uses a block flex box so auto margins can center it', function () {
    $css = SectionLabel::kitCss('section-badge');
    assert_contains('display: flex;', $css);
    assert_contains('inline-size: fit-content;', $css);
    assert_contains('margin-inline: auto !important;', $css);
});

test('a removed side label leaves full-width content and preserves sibling bytes', function () {
    foreach (['none', 'section-badge', 'side-label'] as $label) {
        $result = SectionLabel::normalize(side_label_split(), $label, 'opening', true);
        assert_true(!str_contains($result['markup'], 'wp:column'));
        assert_contains('<p>Three moves, one room.</p>', $result['markup']);
        assert_eq(1, count($result['warnings']));
        assert_eq($result['markup'], SectionLabel::normalize($result['markup'], $label, 'opening', true)['markup']);
    }
    $unrelated = '<!-- wp:paragraph --><p class="section-badge-note">Keep this.</p><!-- /wp:paragraph -->';
    assert_eq($unrelated, SectionLabel::normalize($unrelated, 'none', 'body')['markup']);
});

test('label removal preserves an unrelated empty split and raw sibling text', function () {
    $unrelated = '<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>Sibling</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns -->';
    $split = side_label_split();
    $raw = $unrelated . $split;
    $result = SectionLabel::normalize($raw, 'none', 'body');
    assert_true(str_starts_with($result['markup'], $unrelated));
    assert_contains('<!-- wp:paragraph --><p>Three moves, one room.</p><!-- /wp:paragraph -->', $result['markup']);
});


test('nested label column removals preserve neighboring content and closing markup', function () {
    $paragraph = static fn (string $text): string => '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
    $column = static fn (string $content): string => '<!-- wp:column --><div class="wp-block-column">' . $content . '</div><!-- /wp:column -->';
    $row = static fn (string $content): string => '<!-- wp:columns --><div class="wp-block-columns">' . $content . '</div><!-- /wp:columns -->';
    $label = static fn (string $text): string => '<!-- wp:paragraph {"className":"side-label"} --><p class="side-label">' . $text . '</p><!-- /wp:paragraph -->';
    $neighbor = $paragraph('Ordinary neighboring paragraph must survive.');
    $outside = $paragraph('After the outer columns.');
    $prefix = '<!-- wp:group --><div class="wp-block-group">';
    $suffix = '</div><!-- /wp:group -->';
    // Unaffected rows before and after the edited rows must retain their bytes.
    $unrelated = $row($column('') . $column($paragraph('Unrelated empty split.')));
    foreach ([false, true] as $multipleKeptColumns) {
        $inner = $row($column($label('Inner')) . $column($paragraph('Deep content.')));
        $extra = $multipleKeptColumns ? $column($paragraph('Extra column.')) : '';
        $outer = $row($column($label('Outer')) . $column($inner . $neighbor) . $extra);
        $source = $prefix . $unrelated . $outer . $outside . $unrelated . $suffix;
        $expectedContent = $paragraph('Deep content.') . $neighbor;
        $expectedOuter = $multipleKeptColumns ? $row($column($expectedContent) . $extra) : $expectedContent;
        $expected = $prefix . $unrelated . $expectedOuter . $outside . $unrelated . $suffix;
        $result = SectionLabel::normalize($source, 'none', 'page-home--process');
        assert_contains($outside, $result['markup'], 'the paragraph after the outer row survives');
        assert_eq($expected, $result['markup'], 'only label columns and redundant wrappers are removed');
        assert_eq(2, count($result['warnings']));
        foreach (['Outer', 'Inner'] as $i => $text) {
            assert_contains("file='theme/parts/page-home--process.html'", $result['warnings'][$i]);
            assert_contains("block='paragraph.side-label'", $result['warnings'][$i]);
            assert_contains($text, $result['warnings'][$i]);
            assert_contains('delivered=removed; disposition=', $result['warnings'][$i]);
        }
        $again = SectionLabel::normalize($result['markup'], 'none', 'page-home--process');
        assert_eq($expected, $again['markup'], 'normalization reaches a fixed point');
        assert_eq([], $again['warnings']);
    }
});
