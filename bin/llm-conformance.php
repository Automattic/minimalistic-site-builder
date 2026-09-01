<?php
declare(strict_types=1);

use Automattic\SiteBuild\LlmConformance;

/**
 * Run the Llm contract conformance suite against the configured provider.
 *
 *   php bin/llm-conformance.php              # structural + live checks
 *   php bin/llm-conformance.php --structural # zero-spend checks only
 *
 * Every host that supplies its own Llm (wpcom, Studio, the coding-agent
 * harness) should run this against its adapter before wiring it up, and keep
 * it in that host's CI. The structural pass costs nothing and is safe to run
 * on every commit; the live pass spends six small completions before retries,
 * one of them carrying ~7,500 tokens of cached layers and one ~2,500.
 *
 * "Zero spend" is a property of a CONFORMANT host: the structural checks are
 * only free because the host refuses them before transport. A host that
 * validates nothing sends all four, so the probes cap max_tokens to keep the
 * cost of finding that out small.
 *
 * Exits non-zero when a check fails AND when nothing could be proven, because
 * a gate that goes green on "could not tell" is the false confidence this
 * suite exists to remove.
 */

require_once __DIR__ . '/../src/bootstrap.php';

$args = parse_cli_args($argv, ['--structural' => 'bool']);
if ($args['unknown'] !== null) {
    fwrite(STDERR, "Unknown argument: {$args['unknown']}\n");
    fwrite(STDERR, "Usage: php bin/llm-conformance.php [--structural]\n");
    exit(2);
}
$structuralOnly = $args['flags']['--structural'] ?? false;

$llm = resolve_llm();
$findings = LlmConformance::run($llm, includeLive: !$structuralOnly);

// Rendering and the verdict live on LlmConformance, where they are testable
// without a transport; this file is argument parsing and an exit code.
$report = LlmConformance::report($findings, $structuralOnly);
echo $report['text'];
exit($report['exit']);
