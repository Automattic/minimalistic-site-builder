<?php
declare(strict_types=1);

use Automattic\SiteBuild\ArchetypeCatalog;
use Automattic\SiteBuild\ArchetypeGallery;
use Automattic\SiteBuild\ArchetypeMockups;
use Automattic\SiteBuild\ArchetypeProposals;
use Automattic\SiteBuild\Env;
use Automattic\SiteBuild\PlaygroundRunner;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\RunnerResolver;
use Automattic\SiteBuild\StudioCli;

/**
 * The archetype gallery: what the generator can draw, and what it could draw
 * next.
 *
 *   php bin/archetypes.php                 # build the page and serve it
 *   php bin/archetypes.php serve [--port=]
 *   php bin/archetypes.php build           # write docs/archetypes/index.html only
 *   php bin/archetypes.php list            # the same coverage as text
 *   php bin/archetypes.php capture [--only=<slug>,…]
 *   php bin/archetypes.php propose "<what you want>" [--family=section] [--count=1]
 *   php bin/archetypes.php propose --auto  [--family=section] [--count=1]
 *
 * `capture` boots each built project under projects/, screenshots every part it
 * delivered, and files ONE image per archetype under docs/archetypes/shots —
 * downsized and WebP, because these are committed. They are the tool's own
 * assets, not review evidence, which is why they may live in the repository
 * when a PR screenshot may not.
 *
 * `propose` asks the model for an archetype the catalog cannot express. It
 * writes a record under docs/archetypes/proposals; the mockup is validated
 * before it lands, so a drawing that scripts or reaches the network is refused.
 * The same call is available from the served page, which is the pleasant way to
 * use it.
 */

require_once __DIR__ . '/../src/bootstrap.php';

$args = parse_cli_args($argv, [
    '--port' => 'value',
    '--only' => 'value',
    '--family' => 'value',
    '--count' => 'value',
    '--width' => 'value',
    '--runner' => 'value',
    '--auto' => 'bool',
    '--open' => 'bool',
], maxPositionals: 2);
if ($args['unknown'] !== null) {
    fwrite(STDERR, "Unknown argument: {$args['unknown']}\n");
    usage();
}
$flags = $args['flags'];
$command = $args['positionals'][0] ?? 'serve';
$argument = $args['positionals'][1] ?? '';

$docs = repo_path('docs/archetypes');
$shotsDir = $docs . '/shots';
$page = $docs . '/index.html';

switch ($command) {
    case 'serve':
        build_gallery($page, $docs, live: true);
        serve_gallery($docs, (int) ($flags['--port'] ?? 9310));
        break;

    case 'build':
        build_gallery($page, $docs, live: false);
        echo "Wrote {$page}\n";
        break;

    case 'list':
        list_archetypes($docs);
        break;

    case 'capture':
        capture_shots($shotsDir, (string) ($flags['--only'] ?? ''), (int) ($flags['--width'] ?? 1366), $flags);
        build_gallery($page, $docs, live: false);
        break;

    case 'propose':
        propose_archetypes(
            $docs,
            (string) ($flags['--family'] ?? 'section'),
            $flags['--auto'] ?? false ? '' : $argument,
            max(1, (int) ($flags['--count'] ?? 1)),
            (bool) ($flags['--auto'] ?? false),
        );
        build_gallery($page, $docs, live: false);
        break;

    default:
        fwrite(STDERR, "Unknown command: {$command}\n");
        usage();
}

exit(0);

function usage(): never
{
    fwrite(STDERR, <<<TXT
    Usage:
      php bin/archetypes.php [serve] [--port=9310]
      php bin/archetypes.php build
      php bin/archetypes.php list
      php bin/archetypes.php capture [--only=slug,slug] [--width=1366]
      php bin/archetypes.php propose "<what you want>" [--family=header|hero|section|footer] [--count=1]
      php bin/archetypes.php propose --auto [--family=section] [--count=1]

    TXT);
    exit(1);
}

/** Render the page from the catalogs, the committed shots and the proposals. */
function build_gallery(string $page, string $docs, bool $live): void
{
    $shots = is_file($docs . '/shots/index.json')
        ? (array) json_decode((string) file_get_contents($docs . '/shots/index.json'), true)
        : [];
    $proposals = (new ArchetypeProposals($docs . '/proposals'))->all();
    @mkdir(dirname($page), 0o777, true);
    file_put_contents($page, ArchetypeGallery::render(ArchetypeCatalog::entries(), $shots, $proposals, $live));
}

/** Serve the gallery, with the compose and select endpoints behind it. */
function serve_gallery(string $docs, int $port): void
{
    $router = repo_path('bin/archetypes/router.php');
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $candidate = $port + $attempt;
        $probe = @fsockopen('127.0.0.1', $candidate, $errno, $errstr, 0.2);
        if (is_resource($probe)) {
            fclose($probe);
            continue;
        }
        $url = "http://127.0.0.1:{$candidate}/";
        echo "Archetype gallery: {$url}\n";
        echo "  Ctrl-C to stop.\n";
        passthru(sprintf(
            '%s -S 127.0.0.1:%d -t %s %s',
            escapeshellarg(PHP_BINARY),
            $candidate,
            escapeshellarg($docs),
            escapeshellarg($router),
        ));
        return;
    }
    fwrite(STDERR, "No free port in {$port}.." . ($port + 19) . "\n");
    exit(1);
}

/** The same coverage the page shows, for a terminal. */
function list_archetypes(string $docs): void
{
    $shots = is_file($docs . '/shots/index.json')
        ? (array) json_decode((string) file_get_contents($docs . '/shots/index.json'), true)['shots'] ?? []
        : [];
    $proposals = (new ArchetypeProposals($docs . '/proposals'))->all();
    $byFamily = [];
    foreach (ArchetypeCatalog::entries() as $entry) {
        $byFamily[$entry['family']][] = $entry;
    }
    foreach ($byFamily as $family => $entries) {
        $label = ArchetypeCatalog::familyLabel($family);
        echo "\n{$label['title']} (" . count($entries) . ")\n";
        foreach ($entries as $entry) {
            $mark = isset($shots[$entry['key']]) ? '📷' : '  ';
            printf("  %s %-24s %s\n", $mark, $entry['id'], mb_substr($entry['summary'], 0, 74));
        }
    }
    echo "\nProposals (" . count($proposals) . ")\n";
    foreach ($proposals as $proposal) {
        printf("     %-8s %-24s %s\n", $proposal['family'], $proposal['id'], mb_substr($proposal['idea'], 0, 66));
    }
    echo "\n";
}

/** Screenshot every archetype the built projects delivered. */
function capture_shots(string $shotsDir, string $only, int $width, array $flags): void
{
    $store = new ProjectStore(repo_path('projects'));
    $wanted = array_values(array_filter(array_map('trim', explode(',', $only))));
    $slugs = [];
    foreach (glob(repo_path('projects') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $slug = basename($dir);
        if (!is_file($dir . '/theme/style.css') || str_starts_with($slug, '_')) {
            continue;
        }
        if ($wanted !== [] && !in_array($slug, $wanted, true)) {
            continue;
        }
        $slugs[] = $slug;
    }
    sort($slugs);
    if ($slugs === []) {
        fwrite(STDERR, "No built projects under projects/. Build one first:\n  php bin/build-demos.php --with-images\n");
        exit(1);
    }

    $chrome = chrome_binary();
    if ($chrome === null) {
        fwrite(STDERR, "No Chrome/Chromium binary found (set CHROME_BIN).\n");
        exit(1);
    }
    $resolved = RunnerResolver::resolve(
        isset($flags['--runner']) ? (string) $flags['--runner'] : null,
        new StudioCli(),
        static fn (string $message) => fwrite(STDERR, $message . "\n"),
    );

    $raw = sys_get_temp_dir() . '/archetype-shots-' . getmypid();
    @mkdir($raw, 0o777, true);
    $captured = [];
    foreach ($slugs as $index => $slug) {
        $project = $store->open($slug);
        echo "Booting {$slug}…\n";
        $runner = $resolved->name() === 'playground' ? new PlaygroundRunner(9400 + 50 * $index, '2') : $resolved;
        $site = null;
        try {
            $site = $resolved->name() === 'playground' ? $runner->start($project, 240) : $runner->start($project);
            $command = 'node ' . escapeshellarg(repo_path('bin/screenshot/sections.js'))
                . ' ' . escapeshellarg(rtrim($site->url, '/') . '/')
                . ' ' . escapeshellarg($raw)
                . ' ' . escapeshellarg('--prefix=' . $slug)
                . ' ' . escapeshellarg('--width=' . $width)
                . ' ' . escapeshellarg('--chrome=' . $chrome);
            $output = [];
            $status = 1;
            exec($command . ' 2>/dev/null', $output, $status);
            if ($status !== 0) {
                fwrite(STDERR, "  capture failed for {$slug}\n");
                continue;
            }
            $assignments = assignments_for($project);
            foreach ((json_decode(implode("\n", $output), true)['captures'] ?? []) as $capture) {
                $family = (string) $capture['family'];
                $id = (string) $capture['archetype'] ?: (string) ($assignments[$family] ?? '');
                if ($id === '') {
                    continue;
                }
                $key = $family . '/' . $id;
                $area = (int) $capture['width'] * (int) $capture['height'];
                if (($captured[$key]['area'] ?? -1) >= $area) {
                    continue;
                }
                $captured[$key] = [
                    'source' => (string) $capture['file'],
                    'area' => $area,
                    'site' => (string) ($assignments['site'] ?? $slug),
                    'slug' => $slug,
                    'captured_width' => (int) $capture['width'],
                    'captured_height' => (int) $capture['height'],
                ];
            }
            echo "  captured " . count($captured) . " archetype(s) so far\n";
        } catch (Throwable $e) {
            fwrite(STDERR, "  {$slug}: {$e->getMessage()}\n");
        } finally {
            if ($site !== null) {
                ($site->stop)();
            }
        }
    }

    store_shots($captured, $shotsDir, $width);
    exec('rm -rf ' . escapeshellarg($raw));
}

/**
 * Downsize each winning capture into the committed shot set.
 *
 * These images ship in the repository, so they are converted to WebP and capped
 * at 1100px wide: a gallery card never shows them larger, and the repository
 * should not carry pixels nobody looks at.
 *
 * @param array<string,array<string,mixed>> $captured
 */
function store_shots(array $captured, string $shotsDir, int $width): void
{
    if ($captured === []) {
        fwrite(STDERR, "Nothing captured.\n");
        return;
    }
    @mkdir($shotsDir, 0o777, true);
    $index = is_file($shotsDir . '/index.json')
        ? (array) json_decode((string) file_get_contents($shotsDir . '/index.json'), true)
        : ['width' => $width, 'shots' => []];
    $shots = is_array($index['shots'] ?? null) ? $index['shots'] : [];

    foreach ($captured as $key => $capture) {
        $target = $shotsDir . '/' . str_replace('/', '--', $key) . '.webp';
        if (!downsize_to_webp((string) $capture['source'], $target)) {
            fwrite(STDERR, "  could not convert {$capture['source']}\n");
            continue;
        }
        $shots[$key] = [
            'file' => basename($target),
            'site' => $capture['site'],
            'slug' => $capture['slug'],
            'captured_width' => $capture['captured_width'],
            'captured_height' => $capture['captured_height'],
        ];
    }
    ksort($shots);
    $index['width'] = $width;
    $index['shots'] = $shots;
    file_put_contents(
        $shotsDir . '/index.json',
        json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    );
    echo 'Stored ' . count($captured) . " shot(s); " . count($shots) . " archetypes illustrated.\n";
}

/** GD is in the required extension set, so the conversion needs no new dependency. */
function downsize_to_webp(string $source, string $target, int $maxWidth = 1100, int $maxHeight = 1400): bool
{
    $image = @imagecreatefrompng($source) ?: @imagecreatefromstring((string) @file_get_contents($source));
    if (!$image instanceof GdImage) {
        return false;
    }
    $scale = min(1.0, $maxWidth / imagesx($image), $maxHeight / imagesy($image));
    if ($scale < 1.0) {
        $resized = imagescale($image, (int) round(imagesx($image) * $scale), (int) round(imagesy($image) * $scale));
        if ($resized instanceof GdImage) {
            imagedestroy($image);
            $image = $resized;
        }
    }
    $ok = imagewebp($image, $target, 78);
    imagedestroy($image);
    return $ok;
}

/** What one built project recorded about its own assignments. */
function assignments_for(Automattic\SiteBuild\Project $project): array
{
    $read = static function (string $rel) use ($project): array {
        try {
            return $project->readJson($rel);
        } catch (Throwable) {
            return [];
        }
    };
    $aboveFold = $read('aboveFold.json');
    $pages = $read('pages.json');
    $spec = $read('siteSpec.json');
    return [
        'header' => (string) ($aboveFold['header']['archetype'] ?? ''),
        'hero' => (string) ($aboveFold['recipe'] ?? ''),
        'footer' => (string) ($pages['footer_archetype'] ?? ''),
        'site' => (string) ($spec['name'] ?? $project->slug()),
    ];
}

/** Ask the model for one or more archetypes the catalog cannot express. */
function propose_archetypes(string $docs, string $family, string $request, int $count, bool $auto): void
{
    if (!$auto && trim($request) === '') {
        fwrite(STDERR, "Describe the archetype, or pass --auto to let the model find a gap.\n");
        exit(1);
    }
    $store = new ArchetypeProposals($docs . '/proposals');
    $mockups = new ArchetypeMockups(make_llm(), new PromptRenderer(repo_path('prompts')));
    $catalog = ArchetypeCatalog::entries();

    for ($n = 0; $n < $count; $n++) {
        echo $auto ? "Looking for a gap in the {$family} catalog…\n" : "Drawing a {$family} archetype…\n";
        try {
            $record = $mockups->draw($family, $catalog, $store->all(), $auto ? '' : $request);
            $file = $store->save($record);
            echo "  {$record['id']} — {$record['title']}\n";
            echo "  " . str_replace(repo_path() . '/', '', $file) . "\n";
        } catch (Throwable $e) {
            fwrite(STDERR, "  refused: {$e->getMessage()}\n");
        }
    }
}

/** First working Chrome/Chromium binary (CHROME_BIN wins), or null. */
function chrome_binary(): ?string
{
    $candidates = array_filter([
        Env::get('CHROME_BIN'),
        '/usr/bin/google-chrome-stable',
        '/usr/bin/google-chrome',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
        '/snap/bin/chromium',
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    ]);
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    foreach (['google-chrome', 'chromium'] as $name) {
        if (command_exists($name)) {
            return $name;
        }
    }
    return null;
}
