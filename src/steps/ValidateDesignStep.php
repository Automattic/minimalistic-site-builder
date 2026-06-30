<?php
declare(strict_types=1);

/**
 * Step (deterministic): the design hard-gate — run validators V2–V5 over the
 * finished markup and record the result.
 *
 * V1 (contrast) already ran at token time inside ThemeJsonStep. This step closes
 * the gate on the markup-level contracts (paired color, grid/flex gap, token
 * discipline, alignment), writing a Markdown report to
 * projects/<slug>/logs/design-validation.md and echoing a one-line summary.
 *
 * Reporting (not aborting) by design: the checks are heuristic, so a lone false
 * positive shouldn't sink an otherwise good build — but the violations are made
 * loud (console + report) so regressions are visible. Set BUILDER_STRICT_DESIGN=1
 * to turn the gate hard (throw on any violation).
 */
final class ValidateDesignStep implements Step
{
    public function id(): string
    {
        return 'validate-design';
    }

    public function label(): string
    {
        return 'Validate design (V2–V5)';
    }

    public function run(Project $project): void
    {
        $findings = DesignValidator::validate($project);
        $project->writeText('logs/design-validation.md', DesignValidator::report($findings));

        if ($findings === []) {
            echo "  [V2–V5] design validation passed (0 violations)\n";
            return;
        }

        $byRule = [];
        foreach ($findings as $f) {
            $byRule[$f['rule']] = ($byRule[$f['rule']] ?? 0) + 1;
        }
        $summary = [];
        foreach ($byRule as $rule => $n) {
            $summary[] = "{$rule}={$n}";
        }
        $line = '  [V2–V5] ' . count($findings) . ' violation(s): ' . implode(', ', $summary)
            . ' — see logs/design-validation.md';

        if (Env::get('BUILDER_STRICT_DESIGN', '') === '1') {
            throw new RuntimeException(trim($line));
        }
        fwrite(STDERR, $line . "\n");
    }
}
