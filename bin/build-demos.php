<?php
declare(strict_types=1);

/**
 * Build every demo website listed in eval/theme-prompts.json in one command.
 *
 *   php bin/build-demos.php [--with-images] [--only=<slug>] [--no-serve]
 *
 * Each entry in the prompts file becomes a project under projects/. If a folder
 * with that entry's slug already exists, a fresh sibling is created by appending
 * an incrementing number: photo-journalism-portfolio → photo-journalism-portfolio2
 * → -3 … so re-running the command never overwrites prior testing evidence.
 *
 * Builds run sequentially (not concurrently) so per-step timing is clean and a
 * single failure doesn't abort the others mid-flight.
 *
 * Options:
 *   --with-images   also generate the AI_IMAGE placeholders into real assets.
 *   --only=<slug>   build only the entry whose slug matches.
 *   --no-serve      build only, don't boot WordPress Playground previews.
 *                   (Default is also no-serve for a batch run; pass --serve to
 *                   boot each site on consecutive ports 9400, 9401, …)
 *   --serve         boot each built site in WordPress Playground after building.
 *   --port=<n>      first Playground port (default 9400; increments per site).
 *   --file=<path>   override the prompts file (default eval/theme-prompts.json).
 */

require_once __DIR__ . '/../src/bootstrap.php';

$withImages = false;
$only = null;
$serve = false;
$port = 9400;
$file = repo_path('eval/theme-prompts.json');
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--with-images') { $withImages = true; }
    elseif (str_starts_with($a, '--only=')) { $only = substr($a, 7); }
    elseif (str_starts_with($a, '--port=')) { $port = (int) substr($a, 7); }
    elseif (str_starts_with($a, '--file=')) { $file = substr($a, 7); }
    elseif ($a === '--no-serve') { $serve = false; }
    elseif ($a === '--serve') { $serve = true; }
    else {
        fwrite(STDERR, "Unknown argument: {$a}\n");
        fwrite(STDERR, "Usage: php bin/build-demos.php [--with-images] [--only=<slug>] [--serve] [--port=9400] [--no-serve] [--file=<path>]\n");
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
$store = new ProjectStore(repo_path('projects'));

$built = [];
$failures = 0;
foreach ($entries as $i => $entry) {
    $prompt = (string) $entry['prompt'];
    $baseSlug = ProjectStore::slugify((string) ($entry['slug'] ?? $prompt));
    $slug = next_free_slug($store, $baseSlug);

    $project = $store->create($slug);
    $project->writeJson('meta.json', [
        'prompt'           => $prompt,
        'provisional_slug' => $project->slug(),
        'created_at'       => gmdate('c'),
        'demo_source'      => $file,
        'demo_id'          => $entry['id'] ?? $baseSlug,
    ]);

    echo "\n[" . ($i + 1) . '/' . count($entries) . "] Building '{$project->slug()}'\n";
    if ($slug !== $baseSlug) {
        echo "  (folder '{$baseSlug}' existed → used '{$slug}')\n";
    }
    echo "  prompt: {$prompt}\n";

    $total = 0.0;
    $error = null;
    try {
        $pipeline->runThrough($project, null, function (Step $step, float $secs) use (&$total) {
            $total += $secs;
            printf("  %-22s %6.1fs\n", $step->id(), $secs);
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
            $total += $secs;
            printf("  %-22s %6.1fs\n", $step->id(), $secs);
        } catch (Throwable $e) {
            $error = 'image step: ' . $e->getMessage();
        }
    }

    if ($error !== null) {
        fwrite(STDERR, "  ✗ FAILED: {$error}\n");
        $failures++;
        continue;
    }

    printf("  %-22s %6.1fs\n", 'TOTAL', $total);
    echo "  Output: {$project->path()}\n";
    $built[] = ['slug' => $project->slug(), 'path' => $project->path(), 'seconds' => round($total, 1)];
}

// Summary — the at-a-glance record of what this command produced, suitable as
// the index of testing-evidence artefacts from this run.
echo "\n── built " . count($built) . '/' . count($entries) . " sites";
if ($failures > 0) { echo " ({$failures} failed)"; }
echo " ─────────────────────────────────────\n";
foreach ($built as $b) {
    printf("  %-32s %6.1fs  %s\n", $b['slug'], $b['seconds'], $b['path']);
}

// Optionally boot each built site in WordPress Playground on consecutive ports.
if ($serve && $built !== []) {
    echo "\nStarting previews…\n";
    foreach ($built as $j => $b) {
        $cmd = 'php ' . escapeshellarg(repo_path('bin/playground.php'))
            . ' ' . escapeshellarg($b['slug'])
            . ' --port=' . ($port + $j);
        echo "  " . $b['slug'] . " → http://localhost:" . ($port + $j) . "\n";
        // Each Playground process is foregrounded in turn; Ctrl-C one to move on
        // to the next, or run `php bin/playground.php <slug> --port=<n>` manually.
        passthru($cmd, $exit);
        if ($exit !== 0) {
            fwrite(STDERR, "  preview failed for {$b['slug']} (exit {$exit})\n");
        }
    }
}

exit($failures > 0 ? 1 : 0);

/**
 * Return the first unused folder slug for a project: baseSlug if free, else
 * baseSlug2, baseSlug3, … — so a re-run never collides with prior evidence.
 */
function next_free_slug(ProjectStore $store, string $baseSlug): string
{
    $slug = $baseSlug;
    $n = 2;
    while (is_dir(repo_path('projects/' . $slug))) {
        $slug = $baseSlug . $n;
        $n++;
    }
    return $slug;
}
