<?php
declare(strict_types=1);

/**
 * Build a site from a prompt.
 *
 *   php bin/build.php "A cozy neighborhood bakery" [--slug=my-slug] [--until=step-id] [--with-images] [--port=9400] [--no-serve]
 *
 * Seeds projects/<slug>/meta.json with the prompt, then runs the pipeline,
 * printing per-step timing and token spend and writing the full run overview to
 * projects/<slug>/logs/project.log. Without --slug the folder gets a short
 * arbitrary name (e.g. "amber-otter"); pass --slug to target a fixed directory
 * and reuse it across re-runs.
 *
 * --until=<step-id> stops after that step (an unknown id errors with the list).
 * Steps that run concurrently share one id (e.g. theme-json+section-plan), but
 * --until also accepts a member id (theme-json) and stops once the group is done.
 *
 * --with-images additionally generates the AI image placeholders into real
 * assets via the WPCOM AI proxy (slow + networked; off by default).
 *
 * After a full build it boots the site in WordPress Playground and prints the
 * URL. --no-serve skips that (build only); --until=... also skips it (the build
 * is incomplete). --port chooses the Playground port.
 */

require_once __DIR__ . '/../src/bootstrap.php';

$args = array_slice($argv, 1);
$prompt = null;
$slug = null;
$until = null;
$withImages = false;
$port = null;
$serve = true;
foreach ($args as $a) {
    if (str_starts_with($a, '--slug=')) {
        $slug = substr($a, 7);
    } elseif (str_starts_with($a, '--until=')) {
        $until = substr($a, 8);
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
    fwrite(STDERR, "Usage: php bin/build.php \"<prompt>\" [--slug=...] [--until=step-id] [--with-images] [--port=9400] [--no-serve]\n");
    exit(1);
}

$llm = make_llm();
$pipeline = build_pipeline($llm);

// Validate --until BEFORE creating the project, so an unknown id fails loud
// (instead of silently running the whole build) without leaving a stray project
// directory behind. Group members are valid stops too (see Pipeline::stopIds).
if ($until !== null && !in_array($until, $pipeline->stopIds(), true)) {
    fwrite(STDERR, "Unknown --until step '{$until}'. Valid steps:\n  "
        . implode("\n  ", $pipeline->stopIds()) . "\n");
    exit(1);
}

$store = new ProjectStore(repo_path('projects'));

// Without an explicit --slug, name the folder with a short arbitrary slug
// (e.g. "amber-otter") rather than echoing the whole prompt. freeSlug() adds
// the same repetition protection demo builds get, so a repeat name never
// overwrites an existing project.
$slug ??= $store->freeSlug(ProjectStore::randomSlug());
$project = $store->create($slug);

// Seed meta.json (provisional slug = directory name; the canonical site slug
// comes from siteSpec.json once generated). Merge over any existing meta so an
// orchestrator (bin/build-demos.php) can pre-seed extra fields — demo_source,
// demo_id — before delegating the build to this script.
$meta = $project->exists('meta.json') ? $project->readJson('meta.json') : [];
$project->writeJson('meta.json', array_merge($meta, [
    'prompt'           => $prompt,
    'provisional_slug' => $project->slug(),
    'created_at'       => gmdate('c'),
]));

echo "Building '{$project->slug()}'\n";

$report = new BuildReport($prompt, $project->slug(), $project->path(), gmdate('c'));

// Attribute token spend to each step by diffing the client's cumulative usage
// totals before and after it ran (the reporter fires once a step completes).
// The onStart callback tracks the step being run, so a failure names the step
// that threw — the per-step rows only print on completion, so without it the
// error would appear under the LAST COMPLETED step and point at the wrong one.
$prevIn = 0;
$prevOut = 0;
$currentStep = null;
try {
    $pipeline->runThrough($project, $until, function (Step $step, float $secs) use (&$report, &$prevIn, &$prevOut, $llm) {
        $u = $llm->usageTotals();
        $inDelta = $u['input_tokens'] - $prevIn;
        $outDelta = $u['output_tokens'] - $prevOut;
        $prevIn = $u['input_tokens'];
        $prevOut = $u['output_tokens'];
        $report->addStep($step->id(), $secs, $inDelta, $outDelta);
        echo BuildReport::formatRow($step->id(), $secs, $inDelta, $outDelta), "\n";
    }, function (Step $step) use (&$currentStep): void {
        $currentStep = $step->id();
    });
} catch (Throwable $e) {
    fwrite(STDERR, '✗ FAILED' . ($currentStep !== null ? " in step {$currentStep}" : '') . ": {$e->getMessage()}\n");
    exit(1);
}

// Image generation is opt-in: slow and networked, so it runs only on request
// and only for a full build (skipped when --until stops the pipeline early).
if ($withImages && $until === null) {
    $step = new GenerateImagesStep(make_image_client());
    $start = microtime(true);
    $step->run($project);
    $secs = microtime(true) - $start;
    // Image generation uses the Vertex proxy, not Claude, so it spends no LLM
    // tokens; the row records its wall time, and the tally comes from images.json.
    $report->addStep($step->id(), $secs, 0, 0);
    echo BuildReport::formatRow($step->id(), $secs, 0, 0), "\n";

    $specs = $project->exists('images.json') ? $project->readJson('images.json') : [];
    $generated = 0;
    $failed = 0;
    foreach ($specs as $spec) {
        match ($spec['status'] ?? '') {
            'completed' => $generated++,
            'failed'    => $failed++,
            default     => null,
        };
    }
    $report->setImages($generated, $failed, count($specs));
}

$report->setRequestCount($llm->usageTotals()['requests']);

echo $report->totalLine(), "\n";
if (($imagesLine = $report->imagesLine()) !== null) {
    echo $imagesLine, "\n";
}

// Persist the full run overview alongside the per-call LLM transcripts, so a
// finished project carries its own step-by-step timing/token/image accounting.
$project->writeText('logs/project.log', $report->render());

echo "Output: {$project->path()}\n";

// Boot the site in WordPress Playground and print the URL. Skipped when the
// build stopped early (--until) or the user opted out (--no-serve).
if ($serve && $until === null) {
    echo "\nStarting preview…\n";
    $cmd = 'php ' . escapeshellarg(repo_path('bin/playground.php')) . ' ' . escapeshellarg($project->slug());
    if ($port !== null) {
        $cmd .= ' --port=' . $port;
    }
    passthru($cmd, $exit);
    exit($exit);
}
