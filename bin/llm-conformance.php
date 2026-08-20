<?php
declare(strict_types=1);

use Automattic\SiteBuild\LlmConformance;
use Automattic\SiteBuild\Narrator;

/**
 * Run the Llm contract conformance suite against the configured provider.
 *
 *   php bin/llm-conformance.php              # structural + live checks
 *   php bin/llm-conformance.php --structural # zero-spend checks only
 *
 * Every host that supplies its own Llm (wpcom, Studio, the coding-agent
 * harness) should run this against its adapter before wiring it up, and keep
 * it in that host's CI. The structural pass costs nothing and is safe to run
 * on every commit; the live pass spends five small completions before retries.
 *
 * Exits non-zero when any check fails, so it can gate a build.
 */

require_once __DIR__ . '/../src/bootstrap.php';

$args = parse_cli_args($argv, ['--structural' => 'bool']);
if ($args['unknown'] !== null) {
    Narrator::write("Unknown argument: {$args['unknown']}\n");
    Narrator::write("Usage: php bin/llm-conformance.php [--structural]\n");
    exit(2);
}
$structuralOnly = $args['flags']['--structural'] ?? false;

$llm = make_llm();
$findings = LlmConformance::run($llm, includeLive: !$structuralOnly);

$width = 0;
foreach ($findings as $finding) {
    $width = max($width, strlen($finding->check));
}

$failed = 0;
$passed = 0;
$skipped = 0;
foreach ($findings as $finding) {
    if ($finding->skipped) {
        $mark = 'SKIP';
        $skipped++;
    } elseif ($finding->passed) {
        $mark = 'PASS';
        $passed++;
    } else {
        $mark = 'FAIL';
        $failed++;
    }
    printf("  %-4s  %-{$width}s  [%s]\n", $mark, $finding->check, $finding->tier);
    // A passing check's measurement is worth showing too — it is the evidence
    // that the host was actually exercised rather than trivially satisfied.
    printf("        %s\n", $finding->detail);
}

$total = count($findings);
if ($failed > 0) {
    printf("\n%d of %d checks FAILED. This host's Llm does not satisfy the contract in src/Llm.php.\n", $failed, $total);
    exit(1);
}

if ($skipped > 0) {
    printf(
        "\n%d checks passed, %d skipped%s.\n",
        $passed,
        $skipped,
        $structuralOnly ? ' (structural only)' : '',
    );
} else {
    printf("\nAll %d checks passed%s.\n", $total, $structuralOnly ? ' (structural only)' : '');
}
