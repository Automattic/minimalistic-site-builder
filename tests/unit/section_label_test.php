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
    assert_eq(['none', 'section-badge'], SectionLabel::ALL);
    assert_eq(null, SectionLabel::kitCss('none'));
    assert_eq(null, SectionLabel::kitCss(null));
    $css = (string) SectionLabel::kitCss(' Section-Badge ');
    assert_contains('p.section-badge {', $css);
    assert_contains('var(--shape-radius-pill, 9999px)', $css, 'the pill reads the committed radius scale');
    assert_contains('p.section-badge::before', $css);
    assert_contains('var(--wp--preset--color--accent, currentColor)', $css, 'only the dot takes the accent');
    assert_contains('text-transform: none', $css, 'the badge is never tracked uppercase');
    assert_contains('p.section-badge.has-text-align-center', $css, 'a centered stack centers its badge');
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
