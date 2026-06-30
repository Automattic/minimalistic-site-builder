<?php
declare(strict_types=1);

/**
 * Build every demo website listed in eval/theme-prompts.json in one command.
 *
 *   php bin/build-demos.php [--with-images] [--no-screenshot] [--only=<slug>] [--serve [--port=<n>]] [--file=<path>]
 *
 * Each entry in the prompts file becomes a project under projects/. If a folder
 * with that entry's slug already exists, a fresh sibling is created by appending
 * an incrementing number: photo-journalism-portfolio → photo-journalism-portfolio2
 * → -3 … so re-running the command never overwrites prior testing evidence.
 *
 * Builds run sequentially (not concurrently) so per-step timing is clean and a
 * single failure doesn't abort the others mid-flight.
 *
 * Each build writes its run overview (per-step timing + token spend) to
 * projects/<slug>/logs/project.log, exactly like a single bin/build.php run.
 *
 * After each successful build the home page is captured to a full-page
 * screenshot at projects/<slug>/logs/home.png (headless Playground + Chrome),
 * as visual testing evidence. Disable with --no-screenshot.
 *
 * Options:
 *   --with-images   also generate the AI_IMAGE placeholders into real assets.
 *   --only=<slug>   build only the entry whose slug matches.
 *   --screenshot    capture the post-build home-page screenshot (the default).
 *   --no-screenshot skip the post-build home-page screenshot.
 *   --serve         after building, preview each site in WordPress Playground,
 *                   one at a time (foreground; Ctrl-C a preview to move on to
 *                   the next). Off by default for a batch run.
 *   --no-serve      build only, don't boot any previews (the default).
 *   --port=<n>      Playground port for previews (default 9400; playground.php
 *                   auto-bumps to the next free port if it's busy).
 *   --file=<path>   override the prompts file (default eval/theme-prompts.json).
 */

require_once __DIR__ . '/../src/bootstrap.php';

$withImages = false;
$only = null;
$serve = false;
$screenshot = true;
$port = 9400;
$file = repo_path('eval/theme-prompts.json');
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--with-images') { $withImages = true; }
    elseif (str_starts_with($a, '--only=')) { $only = substr($a, 7); }
    elseif (str_starts_with($a, '--port=')) { $port = (int) substr($a, 7); }
    elseif (str_starts_with($a, '--file=')) { $file = substr($a, 7); }
    elseif ($a === '--no-serve') { $serve = false; }
    elseif ($a === '--serve') { $serve = true; }
    elseif ($a === '--no-screenshot') { $screenshot = false; }
    elseif ($a === '--screenshot') { $screenshot = true; }
    else {
        fwrite(STDERR, "Unknown argument: {$a}\n");
        fwrite(STDERR, "Usage: php bin/build-demos.php [--with-images] [--only=<slug>] [--no-screenshot] [--serve] [--port=9400] [--no-serve] [--file=<path>]\n");
        exit(1);
    }
}

$data = json_decode((string) file_get_contents($file), true);
if (!is_array($data) || !isset($data['prompts']) || !is_array($data['prompts'])) {
    fwrite(STDERR, "Could not read demo prompts from {$file}\n");
    exit(1);
}

// Filter to a single entry when --only is given (matches by slug or id).
$entries = array_values(array_filter(
    $data['prompts'],
    static fn (array $p) => $only === null
        || ($p['slug'] ?? null) === $only
        || ($p['id'] ?? null) === $only,
));
if ($only !== null && $entries === []) {
    fwrite(STDERR, "No prompt with slug/id '{$only}' in {$file}\n");
    exit(1);
}

$llm = make_llm();
$pipeline = build_pipeline($llm);
$models = step_models();
$temperatures = step_temperatures();
$store = new ProjectStore(repo_path('projects'));

$built = [];
$failures = 0;
foreach ($entries as $i => $entry) {
    $prompt = trim((string) ($entry['prompt'] ?? ''));
    if ($prompt === '') {
        $label = (string) ($entry['id'] ?? $entry['slug'] ?? '#' . ($i + 1));
        fwrite(STDERR, "  ✗ SKIPPED: prompt entry '{$label}' has no prompt text\n");
        $failures++;
        continue;
    }
    $baseSlug = ProjectStore::slugify((string) ($entry['slug'] ?? $prompt));
    $slug = $store->freeSlug($baseSlug);

    $project = $store->create($slug);
    $createdAt = gmdate('c');
    $project->writeJson('meta.json', [
        'prompt'           => $prompt,
        'provisional_slug' => $project->slug(),
        'created_at'       => $createdAt,
        // Absolute path so a built project stays traceable to its source prompt
        // file regardless of where the command was invoked from.
        'demo_source'      => realpath($file) ?: $file,
        'demo_id'          => $entry['id'] ?? $baseSlug,
    ]);

    echo "\n[" . ($i + 1) . '/' . count($entries) . "] Building '{$project->slug()}'\n";
    if ($slug !== $baseSlug) {
        echo "  (folder '{$baseSlug}' existed → used '{$slug}')\n";
    }
    echo "  prompt: {$prompt}\n";

    // One BuildReport per demo, written to its own logs/project.log — same
    // accounting bin/build.php produces for a single build. The LLM client's
    // usage totals are cumulative across the whole batch, so baseline them at
    // the start of each demo and diff per step.
    $report = new BuildReport($prompt, $project->slug(), $project->path(), $createdAt);
    $report->setLlmConfig($models, $temperatures);
    $base = $llm->usageTotals();
    $prevIn = $base['input_tokens'];
    $prevOut = $base['output_tokens'];
    $reqStart = $base['requests'];

    $error = null;
    try {
        $pipeline->runThrough($project, null, function (Step $step, float $secs) use (&$report, &$prevIn, &$prevOut, $llm) {
            $u = $llm->usageTotals();
            $inDelta = $u['input_tokens'] - $prevIn;
            $outDelta = $u['output_tokens'] - $prevOut;
            $prevIn = $u['input_tokens'];
            $prevOut = $u['output_tokens'];
            $report->addStep($step->id(), $secs, $inDelta, $outDelta);
            echo BuildReport::formatRow($step->id(), $secs, $inDelta, $outDelta), "\n";
        });
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    if ($withImages && $error === null) {
        $step = new GenerateImagesStep(make_image_client());
        $start = microtime(true);
        try {
            $step->run($project);
            $secs = microtime(true) - $start;
            // Image generation uses the Vertex proxy, not Claude — no LLM tokens.
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
        } catch (Throwable $e) {
            $error = 'image step: ' . $e->getMessage();
        }
    }

    if ($error !== null) {
        fwrite(STDERR, "  ✗ FAILED: {$error}\n");
        $failures++;
        continue;
    }

    $report->setRequestCount($llm->usageTotals()['requests'] - $reqStart);
    echo $report->totalLine(), "\n";
    if (($imagesLine = $report->imagesLine()) !== null) {
        echo $imagesLine, "\n";
    }
    $project->writeText('logs/project.log', $report->render());
    $total = $report->totalSecs();
    echo "  Output: {$project->path()}\n";

    // Capture a full-page screenshot of the home page as visual testing
    // evidence (projects/<slug>/logs/home.png). Boots the site headless in
    // Playground, shoots, tears down. A failure here doesn't fail the build —
    // the theme is the artefact; the screenshot is a bonus record.
    $shotPath = null;
    if ($screenshot) {
        $shotPath = $project->logPath('home.png');
        $cmd = 'php ' . escapeshellarg(repo_path('bin/screenshot.php'))
            . ' ' . escapeshellarg($project->slug())
            . ' --port=' . $port
            . ' --out=' . escapeshellarg($shotPath);
        passthru($cmd, $shotExit);
        if ($shotExit !== 0) {
            fwrite(STDERR, "  (screenshot failed — continuing)\n");
            $shotPath = null;
        }
    }

    $built[] = [
        'slug'       => $project->slug(),
        'path'       => $project->path(),
        'seconds'    => round($total, 1),
        'screenshot' => $shotPath,
    ];
}

// Summary — the at-a-glance record of what this command produced, suitable as
// the index of testing-evidence artefacts from this run.
echo "\n── built " . count($built) . '/' . count($entries) . " sites";
if ($failures > 0) { echo " ({$failures} failed)"; }
echo " ─────────────────────────────────────\n";
foreach ($built as $b) {
    printf("  %-32s %6.1fs  %s\n", $b['slug'], $b['seconds'], $b['path']);
    if (($b['screenshot'] ?? null) !== null) {
        echo "      ↳ screenshot: {$b['screenshot']}\n";
    }
}

// Optionally preview each built site in WordPress Playground. Each instance runs
// in the foreground (it's a long-lived server), so they're shown one at a time:
// Ctrl-C the current preview to advance to the next. They all reuse the same
// --port — by the time one starts the previous has been stopped — and
// playground.php auto-bumps if that port is somehow still busy.
if ($serve && $built !== []) {
    echo "\nStarting previews (Ctrl-C each to move to the next)…\n";
    foreach ($built as $b) {
        $cmd = 'php ' . escapeshellarg(repo_path('bin/playground.php'))
            . ' ' . escapeshellarg($b['slug'])
            . ' --port=' . $port;
        passthru($cmd, $exit);
        if ($exit !== 0) {
            fwrite(STDERR, "  preview failed for {$b['slug']} (exit {$exit})\n");
        }
    }
}

exit($failures > 0 ? 1 : 0);
