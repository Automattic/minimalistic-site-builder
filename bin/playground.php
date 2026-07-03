<?php
declare(strict_types=1);

/**
 * Start a local WordPress Playground instance with a generated theme activated.
 *
 *   php bin/playground.php <slug> [--port=9400]
 *
 * Mounts projects/<slug>/theme into wp-content/themes/<slug> and activates it
 * via a Blueprint, then boots Playground (downloads WordPress on first run).
 * Requires Node.js (uses `npx @wp-playground/cli`). Runs in the foreground;
 * Ctrl-C to stop.
 */

require_once __DIR__ . '/../src/bootstrap.php';

$slug = null;
$port = 9400;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--port=')) {
        $port = (int) substr($a, 7);
    } elseif ($slug === null) {
        $slug = $a;
    }
}

if ($slug === null) {
    fwrite(STDERR, "Usage: php bin/playground.php <slug> [--port=9400]\n");
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
$blueprintPath = repo_path("projects/{$slug}/.playground-blueprint.json");
file_put_contents($blueprintPath, json_encode($blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$mount = $themeDir . ':/wordpress/wp-content/themes/' . $slug;

// `start` is the recommended (and fast) Playground CLI command; --skip-browser
// keeps it headless-friendly (it opens a browser tab by default). --no-auto-mount
// disables auto-detecting a project from the cwd (the repo root, which is not a
// WP project) — we specify exactly what to mount with --mount, and the CLI's own
// help recommends pairing --mount with --no-auto-mount for deterministic mounts.
//
// --reset wipes the persisted site directory before booting. Without it the theme
// silently fails to activate with "Stylesheet is missing": Playground persists the
// site under ~/.wordpress-playground/sites/<sha256(cwd)> and writes the *mount
// point* wp-content/themes/<slug> to disk as an empty folder (the bind-mounted
// theme content itself is never persisted). On the next boot that persisted empty
// folder shadows our theme --mount, so WordPress sees a theme dir with no
// style.css. Resetting guarantees the mounted theme is the only thing at that path.
$cmd = sprintf(
    'npx --yes @wp-playground/cli@latest start --skip-browser --no-auto-mount --reset --port=%d --mount=%s --blueprint=%s',
    $port,
    escapeshellarg($mount),
    escapeshellarg($blueprintPath)
);

// The site id is sha256(cwd) when --no-auto-mount is set, so launching every
// project from the repo root funnels them all into ONE shared persisted site
// (whose themes/ then accumulates a stale empty folder per project, and whose
// --reset would clobber another project's still-running --keep-alive server).
// Launch from the project directory instead: each project gets its own site id,
// so --reset only ever touches this project's own site. Paths above are absolute,
// so the cwd change is safe.
chdir(repo_path("projects/{$slug}"));

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
