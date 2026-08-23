<?php
declare(strict_types=1);

use Automattic\SiteBuild\PlaygroundArtifact;
use Automattic\SiteBuild\PlaygroundRunner;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\RunnerResolver;
use Automattic\SiteBuild\StudioAppRunner;
use Automattic\SiteBuild\StudioCli;
use Automattic\SiteBuild\StudioSiteGuard;

/**
 * Start a generated theme on the resolved site runner (Studio by default,
 * Playground as failover).
 *
 *   php bin/serve.php <slug> [--runner=studio|playground] [--port=9400] [--workers=2]
 *   php bin/serve.php <slug> --stop
 *   php bin/serve.php --stop-all
 *   php bin/serve.php --prune
 *
 * --port and --workers apply to Playground only; on Studio one note is printed.
 * On a persistent (Studio) runner this prints url + admin url + the stop
 * command and returns. On Playground it blocks until Ctrl-C.
 */

require_once __DIR__ . '/../src/bootstrap.php';

$args = parse_cli_args($argv, [
    '--runner'   => 'value',
    '--stop'     => 'bool',
    '--stop-all' => 'bool',
    '--prune'    => 'bool',
    '--port'     => 'value',
    '--workers'  => 'value',
], maxPositionals: 1);
if ($args['unknown'] !== null) {
    fwrite(STDERR, "Unknown argument: {$args['unknown']}\n");
    usage();
}
$flags = $args['flags'];
$slug  = $args['positionals'][0] ?? null;

$workers = $flags['--workers'] ?? '2';
if ($workers !== 'auto' && (int) $workers < 1) {
    fwrite(STDERR, "--workers must be a positive integer or \"auto\".\n");
    exit(1);
}

if (!empty($flags['--prune'])) {
    $result = studio_app_runner()->pruneSites();
    echo "Pruned {$result['removed']} site(s), {$result['bytes']} bytes.\n";
    exit(0);
}

if (!empty($flags['--stop-all'])) {
    stop_all_ours();
    exit(0);
}

if ($slug === null) {
    usage();
}

$slug = ProjectStore::slugify($slug);

if (!empty($flags['--stop'])) {
    studio_app_runner()->stopSite($slug);
    exit(0);
}

$themeDir = repo_path("projects/{$slug}/theme");
if (!is_dir($themeDir) || !is_file($themeDir . '/style.css')) {
    fwrite(STDERR, "No theme found at projects/{$slug}/theme (need style.css).\n");
    fwrite(STDERR, "Build it first: php bin/build.php \"<prompt>\" --slug={$slug}\n");
    exit(1);
}

$port = (int) ($flags['--port'] ?? 9400);
$cli  = new StudioCli();
try {
    $runner = RunnerResolver::resolve(
        isset($flags['--runner']) ? (string) $flags['--runner'] : null,
        $cli,
        static function (string $message): void {
            fwrite(STDERR, $message . "\n");
        }
    );
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

if ($runner->name() === 'playground') {
    $runner = new PlaygroundRunner($port, (string) $workers);
} elseif (isset($flags['--port']) || isset($flags['--workers'])) {
    echo "--port and --workers apply to Playground only; ignored for Studio.\n";
}

$project = (new ProjectStore(repo_path('projects')))->open($slug);
try {
    $site = $runner->start($project);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$name = PlaygroundArtifact::themeDisplayName($project);
echo "Starting '{$slug}'" . ($name !== '' ? " ({$name})" : '') . " via {$runner->name()}\n";
echo "  theme:  {$themeDir}\n";
echo "  url:    {$site->url}\n";
echo "  admin:  {$site->adminUrl}\n";

if ($site->persistent) {
    echo "  still running — stop it with: php bin/serve.php {$slug} --stop\n";
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

function studio_app_runner(): StudioAppRunner
{
    return new StudioAppRunner(new StudioCli(), StudioAppRunner::defaultRoot(), repo_path());
}

/** Stop every site-builder site under the Studio root that belongs to this checkout. */
function stop_all_ours(): void
{
    $root   = StudioAppRunner::defaultRoot();
    $repo   = repo_path();
    $runner = studio_app_runner();
    if (!is_dir($root)) {
        return;
    }
    foreach (scandir($root) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $dir = $root . '/' . $name;
        if (StudioSiteGuard::decide($dir, $name) !== 'recreate') {
            continue;
        }
        $marker = json_decode((string) file_get_contents($dir . '/' . StudioSiteGuard::MARKER), true);
        if (!is_array($marker) || ($marker['repo'] ?? null) !== $repo) {
            continue;
        }
        $runner->stopSite($name);
    }
}

/** The one invocation summary, shared by every path that rejects the line. */
function usage(): never
{
    fwrite(STDERR, "Usage: php bin/serve.php <slug> [--runner=studio|playground] [--port=9400] [--workers=2]\n");
    fwrite(STDERR, "       php bin/serve.php <slug> --stop\n");
    fwrite(STDERR, "       php bin/serve.php --stop-all\n");
    fwrite(STDERR, "       php bin/serve.php --prune\n");
    print_built_projects(STDERR, 'Available themes:');
    exit(1);
}
