<?php
declare(strict_types=1);

use Automattic\SiteBuild\Verification\HtmlFirstFidelityRunner;
use Automattic\SiteBuild\Narrator;

/**
 * Re-run complete HTML-first control-versus-treatment fidelity harness.
 *
 *   php bin/html-first-fidelity.php
 *
 * Holds /tmp/msb-gate.lock, creates a detached origin/trunk control worktree,
 * copies six read-only source projects to both sides, resumes deterministic
 * tails, measures deltas, captures three renders per project, then writes
 * report.json and index.html under site-builder-eval/eval/html-first-fidelity.
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/html-first-fidelity/Harness.php';

try {
    $exit = (new HtmlFirstFidelityRunner(dirname(__DIR__)))->run();
} catch (Throwable $error) {
    Narrator::write("HTML-first fidelity cleanup failed: {$error->getMessage()}\n");
    $exit = 1;
}
exit($exit);
