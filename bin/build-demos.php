<?php
declare(strict_types=1);

use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\RunnerResolver;
use Automattic\SiteBuild\SiteRunner;
use Automattic\SiteBuild\StudioAppRunner;
use Automattic\SiteBuild\StudioCli;

/**
 * Build every demo website listed in eval/theme-prompts.json in one command.
 *
 *   php bin/build-demos.php [--with-images] [--html-first|--blocks-first] [--provider=<name>] [--no-screenshot] [--only=<slug>] [--parallel=<n>] [--serve] [--stop] [--port=<n>] [--file=<path>]
 *
 * Each entry in the prompts file becomes a project under projects/. If a folder
 * with that entry's slug already exists, a fresh sibling is created by appending
 * an incrementing number: photo-journalism-portfolio → photo-journalism-portfolio2
 * → -3 … so re-running the command never overwrites prior testing evidence.
 *
 * An entry may carry a `site_spec` object in the package-canonical shape
 * (examples/site-spec.json). It is pre-seeded into the project's meta.json, so
 * the site-spec step normalizes it deterministically instead of making an LLM
 * call — a reproducible probe of the host-supplied-spec path (BIGR-754). Page
 * scope still follows the runner flags: without --multi-page the spec's page
 * tree is cut down to the homepage like every other demo build.
 *
 * Builds run CONCURRENTLY, one `bin/build.php` child process per entry. Child
 * processes keep the per-step timing/token accounting clean: every child owns
 * its LLM client, so each demo's logs/project.log carries exactly its own
 * numbers, and one demo failing never aborts the others. Slugs are reserved in
 * this parent before spawning (concurrent children calling freeSlug() would
 * race to the same folder name). Each child's output is streamed here with a
 * [slug] prefix.
 *
 * Note: each build normally fires up to ~10 concurrent LLM requests (the
 * OpenRouter transport caps its own fan-out at 4), so outer parallelism still
 * multiplies API concurrency. If rate limits bite, use --parallel=<n>.
 *
 * After the builds, each home page is captured to a full-page screenshot at
 * projects/<slug>/logs/home.png (headless Playground + Chrome) as visual
 * testing evidence — also in parallel, each site on its own port. Disable with
 * --no-screenshot.
 *
 * Options:
 *   --multi-page    let each site plan inner pages beyond the homepage.
 *                   Off by default: builds produce ONLY the landing page.
 *   --pages="…"     (requires --multi-page) fix every site's page list —
 *                   comma-separated titles, the FIRST one is the homepage —
 *                   instead of letting the LLM invent one per site;
 *                   forwarded verbatim to each child build.
 *   --with-images   also generate the AI_IMAGE placeholders into real assets.
 *   --only=<slug>   build only the entry whose slug matches.
 *   --provider=<p>  use one configured model provider for every demo.
 *   --parallel=<n>  cap on concurrent builds (default: all entries at once;
 *                   OpenRouter is bounded at 3).
 *   --screenshot    capture the post-build home-page screenshots (the default).
 *   --no-screenshot skip the post-build home-page screenshots.
 *   --serve         after the batch, serve ALL built sites. Studio sites are
 *                   daemons: URLs print and the command returns. Playground
 *                   still blocks until Ctrl-C. Off by default.
 *   --no-serve      build only, don't boot any previews (the default).
 *   --stop          stop every Studio site built from the current --file.
 *                   Does not call `studio stop --all` (that would also stop
 *                   hand-made sites).
 *   --port=<n>      base Playground port (default 9400); site i gets the port
 *                   window base+50i, and playground.php auto-bumps busy ports.
 *   --file=<path>   override the prompts file (default eval/theme-prompts.json).
 */

require_once __DIR__ . '/../src/bootstrap.php';

// Playground's find_free_port() probes ports without reserving them and scans
// up to 50 past the requested one, so concurrent children whose scan windows
// overlap can converge on the SAME free port and race to bind it (the losers
// die with EADDRINUSE). Spacing the per-site base ports by that same scan
// range keeps the windows disjoint, so no two children can collide.
const PORT_STRIDE = 50;

// File-scope functions below stay registered when this file is required as a
// library (W3 / tests). Skip the CLI body unless we are the entry script.
$siteBuildDemosIsMain = isset($argv[0]) && is_string($argv[0])
    && ($argv0 = realpath($argv[0])) !== false
    && $argv0 === realpath(__FILE__);

if ($siteBuildDemosIsMain) {

$args = parse_cli_args($argv, [
    '--with-images'  => 'bool',
    '--html-first'   => 'bool',
    '--blocks-first' => 'bool',
    '--multi-page'   => 'bool',
    '--pages'        => 'value',
    '--only'         => 'value',
    '--provider'     => 'value',
    '--parallel'     => 'value',
    '--port'         => 'value',
    '--file'         => 'value',
    '--serve'        => 'toggle',
    '--screenshot'   => 'toggle',
    '--stop'         => 'bool',
]);
if ($args['unknown'] !== null) {
    fwrite(STDERR, "Unknown argument: {$args['unknown']}\n");
    fwrite(STDERR, "Usage: php bin/build-demos.php [--html-first|--blocks-first] [--multi-page] [--pages=\"Home, Menu, About\"] [--with-images] [--only=<slug>] [--provider=anthropic|openai|xai|openrouter] [--parallel=<n>] [--no-screenshot] [--serve] [--stop] [--port=9400] [--no-serve] [--file=<path>]\n");
    exit(1);
}
$flags = $args['flags'];
$withImages = $flags['--with-images'] ?? false;
$htmlFirst = $flags['--html-first'] ?? false;
$blocksFirst = $flags['--blocks-first'] ?? false;
$multiPage = $flags['--multi-page'] ?? false;
$pagesArg = $flags['--pages'] ?? null;
$only = $flags['--only'] ?? null;
$serve = $flags['--serve'] ?? false;
$screenshot = $flags['--screenshot'] ?? true;
// 0 = provider-aware default (all entries; OpenRouter <= 3)
$parallel = isset($flags['--parallel']) ? max(1, (int) $flags['--parallel']) : 0;
$port = (int) ($flags['--port'] ?? 9400);
$provider = $flags['--provider'] ?? null;
$file = $flags['--file'] ?? repo_path('eval/theme-prompts.json');

if (!empty($flags['--stop'])) {
    stop_demo_sites($file);
    exit(0);
}

if ($htmlFirst && $blocksFirst) {
    Narrator::write("--html-first and --blocks-first are mutually exclusive; pass one.\n");
    exit(1);
}

// Both flags are forwarded to the children, so check them once here rather than
// let every child build fail with the same message. The page list goes over
// verbatim; only --provider's normalized form is kept, for the child commands.
try {
    require_multi_page_for_pages($pagesArg, $multiPage);
    $provider = normalize_provider($provider);
} catch (InvalidArgumentException $e) {
    Narrator::write($e->getMessage() . "\n");
    exit(1);
}

$data = json_decode((string) file_get_contents($file), true);
if (!is_array($data) || !isset($data['prompts']) || !is_array($data['prompts'])) {
    fwrite(STDERR, "Could not read demo prompts from {$file}\n");
    exit(1);
}

// Filter to a single entry when --only is given (matches by slug or id).
$entries = array_values(array_filter(
    $data['prompts'],
    static fn (array $p) => $only === null
        || ($p['slug'] ?? null) === $only
        || ($p['id'] ?? null) === $only,
));
if ($only !== null && $entries === []) {
    fwrite(STDERR, "No prompt with slug/id '{$only}' in {$file}\n");
    exit(1);
}

$store = new ProjectStore(repo_path('projects'));

// Reserve a project folder per entry BEFORE spawning any children: freeSlug()
// picks names by looking at existing folders, so concurrent children would race
// to the same one. create() makes the directory immediately, which is what
// makes the next freeSlug() call see it as taken.
$failures = 0;
$jobs = [];
foreach ($entries as $i => $entry) {
    $prompt = trim((string) ($entry['prompt'] ?? ''));
    if ($prompt === '') {
        $label = (string) ($entry['id'] ?? $entry['slug'] ?? '#' . ($i + 1));
        fwrite(STDERR, "  ✗ SKIPPED: prompt entry '{$label}' has no prompt text\n");
        $failures++;
        continue;
    }
    // An entry may carry a canonical site spec (see examples/site-spec.json):
    // seeded below as meta.json `site_spec`, it makes the site-spec step
    // normalize it deterministically instead of generating one via LLM —
    // testing the host-supplied-spec path with a fixed, reproducible input.
    $siteSpec = $entry['site_spec'] ?? null;
    if ($siteSpec !== null && !is_array($siteSpec)) {
        $label = (string) ($entry['id'] ?? $entry['slug'] ?? '#' . ($i + 1));
        fwrite(STDERR, "  ✗ SKIPPED: prompt entry '{$label}' has a non-object site_spec\n");
        $failures++;
        continue;
    }
    $baseSlug = ProjectStore::slugify((string) ($entry['slug'] ?? $prompt));
    $slug = $store->freeSlug($baseSlug);

    $project = $store->create($slug);
    $project->writeJson('meta.json', array_merge([
        'prompt'           => $prompt,
        'provisional_slug' => $project->slug(),
        'created_at'       => gmdate('c'),
        // Absolute path so a built project stays traceable to its source prompt
        // file regardless of where the command was invoked from. bin/build.php
        // merges its own meta over this seed, preserving the extra fields.
        'demo_source'      => realpath($file) ?: $file,
        'demo_id'          => $entry['id'] ?? $baseSlug,
    ], $siteSpec !== null ? ['site_spec' => $siteSpec] : []));

    echo '[' . ($i + 1) . '/' . count($entries) . "] queued '{$project->slug()}'\n";
    if ($slug !== $baseSlug) {
        echo "  (folder '{$baseSlug}' existed → used '{$slug}')\n";
    }
    echo "  prompt: {$prompt}\n";
    if ($siteSpec !== null) {
        echo "  site spec: supplied by the entry — the site-spec step will make no LLM call\n";
    }

    $jobs[] = [
        'slug' => $project->slug(),
        'path' => $project->path(),
        'cmd'  => 'exec php ' . escapeshellarg(repo_path('bin/build.php'))
            . ' ' . escapeshellarg($prompt)
            . ' --slug=' . escapeshellarg($project->slug())
            . ' --no-serve'
            . ($provider !== null ? ' --provider=' . escapeshellarg($provider) : '')
            . ($withImages ? ' --with-images' : '')
            . ($htmlFirst ? ' --html-first' : '')
            . ($blocksFirst ? ' --blocks-first' : '')
            . ($multiPage ? ' --multi-page' : '')
            . ($pagesArg !== null ? ' --pages=' . escapeshellarg($pagesArg) : ''),
    ];
}

if ($jobs === []) {
    fwrite(STDERR, "Nothing to build.\n");
    exit(1);
}

$activeProvider = $provider ?? \Automattic\SiteBuild\StepDefaults::provider();
$cap = $parallel > 0
    ? $parallel
    : ($activeProvider === 'openrouter' ? min(3, count($jobs)) : count($jobs));
echo "\nBuilding " . count($jobs) . ' demo(s), up to ' . $cap . " in parallel…\n\n";

$results = run_jobs($jobs, $cap);

$built = [];
foreach ($jobs as $i => $job) {
    $r = $results[$i];
    if ($r['exit'] !== 0) {
        fwrite(STDERR, "  ✗ FAILED: {$job['slug']} (exit {$r['exit']}) — see {$job['path']}/logs/\n");
        $failures++;
        continue;
    }
    $built[] = [
        'slug'       => $job['slug'],
        'path'       => $job['path'],
        'seconds'    => round($r['secs'], 1),
        'screenshot' => null,
    ];
}

// Capture a full-page screenshot of each home page as visual testing evidence
// (projects/<slug>/logs/home.png). Each child boots its site headless in
// Playground on its own port, shoots, tears down — so they run in parallel too.
// A failure here doesn't fail the batch — the theme is the artefact; the
// screenshot is a bonus record.
if ($screenshot && $built !== []) {
    echo "\nCapturing " . count($built) . " screenshot(s)…\n\n";
    $shotJobs = [];
    foreach ($built as $i => $b) {
        $shotJobs[] = [
            'slug' => $b['slug'],
            'cmd'  => 'exec php ' . escapeshellarg(repo_path('bin/screenshot.php'))
                . ' ' . escapeshellarg($b['slug'])
                . ' --port=' . ($port + $i * PORT_STRIDE)
                . ' --out=' . escapeshellarg($b['path'] . '/logs/home.png'),
        ];
    }
    // The provider-aware OpenRouter cap protects LLM generation only. Keep
    // independent screenshots parallel unless the caller explicitly supplied
    // --parallel to cap every batch in this command.
    $shotCap = $parallel > 0 ? $parallel : count($shotJobs);
    $shotResults = run_jobs($shotJobs, $shotCap);
    foreach ($built as $i => &$b) {
        $shot = $b['path'] . '/logs/home.png';
        if ($shotResults[$i]['exit'] === 0 && is_file($shot)) {
            $b['screenshot'] = $shot;
        } else {
            fwrite(STDERR, "  ({$b['slug']}: screenshot failed — continuing)\n");
        }
    }
    unset($b);
}

// Summary — the at-a-glance record of what this command produced, suitable as
// the index of testing-evidence artefacts from this run.
echo "\n── built " . count($built) . '/' . count($entries) . " sites";
if ($failures > 0) { echo " ({$failures} failed)"; }
echo " ─────────────────────────────────────\n";
foreach ($built as $b) {
    printf("  %-32s %6.1fs  %s\n", $b['slug'], $b['seconds'], $b['path']);
    if (($b['screenshot'] ?? null) !== null) {
        echo "      ↳ screenshot: {$b['screenshot']}\n";
    }
}

$exitCode = $failures > 0 ? 1 : 0;

// Serve all built sites. Studio: start each, print URLs, return (daemons).
// Playground: today's concurrent spawn + Ctrl-C teardown.
if ($serve && $built !== []) {
    serve_all(array_column($built, 'slug'), $port, $exitCode);
}

exit($exitCode);

}

/**
 * Run each job's shell command as a child process, at most $cap at a time,
 * streaming stdout/stderr line-by-line with a [slug] prefix so interleaved
 * output from concurrent children stays attributable. Returns, per job index:
 * ['exit' => int, 'secs' => float wall-clock].
 *
 * @param list<array{slug: string, cmd: string}> $jobs
 * @return array<int, array{exit: int, secs: float}>
 */
function run_jobs(array $jobs, int $cap): array
{
    $pending = array_keys($jobs);
    $running = [];
    $results = [];

    while ($pending !== [] || $running !== []) {
        while (count($running) < $cap && $pending !== []) {
            $idx = array_shift($pending);
            $proc = proc_open(
                $jobs[$idx]['cmd'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                repo_path()
            );
            if (!is_resource($proc)) {
                fwrite(STDERR, "[{$jobs[$idx]['slug']}] failed to start child process\n");
                $results[$idx] = ['exit' => 1, 'secs' => 0.0];
                continue;
            }
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $running[$idx] = [
                'proc'  => $proc,
                'pipes' => [1 => $pipes[1], 2 => $pipes[2]],
                'buf'   => [1 => '', 2 => ''],
                'start' => microtime(true),
            ];
        }

        pump_children($running, static function (int $idx, int $fd, string $line) use ($jobs): void {
            $out = "[{$jobs[$idx]['slug']}] " . $line;
            $fd === 2 ? fwrite(STDERR, $out) : print($out);
        });

        foreach ($running as $idx => $r) {
            $status = proc_get_status($r['proc']);
            if ($status['running'] || $r['pipes'][1] !== null || $r['pipes'][2] !== null) {
                continue;
            }
            $results[$idx] = ['exit' => $status['exitcode'], 'secs' => microtime(true) - $r['start']];
            proc_close($r['proc']);
            unset($running[$idx]);
        }
    }

    ksort($results);
    return $results;
}

/**
 * One multiplexing pass over all running children: wait briefly for output on
 * any pipe, then drain complete lines through $emit(idx, fd, line). Closed
 * pipes are set to null in place; a trailing unterminated line is flushed when
 * its pipe closes.
 *
 * @param array<int, array{proc: resource, pipes: array<int, resource|null>, buf: array<int, string>, start: float}> $running
 */
function pump_children(array &$running, callable $emit): void
{
    $read = [];
    foreach ($running as $r) {
        foreach ([1, 2] as $fd) {
            if ($r['pipes'][$fd] !== null) {
                $read[] = $r['pipes'][$fd];
            }
        }
    }
    if ($read === []) {
        usleep(50_000);
        return;
    }
    $write = $except = null;
    @stream_select($read, $write, $except, 0, 200_000);

    foreach ($running as $idx => &$r) {
        foreach ([1, 2] as $fd) {
            $pipe = $r['pipes'][$fd];
            if ($pipe === null) {
                continue;
            }
            $chunk = stream_get_contents($pipe);
            if ($chunk !== false && $chunk !== '') {
                $r['buf'][$fd] .= $chunk;
                while (($nl = strpos($r['buf'][$fd], "\n")) !== false) {
                    $emit($idx, $fd, substr($r['buf'][$fd], 0, $nl + 1));
                    $r['buf'][$fd] = substr($r['buf'][$fd], $nl + 1);
                }
            }
            if (feof($pipe)) {
                if ($r['buf'][$fd] !== '') {
                    $emit($idx, $fd, $r['buf'][$fd] . "\n");
                    $r['buf'][$fd] = '';
                }
                fclose($pipe);
                $r['pipes'][$fd] = null;
            }
        }
    }
    unset($r);
}

/**
 * Boot one site per slug. Studio creates are serialized (N concurrent creates
 * against one daemon is unverified; twenty sites is about 1.2GB). Playground
 * keeps today's concurrent spawn + Ctrl-C teardown.
 *
 * @param list<string> $slugs
 */
function serve_all(array $slugs, int $basePort, int $exitCode): void
{
    $cli = new StudioCli();
    $runner = RunnerResolver::resolve(
        null,
        $cli,
        static function (string $message): void {
            fwrite(STDERR, $message . "\n");
        }
    );
    if ($runner->name() === 'studio') {
        if ($basePort !== 9400) {
            echo "--port applies to Playground only; ignored for Studio.\n";
        }
        serve_all_studio($runner, $slugs);
        return;
    }
    serve_all_playground($slugs, $basePort, $exitCode);
}

/**
 * Start each Studio site in series, print URLs, return. No pcntl, no
 * register_shutdown teardown, no pkill — the sites are daemons.
 *
 * @param list<string> $slugs
 */
function serve_all_studio(SiteRunner $runner, array $slugs): void
{
    $store = new ProjectStore(repo_path('projects'));
    echo "\nStarting " . count($slugs) . " Studio site(s)…\n\n";
    $sites = [];
    foreach ($slugs as $slug) {
        try {
            $project = $store->open($slug);
            $site = $runner->start($project);
            $sites[] = ['slug' => $slug, 'site' => $site];
        } catch (Throwable $e) {
            fwrite(STDERR, "  could not start Studio for '{$slug}': {$e->getMessage()}\n");
        }
    }
    if ($sites === []) {
        return;
    }
    announce_sites_up($sites, $sites[0]['site']->persistent);
}

/**
 * Stop every Studio site built from $file: projects whose meta.demo_source
 * matches, plus the prompt slugs listed in that file. Never `studio stop --all`.
 */
function stop_demo_sites(string $file): void
{
    $runner = new StudioAppRunner(new StudioCli(), StudioAppRunner::defaultRoot(), repo_path());
    $want = realpath($file) ?: $file;
    $slugs = [];

    $prompts = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    if (is_array($prompts) && isset($prompts['prompts']) && is_array($prompts['prompts'])) {
        foreach ($prompts['prompts'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $raw = (string) ($entry['slug'] ?? $entry['id'] ?? '');
            if ($raw !== '') {
                $slugs[ProjectStore::slugify($raw)] = true;
            }
        }
    }

    $projectsDir = repo_path('projects');
    foreach (glob($projectsDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $metaFile = $dir . '/meta.json';
        if (!is_file($metaFile)) {
            continue;
        }
        $meta = json_decode((string) file_get_contents($metaFile), true);
        $source = is_array($meta) ? ($meta['demo_source'] ?? null) : null;
        if (!is_string($source) || ($source !== $want && $source !== $file)) {
            continue;
        }
        $slugs[basename($dir)] = true;
    }

    foreach (array_keys($slugs) as $slug) {
        $runner->stopSite($slug);
        echo "stopped {$slug}\n";
    }
}

/**
 * @param list<array{slug:string,site:\Automattic\SiteBuild\RunningSite}> $sites
 */
function announce_sites_up(array $sites, bool $persistent): void
{
    echo "\n── all sites up ─────────────────\n";
    echo $persistent
        ? "  stop them with: php bin/build-demos.php --stop\n"
        : "  Ctrl-C stops everything\n";
    foreach ($sites as $row) {
        $url = $row['site']->url;
        printf("  %-32s %s\n", $row['slug'], $url);
        echo "      ↳ admin: {$row['site']->adminUrl} (auto-logged in)\n";
    }
    echo "\n";
}

/**
 * Boot one Playground server per slug (site i on $basePort + i*PORT_STRIDE),
 * print every URL
 * once all are ready, then block until they exit. A single Ctrl-C stops the
 * parent AND all servers: the SIGINT handler turns the signal into a normal
 * exit so the registered teardown runs (SIGINT also reaches the children
 * directly — they share the foreground process group — but the pkill catches
 * the Playground-spawned node servers that reparent to init and escape both).
 *
 * @param list<string> $slugs
 */
function serve_all_playground(array $slugs, int $basePort, int $exitCode): void
{
    echo "\nStarting " . count($slugs) . " Playground server(s)…\n\n";

    $servers = [];
    foreach ($slugs as $i => $slug) {
        // php_child_command() uses `exec`, so teardown sees playground.php's
        // own pid; it also pins the PHP binary and temp dir so both sides derive
        // the same pid-stamped blueprint path.
        $cmd = php_child_command(repo_path('bin/playground.php'), [
            $slug,
            '--port=' . ($basePort + $i * PORT_STRIDE),
        ]);
        $proc = proc_open(
            $cmd,
            // Merge stderr into stdout: server chatter is informational here,
            // and one stream keeps the readiness scan simple.
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            repo_path()
        );
        if (!is_resource($proc)) {
            fwrite(STDERR, "  could not start Playground for '{$slug}'\n");
            continue;
        }
        stream_set_blocking($pipes[1], false);
        $servers[$i] = [
            'proc'  => $proc,
            'pipes' => [1 => $pipes[1], 2 => null],
            'buf'   => [1 => '', 2 => ''],
            'start' => microtime(true),
            'slug'  => $slug,
            'pid'   => proc_get_status($proc)['pid'] ?? 0,
            'url'   => null,
        ];
    }
    if ($servers === []) {
        return;
    }

    register_shutdown_function(static function () use ($servers): void {
        foreach ($servers as $s) {
            // Thanks to `exec` in the spawn command, $s['pid'] is
            // playground.php's own pid — the one its blueprint is stamped with.
            teardown_playground($s['proc'], $s['pid'], playground_blueprint_path($s['slug'], $s['pid']));
        }
    });
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        $stop = static function () use ($exitCode): void {
            echo "\nStopping all Playground servers…\n";
            exit($exitCode); // normal exit → the shutdown teardown above runs
        };
        pcntl_signal(SIGINT, $stop);
        pcntl_signal(SIGTERM, $stop);
    }

    $announced = false;
    while ($servers !== []) {
        pump_children($servers, static function (int $idx, int $fd, string $line) use (&$servers): void {
            echo "[{$servers[$idx]['slug']}] " . $line;
            $servers[$idx]['url'] ??= playground_ready_url($line);
        });

        $allReady = $servers !== [];
        foreach ($servers as $s) {
            if ($s['url'] === null) {
                $allReady = false;
                break;
            }
        }
        if (!$announced && $allReady) {
            $announced = true;
            echo "\n── all sites up ─────────────────\n";
            echo "  Ctrl-C stops everything\n";
            foreach ($servers as $s) {
                printf("  %-32s %s\n", $s['slug'], $s['url']);
                echo "      ↳ admin: {$s['url']}wp-admin/ (auto-logged in)\n";
            }
            echo "\n";
        }

        foreach ($servers as $idx => $s) {
            $status = proc_get_status($s['proc']);
            if ($status['running'] || $s['pipes'][1] !== null) {
                continue;
            }
            fwrite(STDERR, "  Playground for '{$s['slug']}' exited (code {$status['exitcode']})\n");
            proc_close($s['proc']);
            unset($servers[$idx]);
        }
    }
}
