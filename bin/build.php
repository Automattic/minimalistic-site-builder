<?php
declare(strict_types=1);

use Automattic\SiteBuild\BuildReport;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\PlaygroundRunner;
use Automattic\SiteBuild\RunnerResolver;
use Automattic\SiteBuild\SiteVerifier;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepComposition;
use Automattic\SiteBuild\StudioAppRunner;
use Automattic\SiteBuild\StudioCli;

/**
 * Build a site from a prompt.
 *
 *   php bin/build.php "A cozy neighborhood bakery" [--provider=openai] [--slug=my-slug] [--from=step-id] [--until=step-id] [--html-first|--blocks-first] [--multi-page] [--pages="Home, Menu, About"] [--writing-direction=ltr|rtl] [--hero-canvas=full-bleed|framed] [--hero-media-modes=foreground-image] [--max-hero-images=1] [--hero-copy-capacity=compact|standard|expanded] [--with-images] [--use-jetpack-placeholders] [--runner=studio|playground] [--port=9400] [--no-serve]
 *
 * --provider=<anthropic|openai|xai|openrouter> picks the model set (config/models.json):
 * each step runs on that provider's large/small tier. Per-step LLM_MODEL_<STEP>
 * env overrides still win. Unset falls back to LLM_PROVIDER / the config default.
 *
 * --html-first / --blocks-first pick the pipeline graph. HTML-first has the model
 * author an HTML+CSS design that transform-site converts to block markup;
 * blocks-first has it author block markup directly. Either flag overrides
 * SITE_BUILD_HTML_FIRST from the shell or .env. With neither, that env var
 * decides, and unset still means blocks-first.
 *
 * The graph is recorded in meta.json, so --from resumes on whatever built the
 * project without being told again. A flag contradicting that record is refused
 * rather than honored: the other graph never wrote the artifacts a resume reads.
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
 * --from=<step-id> is the mirror image: it SKIPS every step that order-precedes
 * that id and resumes from there, reusing the named --slug project's on-disk
 * artifacts (design/*.html, site.css, designDirection.json, meta.json, …) as
 * inputs. It requires --slug (the existing project), ignores the prompt (the
 * design already exists), and leaves the reused directory otherwise untouched.
 * Same id list as --until, group members included, and on a resume that list
 * comes from the graph the project was built on.
 *
 * Deterministic-tail recipe for the default blocks graph — re-run the passes
 * that require NO LLM and NO image generation, in seconds:
 *
 *   php bin/build.php --slug=portfolio-new --from=section-rhythm --until=assemble-pages
 *
 * On an HTML-first project, page-styles is also deterministic, so its longer
 * no-network tail is:
 *
 *   php bin/build.php --html-first --slug=portfolio-new --from=transform-site --until=page-styles
 *
 * Because --until stops before generate-images (and --with-images stays opt-in),
 * both resumes make zero network calls.
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
 * --use-jetpack-placeholders is for hosts that own a real form backend. With
 * it, a section that genuinely needs a form reserves its place with a JP_FORM
 * placeholder block for the host to replace after the build. Without it (the
 * default) the build emits no form markup at all, because a form with nothing
 * behind it silently discards whatever visitors type.
 *
 * After a full build it boots the site in WordPress Playground and prints the
 * URL. --no-serve skips that (build only); --until=... also skips it (the build
 * is incomplete). --port chooses the Playground port.
 */

require_once __DIR__ . '/../src/bootstrap.php';

/**
 * Count pattern manifest entries by their rendered kind.
 *
 * Version 1 manifests have no kind field and contain only sections. Explicit
 * version 2 kinds keep their current meaning; malformed and unknown entries
 * count as neither kind.
 *
 * @param list<mixed> $patternEntries
 * @return array{sections:int,components:int}
 */
function build_pattern_kind_counts(array $patternEntries): array
{
    return [
        'sections' => count(array_filter(
            $patternEntries,
            static fn (mixed $pattern): bool => is_array($pattern)
                && (!array_key_exists('kind', $pattern) || $pattern['kind'] === 'section'),
        )),
        'components' => count(array_filter(
            $patternEntries,
            static fn (mixed $pattern): bool => is_array($pattern)
                && ($pattern['kind'] ?? null) === 'component',
        )),
    ];
}

// Keep the pure counter directly testable without executing the CLI.
$scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
if (!is_string($scriptFilename) || realpath($scriptFilename) !== __FILE__) {
    return;
}

$args = parse_cli_args($argv, [
    '--slug'                     => 'value',
    '--provider'                 => 'value',
    '--until'                    => 'value',
    '--from'                     => 'value',
    '--pages'                    => 'value',
    '--port'                     => 'value',
    '--runner'                   => 'value',
    '--writing-direction'        => 'value',
    '--hero-canvas'              => 'value',
    '--hero-media-modes'         => 'value',
    '--max-hero-images'          => 'value',
    '--hero-copy-capacity'       => 'value',
    '--html-first'               => 'bool',
    '--blocks-first'             => 'bool',
    '--with-images'              => 'bool',
    '--use-jetpack-placeholders' => 'bool',
    '--multi-page'               => 'bool',
    '--serve'                    => 'toggle',
], maxPositionals: 1);
if ($args['unknown'] !== null) {
    Narrator::write("Unknown argument: {$args['unknown']}\n");
    usage();
}
$flags = $args['flags'];
$prompt = $args['positionals'][0] ?? null;
$slug = $flags['--slug'] ?? null;
$until = $flags['--until'] ?? null;
$from = $flags['--from'] ?? null;
$htmlFirst = $flags['--html-first'] ?? false;
$blocksFirst = $flags['--blocks-first'] ?? false;
$withImages = $flags['--with-images'] ?? false;
$formPlaceholders = $flags['--use-jetpack-placeholders'] ?? false;
$multiPage = $flags['--multi-page'] ?? false;
$pagesArg = $flags['--pages'] ?? null;
$port = isset($flags['--port']) ? (int) $flags['--port'] : null;
$serve = $flags['--serve'] ?? true;
$provider = $flags['--provider'] ?? null;
$writingDirection = $flags['--writing-direction'] ?? null;
$heroCanvas = $flags['--hero-canvas'] ?? null;
$heroMediaModesArg = $flags['--hero-media-modes'] ?? null;
$maxHeroImagesArg = $flags['--max-hero-images'] ?? null;
$heroCopyCapacity = $flags['--hero-copy-capacity'] ?? null;

// --from resumes an existing build's deterministic tail against on-disk
// artifacts, so the prompt is optional (the design already exists); every
// other invocation still requires it.
if ($from === null && ($prompt === null || trim($prompt) === '')) {
    usage();
}

// --from resumes a materialized project in place, so it needs the existing
// slug to locate that directory (createProject would otherwise pick a random
// one, and there would be no design/*.html to resume from).
if ($from !== null && ($slug === null || trim($slug) === '')) {
    Narrator::write("--from requires --slug=<existing project> to resume its on-disk artifacts.\n");
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

// --html-first / --blocks-first pick the pipeline graph by setting the env key
// StepComposition::htmlFirstSelected() owns, the same way --provider sets
// LLM_PROVIDER. Setting it unconditionally is what makes the flag beat an
// exported SITE_BUILD_HTML_FIRST or an .env line; with no flag the env decides.
if ($htmlFirst && $blocksFirst) {
    Narrator::write("--html-first and --blocks-first are mutually exclusive; pass one.\n");
    exit(1);
}
if ($htmlFirst || $blocksFirst) {
    putenv(StepComposition::HTML_FIRST_ENV . '=' . ($htmlFirst ? '1' : '0'));
}

$llm = make_llm();
$builder = make_site_builder($llm);

// A resume has to run the graph that built the project, so its record is read
// BEFORE the pipeline is assembled. That also makes --from's "valid steps" list
// come from the right graph instead of whichever one the flags happened to pick.
$project = null;
$meta = [];
if ($from !== null) {
    try {
        $project = $builder->store()->open($slug);
    } catch (RuntimeException $e) {
        Narrator::write("--from: {$e->getMessage()}\n");
        exit(1);
    }
    $meta = $project->exists('meta.json') ? $project->readJson('meta.json') : [];
    $recordedGraph = $meta['graph'] ?? null;
    try {
        $resumeHtmlFirst = StepComposition::resumeHtmlFirst(
            is_string($recordedGraph) ? $recordedGraph : null,
            $htmlFirst || $blocksFirst ? $htmlFirst : null,
        );
    } catch (InvalidArgumentException $e) {
        Narrator::write("--from: {$e->getMessage()}\n");
        exit(1);
    }
    // Null means nothing was recorded to honor, so the flag/env choice stands.
    if ($resumeHtmlFirst !== null) {
        putenv(StepComposition::HTML_FIRST_ENV . '=' . ($resumeHtmlFirst ? '1' : '0'));
    }
}

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

// Validate --from the same way (same id list, group members included), so an
// unknown resume point fails loud instead of silently running everything.
if ($from !== null && !in_array($from, $pipeline->stopIds(), true)) {
    Narrator::write("Unknown --from step '{$from}'. Valid steps:\n  "
        . implode("\n  ", $pipeline->stopIds()) . "\n");
    exit(1);
}

$runnerFlag = isset($flags['--runner']) ? (string) $flags['--runner'] : null;
$runnerFallback = false;
try {
    $runner = RunnerResolver::resolve(
        $runnerFlag,
        new StudioCli(),
        static function (string $message) use (&$runnerFallback): void {
            $runnerFallback = true;
            Narrator::write($message . "\n");
        }
    );
} catch (RuntimeException $e) {
    Narrator::write($e->getMessage() . "\n");
    exit(1);
}

if ($from !== null) {
    // Resume: the project was opened untouched above (no createProject, which
    // would re-seed meta.json and could clobber multi_page/design_constraints
    // from the original build). Its design/*.html, site.css, meta.json etc. are
    // the inputs the deterministic tail reads, so every artifact stays as-is.
    // The prompt argument is ignored on resume; the report reuses the recorded one.
    $prompt = (string) ($meta['prompt'] ?? '');
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
            formPlaceholders: $formPlaceholders,
        );
    } catch (InvalidArgumentException $e) {
        Narrator::write($e->getMessage() . "\n");
        exit(1);
    }
}

echo ($from !== null ? "Resuming '{$project->slug()}' from {$from}\n" : "Building '{$project->slug()}'\n");
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
    }, $from);
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
    foreach (StepComposition::postImages(make_generate_images_step($llm)) as $step) {
        $runExtraStep($step);
    }

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

if ($project->exists('patterns.json')) {
    $patternManifest = $project->readJson('patterns.json');
    $patternEntries = $patternManifest['patterns'] ?? [];
    $patternCounts = build_pattern_kind_counts($patternEntries);
    $report->setPatterns(
        $patternCounts['sections'],
        $patternCounts['components'],
        count($patternManifest['dropped'] ?? []),
    );
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
$stats = $report->stats(default_llm_model(), $models);
$stats['runner'] = $runner->name();
$stats['runner_fallback'] = $runnerFallback;
$project->writeJson('build-stats.json', $stats);

echo "Output: {$project->path()}\n";

// Boot the site in WordPress Playground and print the URL. Skipped when the
// build stopped early (--until) or the user opted out (--no-serve).
if ($serve && $until === null) {
    echo "\nStarting preview…\n";
    // Owned wholly by this run: a resumed build that reaches Studio must not
    // keep the previous run's "fell back to Playground" receipt.
    $project->replaceWarnings('site-runner', []);
    $serveRunner = $runner;
    if ($serveRunner->name() === 'playground') {
        $serveRunner = new PlaygroundRunner($port ?? 9400);
    } elseif ($port !== null) {
        echo "--port applies to Playground only; ignored for Studio.\n";
    }
    try {
        $site = $serveRunner->start($project);
    } catch (RuntimeException $e) {
        // A Studio we chose ourselves is a preference, not a requirement: the
        // build is already paid for, so drop to Playground rather than throw
        // it away (AGENTS.md "fix, degrade, warn"). A runner the caller named still fails hard.
        if ($serveRunner->name() !== 'studio' || RunnerResolver::requestedName($runnerFlag) !== null) {
            Narrator::write($e->getMessage() . "\n");
            exit(1);
        }
        Narrator::write("Studio preview failed: {$e->getMessage()}\n");
        Narrator::write("Falling back to Playground…\n");
        $project->replaceWarnings('site-runner', ['Studio preview failed; fell back to Playground: ' . $e->getMessage()]);
        $serveRunner = new PlaygroundRunner($port ?? 9400);
        $stats['runner'] = $serveRunner->name();
        $stats['runner_fallback'] = true;
        $project->writeJson('build-stats.json', $stats);
        try {
            $site = $serveRunner->start($project);
        } catch (RuntimeException $playgroundFailure) {
            Narrator::write($playgroundFailure->getMessage() . "\n");
            exit(1);
        }
    }
    // Post-build WP checks. Not a pipeline step: wpcom/Linux CI cannot boot
    // Studio. Findings warn and the build still exits 0 (AGENTS.md "fix, degrade, warn").
    if ($serveRunner->name() === 'studio' && $serveRunner instanceof StudioAppRunner) {
        $findings = SiteVerifier::check(new StudioCli(), $serveRunner->siteDir($project->slug()));
        $project->addWarnings('site-verifier', $findings);
    }
    echo "  url:    {$site->url}\n";
    echo "  admin:  {$site->adminUrl}\n";
    if ($site->persistent) {
        echo "  still running — stop it with: php bin/serve.php {$project->slug()} --stop\n";
        exit(0);
    }
    echo "  (first run downloads WordPress; Ctrl-C to stop)\n\n";
    register_shutdown_function($site->stop);
    if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
        pcntl_async_signals(true);
        $halt = static function () use ($site): never {
            ($site->stop)();
            exit(0);
        };
        pcntl_signal(SIGINT, $halt);
        pcntl_signal(SIGTERM, $halt);
    }
    while (true) {
        sleep(1);
    }
}

/** The one invocation summary, shared by every path that rejects the line. */
function usage(): never
{
    Narrator::write("Usage: php bin/build.php \"<prompt>\" [--provider=anthropic|openai|xai|openrouter] [--slug=...] [--from=step-id] [--until=step-id] [--html-first|--blocks-first] [--multi-page] [--pages=\"Home, Menu, About\"] [--writing-direction=ltr|rtl] [--hero-canvas=full-bleed|framed] [--hero-media-modes=cover-image,foreground-image] [--max-hero-images=1..2] [--hero-copy-capacity=compact|standard|expanded] [--with-images] [--use-jetpack-placeholders] [--runner=studio|playground] [--port=9400] [--no-serve]\n");
    exit(1);
}
