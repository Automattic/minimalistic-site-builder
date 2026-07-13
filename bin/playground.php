<?php
declare(strict_types=1);

use Automattic\SiteBuild\PlaygroundArtifact;
use Automattic\SiteBuild\ProjectStore;

/**
 * Start a local WordPress Playground instance with a generated theme activated.
 *
 *   php bin/playground.php <slug> [--port=9400] [--workers=2]
 *
 * Mounts projects/<slug>/theme into wp-content/themes/<slug> and activates it
 * via a Blueprint, then boots Playground (downloads WordPress on first run).
 * Requires Node.js (uses `npx @wp-playground/cli`). Runs in the foreground;
 * Ctrl-C to stop.
 *
 * --workers caps the request-handling worker threads (each one holds its own
 * PHP wasm runtime, so fewer workers = less memory). Accepts a positive
 * integer or "auto" (one per CPU core minus one).
 */

require_once __DIR__ . '/../src/bootstrap.php';

$slug = null;
$port = 9400;
$workers = '2';
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--port=')) {
        $port = (int) substr($a, 7);
    } elseif (str_starts_with($a, '--workers=')) {
        $workers = substr($a, 10);
    } elseif ($slug === null) {
        $slug = $a;
    }
}

if ($workers !== 'auto' && (int) $workers < 1) {
    fwrite(STDERR, "--workers must be a positive integer or \"auto\".\n");
    exit(1);
}

if ($slug === null) {
    fwrite(STDERR, "Usage: php bin/playground.php <slug> [--port=9400] [--workers=2]\n");
    fwrite(STDERR, "Available themes:\n");
    foreach (glob(repo_path('projects/*/theme/style.css')) ?: [] as $f) {
        fwrite(STDERR, '  - ' . basename(dirname(dirname($f))) . "\n");
    }
    exit(1);
}

$slug = ProjectStore::slugify($slug);
$themeDir = repo_path("projects/{$slug}/theme");

if (!is_dir($themeDir) || !is_file($themeDir . '/style.css')) {
    fwrite(STDERR, "No theme found at projects/{$slug}/theme (need style.css).\n");
    fwrite(STDERR, "Build it first: php bin/build.php \"<prompt>\" --slug={$slug}\n");
    exit(1);
}

if (!command_exists('npx')) {
    fwrite(STDERR, "npx (Node.js) is required to run WordPress Playground.\n");
    exit(1);
}

$project = (new ProjectStore(repo_path('projects')))->open($slug);
$name = PlaygroundArtifact::themeDisplayName($project);

// Pick a free port starting from the requested one (avoids EADDRINUSE when a
// previous Playground is still running).
$requestedPort = $port;
$port = find_free_port($port);
if ($port !== $requestedPort) {
    fwrite(STDERR, "Port {$requestedPort} is in use — using {$port} instead.\n");
}

// Blueprint: set the site identity, activate the mounted theme, log in. The
// identity comes from PlaygroundArtifact::siteOptions so this local preview
// matches the published Playground bundles.
$blueprint = [
    '$schema'      => 'https://playground.wordpress.net/blueprint-schema.json',
    'landingPage'  => '/',
    'login'        => true,
    'steps'        => [
        ['step' => 'setSiteOptions', 'options' => PlaygroundArtifact::siteOptions($project)],
        ['step' => 'activateTheme', 'themeFolderName' => $slug],
    ],
];
// The path is unique per boot (this process's pid), not per project: the
// teardowns in bin/screenshot.php and bin/build-demos.php stop the reparented
// node server by pkill-matching this path in its argv, and with a fixed
// per-project name that match takes down every running server of the project —
// a sibling preview included — instead of just the one being stopped. Unique
// names also stop concurrent boots of one project overwriting each other's
// blueprint mid-boot.
$blueprintPath = repo_path("projects/{$slug}/.playground-blueprint." . getmypid() . '.json');
file_put_contents($blueprintPath, json_encode($blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
register_shutdown_function(static fn () => @unlink($blueprintPath));

// Sweep blueprints left by earlier boots that died by signal (a signal skips
// the shutdown unlink above). A stamped file whose pid is gone is stale; the
// unstamped name is the pre-pid-stamp legacy leftover.
foreach (glob(repo_path("projects/{$slug}/.playground-blueprint.*.json")) ?: [] as $stale) {
    $pid = (int) explode('.', basename($stale))[2];
    if ($pid > 0 && $pid !== getmypid() && !process_alive($pid)) {
        @unlink($stale);
    }
}
@unlink(repo_path("projects/{$slug}/.playground-blueprint.json"));

$mount = $themeDir . ':/wordpress/wp-content/themes/' . $slug;

// `server` (not `start`) because it accepts --workers, which caps the
// request-handling worker threads — each one holds a full PHP wasm runtime, so
// the CLI default of min(6, cpus-1) costs several hundred MB we don't need for
// a single-user preview. The CLI warns that fewer than 6 workers raises the
// odds of deadlock on file locks; with our sequential preview/screenshot
// traffic that hasn't been an issue, and 2 keeps a spare worker for loopback
// subrequests. Override with --workers=auto to get the old behaviour.
//
// `server` also boots an ephemeral site (nothing persisted under
// ~/.wordpress-playground/sites), which sidesteps the persisted-mount-point
// problem that used to require `start --reset`: a persisted empty
// wp-content/themes/<slug> folder would shadow the theme --mount on the next
// boot and activation failed with "Stylesheet is missing". No persisted site,
// no shadowing — and no auto-mount either (for `server` it is opt-in), so the
// only mount is the one we pass. Booting from scratch costs no extra time
// versus the old `start --reset`, which wiped the site every run anyway.
$cmd = sprintf(
    'npx --yes @wp-playground/cli@latest server --login --workers=%s --port=%d --mount=%s --blueprint=%s',
    escapeshellarg($workers),
    $port,
    escapeshellarg($mount),
    escapeshellarg($blueprintPath)
);

echo "Starting WordPress Playground for '{$slug}'" . ($name !== '' ? " ({$name})" : '') . "\n";
echo "  theme:  {$themeDir}\n";
echo "  url:    http://127.0.0.1:{$port}/\n";
echo "  admin:  http://127.0.0.1:{$port}/wp-admin/ (auto-logged in)\n";
echo "  (first run downloads WordPress; Ctrl-C to stop)\n\n";

passthru($cmd, $exit);
exit($exit);

function command_exists(string $bin): bool
{
    return trim((string) shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null')) !== '';
}

/** True if a process with this pid exists (signal 0 probes without killing). */
function process_alive(int $pid): bool
{
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }
    @exec('kill -0 ' . $pid . ' 2>/dev/null', $ignored, $code);
    return $code === 0;
}

/** Return the first free TCP port at or after $start (gives up after 50 tries). */
function find_free_port(int $start): int
{
    for ($port = $start; $port < $start + 50; $port++) {
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($conn === false) {
            return $port; // nothing listening — free
        }
        fclose($conn);
    }
    return $start;
}
