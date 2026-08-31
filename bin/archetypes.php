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
 *   php bin/archetypes.php capture [--only=<slug>,…] [--per-archetype=3]
 *   php bin/archetypes.php fill    [--only=<brief>,…] [--parallel=3]
 *   php bin/archetypes.php status  <family/id> <waiting|built|dropped> [--note=…]
 *   php bin/archetypes.php propose "<what you want>" [--family=section] [--count=1]
 *   php bin/archetypes.php propose --auto  [--family=section] [--count=1]
 *
 * `capture` boots each built project under projects/, screenshots every part it
 * delivered, and files up to --per-archetype images per archetype under
 * docs/archetypes/shots — downsized and WebP, because these are committed. They
 * are the tool's own assets, not review evidence, which is why they may live in
 * the repository when a PR screenshot may not. Several examples rather than one,
 * because one image proves an archetype exists and only a set of them shows how
 * much it varies, which is the question a variety review asks.
 *
 * `fill` builds the cohort that reaches the archetypes no demo brief selects —
 * `capture` can only photograph what a site drew, so an empty card needs a build
 * before it needs a screenshot.
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
    '--per-archetype' => 'value',
    '--parallel' => 'value',
    '--note' => 'value',
    '--auto' => 'bool',
    '--open' => 'bool',
], maxPositionals: 3);
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
        capture_shots(
            $shotsDir,
            (string) ($flags['--only'] ?? ''),
            (int) ($flags['--width'] ?? 1366),
            $flags,
            max(1, (int) ($flags['--per-archetype'] ?? 3)),
        );
        build_gallery($page, $docs, live: false);
        break;

    case 'fill':
        fill_cohort((string) ($flags['--only'] ?? ''), (int) ($flags['--parallel'] ?? 3));
        break;

    case 'status':
        set_proposal_status(
            $docs,
            $argument,
            (string) ($args['positionals'][2] ?? ''),
            (string) ($flags['--note'] ?? ''),
        );
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
      php bin/archetypes.php capture [--only=slug,slug] [--width=1366] [--per-archetype=3]
      php bin/archetypes.php fill [--only=brief,brief] [--parallel=3]
      php bin/archetypes.php status <family/id> <waiting|built|dropped> [--note="…"]
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
    $shots = read_shot_index($docs . '/shots', 1366)['shots'];
    $proposals = (new ArchetypeProposals($docs . '/proposals'))->all();
    $byFamily = [];
    $known = [];
    foreach (ArchetypeCatalog::entries() as $entry) {
        $byFamily[$entry['family']][] = $entry;
        $known[$entry['key']] = true;
    }
    $thin = [];
    foreach ($byFamily as $family => $entries) {
        $label = ArchetypeCatalog::familyLabel($family);
        echo "\n{$label['title']} (" . count($entries) . ")\n";
        foreach ($entries as $entry) {
            $count = count($shots[$entry['key']] ?? []);
            // One example proves the archetype exists; the review question is
            // how much it varies, so the count is the number that matters.
            $mark = $count === 0 ? '   ' : sprintf('%dx ', $count);
            if ($count === 1) {
                $thin[] = $entry['key'];
            }
            printf("  %s %-24s %s\n", $mark, $entry['id'], mb_substr($entry['brief'], 0, 74));
        }
    }

    $waiting = array_values(array_filter($proposals, static fn (array $p): bool => $p['status'] === 'waiting'));
    $settled = array_values(array_filter($proposals, static fn (array $p): bool => $p['status'] !== 'waiting'));
    echo "\nProposals waiting (" . count($waiting) . ")\n";
    foreach ($waiting as $proposal) {
        printf("     %-8s %-24s %s\n", $proposal['family'], $proposal['id'], mb_substr($proposal['idea'], 0, 66));
    }
    if ($settled !== []) {
        echo "\nProposals settled (" . count($settled) . ")\n";
        foreach ($settled as $proposal) {
            printf(
                "     %-8s %-24s %-8s %s\n",
                $proposal['family'],
                $proposal['id'],
                $proposal['status'],
                mb_substr($proposal['status_note'], 0, 56),
            );
        }
    }

    if ($thin !== []) {
        echo "\nOne example only (" . count($thin) . "): " . implode(', ', $thin) . "\n";
        echo "  php bin/archetypes.php fill   builds sites that draw them\n";
    }
    $orphans = array_values(array_filter(array_keys($shots), static fn (string $k): bool => !isset($known[$k])));
    if ($orphans !== []) {
        echo "\nStale shots of retired archetypes (" . count($orphans) . "): " . implode(', ', $orphans) . "\n";
        echo "  php bin/archetypes.php capture   drops them\n";
    }
    echo "\n";
}

/**
 * Build the cohort that fills the empty cards.
 *
 * `capture` can only photograph what a built site drew, and selection is
 * deterministic per brief, so an archetype no brief happens to select stays
 * blank however many demos are built. The cohort pins the three steerable
 * assignments per brief instead.
 */
function fill_cohort(string $only, int $parallel): void
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(repo_path('bin/build-catalog-cohort.php'))
        . ' --parallel=' . max(1, $parallel)
        . ($only === '' ? '' : ' --only=' . escapeshellarg($only));
    echo "Building the fill cohort. This runs full site builds, so it is slow and it costs model calls.\n";
    $status = 1;
    passthru($command, $status);
    if ($status !== 0) {
        fwrite(STDERR, "The cohort build failed.\n");
        exit($status);
    }
    echo "\nNow photograph what they drew:\n  php bin/archetypes.php capture\n";
}

/** Record where a proposal ended up, so the queue stops offering it. */
function set_proposal_status(string $docs, string $key, string $status, string $note): void
{
    $parts = explode('/', trim($key));
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '' || $status === '') {
        fwrite(STDERR, "Usage: php bin/archetypes.php status <family/id> <"
            . implode('|', ArchetypeProposals::STATUSES) . "> [--note=\"…\"]\n");
        exit(1);
    }
    try {
        $file = (new ArchetypeProposals($docs . '/proposals'))->setStatus($parts[0], $parts[1], $status, $note);
        echo "{$parts[0]}/{$parts[1]} is now {$status}\n";
        echo '  ' . str_replace(repo_path() . '/', '', $file) . "\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "  {$e->getMessage()}\n");
        exit(1);
    }
}

/** Screenshot every archetype the built projects delivered. */
function capture_shots(string $shotsDir, string $only, int $width, array $flags, int $perArchetype): void
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
    // Booting every project takes minutes; refusing after that because nothing
    // can write a WebP wastes the whole run.
    if (webp_encoder() === null) {
        fwrite(STDERR, "No WebP encoder: install the Imagick or GD extension, or ImageMagick's magick binary.\n");
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
                // Every candidate is kept here and the choice is made once, in
                // store_shots(): one archetype needs several examples from
                // different sites, and which site wins is not knowable until
                // every project has reported.
                $captured[$family . '/' . $id][] = [
                    'source' => (string) $capture['file'],
                    'area' => (int) $capture['width'] * (int) $capture['height'],
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

    store_shots($captured, $shotsDir, $width, $perArchetype);
    exec('rm -rf ' . escapeshellarg($raw));
}

/**
 * Downsize the chosen captures into the committed shot set.
 *
 * One image per archetype answers "does this exist". It cannot answer "how much
 * does this vary", which is the question a variety review actually asks, so up
 * to $perArchetype examples are kept and they are taken from different sites
 * wherever the cohort supplies them.
 *
 * These images ship in the repository, so they are converted to WebP and capped
 * at 1100px wide: a gallery card never shows them larger, and the repository
 * should not carry pixels nobody looks at.
 *
 * @param array<string,list<array<string,mixed>>> $captured
 */
function store_shots(array $captured, string $shotsDir, int $width, int $perArchetype): void
{
    if ($captured === []) {
        fwrite(STDERR, "Nothing captured.\n");
        return;
    }
    @mkdir($shotsDir, 0o777, true);
    $index = read_shot_index($shotsDir, $width);
    $shots = $index['shots'];

    $stored = 0;
    foreach ($captured as $key => $candidates) {
        $entries = [];
        foreach (pick_shots($candidates, $perArchetype) as $rank => $capture) {
            $name = str_replace('/', '--', $key) . ($rank === 0 ? '' : '-' . ($rank + 1)) . '.webp';
            if (!downsize_to_webp((string) $capture['source'], $shotsDir . '/' . $name)) {
                fwrite(STDERR, "  could not convert {$capture['source']}\n");
                continue;
            }
            $entries[] = [
                'file' => $name,
                'site' => $capture['site'],
                'slug' => $capture['slug'],
                'captured_width' => $capture['captured_width'],
                'captured_height' => $capture['captured_height'],
            ];
            $stored++;
        }
        if ($entries !== []) {
            $shots[$key] = $entries;
        }
    }
    ksort($shots);
    $index['shots'] = $shots;
    $index['width'] = $width;
    $index['per_archetype'] = $perArchetype;
    write_shot_index($shotsDir, $index);

    $thin = array_keys(array_filter($shots, static fn (array $list): bool => count($list) < 2));
    echo "Stored {$stored} image(s) across " . count($captured) . ' archetype(s); '
        . count($shots) . " archetypes illustrated.\n";
    if ($thin !== []) {
        echo '  Only one example so far: ' . implode(', ', $thin) . "\n";
        echo "  Build more sites that draw them: php bin/archetypes.php fill\n";
    }
    prune_shots($shotsDir);
}

/**
 * Choose which captures of one archetype to keep.
 *
 * Different sites first, because two crops of the same build show the same
 * decisions twice; the largest capture wins inside a site, and the leftovers
 * fill the remaining slots only when the cohort has no other site to offer.
 *
 * @param list<array<string,mixed>> $candidates
 * @return list<array<string,mixed>>
 */
function pick_shots(array $candidates, int $limit): array
{
    usort($candidates, static fn (array $a, array $b): int => $b['area'] <=> $a['area']);
    $chosen = [];
    $seenSlugs = [];
    foreach ($candidates as $candidate) {
        if (count($chosen) >= $limit) {
            break;
        }
        if (isset($seenSlugs[$candidate['slug']])) {
            continue;
        }
        $seenSlugs[$candidate['slug']] = true;
        $chosen[] = $candidate;
    }
    foreach ($candidates as $candidate) {
        if (count($chosen) >= $limit) {
            break;
        }
        if (!in_array($candidate, $chosen, true)) {
            $chosen[] = $candidate;
        }
    }
    return $chosen;
}

/**
 * Drop shots the catalogs no longer own, and image files the index no longer
 * names.
 *
 * The catalogs are code and they change: a recipe merged into another one
 * (BIGR-912 folded `editorial-split` away) leaves an image of an archetype that
 * no longer exists. The gallery renders only what the catalog knows, so such a
 * shot is invisible rather than wrong — which is exactly why it has to be
 * reported here instead of waiting to be noticed.
 */
function prune_shots(string $shotsDir): void
{
    $index = read_shot_index($shotsDir, 1366);
    $known = [];
    foreach (ArchetypeCatalog::entries() as $entry) {
        $known[$entry['key']] = true;
    }

    $orphans = array_values(array_filter(array_keys($index['shots']), static fn (string $k): bool => !isset($known[$k])));
    foreach ($orphans as $key) {
        foreach ($index['shots'][$key] as $entry) {
            @unlink($shotsDir . '/' . $entry['file']);
        }
        unset($index['shots'][$key]);
    }

    $referenced = [];
    foreach ($index['shots'] as $entries) {
        foreach ($entries as $entry) {
            $referenced[$entry['file']] = true;
        }
    }
    $strays = 0;
    foreach (glob($shotsDir . '/*.webp') ?: [] as $file) {
        if (!isset($referenced[basename($file)])) {
            @unlink($file);
            $strays++;
        }
    }

    if ($orphans !== [] || $strays > 0) {
        write_shot_index($shotsDir, $index);
        if ($orphans !== []) {
            echo '  Dropped ' . count($orphans) . ' shot(s) of retired archetypes: ' . implode(', ', $orphans) . "\n";
        }
        if ($strays > 0) {
            echo "  Deleted {$strays} image file(s) the index no longer names.\n";
        }
    }
}

/**
 * Read the shot index, normalizing the per-archetype value to a list.
 *
 * @return array{width:int,per_archetype:int,shots:array<string,list<array<string,mixed>>>}
 */
function read_shot_index(string $shotsDir, int $width): array
{
    $file = $shotsDir . '/index.json';
    $raw = is_file($file) ? (array) json_decode((string) file_get_contents($file), true) : [];
    return [
        'width' => (int) ($raw['width'] ?? $width),
        'per_archetype' => (int) ($raw['per_archetype'] ?? 3),
        'shots' => ArchetypeGallery::normalizeShots($raw['shots'] ?? []),
    ];
}

/** @param array<string,mixed> $index */
function write_shot_index(string $shotsDir, array $index): void
{
    file_put_contents(
        $shotsDir . '/index.json',
        json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    );
}

/**
 * The first WebP encoder this PHP can reach, or null when there is none.
 *
 * composer.json requires curl, json and mbstring only, so neither GD nor
 * Imagick is guaranteed — and a capture run boots every project before it
 * writes an image, so a missing encoder has to be found before that, not after.
 */
function webp_encoder(): ?string
{
    if (class_exists('Imagick')) {
        return 'imagick';
    }
    if (function_exists('imagewebp')) {
        return 'gd';
    }
    foreach (['magick', 'convert'] as $binary) {
        if (command_exists($binary)) {
            return $binary;
        }
    }
    return null;
}

/**
 * Downsize one capture into a committed shot.
 *
 * These images ship in the repository, so they are capped at 1100x1400 and
 * re-encoded as WebP: a gallery card never shows them larger.
 */
function downsize_to_webp(string $source, string $target, int $maxWidth = 1100, int $maxHeight = 1400): bool
{
    return match (webp_encoder()) {
        'imagick' => downsize_with_imagick($source, $target, $maxWidth, $maxHeight),
        'gd' => downsize_with_gd($source, $target, $maxWidth, $maxHeight),
        null => false,
        default => downsize_with_cli($source, $target, $maxWidth, $maxHeight),
    };
}

function downsize_with_imagick(string $source, string $target, int $maxWidth, int $maxHeight): bool
{
    try {
        $image = new Imagick($source);
        // bestfit, so a tall section crop is bounded by its height and a wide
        // header crop by its width, with the aspect kept either way.
        $image->setImageFormat('webp');
        $image->setImageCompressionQuality(78);
        if ($image->getImageWidth() > $maxWidth || $image->getImageHeight() > $maxHeight) {
            $image->resizeImage($maxWidth, $maxHeight, Imagick::FILTER_LANCZOS, 1, true);
        }
        $ok = $image->writeImage($target);
        $image->clear();
        return $ok;
    } catch (Throwable) {
        return false;
    }
}

function downsize_with_gd(string $source, string $target, int $maxWidth, int $maxHeight): bool
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

function downsize_with_cli(string $source, string $target, int $maxWidth, int $maxHeight): bool
{
    $binary = webp_encoder();
    $command = escapeshellarg((string) $binary) . ' ' . escapeshellarg($source)
        . ' -resize ' . escapeshellarg("{$maxWidth}x{$maxHeight}>")
        . ' -quality 78 ' . escapeshellarg('webp:' . $target) . ' 2>/dev/null';
    $status = 1;
    $output = [];
    exec($command, $output, $status);
    return $status === 0 && is_file($target);
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
