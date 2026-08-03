<?php
declare(strict_types=1);

use Automattic\SiteBuild\PlaygroundArtifact;
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
$name = PlaygroundArtifact::themeDisplayName($project);

// Pick a free port starting from the requested one (avoids EADDRINUSE when a
// previous Playground is still running).
$requestedPort = $port;
$port = find_free_port($port);
if ($port !== $requestedPort) {
    fwrite(STDERR, "Port {$requestedPort} is in use — using {$port} instead.\n");
}

// Blueprint: set the site identity, activate the mounted theme (and the
// mounted content plugin, which seeds the pages), log in. The identity comes
// from PlaygroundArtifact::siteOptions so this local preview matches the
// published Playground bundles.
$steps = [
    // Neutralize outbound HTTP — a blocked fetch (e.g. wp:embed's oEmbed
    // discovery) would pin a wasm worker forever. See offlineGuardStep().
    PlaygroundArtifact::offlineGuardStep(),
    ['step' => 'setSiteOptions', 'options' => PlaygroundArtifact::siteOptions($project)],
    ['step' => 'activateTheme', 'themeFolderName' => $slug],
];
$pluginDir = repo_path("projects/{$slug}/plugin");
$hasPlugin = is_file($pluginDir . '/site-content.php');
if ($hasPlugin) {
    // AFTER the theme: the seeder resolves asset URLs against the active
    // stylesheet when it creates the pages.
    $steps[] = ['step' => 'activatePlugin', 'pluginPath' => "{$slug}-content/site-content.php"];
}
$blueprint = [
    '$schema'      => 'https://playground.wordpress.net/blueprint-schema.json',
    'landingPage'  => '/',
    'login'        => true,
    'steps'        => $steps,
];
// Pid-stamped, instance-unique path — the why lives on the helper.
$blueprintPath = playground_blueprint_path($slug, getmypid());
if (file_put_contents($blueprintPath, json_encode($blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
    fwrite(STDERR, "Failed to write blueprint to {$blueprintPath}\n");
    exit(1);
}
register_shutdown_function(static fn () => @unlink($blueprintPath));

$mount = $themeDir . ':/wordpress/wp-content/themes/' . $slug;
$pluginMount = $hasPlugin ? $pluginDir . ':/wordpress/wp-content/plugins/' . $slug . '-content' : null;

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
    '%s server --login --workers=%s --port=%d --mount=%s%s --blueprint=%s',
    escapeshellarg($playgroundCli),
    escapeshellarg($workers),
    $port,
    escapeshellarg($mount),
    $pluginMount !== null ? ' --mount=' . escapeshellarg($pluginMount) : '',
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

/** Return the first free TCP port at or after $start (fails after 50 tries). */
function find_free_port(int $start): int
{
    for ($port = $start; $port < $start + 50; $port++) {
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($conn === false) {
            return $port; // nothing listening — free
        }
        fclose($conn);
    }
    fwrite(STDERR, sprintf("No free TCP port in %d..%d.\n", $start, $start + 49));
    exit(1);
}
