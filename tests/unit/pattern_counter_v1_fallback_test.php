<?php
declare(strict_types=1);

use Automattic\SiteBuild\BuildReport;

require_once dirname(__DIR__, 2) . '/bin/build.php';

/** @param list<mixed> $patterns */
function pattern_counter_rendered_line(array $patterns, int $dropped): string
{
    assert_true(
        function_exists('build_pattern_kind_counts'),
        'bin/build.php must expose its pattern-kind counter for direct regression tests',
    );

    $counts = build_pattern_kind_counts($patterns);
    $report = new BuildReport('p', 'slug', '/tmp/slug', '2026-08-22T00:00:00+00:00');
    $report->setPatterns($counts['sections'], $counts['components'], $dropped);

    return $report->patternsLine() ?? '';
}

test('v1 manifest entries without kind render as sections', function (): void {
    $patterns = array_fill(0, 12, ['slug' => 'v1-section']);

    assert_eq(
        'Patterns: 12 sections, 0 components, 0 dropped',
        pattern_counter_rendered_line($patterns, 0),
    );
});

test('v2 manifest entries keep counting by explicit kind', function (): void {
    $patterns = array_merge(
        array_fill(0, 10, ['slug' => 'v2-section', 'kind' => 'section']),
        array_fill(0, 12, ['slug' => 'v2-component', 'kind' => 'component']),
    );

    assert_eq(
        'Patterns: 10 sections, 12 components, 9 dropped',
        pattern_counter_rendered_line($patterns, 9),
    );
});

test('mixed manifests count kind-less entries as sections and explicit entries by kind', function (): void {
    $patterns = [
        ['slug' => 'v1-section'],
        ['slug' => 'v2-section', 'kind' => 'section'],
        ['slug' => 'v2-component', 'kind' => 'component'],
        ['slug' => 'unknown', 'kind' => 'unknown'],
        ['slug' => 'null-kind', 'kind' => null],
        'malformed',
    ];

    assert_eq(
        'Patterns: 2 sections, 1 components, 0 dropped',
        pattern_counter_rendered_line($patterns, 0),
    );
});
