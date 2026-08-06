<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockFixers;
use Automattic\SiteBuild\BuildReport;
use Automattic\SiteBuild\ModelConfig;
use Automattic\SiteBuild\Package;
use Automattic\SiteBuild\SiteBuilder;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\Steps\CoverContrastStep;
use Automattic\SiteBuild\Steps\GenerateImagesStep;

/**
 * Build a site from a prompt.
 *
 *   php bin/build.php "A cozy neighborhood bakery" [--provider=openai] [--slug=my-slug] [--from=step-id] [--until=step-id] [--multi-page] [--pages="Home, Menu, About"] [--writing-direction=ltr|rtl] [--hero-canvas=full-bleed|framed] [--hero-media-modes=foreground-image] [--max-hero-images=1] [--hero-copy-capacity=compact|standard|expanded] [--with-images] [--port=9400] [--no-serve]
 *
 * --provider=<anthropic|openai|xai|openrouter> picks the model set (config/models.json):
 * each step runs on that provider's large/small tier. Per-step LLM_MODEL_<STEP>
 * env overrides still win. Unset falls back to LLM_PROVIDER / the config default.
 *
 * Seeds projects/<slug>/meta.json with the prompt, then runs the pipeline,
 * printing per-step timing and token spend and writing the full run overview to
 * projects/<slug>/logs/project.log. Without --slug the folder gets a short
 * arbitrary name (e.g. "amber-otter"); pass --slug to target a fixed directory
 * and reuse it across re-runs.
 *
 * --until=<step-id> stops after that step (an unknown id errors with the list).
 * Steps that run concurrently share one id (e.g. theme-json+page-plan), but
 * --until also accepts a member id (theme-json) and stops once the group is done.
 *
 * --from=<step-id> is the mirror image: it SKIPS every step that order-precedes
 * that id and resumes from there, reusing the named --slug project's on-disk
 * artifacts (design/*.html, site.css, designDirection.json, meta.json, …) as
 * inputs. It requires --slug (the existing project), ignores the prompt (the
 * design already exists), and leaves the reused directory otherwise untouched.
 * Same id list as --until, group members included.
 *
 * Deterministic-tail recipe — re-run transform→theme against an already-built
 * project with NO LLM and NO image generation, iterating in seconds:
 *
 *   php bin/build.php --slug=portfolio-new --from=transform-site --until=page-styles
 *
 * Because --until stops before generate-images (and --with-images stays opt-in),
 * that resume makes zero network calls.
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
$from = null;
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
    } elseif (str_starts_with($a, '--from=')) {
        $from = substr($a, 7);
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
        fwrite(STDERR, "Unknown argument: {$a}\n");
        fwrite(STDERR, "Usage: php bin/build.php \"<prompt>\" [--provider=anthropic|openai|xai|openrouter] [--slug=...] [--from=step-id] [--until=step-id] [--multi-page] [--pages=\"Home, Menu, About\"] [--with-images] [--port=9400] [--no-serve]\n");
        exit(1);
    }
}

// --from resumes an existing build's deterministic tail against on-disk
// artifacts, so the prompt is optional (the design already exists); every
// other invocation still requires it.
if ($from === null && ($prompt === null || trim($prompt) === '')) {
    fwrite(STDERR, "Usage: php bin/build.php \"<prompt>\" [--provider=anthropic|openai|xai|openrouter] [--slug=...] [--from=step-id] [--until=step-id] [--multi-page] [--pages=\"Home, Menu, About\"] [--writing-direction=ltr|rtl] [--hero-canvas=full-bleed|framed] [--hero-media-modes=cover-image,foreground-image] [--max-hero-images=1..2] [--hero-copy-capacity=compact|standard|expanded] [--with-images] [--port=9400] [--no-serve]\n");
    exit(1);
}

// --from resumes a materialized project in place, so it needs the existing
// slug to locate that directory (createProject would otherwise pick a random
// one, and there would be no design/*.html to resume from).
if ($from !== null && ($slug === null || trim($slug) === '')) {
    fwrite(STDERR, "--from requires --slug=<existing project> to resume its on-disk artifacts.\n");
    exit(1);
}

// --pages fixes WHICH pages get built; --multi-page owns WHETHER inner pages
// exist at all, so a list without the flag is a contradiction — fail loud
// rather than silently ignore either.
if ($pagesArg !== null && !$multiPage) {
    fwrite(STDERR, "--pages requires --multi-page.\n");
    exit(1);
}
$pages = $pagesArg === null ? []
    : array_values(array_filter(array_map('trim', explode(',', $pagesArg)), static fn (string $t): bool => $t !== ''));

$designConstraints = [];
if ($heroCanvas !== null) {
    $designConstraints['hero_canvas'] = $heroCanvas;
}
if ($heroMediaModesArg !== null) {
    $designConstraints['allowed_hero_media_modes'] = array_values(array_filter(
        array_map('trim', explode(',', $heroMediaModesArg)),
        static fn (string $mode): bool => $mode !== '',
    ));
}
if ($maxHeroImagesArg !== null) {
    if (preg_match('/^\d+$/', $maxHeroImagesArg) !== 1) {
        fwrite(STDERR, "--max-hero-images must be an integer from 1 through 2.\n");
        exit(1);
    }
    $designConstraints['max_hero_images'] = (int) $maxHeroImagesArg;
}
if ($heroCopyCapacity !== null) {
    $designConstraints['hero_copy_capacity'] = $heroCopyCapacity;
}

// --provider selects the model set for the whole run. It just sets LLM_PROVIDER
// (which make_llm() and StepDefaults both read), so per-step LLM_MODEL_<STEP>
// overrides still apply on top. Validate here for a friendly early error.
if ($provider !== null) {
    $provider = strtolower(trim($provider));
    if (!ModelConfig::hasProvider($provider)) {
        fwrite(STDERR, "Unknown --provider '{$provider}'. Known: "
            . implode(', ', ModelConfig::providerNames()) . "\n");
        exit(1);
    }
    putenv("LLM_PROVIDER={$provider}");
    $_ENV['LLM_PROVIDER'] = $provider;
}

$llm = make_llm();
$builder = new SiteBuilder(
    llm: $llm,
    promptsDir: Package::promptsDir(),
    outputRoot: repo_path('projects'),
    blockFixer: BlockFixers::default(),
    models: step_models(),
);
$pipeline = $builder->pipeline();

// Validate --until BEFORE creating the project, so an unknown id fails loud
// (instead of silently running the whole build) without leaving a stray project
// directory behind. Group members are valid stops too (see Pipeline::stopIds).
if ($until !== null && !in_array($until, $pipeline->stopIds(), true)) {
    fwrite(STDERR, "Unknown --until step '{$until}'. Valid steps:\n  "
        . implode("\n  ", $pipeline->stopIds()) . "\n");
    exit(1);
}

// Validate --from the same way (same id list, group members included), so an
// unknown resume point fails loud instead of silently running everything.
if ($from !== null && !in_array($from, $pipeline->stopIds(), true)) {
    fwrite(STDERR, "Unknown --from step '{$from}'. Valid steps:\n  "
        . implode("\n  ", $pipeline->stopIds()) . "\n");
    exit(1);
}

if ($from !== null) {
    // Resume: open the existing project untouched (no createProject, which would
    // re-seed meta.json and could clobber multi_page/design_constraints from the
    // original build). Its design/*.html, site.css, meta.json etc. are the inputs
    // the deterministic tail reads, so leave every artifact on disk as-is.
    try {
        $project = $builder->store()->open($slug);
    } catch (RuntimeException $e) {
        fwrite(STDERR, "--from: {$e->getMessage()}\n");
        exit(1);
    }
    // The prompt argument is ignored on resume; the report reuses the recorded one.
    $prompt = $project->exists('meta.json')
        ? (string) ($project->readJson('meta.json')['prompt'] ?? '')
        : '';
} else {
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
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}

echo ($from !== null ? "Resuming '{$project->slug()}' from {$from}\n" : "Building '{$project->slug()}'\n");

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
    }, $from);
} catch (Throwable $e) {
    fwrite(STDERR, '✗ FAILED' . ($currentStep !== null ? " in step {$currentStep}" : '') . ": {$e->getMessage()}\n");
    exit(1);
}

// Image generation is opt-in: slow and networked, so it runs only on request
// and only for a full build (skipped when --until stops the pipeline early).
if ($withImages && $until === null) {
    // The Llm rewrites safety-filtered prompts (small tier) and regenerates.
    $step = new GenerateImagesStep(make_image_client(), $llm, step_models()['image-prompt-repair'] ?? null);
    $start = microtime(true);
    $step->run($project);
    $secs = microtime(true) - $start;
    // Image generation uses the Vertex proxy, not Claude, so it spends no LLM
    // tokens; the row records its wall time, and the tally comes from images.json.
    $report->addStep($step->id(), $secs, 0, 0);
    echo BuildReport::formatRow($step->id(), $secs, 0, 0), "\n";

    // Now that the real pixels exist, re-check cover text against the actual
    // (dimmed) images and raise dimRatio / flip text colors where needed.
    $step = new CoverContrastStep(BlockFixers::default());
    $start = microtime(true);
    $step->run($project);
    $secs = microtime(true) - $start;
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

// Surface the defects the build delivered through (warnings.json) so a
// warned build never looks identical to a clean one on the console. A corrupt
// warnings.json is a corrupt required build artifact, so its read failure stays
// fatal instead of rendering a falsely clean successful summary.
$report->setWarnings(
    $project->exists('warnings.json') ? $project->readJson('warnings.json') : []
);

echo $report->totalLine(), "\n";
if (($imagesLine = $report->imagesLine()) !== null) {
    echo $imagesLine, "\n";
}
if (($warningsLine = $report->warningsLine()) !== null) {
    echo $warningsLine, "\n";
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
