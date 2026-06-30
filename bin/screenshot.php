<?php
declare(strict_types=1);

/**
 * Boot a built project in WordPress Playground (headless), capture a full-page
 * screenshot of its home page, and save it under the project's logs/ directory.
 *
 *   php bin/screenshot.php <slug> [--port=9400] [--out=<path>] [--timeout=240]
 *
 * Reuses bin/playground.php to start the server (so the same blueprint, theme
 * mount and site options apply), waits until it reports ready, screenshots `/`
 * via bin/screenshot/screenshot.js (Playwright + system Chrome; scrolls the page
 * so lazy-loaded images render), then shuts the server down. The image is written
 * to projects/<slug>/logs/home.png by default, as visual evidence alongside logs.
 *
 * Options:
 *   --port=<n>     port to boot Playground on (default 9400; auto-bumped if busy).
 *   --out=<path>   screenshot destination (default projects/<slug>/logs/home.png).
 *   --timeout=<s>  seconds to wait for the server to come up (default 240; the
 *                  first run downloads WordPress, which is slow).
 *
 * Requires Node.js (npx, for Playground; playwright-core under bin/screenshot)
 * and a Chrome/Chromium binary. Override the browser with CHROME_BIN; width with
 * SHOT_WIDTH (see bin/screenshot/screenshot.js).
 */

require_once __DIR__ . '/../src/bootstrap.php';

$slug = null;
$port = 9400;
$out = null;
$timeout = 240;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--port=')) { $port = (int) substr($a, 7); }
    elseif (str_starts_with($a, '--out=')) { $out = substr($a, 6); }
    elseif (str_starts_with($a, '--timeout=')) { $timeout = (int) substr($a, 10); }
    elseif ($slug === null && !str_starts_with($a, '--')) { $slug = $a; }
    else {
        fwrite(STDERR, "Unknown argument: {$a}\n");
        fwrite(STDERR, "Usage: php bin/screenshot.php <slug> [--port=9400] [--out=<path>] [--timeout=240]\n");
        exit(1);
    }
}

if ($slug === null) {
    fwrite(STDERR, "Usage: php bin/screenshot.php <slug> [--port=9400] [--out=<path>] [--timeout=240]\n");
    exit(1);
}

$store = new ProjectStore(repo_path('projects'));
$project = $store->open(ProjectStore::slugify($slug));
$slug = $project->slug();

if (!is_file($project->themePath('style.css'))) {
    fwrite(STDERR, "No built theme at {$project->themePath()} (need style.css). Build it first.\n");
    exit(1);
}

if (!command_exists('npx')) {
    fwrite(STDERR, "npx (Node.js) is required to run WordPress Playground.\n");
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
// itself, so teardown can walk and kill its npx/node subtree.
@unlink($serverLog);
$cmd = 'exec php ' . escapeshellarg(repo_path('bin/playground.php'))
    . ' ' . escapeshellarg($slug) . ' --port=' . (int) $port;
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
// The npx-spawned node server reparents to init once npx exits, so it escapes a
// parent-walk kill. It carries this unique blueprint path in its argv, so match
// on that to stop exactly this project's server (and nothing else's).
$blueprintPath = repo_path("projects/{$slug}/.playground-blueprint.json");
register_shutdown_function(static function () use ($proc, $wrapperPid, $blueprintPath) {
    teardown_playground($proc, $wrapperPid, $blueprintPath);
});

echo "Booting Playground for '{$slug}' (first run downloads WordPress)…\n";
$deadline = time() + $timeout;
while (time() < $deadline) {
    // Did playground.php die before serving? (bad theme, npx error, …)
    if (!proc_get_status($proc)['running']) {
        fwrite(STDERR, "Playground exited before it was ready. See {$serverLog}\n");
        echo @file_get_contents($serverLog);
        exit(1);
    }
    // Playground prints this exact line once it is actually serving; it carries
    // the real port. (The site's `/` answers 302, not 200, so polling for a 200
    // would never succeed — the log line is the reliable readiness signal.)
    // Strip ANSI colour codes first: the CLI wraps "Ready!" and the URL in
    // colour escapes when stdout is a TTY (and some envs force colour even when
    // it isn't, e.g. FORCE_COLOR). Those escape sequences sit between "Ready!"
    // and the URL and would break a naive \s+ match — so remove them up front.
    $log = is_file($serverLog) ? (string) file_get_contents($serverLog) : '';
    $log = preg_replace('~\x1b\[[0-9;]*m~', '', $log) ?? $log;
    if (preg_match('~Ready!\s+WordPress is running on (http://127\.0\.0\.1:\d+)~', $log, $m)) {
        $baseUrl = $m[1] . '/';
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
// Use the lazy-load-aware capturer (bin/screenshot/screenshot.js): it scrolls the
// whole page to trip every lazy-load trigger and waits for all images to decode
// before shooting, so AI_IMAGE assets far down a tall homepage actually render
// (the old bin/screenshot.mjs grabbed the full height without scrolling, leaving
// lazy images as empty boxes). Width honors SHOT_WIDTH for back-compat.
$width = (int) (getenv('SHOT_WIDTH') ?: 1280);
$shot = 'node ' . escapeshellarg(repo_path('bin/screenshot/screenshot.js'))
    . ' ' . escapeshellarg($baseUrl) . ' ' . escapeshellarg($out)
    . ' --width=' . $width
    . ' --chrome=' . escapeshellarg($chrome);
passthru($shot, $exit);

if ($exit === 0 && is_file($out)) {
    echo "Saved screenshot: {$out}\n";
} else {
    fwrite(STDERR, "Screenshot failed (exit {$exit}).\n");
}

exit($exit);

/** Stop the Playground process tree (the php wrapper, npx, and node server). */
function teardown_playground($proc, int $pid, string $blueprintPath): void
{
    if ($pid > 0) {
        kill_tree($pid);
    }
    // Catch the reparented node server, which the parent-walk above misses.
    @exec('pkill -f ' . escapeshellarg(preg_quote($blueprintPath, '~')) . ' 2>/dev/null');
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
}

/** Recursively SIGTERM a process and all its descendants (leaves first). */
function kill_tree(int $pid): void
{
    $children = [];
    @exec('pgrep -P ' . $pid . ' 2>/dev/null', $children);
    foreach ($children as $child) {
        kill_tree((int) $child);
    }
    @exec('kill -TERM ' . $pid . ' 2>/dev/null');
}

function command_exists(string $bin): bool
{
    return trim((string) shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null')) !== '';
}

/**
 * First available Chrome/Chromium binary as an ABSOLUTE path (CHROME_BIN wins),
 * or null. Playwright's executablePath needs a real path, not a bare command
 * name resolved via PATH — so resolve bare names with `command -v`.
 */
function chrome_binary(): ?string
{
    $candidates = array_filter([
        getenv('CHROME_BIN') ?: null,
        'google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser',
    ]);
    foreach ($candidates as $bin) {
        if (str_contains($bin, '/')) {
            if (is_executable($bin)) {
                return $bin;
            }
            continue;
        }
        $resolved = trim((string) shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null'));
        if ($resolved !== '') {
            return $resolved;
        }
    }
    return null;
}
