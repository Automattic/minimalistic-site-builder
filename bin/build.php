<?php
declare(strict_types=1);

/**
 * Build a site from a prompt.
 *
 *   php bin/build.php "A cozy neighborhood bakery" [--slug=my-slug] [--until=step-id]
 *
 * Seeds projects/<slug>/meta.json with the prompt, then runs the pipeline,
 * printing per-step timing. Re-running reuses the same project directory.
 */

require_once __DIR__ . '/../src/bootstrap.php';

$args = array_slice($argv, 1);
$prompt = null;
$slug = null;
$until = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--slug=')) {
        $slug = substr($a, 7);
    } elseif (str_starts_with($a, '--until=')) {
        $until = substr($a, 8);
    } elseif ($prompt === null) {
        $prompt = $a;
    }
}

if ($prompt === null || trim($prompt) === '') {
    fwrite(STDERR, "Usage: php bin/build.php \"<prompt>\" [--slug=...] [--until=step-id]\n");
    exit(1);
}

$slug ??= $prompt;

$store = new ProjectStore(repo_path('projects'));
$project = $store->create($slug);

// Seed meta.json (provisional slug = directory name; the canonical site slug
// comes from siteSpec.json once generated).
$project->writeJson('meta.json', [
    'prompt'           => $prompt,
    'provisional_slug' => $project->slug(),
    'created_at'       => gmdate('c'),
]);

$llm = make_llm();
$pipeline = build_pipeline($llm);

echo "Building '{$project->slug()}'\n";
$total = 0.0;
$pipeline->runThrough($project, $until, function (Step $step, float $secs) use (&$total) {
    $total += $secs;
    printf("  %-22s %6.1fs\n", $step->id(), $secs);
});
printf("  %-22s %6.1fs\n", 'TOTAL', $total);
echo "Output: {$project->path()}\n";
