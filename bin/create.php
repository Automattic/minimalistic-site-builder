<?php
declare(strict_types=1);

/**
 * One-shot: build a site from a prompt, report tokens + wall time, then boot it
 * in WordPress Playground and print the URL.
 *
 *   php bin/create.php "A cozy neighborhood bakery" [--slug=my-bakery] [--port=9400] [--no-serve]
 *
 * --no-serve builds and reports metrics without launching Playground.
 */

require_once __DIR__ . '/../src/bootstrap.php';

$prompt = null;
$slug = null;
$port = null;
$serve = true;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--slug=')) {
        $slug = substr($a, 7);
    } elseif (str_starts_with($a, '--port=')) {
        $port = (int) substr($a, 7);
    } elseif ($a === '--no-serve') {
        $serve = false;
    } elseif ($prompt === null) {
        $prompt = $a;
    }
}

if ($prompt === null || trim($prompt) === '') {
    fwrite(STDERR, "Usage: php bin/create.php \"<prompt>\" [--slug=...] [--port=9400] [--no-serve]\n");
    exit(1);
}

$slug ??= $prompt;
$project = (new ProjectStore(repo_path('projects')))->create($slug);
$project->writeJson('meta.json', [
    'prompt' => $prompt, 'provisional_slug' => $project->slug(), 'created_at' => gmdate('c'),
]);

$llm = make_llm();
$pipeline = build_pipeline($llm);

echo "Building '{$project->slug()}'\n";
echo "  prompt: {$prompt}\n\n";
printf("  %-18s %8s %10s %10s\n", 'step', 'time', 'in-tok', 'out-tok');
printf("  %-18s %8s %10s %10s\n", str_repeat('-', 18), str_repeat('-', 8), str_repeat('-', 10), str_repeat('-', 10));

$prevIn = 0;
$prevOut = 0;
$wallStart = microtime(true);
$pipeline->runThrough($project, null, function (Step $step, float $secs) use ($llm, &$prevIn, &$prevOut) {
    $u = $llm->usageTotals();
    $dIn = $u['input_tokens'] - $prevIn;
    $dOut = $u['output_tokens'] - $prevOut;
    $prevIn = $u['input_tokens'];
    $prevOut = $u['output_tokens'];
    printf("  %-18s %7.1fs %10s %10s\n", $step->id(), $secs, fmt($dIn), fmt($dOut));
});
$wall = microtime(true) - $wallStart;

$u = $llm->usageTotals();
echo "\n";
echo "  ── totals ──────────────────────────────────────\n";
printf("  wall time:      %.1fs\n", $wall);
printf("  LLM requests:   %d\n", $u['requests']);
printf("  input tokens:   %s\n", fmt($u['input_tokens']));
printf("  output tokens:  %s\n", fmt($u['output_tokens']));
printf("  total tokens:   %s\n", fmt($u['total_tokens']));
echo "\nOutput: {$project->path()}\n";

// Record the run alongside the project for later reference.
$project->writeJson('build-stats.json', [
    'prompt'        => $prompt,
    'wall_seconds'  => round($wall, 1),
    'requests'      => $u['requests'],
    'input_tokens'  => $u['input_tokens'],
    'output_tokens' => $u['output_tokens'],
    'total_tokens'  => $u['total_tokens'],
    'model'         => Env::get('LLM_MODEL', 'claude-opus-4-8'),
    'built_at'      => gmdate('c'),
]);

if (!$serve) {
    exit(0);
}

echo "\nStarting preview…\n";
$cmd = 'php ' . escapeshellarg(repo_path('bin/playground.php')) . ' ' . escapeshellarg($project->slug());
if ($port !== null) {
    $cmd .= ' --port=' . $port;
}
passthru($cmd, $exit);
exit($exit);

function fmt(int $n): string
{
    return number_format($n);
}
