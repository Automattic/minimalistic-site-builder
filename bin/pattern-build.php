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
 *
 * Everything under the fixture directory is copied into the project as-is, so
 * a fixture may supply as few or as many stages' outputs as it has.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/bootstrap.php';

use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Patterns\PatternArtifacts;
use Automattic\SiteBuild\Pipeline;
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

$composition = StepComposition::patterns();
$pipeline = new Pipeline($composition->steps(), $composition->seeds());

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

copy_fixture_tree($fixtures, $project->path());
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
report($project);

function report(Project $project): void
{
    if (!$project->exists(PatternArtifacts::REPORT)) {
        return;
    }

    $report = $project->readJson(PatternArtifacts::REPORT);
    Narrator::write(sprintf(
        "report:   %d page(s), %d part(s), %d image(s), %s\n",
        $report['pages'] ?? 0,
        $report['parts'] ?? 0,
        $report['media'] ?? 0,
        ($report['passed'] ?? false) ? 'checks passed' : 'checks FAILED',
    ));

    if ($project->exists(PatternArtifacts::BUNDLE)) {
        Narrator::write('bundle:   ' . $project->path(PatternArtifacts::BUNDLE) . "\n");
    }
}

/** Copy a fixture tree into the project, preserving its layout. */
function copy_fixture_tree(string $from, string $to): void
{
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($entries as $entry) {
        $target = $to . '/' . substr($entry->getPathname(), strlen($from) + 1);
        if ($entry->isDir()) {
            @mkdir($target, 0o777, true);
            continue;
        }
        @mkdir(dirname($target), 0o777, true);
        copy($entry->getPathname(), $target);
    }
}
