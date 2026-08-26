<?php
declare(strict_types=1);

use Automattic\SiteBuild\PlaygroundArtifact;
use Automattic\SiteBuild\PlaygroundRunner;
use Automattic\SiteBuild\ProjectStore;

/**
 * Start a local WordPress Playground instance with a generated theme activated
 * (plus the companion content plugin, when the project has one).
 *
 *   php bin/playground.php <slug> [--port=9400] [--workers=2]
 *
 * Mounts projects/<slug>/theme (and plugin) into wp-content and activates them
 * via a Blueprint, then boots Playground (downloads WordPress on first run).
 * Requires Node.js and the lockfile-pinned local Playground CLI (`npm ci`).
 * Runs in the foreground;
 * Ctrl-C to stop.
 *
 * --workers caps the request-handling worker threads (each one holds its own
 * PHP wasm runtime, so fewer workers = less memory). Accepts a positive
 * integer or "auto" (one per CPU core minus one).
 */

require_once __DIR__ . '/../src/bootstrap.php';

$args = parse_cli_args($argv, [
    '--port'    => 'value',
    '--workers' => 'value',
], maxPositionals: 1);
if ($args['unknown'] !== null) {
    fwrite(STDERR, "Unknown argument: {$args['unknown']}\n");
    usage();
}
$flags = $args['flags'];
$slug = $args['positionals'][0] ?? null;
$port = (int) ($flags['--port'] ?? 9400);
$workers = $flags['--workers'] ?? '2';

if ($workers !== 'auto' && (int) $workers < 1) {
    fwrite(STDERR, "--workers must be a positive integer or \"auto\".\n");
    exit(1);
}

if ($slug === null) {
    usage();
}

$slug = ProjectStore::slugify($slug);
$themeDir = repo_path("projects/{$slug}/theme");

if (!is_dir($themeDir) || !is_file($themeDir . '/style.css')) {
    fwrite(STDERR, "No theme found at projects/{$slug}/theme (need style.css).\n");
    fwrite(STDERR, "Build it first: php bin/build.php \"<prompt>\" --slug={$slug}\n");
    exit(1);
}

if (!command_exists('node')) {
    fwrite(STDERR, "Node.js is required to run WordPress Playground.\n");
    exit(1);
}
$playgroundCli = repo_path('node_modules/.bin/wp-playground-cli');
if (!is_file($playgroundCli)) {
    fwrite(STDERR, "WordPress Playground CLI is not installed. Run `npm ci` at the repository root.\n");
    exit(1);
}

$project = (new ProjectStore(repo_path('projects')))->open($slug);
$runner = new PlaygroundRunner($port, (string) $workers);
try {
    $site = $runner->start($project);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
$name = PlaygroundArtifact::themeDisplayName($project);
echo "Starting WordPress Playground for '{$slug}'" . ($name !== '' ? " ({$name})" : '') . "\n";
echo "  theme:  {$themeDir}\n";
echo "  url:    {$site->url}\n";
echo "  admin:  {$site->adminUrl} (auto-logged in)\n";
echo "  (first run downloads WordPress; Ctrl-C to stop)\n\n";
register_shutdown_function($site->stop);
// SIGTERM/SIGINT skip PHP shutdown handlers unless we catch them. passthru
// used to die with the foreground process group (Ctrl-C killed php AND node).
// start()+sleep does not; a kill of this pid would leak the reparented node
// (see PlaygroundRunner::teardown). Catch the signals, stop(), then exit so
// the shutdown function is a second, idempotent teardown.
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    $halt = static function () use ($site): void {
        ($site->stop)();
        exit(0);
    };
    pcntl_signal(SIGINT, $halt);
    pcntl_signal(SIGTERM, $halt);
}
while (true) { sleep(1); }

/** The one invocation summary, shared by every path that rejects the line. */
function usage(): never
{
    fwrite(STDERR, "Usage: php bin/playground.php <slug> [--port=9400] [--workers=2]\n");
    print_built_projects(STDERR, 'Available themes:');
    exit(1);
}
