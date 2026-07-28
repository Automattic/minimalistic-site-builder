<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockSerializer\DroppedContentDetector;
use Automattic\SiteBuild\BlockSerializer\FileReport;
use Automattic\SiteBuild\BlockSerializer\FixerReport;
use Automattic\SiteBuild\BlockSerializer\ParagraphFixer;
use Automattic\SiteBuild\BlockSerializer\Repair;

test('DroppedContentDetector matches occurrence and ordering semantics', function () {
    $before = '<div class="one gone gone" style="Padding-top: 1rem; color:red"></div>'
        . "<i class='gone' style='padding-top:1rem'></i>";
    $after = '<div class="one gone" style="padding-top:1rem"></div>';
    $drops = (new DroppedContentDetector())->detect($before, $after);
    assert_eq('DROPPED style `padding-top:1rem` — not mirrored in the block comment JSON attributes', $drops[0]->line());
    assert_eq('DROPPED style `color:red` — not mirrored in the block comment JSON attributes', $drops[1]->line());
    assert_eq('DROPPED class `gone` (x2) — not mirrored in the block comment JSON attributes', $drops[2]->line());
});

test('DroppedContentDetector preserves a numeric-string style declaration', function () {
    $drops = (new DroppedContentDetector())->detect('<p style="0">x</p>', '<p>x</p>');

    assert_eq(1, count($drops));
    assert_eq('DROPPED style `0` — not mirrored in the block comment JSON attributes', $drops[0]->line());
});

test('DroppedContentDetector preserves a numeric-string class token', function () {
    $drops = (new DroppedContentDetector())->detect('<p class="0">x</p>', '<p>x</p>');

    assert_eq(1, count($drops));
    assert_eq('DROPPED class `0` — not mirrored in the block comment JSON attributes', $drops[0]->line());
});

test('FixerReport keeps summary first and normalized N M D T contract', function () {
    $drop = (new DroppedContentDetector())->detect('<p class="lost">x</p>', '<p>x</p>');
    $report = new FixerReport([
        new FileReport('parts/a.html', 'fixed', $drop, [new Repair('nested-paragraph', '0')]),
        new FileReport('parts/b.html', 'ok'),
        new FileReport('templates/plain.html', 'skip'),
    ]);
    assert_true(str_starts_with($report->format(), '[fix-templates] 1/2'));
    assert_contains('1 issue(s) fixed, 1 style/class value(s) dropped across 1 theme(s).', $report->summary());
    assert_eq(['N' => 1, 'M' => 2, 'D' => 1, 'T' => 1], $report->normalized()['totals']);
    assert_eq('FIXED', $report->normalized()['files'][0]['status']);
});

test('FileReport failed rows carry their reason through format and normalized output', function () {
    $failed = new FileReport('parts/broken.html', 'failed', error: 'did not converge within 5 passes');
    $report = new FixerReport([
        new FileReport('parts/a.html', 'ok'),
        $failed,
    ]);

    assert_contains('1 file(s) left unmodified after a failed transformation.', $report->summary());
    assert_contains('FAILED parts/broken.html', $report->format());
    assert_contains('! left unmodified: did not converge within 5 passes', $report->format());
    assert_eq(1, $report->failedCount());
    assert_eq([$failed], $report->failures());
    assert_eq('did not converge within 5 passes', $failed->normalized()['error']);

    // The status and its reason must come together, both ways.
    assert_throws(static fn () => new FileReport('parts/x.html', 'failed'));
    assert_throws(static fn () => new FileReport('parts/x.html', 'ok', error: 'reason'));
});

test('ParagraphFixer ports nested paragraph attribute merging', function () {
    $input = '<!-- wp:paragraph --><p class="outer" style="color:red"><p class="inner" style="color:blue;font-size:1rem">Hi</p></p><!-- /wp:paragraph -->';
    $result = (new ParagraphFixer())->fix($input);
    assert_eq(1, $result->count);
    assert_eq([0], $result->repairedParagraphOrdinals);
    assert_eq(
        '<!-- wp:paragraph --><p class="outer inner" style="color:blue;font-size:1rem">Hi</p><!-- /wp:paragraph -->',
        $result->html
    );
});

test('ParagraphFixer keeps greater-than characters inside quoted attributes', function () {
    $input = '<!-- wp:paragraph --><p><p title="1 > 0">Hi</p></p><!-- /wp:paragraph -->';
    $result = (new ParagraphFixer())->fix($input);
    assert_eq(1, $result->count);
    assert_eq(
        '<!-- wp:paragraph --><p title="1 > 0">Hi</p><!-- /wp:paragraph -->',
        $result->html,
    );
});
