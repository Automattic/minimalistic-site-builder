<?php
declare(strict_types=1);

use Automattic\SiteBuild\Env;
use Automattic\SiteBuild\PlaygroundRunner;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\RunnerResolver;
use Automattic\SiteBuild\StudioCli;

/**
 * Boot a built project on the resolved site runner (Studio by default,
 * Playground as failover), capture a full-page screenshot of its home page,
 * and save it under the project's logs/ directory.
 *
 *   php bin/screenshot.php <slug> [--runner=studio|playground] [--port=9400] [--out=<path>] [--timeout=240] [--workers=N] [--keep-alive]
 *
 * Starts the site via the runner, screenshots `/` via the lazy-load-aware
 * Playwright helper, then shuts the site down unless --keep-alive. The
 * image is written to projects/<slug>/logs/home.png by default, serving as
 * visual testing evidence alongside the per-step logs.
 *
 * Options:
 *   --port=<n>     Playground only: port to boot on (default 9400; auto-bumped if busy).
 *   --out=<path>   screenshot destination (default projects/<slug>/logs/home.png).
 *   --timeout=<s>  Playground only: seconds to wait for the server to come up
 *                  (default 240; the first Playground run downloads WordPress,
 *                  which is slow. Studio does not).
 *   --workers=<n>  Playground only: worker threads, forwarded to Playground
 *                  (default 2; each worker holds a PHP wasm runtime).
 *   --keep-alive   after the screenshot, leave the site running so it can be
 *                  inspected in a browser. Ctrl-C to stop (Playground); Studio
 *                  stays up until `php bin/serve.php <slug> --stop`.
 *
 * Requires Node.js, the lockfile-pinned Playground CLI, and a Chrome/Chromium binary. Override
 * the browser with CHROME_BIN; width with SHOT_WIDTH.
 */

require_once __DIR__ . '/../src/bootstrap.php';

$args = parse_cli_args($argv, [
    '--runner'     => 'value',
    '--port'       => 'value',
    '--out'        => 'value',
    '--timeout'    => 'value',
    '--workers'    => 'value',
    '--route'      => 'value',
    '--keep-alive' => 'bool',
], maxPositionals: 1);
if ($args['unknown'] !== null) {
    fwrite(STDERR, "Unknown argument: {$args['unknown']}\n");
    usage();
}
$flags = $args['flags'];
$slug = $args['positionals'][0] ?? null;
$port = (int) ($flags['--port'] ?? 9400);
$out = $flags['--out'] ?? null;
$timeout = (int) ($flags['--timeout'] ?? 240);
$keepAlive = $flags['--keep-alive'] ?? false;
// Normalize the route to a single leading slash so it appends cleanly to the
// site base URL (which already ends in "/"). Default "/" captures home.
$route = '/' . ltrim($flags['--route'] ?? '/', '/');

if ($slug === null) {
    usage();
}

$store = new ProjectStore(repo_path('projects'));
$project = $store->open(ProjectStore::slugify($slug));
$slug = $project->slug();

if (!is_file($project->themePath('style.css'))) {
    fwrite(STDERR, "No built theme at {$project->themePath()} (need style.css). Build it first.\n");
    exit(1);
}

if (!command_exists('node')) {
    fwrite(STDERR, "node is required to capture screenshots.\n");
    exit(1);
}
$chrome = chrome_binary();
if ($chrome === null) {
    fwrite(STDERR, "No Chrome/Chromium binary found (set CHROME_BIN). Cannot screenshot.\n");
    exit(1);
}

$out ??= $project->logPath('home.png');

$runnerFlag = isset($flags['--runner']) ? (string) $flags['--runner'] : null;
$cli = new StudioCli();
try {
    $runner = RunnerResolver::resolve(
        $runnerFlag,
        $cli,
        static function (string $message): void {
            fwrite(STDERR, $message . "\n");
        }
    );
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$workers = $flags['--workers'] ?? '2';
if ($workers !== 'auto' && (int) $workers < 1) {
    fwrite(STDERR, "--workers must be a positive integer or \"auto\".\n");
    exit(1);
}

if ($runner->name() === 'playground') {
    $runner = new PlaygroundRunner($port, (string) $workers);
} elseif (isset($flags['--port']) || isset($flags['--workers']) || isset($flags['--timeout'])) {
    echo "--port, --workers, and --timeout apply to Playground only; ignored for Studio.\n";
}

$site = null;
$exit = 1;
try {
    echo "Booting '{$slug}' via {$runner->name()}…\n";
    try {
        $site = $runner->name() === 'playground'
            ? $runner->start($project, $timeout)
            : $runner->start($project);
    } catch (RuntimeException $studioFailure) {
        // Same rule as serve/build: our own choice of Studio degrades, a
        // caller's --runner=studio does not.
        if ($runner->name() !== 'studio' || RunnerResolver::requestedName($runnerFlag) !== null) {
            throw $studioFailure;
        }
        fwrite(STDERR, "Studio failed: {$studioFailure->getMessage()}\nFalling back to Playground…\n");
        $runner = new PlaygroundRunner($port, (string) $workers);
        $site = $runner->start($project, $timeout);
    }
    $baseUrl = rtrim($site->url, '/') . $route;
    echo "Capturing {$baseUrl} → {$out}\n";
    $shot = 'node ' . escapeshellarg(repo_path('bin/screenshot/screenshot.js'))
        . ' ' . escapeshellarg($baseUrl) . ' ' . escapeshellarg($out)
        . ' ' . escapeshellarg('--chrome=' . $chrome);
    passthru($shot, $exit);
    if ($exit === 0 && is_file($out)) {
        echo "Saved screenshot: {$out}\n";
    } else {
        fwrite(STDERR, "Screenshot failed (exit {$exit}).\n");
    }
    if ($keepAlive) {
        echo "\nKeeping site alive — open {$baseUrl} (Ctrl-C to stop)\n";
        echo "  admin: {$site->adminUrl}\n";
        if ($site->persistent) {
            echo "  still running — stop it with: php bin/serve.php {$slug} --stop\n";
        } else {
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
    }
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    $exit = 1;
} finally {
    if ($site !== null && !$keepAlive) {
        ($site->stop)();
    }
}

exit($exit);

/** The one invocation summary, shared by every path that rejects the line. */
function usage(): never
{
    fwrite(STDERR, "Usage: php bin/screenshot.php <slug> [--runner=studio|playground] [--port=9400] [--out=<path>] [--timeout=240] [--workers=N] [--keep-alive]\n");
    exit(1);
}

/** First working Chrome/Chromium binary (CHROME_BIN wins), or null. */
function chrome_binary(): ?string
{
    // Env::get, not getenv: CHROME_BIN set in .env never reaches the real
    // environment, only the Env map. Absolute paths come before the bare
    // names: each name costs a `command -v` subprocess, a path just a stat.
    $candidates = array_filter([
        Env::get('CHROME_BIN'),
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/Applications/Chromium.app/Contents/MacOS/Chromium',
        'google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser',
    ]);
    foreach ($candidates as $bin) {
        $path = str_contains($bin, '/') ? $bin : command_path($bin);
        if ($path === null || !is_executable($path)) {
            continue;
        }
        // Existing and executable isn't enough: a stale Homebrew cask wrapper
        // execs an app bundle that may no longer exist (exit 126/127). Only
        // trust a binary that actually runs.
        exec(escapeshellarg($path) . ' --version 2>/dev/null', $ignored, $code);
        if ($code === 0) {
            return $path;
        }
    }
    return null;
}
