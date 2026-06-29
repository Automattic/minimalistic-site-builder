<?php
declare(strict_types=1);

/**
 * Unit tests for FixBlocksStep::summaryLine — the one console line distilled
 * from the fixer's verbose stdout (the full report goes to the project log).
 */

test('summaryLine returns the [fix-templates] summary, ignoring the verbose report', function () {
    $stdout = "  FIXED  parts/header.html\n"
        . "         - core/button: Expected attribute `class` ...\n"
        . "  ok     parts/footer.html\n"
        . "\n[fix-templates] 7/11 file(s) re-serialized, 14 issue(s) fixed across 1 theme(s).";

    assert_eq(
        '[fix-templates] 7/11 file(s) re-serialized, 14 issue(s) fixed across 1 theme(s).',
        FixBlocksStep::summaryLine($stdout)
    );
});

test('summaryLine falls back when no summary line is present', function () {
    assert_eq('block-fixer: no files changed', FixBlocksStep::summaryLine("   \n  noise\n"));
});
