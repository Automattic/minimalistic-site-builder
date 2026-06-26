<?php
declare(strict_types=1);

/**
 * One-shot: build a site from a prompt, report tokens + wall time, then boot it
 * in WordPress Playground and print the URL.
 *
 *   php bin/create.php "A cozy neighborhood bakery" [--slug=my-bakery] [--port=9400] [--no-serve] [--with-images]
 *
 * --no-serve builds and reports metrics without launching Playground.
 * --with-images also generates real assets for the AI_IMAGE placeholders
 *   (slow + networked; via the WPCOM proxy).
 */

require_once __DIR__ . '/../src/bootstrap.php';

$prompt = null;
$slug = null;
$port = null;
$serve = true;
$withImages = false;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--slug=')) {
        $slug = substr($a, 7);
    } elseif (str_starts_with($a, '--port=')) {
        $port = (int) substr($a, 7);
    } elseif ($a === '--no-serve') {
        $serve = false;
    } elseif ($a === '--with-images') {
        $withImages = true;
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

// Per-step records (id, seconds, input/output token deltas) accumulated as the
// build runs, so a consolidated report can be printed and persisted at the end.
$steps = [];
$prevIn = 0;
$prevOut = 0;
$wallStart = microtime(true);
$pipeline->runThrough($project, null, function (Step $step, float $secs) use ($llm, &$prevIn, &$prevOut, &$steps) {
    $u = $llm->usageTotals();
    $dIn = $u['input_tokens'] - $prevIn;
    $dOut = $u['output_tokens'] - $prevOut;
    $prevIn = $u['input_tokens'];
    $prevOut = $u['output_tokens'];
    $steps[] = record_step($step->id(), $secs, $dIn, $dOut);
    printf("  %-18s %7.1fs %10s %10s\n", $step->id(), $secs, fmt($dIn), fmt($dOut));
});

// Opt-in image generation: turn the AI_IMAGE placeholders into real assets.
// It makes no LLM calls, so its token columns are a deterministic zero.
if ($withImages) {
    $start = microtime(true);
    (new GenerateImagesStep(make_image_client()))->run($project);
    $secs = microtime(true) - $start;
    $steps[] = record_step('generate-images', $secs, 0, 0);
    printf("  %-18s %7.1fs %10s %10s\n", 'generate-images', $secs, fmt(0), fmt(0));
}

$wall = microtime(true) - $wallStart;
$u = $llm->usageTotals();

// Final per-step report: a single at-a-glance breakdown of where time and tokens
// went, so the user doesn't have to scan the rows that scrolled by during the build.
echo "\n";
echo "  ── per-step report ──────────────────────────────────────────────\n";
printf("  %-18s %8s %10s %10s %10s\n", 'step', 'time', 'in-tok', 'out-tok', 'total');
printf("  %-18s %8s %10s %10s %10s\n", str_repeat('-', 18), str_repeat('-', 8), str_repeat('-', 10), str_repeat('-', 10), str_repeat('-', 10));
foreach ($steps as $s) {
    printf(
        "  %-18s %7.1fs %10s %10s %10s\n",
        $s['id'], $s['seconds'], fmt($s['input_tokens']), fmt($s['output_tokens']), fmt($s['total_tokens'])
    );
}

echo "\n";
echo "  ── totals ───────────────────────────────────────────────────────\n";
printf("  wall time:      %.1fs\n", $wall);
printf("  LLM requests:   %d\n", $u['requests']);
printf("  input tokens:   %s\n", fmt($u['input_tokens']));
printf("  output tokens:  %s\n", fmt($u['output_tokens']));
printf("  total tokens:   %s\n", fmt($u['total_tokens']));
echo "\nOutput: {$project->path()}\n";

// Record the run alongside the project for later reference, including the
// per-step breakdown so it can be inspected after the fact.
$project->writeJson('build-stats.json', [
    'prompt'        => $prompt,
    'wall_seconds'  => round($wall, 1),
    'requests'      => $u['requests'],
    'input_tokens'  => $u['input_tokens'],
    'output_tokens' => $u['output_tokens'],
    'total_tokens'  => $u['total_tokens'],
    'model'         => Env::get('LLM_MODEL', 'claude-opus-4-8'),
    'built_at'      => gmdate('c'),
    'steps'         => $steps,
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

/**
 * One per-step record for the final report and build-stats.json. Token counts
 * are always present (a deterministic, non-LLM step records 0, not blank).
 *
 * @return array{id:string,seconds:float,input_tokens:int,output_tokens:int,total_tokens:int}
 */
function record_step(string $id, float $seconds, int $in, int $out): array
{
    return [
        'id'            => $id,
        'seconds'       => round($seconds, 1),
        'input_tokens'  => $in,
        'output_tokens' => $out,
        'total_tokens'  => $in + $out,
    ];
}
