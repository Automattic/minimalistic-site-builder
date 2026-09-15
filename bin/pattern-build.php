<?php
/**
 * Run the pattern composition locally, from files, with no service calls.
 *
 * The composition produces content for a theme the host already has. Most of
 * its stages are still being extracted, so the useful mode today is to supply
 * their artifacts from a fixture directory and run the stages that exist —
 * which is also how a regression check will work once they all land, because a
 * fixture run makes the same choices every time and a live one does not.
 *
 * Usage:
 *   php bin/pattern-build.php --fixtures=tests/fixtures/patterns/conference-hub
 *                             [--slug=name] [--from=stage] [--until=stage]
 *                             [--responses=<file>] [--record=<file>]
 *
 * Everything under the fixture directory is copied into the project as-is, so
 * a fixture may supply as few or as many stages' outputs as it has. With
 * --responses the model is a recording and nothing leaves the machine; with
 * --record a live run writes the recording a later --responses can replay.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/bootstrap.php';

use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Patterns\FixtureLoader;
use Automattic\SiteBuild\Patterns\PatternArtifacts;
use Automattic\SiteBuild\Patterns\RecordingLlm;
use Automattic\SiteBuild\Patterns\ReplayLlm;
use Automattic\SiteBuild\Pipeline;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepComposition;

$flags = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m)) {
        $flags[$m[1]] = $m[2];
    }
}

$fixtures = $flags['fixtures'] ?? null;
if ($fixtures === null || !is_dir($fixtures)) {
    fwrite(STDERR, "--fixtures=<dir> is required and must exist\n");
    exit(1);
}

// A recording answers every model call, so a replay never needs a key; a
// live run resolves its transport exactly as bin/build.php does, and can be
// recorded on the way.
$responses = $flags['responses'] ?? null;
$record = $flags['record'] ?? null;
if ($responses !== null && $record !== null) {
    fwrite(STDERR, "--responses and --record are exclusive: one replays a recording, the other makes one\n");
    exit(1);
}
if ($responses !== null) {
    $recording = is_file($responses) ? json_decode((string) file_get_contents($responses), true) : null;
    if (!is_array($recording)) {
        fwrite(STDERR, "--responses: {$responses} is not a JSON recording\n");
        exit(1);
    }
    $llm = new ReplayLlm($recording);
} else {
    // The same resolution bin/build.php uses: a coding-agent harness when
    // one is available, the metered API otherwise, SITE_BUILD_LLM to choose.
    $llm = resolve_llm();
}
$recorder = $record !== null ? new RecordingLlm($llm) : null;

$composition = StepComposition::patterns(
    $recorder ?? $llm,
    new PromptRenderer(__DIR__ . '/../prompts'),
    step_models(),
);
$pipeline = new Pipeline($composition->steps(), $composition->seeds());

// Pipeline skips every step when it never matches $fromId and returns
// normally, so an unknown stage would look like a build that ran and produced
// nothing. Reject it here instead.
$from = $flags['from'] ?? null;
$until = $flags['until'] ?? null;
foreach (['from' => $from, 'until' => $until] as $flag => $id) {
    if ($id !== null && !in_array($id, $pipeline->stopIds(), true)) {
        fwrite(STDERR, "--{$flag}: unknown stage '{$id}'. Stages: " . implode(', ', $pipeline->stopIds()) . "\n");
        exit(1);
    }
}

$store = new ProjectStore(__DIR__ . '/../projects');
$slug = $flags['slug'] ?? 'patterns-' . bin2hex(random_bytes(3));
$project = $store->create($slug);

FixtureLoader::load($fixtures, $project);
$project->writeJson('meta.json', [
    'prompt' => 'pattern composition (fixture run)',
    'provisional_slug' => $slug,
    'created_at' => gmdate('c'),
    'fixtures' => realpath($fixtures),
]);

Narrator::write("project:  {$project->slug()}\n");
Narrator::write('fixtures: ' . $fixtures . "\n\n");

$started = microtime(true);
try {
    $pipeline->runThrough(
        $project,
        $until,
        static function (Step $step, float $secs): void {
            Narrator::write(sprintf("  %-22s %6.2fs\n", $step->id(), $secs));
        },
        null,
        $from,
    );
} catch (Throwable $e) {
    Narrator::write("\n" . $e->getMessage() . "\n");
    report($project);
    exit(1);
}

Narrator::write(sprintf("\nran in %.2fs\n", microtime(true) - $started));
if ($recorder !== null) {
    file_put_contents($record, json_encode($recorder->recording(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    Narrator::write("recorded: {$record}\n");
}
report($project);

function report(Project $project): void
{
    if (!$project->exists(PatternArtifacts::REPORT)) {
        return;
    }

    $report = $project->readJson(PatternArtifacts::REPORT);
    Narrator::write(sprintf(
        "report:   %d page(s) (%d composed, %d supplied%s), %d image(s), %s\n",
        $report['pages'] ?? 0,
        $report['composed'] ?? 0,
        $report['supplied'] ?? 0,
        ($report['frozen'] ?? []) === [] ? '' : ', frozen: ' . implode(' ', $report['frozen']),
        $report['media'] ?? 0,
        ($report['passed'] ?? false) ? 'checks passed' : 'checks FAILED',
    ));

    if ($project->exists(PatternArtifacts::BUNDLE)) {
        Narrator::write('bundle:   ' . $project->path(PatternArtifacts::BUNDLE) . "\n");
    }
}

