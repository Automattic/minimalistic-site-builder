<?php
declare(strict_types=1);

use Automattic\SiteBuild\Env;
use Automattic\SiteBuild\ProjectStore;

/**
 * Boot a built project in WordPress Playground (headless), capture a full-page
 * screenshot of its home page, and save it under the project's logs/ directory.
 *
 *   php bin/screenshot.php <slug> [--port=9400] [--out=<path>] [--timeout=240] [--workers=N] [--keep-alive]
 *
 * Reuses bin/playground.php to start the server (so the same blueprint, theme
 * mount and site options apply), waits until it reports ready, screenshots `/`
 * via the lazy-load-aware Playwright helper, then shuts the server down. The
 * image is written to projects/<slug>/logs/home.png by default, serving as
 * visual testing evidence alongside the per-step logs.
 *
 * Options:
 *   --port=<n>     port to boot Playground on (default 9400; auto-bumped if busy).
 *   --out=<path>   screenshot destination (default projects/<slug>/logs/home.png).
 *   --timeout=<s>  seconds to wait for the server to come up (default 240; the
 *                  first run downloads WordPress, which is slow).
 *   --workers=<n>  Playground worker threads, forwarded to playground.php
 *                  (default 2 there; each worker holds a PHP wasm runtime).
 *   --keep-alive   after the screenshot, leave Playground running in the
 *                  foreground (don't tear it down) so the site can be inspected
 *                  in a browser. Ctrl-C to stop the server.
 *
 * Requires Node.js, the lockfile-pinned Playground CLI, and a Chrome/Chromium binary. Override
 * the browser with CHROME_BIN; width with SHOT_WIDTH.
 */

require_once __DIR__ . '/../src/bootstrap.php';

$args = parse_cli_args($argv, [
    '--port'       => 'value',
    '--out'        => 'value',
    '--timeout'    => 'value',
    '--workers'    => 'value',
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
$workers = $flags['--workers'] ?? null;

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
$serverLog = $project->logPath('playground-screenshot.log');

// Start Playground in the background, routing its output to a log we tail for
// the "Ready!" line (which carries the actual port — playground.php auto-bumps
// if the requested one is busy). `exec` makes proc_open's pid the php process
// itself, so teardown can walk and kill its Playground/node subtree. The shared child
// command also pins PHP_BINARY and sys_temp_dir, keeping our independently
// derived blueprint paths identical.
@unlink($serverLog);
$playgroundArgs = [$slug, '--port=' . (int) $port];
if ($workers !== null) {
    $playgroundArgs[] = '--workers=' . $workers;
}
$cmd = php_child_command(repo_path('bin/playground.php'), $playgroundArgs);
$proc = proc_open(
    $cmd,
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $serverLog, 'w'], 2 => ['file', $serverLog, 'a']],
    $pipes,
    repo_path()
);
if (!is_resource($proc)) {
    fwrite(STDERR, "Could not start Playground.\n");
    exit(1);
}

$baseUrl = null;
$exit = 1;
$wrapperPid = proc_get_status($proc)['pid'] ?? 0;
// Pid-stamped, instance-unique path (see playground_blueprint_path): thanks to
// `exec` above, the wrapper pid IS playground.php's pid, so this is the path
// it minted — and no sibling server's.
$blueprintPath = playground_blueprint_path($slug, $wrapperPid);
register_shutdown_function(static function () use ($proc, $wrapperPid, $blueprintPath) {
    teardown_playground($proc, $wrapperPid, $blueprintPath);
});

echo "Booting Playground for '{$slug}' (first run downloads WordPress)…\n";
$deadline = time() + $timeout;
while (time() < $deadline) {
    // Did playground.php die before serving? (bad theme, CLI error, …)
    if (!proc_get_status($proc)['running']) {
        fwrite(STDERR, "Playground exited before it was ready. See {$serverLog}\n");
        echo @file_get_contents($serverLog);
        exit(1);
    }
    $log = is_file($serverLog) ? (string) file_get_contents($serverLog) : '';
    $baseUrl = playground_ready_url($log);
    if ($baseUrl !== null) {
        break;
    }
    usleep(500_000);
    echo '.';
}
echo "\n";

if ($baseUrl === null) {
    fwrite(STDERR, "Playground was not ready within {$timeout}s. See {$serverLog}\n");
    exit(1);
}

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

// --keep-alive: hold the (already-running) Playground server open in the
// foreground so the freshly built site can be inspected in a browser, instead
// of tearing it down the instant the screenshot lands. The server keeps serving
// for as long as this process lives; block here until the user interrupts with
// Ctrl-C, which fires the registered shutdown handler and stops the server.
if ($keepAlive && is_resource($proc) && $baseUrl !== null) {
    echo "\nKeeping Playground alive — open {$baseUrl} (Ctrl-C to stop)\n";
    echo "  admin: {$baseUrl}wp-admin/ (auto-logged in)\n";
    while (proc_get_status($proc)['running'] ?? false) {
        sleep(1);
    }
}

exit($exit);

/** The one invocation summary, shared by every path that rejects the line. */
function usage(): never
{
    fwrite(STDERR, "Usage: php bin/screenshot.php <slug> [--port=9400] [--out=<path>] [--timeout=240] [--workers=N] [--keep-alive]\n");
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
