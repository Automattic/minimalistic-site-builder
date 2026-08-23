<?php
declare(strict_types=1);

use Automattic\SiteBuild\BuildReport;

test('BuildReport renders section component and dropped pattern counts separately', function (): void {
    $report = new BuildReport('p', 'slug', '/tmp/slug', '2026-08-22T00:00:00+00:00');
    assert_eq(null, $report->patternsLine());

    $report->setPatterns(10, 12, 9);

    $line = 'Patterns: 10 sections, 12 components, 9 dropped';
    assert_eq($line, $report->patternsLine());
    assert_contains($line, $report->render());
});
