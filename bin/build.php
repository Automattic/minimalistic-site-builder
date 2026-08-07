<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockFixers;
use Automattic\SiteBuild\BuildReport;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\Steps\CoverContrastStep;

/**
 * Build a site from a prompt.
 *
 *   php bin/build.php "A cozy neighborhood bakery" [--provider=openai] [--slug=my-slug] [--until=step-id] [--multi-page] [--pages="Home, Menu, About"] [--writing-direction=ltr|rtl] [--hero-canvas=full-bleed|framed] [--hero-media-modes=foreground-image] [--max-hero-images=1] [--hero-copy-capacity=compact|standard|expanded] [--with-images] [--port=9400] [--no-serve]
 *
 * --provider=<anthropic|openai|xai|openrouter> picks the model set (config/models.json):
 * each step runs on that provider's large/small tier. Per-step LLM_MODEL_<STEP>
 * env overrides still win. Unset falls back to LLM_PROVIDER / the config default.
 *
 * Seeds projects/<slug>/meta.json with the prompt, then runs the pipeline,
 * printing per-step timing, token spend and configured model(s) as each step
 * lands, then a consolidated report at the end. The same overview is written to
 * projects/<slug>/logs/project.log, and the run's machine-readable accounting
 * to projects/<slug>/build-stats.json. Without --slug the folder gets a short
 * arbitrary name (e.g. "amber-otter"); pass --slug to target a fixed directory
 * and reuse it across re-runs.
 *
 * --until=<step-id> stops after that step (an unknown id errors with the list).
 * Steps that run concurrently share one id (e.g. theme-json+page-plan), but
 * --until also accepts a member id (theme-json) and stops once the group is done.
 *
 * --multi-page lets the site plan inner pages (about, contact, …) beyond the
 * homepage. Off by default: the build produces ONLY the landing page.
 *
 * --pages="Home, Menu, About" (requires --multi-page) fixes the page list —
 * comma-separated titles, the FIRST one is the homepage — instead of letting
 * the LLM invent it; the site-spec model still writes each page's purpose.
 * Without it the LLM decides which pages the site needs.
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
$multiPage = false;
$pagesArg = null;
$port = null;
$serve = true;
$provider = null;
$writingDirection = null;
$heroCanvas = null;
$heroMediaModesArg = null;
$maxHeroImagesArg = null;
$heroCopyCapacity = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--slug=')) {
        $slug = substr($a, 7);
    } elseif (str_starts_with($a, '--provider=')) {
        $provider = substr($a, 11);
    } elseif (str_starts_with($a, '--until=')) {
        $until = substr($a, 8);
    } elseif (str_starts_with($a, '--pages=')) {
        $pagesArg = substr($a, 8);
    } elseif (str_starts_with($a, '--port=')) {
        $port = (int) substr($a, 7);
    } elseif (str_starts_with($a, '--writing-direction=')) {
        $writingDirection = substr($a, 20);
    } elseif (str_starts_with($a, '--hero-canvas=')) {
        $heroCanvas = substr($a, 14);
    } elseif (str_starts_with($a, '--hero-media-modes=')) {
        $heroMediaModesArg = substr($a, 19);
    } elseif (str_starts_with($a, '--max-hero-images=')) {
        $maxHeroImagesArg = substr($a, 18);
    } elseif (str_starts_with($a, '--hero-copy-capacity=')) {
        $heroCopyCapacity = substr($a, 21);
    } elseif ($a === '--no-serve') {
        $serve = false;
    } elseif ($a === '--with-images') {
        $withImages = true;
    } elseif ($a === '--multi-page') {
        $multiPage = true;
    } elseif ($prompt === null && !str_starts_with($a, '--')) {
        $prompt = $a;
    } else {
        Narrator::write("Unknown argument: {$a}\n");
        Narrator::write("Usage: php bin/build.php \"<prompt>\" [--provider=anthropic|openai|xai|openrouter] [--slug=...] [--until=step-id] [--multi-page] [--pages=\"Home, Menu, About\"] [--with-images] [--port=9400] [--no-serve]\n");
        exit(1);
    }
}

if ($prompt === null || trim($prompt) === '') {
    Narrator::write("Usage: php bin/build.php \"<prompt>\" [--provider=anthropic|openai|xai|openrouter] [--slug=...] [--until=step-id] [--multi-page] [--pages=\"Home, Menu, About\"] [--writing-direction=ltr|rtl] [--hero-canvas=full-bleed|framed] [--hero-media-modes=cover-image,foreground-image] [--max-hero-images=1..2] [--hero-copy-capacity=compact|standard|expanded] [--with-images] [--port=9400] [--no-serve]\n");
    exit(1);
}

try {
    require_multi_page_for_pages($pagesArg, $multiPage);
    $pages = $pagesArg === null ? [] : split_csv_flag($pagesArg);
} catch (InvalidArgumentException $e) {
    Narrator::write($e->getMessage() . "\n");
    exit(1);
}

$designConstraints = [];
if ($heroCanvas !== null) {
    $designConstraints['hero_canvas'] = $heroCanvas;
}
if ($heroMediaModesArg !== null) {
    $designConstraints['allowed_hero_media_modes'] = split_csv_flag($heroMediaModesArg);
}
if ($maxHeroImagesArg !== null) {
    if (preg_match('/^\d+$/', $maxHeroImagesArg) !== 1) {
        Narrator::write("--max-hero-images must be an integer from 1 through 2.\n");
        exit(1);
    }
    $designConstraints['max_hero_images'] = (int) $maxHeroImagesArg;
}
if ($heroCopyCapacity !== null) {
    $designConstraints['hero_copy_capacity'] = $heroCopyCapacity;
}

// --provider selects the model set for the whole run. It just sets LLM_PROVIDER
// (which make_llm() and StepDefaults both read), so per-step LLM_MODEL_<STEP>
// overrides still apply on top. Keep this after the design-constraint checks so
// a command with multiple invalid flags reports the same first error as before.
try {
    $provider = normalize_provider($provider);
} catch (InvalidArgumentException $e) {
    Narrator::write($e->getMessage() . "\n");
    exit(1);
}
if ($provider !== null) {
    putenv("LLM_PROVIDER={$provider}");
}

$llm = make_llm();
$builder = make_site_builder($llm);
$pipeline = $builder->pipeline();

// step id => model, for the model column (see BuildReport::modelLabel).
$models = step_models();

// Validate --until BEFORE creating the project, so an unknown id fails loud
// (instead of silently running the whole build) without leaving a stray project
// directory behind. Group members are valid stops too (see Pipeline::stopIds).
if ($until !== null && !in_array($until, $pipeline->stopIds(), true)) {
    Narrator::write("Unknown --until step '{$until}'. Valid steps:\n  "
        . implode("\n  ", $pipeline->stopIds()) . "\n");
    exit(1);
}

// Without an explicit --slug, createProject picks a free random adjective-noun
// name. Explicit --slug reuses that directory across re-runs. meta.json is
// seeded (and merged) inside createProject so demo orchestrators can pre-seed.
try {
    $project = $builder->createProject(
        prompt: $prompt,
        slug: $slug,
        multiPage: $multiPage,
        pages: $pages,
        designConstraints: $designConstraints,
        writingDirection: $writingDirection,
    );
} catch (InvalidArgumentException $e) {
    Narrator::write($e->getMessage() . "\n");
    exit(1);
}

echo "Building '{$project->slug()}'\n";
echo "  prompt: {$prompt}\n\n";

$report = new BuildReport($prompt, $project->slug(), $project->path(), gmdate('c'));
echo BuildReport::formatHeader(), "\n";

// Announce a step before it runs, flushed immediately so it appears in real
// time — a long step (landing-page, image generation) never leaves the build
// looking frozen.
$announce = static function (Step $step): void {
    echo BuildReport::formatStartRow($step->id(), $step->label()), "\n";
    flush();
};

// Record one completed step from the client's cumulative totals. Reusing this
// for both pipeline and opt-in steps keeps one usage cursor, so a conditional
// image-prompt repair is attributed instead of disappearing from the report.
$recordCompleted = static function (Step $step, float $secs) use ($llm, $models, $report): void {
    $usage = $llm->usageTotals();
    $configuredModels = BuildReport::modelLabel($step->id(), $models);
    $row = $report->recordStep(
        $step->id(),
        $secs,
        $usage['input_tokens'],
        $usage['output_tokens'],
        $configuredModels,
    );
    echo BuildReport::formatRow($row['id'], $row['secs'], $row['in'], $row['out'], $row['model']), "\n";
};

// Run a step that lives OUTSIDE the pipeline (the opt-in image pair below):
// announce it, time it, and record it through the same usage path as the graph.
$runExtraStep = static function (Step $step) use ($announce, $project, $recordCompleted): void {
    $announce($step);
    $start = microtime(true);
    $step->run($project);
    $secs = microtime(true) - $start;
    $recordCompleted($step, $secs);
};

// The onStart callback tracks the step being run, so a failure names the step
// that threw — the per-step rows only print on completion, so without it the
// error would appear under the LAST COMPLETED step and point at the wrong one.
$currentStep = null;
$wallStart = microtime(true);
try {
    $pipeline->runThrough($project, $until, function (Step $step, float $secs) use ($recordCompleted) {
        $recordCompleted($step, $secs);
    }, function (Step $step) use (&$currentStep, $announce): void {
        $currentStep = $step->id();
        $announce($step);
    });
} catch (Throwable $e) {
    Narrator::write('✗ FAILED' . ($currentStep !== null ? " in step {$currentStep}" : '') . ": {$e->getMessage()}\n");
    exit(1);
}

// Image generation is opt-in: slow and networked, so it runs only on request
// and only for a full build (skipped when --until stops the pipeline early).
if ($withImages && $until === null) {
    // Image generation goes through the Vertex proxy, not the LLM — its only
    // model use is the Llm rewriting safety-filtered prompts (small tier) and
    // regenerating. The tally comes from images.json below.
    $runExtraStep(make_generate_images_step($llm));

    // Now that the real pixels exist, re-check cover text against the actual
    // (dimmed) images and raise dimRatio / flip text colors where needed.
    $runExtraStep(new CoverContrastStep(BlockFixers::default()));

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

$usage = $llm->usageTotals();
$report->setLlmTotals($usage['requests'], $usage['input_tokens'], $usage['output_tokens']);
$report->setWallSeconds(microtime(true) - $wallStart);
$report->setBuiltAt(gmdate('c'));

// Surface the defects the build delivered through (warnings.json) so a
// warned build never looks identical to a clean one on the console. A corrupt
// warnings.json is a corrupt required build artifact, so its read failure stays
// fatal instead of rendering a falsely clean successful summary.
$report->setWarnings(
    $project->exists('warnings.json') ? $project->readJson('warnings.json') : []
);

// One consolidated breakdown of where the time, tokens and models went, so the
// numbers don't have to be reassembled from the rows that scrolled past. The
// very same bytes are persisted next to the per-call LLM transcripts.
$overview = $report->render();
echo "\n", $overview;
$project->writeText('logs/project.log', $overview);

// The same run as a machine-readable record, for comparing cost and model mix
// across builds after the fact.
$project->writeJson('build-stats.json', $report->stats(default_llm_model(), $models));

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
