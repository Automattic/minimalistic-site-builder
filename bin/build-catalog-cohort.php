<?php
declare(strict_types=1);

use Automattic\SiteBuild\AboveFoldContract;
use Automattic\SiteBuild\FooterComposition;
use Automattic\SiteBuild\HeroComposition;
use Automattic\SiteBuild\Steps\DesignDirectionStep;

/**
 * Build the fill cohort for the section catalog: sites whose header archetype,
 * hero recipe and footer archetype are pinned, so every archetype in the four
 * catalogs reaches at least two real screenshots.
 *
 *   php bin/build-catalog-cohort.php [--only=<slug>] [--parallel=3] [--file=<path>]
 *
 * bin/build-demos.php builds whatever its briefs select, and selection is
 * deterministic per brief, so the demo cohort repeats a handful of archetypes
 * and never draws the rest. Three assignments are steerable, each by a
 * different channel, and this command drives all three:
 *
 *   header  HEADER_ARCHETYPE env, read by the sections step through
 *           AboveFoldContract::resolve().
 *   hero    HERO_RECIPE env, read by the design-direction step.
 *   footer  no env exists — the archetype is hashed at plan time into
 *           pages.json, and FooterComposition::archetypeForProject() prefers
 *           that persisted value. So each build runs in two phases and the
 *           value is patched in between.
 *
 * Phase 1 `--until=page-plan`, patch `pages.json`, phase 2 `--from=sections
 * --with-images`. Both phases carry the env overrides, because the sections
 * step re-resolves the above-fold contract on the resume.
 *
 * A forced assignment the contract refuses is degraded, not honored: it lands
 * in the project's warnings and the build continues, so this command re-reads
 * what each project actually recorded and reports every entry that did not get
 * what it asked for. Refusal rules worth knowing before adding a brief:
 * centered-masthead is refused over an image-led hero, split-nav is refused on
 * a one-page site, and oversized-wordmark is refused whenever a front hero
 * exists — which resolve() always has, so it cannot be requested at all today.
 *
 * Options:
 *   --only=<slug>   build only this entry (repeatable, comma separated).
 *   --parallel=<n>  concurrent builds (default 3; each build already fans out
 *                   its own LLM requests).
 *   --file=<path>   override the briefs file (default eval/catalog-fill-prompts.json).
 */

require_once __DIR__ . '/../src/bootstrap.php';

$args = parse_cli_args($argv, [
    '--only'     => 'value',
    '--parallel' => 'value',
    '--file'     => 'value',
], maxPositionals: 0);
if ($args['unknown'] !== null) {
    fwrite(STDERR, "Unknown argument: {$args['unknown']}\n");
    fwrite(STDERR, "Usage: php bin/build-catalog-cohort.php [--only=<slug>] [--parallel=3] [--file=<path>]\n");
    exit(1);
}
$flags = $args['flags'];
$file = repo_path((string) ($flags['--file'] ?? 'eval/catalog-fill-prompts.json'));
$parallel = max(1, (int) ($flags['--parallel'] ?? 3));
$only = array_values(array_filter(array_map('trim', explode(',', (string) ($flags['--only'] ?? '')))));

if (!is_file($file)) {
    fwrite(STDERR, "No briefs file at {$file}\n");
    exit(1);
}
$briefs = json_decode((string) file_get_contents($file), true)['prompts'] ?? [];
if (!is_array($briefs) || $briefs === []) {
    fwrite(STDERR, "No prompts in {$file}\n");
    exit(1);
}

$entries = [];
foreach ($briefs as $brief) {
    $slug = (string) ($brief['slug'] ?? '');
    if ($slug === '' || ($only !== [] && !in_array($slug, $only, true))) {
        continue;
    }
    // Refuse an unbuildable brief here rather than paying for a build that
    // silently degrades to standard-row.
    $header = (string) ($brief['header'] ?? '');
    $hero = (string) ($brief['hero'] ?? '');
    $footer = (string) ($brief['footer'] ?? '');
    if ($header !== '' && !in_array($header, AboveFoldContract::HEADER_ARCHETYPES, true)) {
        fwrite(STDERR, "{$slug}: unknown header archetype '{$header}'\n");
        exit(1);
    }
    if ($hero !== '') {
        HeroComposition::assertKnown($hero);
    }
    if ($footer !== '') {
        FooterComposition::assertKnown($footer);
    }
    $entries[] = $brief;
}
if ($entries === []) {
    fwrite(STDERR, "No matching briefs.\n");
    exit(1);
}

echo 'Building ' . count($entries) . " catalog-fill site(s), {$parallel} at a time.\n";

/** One brief, both phases, in a child shell. Returns the shell command. */
function build_command(array $brief): string
{
    $slug = (string) $brief['slug'];
    $env = '';
    if (($brief['header'] ?? '') !== '') {
        $env .= AboveFoldContract::HEADER_ARCHETYPE_ENV . '=' . escapeshellarg((string) $brief['header']) . ' ';
    }
    if (($brief['hero'] ?? '') !== '') {
        $env .= DesignDirectionStep::HERO_RECIPE_ENV . '=' . escapeshellarg((string) $brief['hero']) . ' ';
    }
    $scope = '';
    if (($brief['multi_page'] ?? false) === true) {
        $scope .= ' --multi-page';
        if (($brief['pages'] ?? '') !== '') {
            $scope .= ' --pages=' . escapeshellarg((string) $brief['pages']);
        }
    }
    $php = escapeshellarg(PHP_BINARY);
    $build = escapeshellarg(repo_path('bin/build.php'));
    $patch = escapeshellarg(repo_path('bin/catalog-footer-patch.php'));

    $plan = "{$env}{$php} {$build} --slug=" . escapeshellarg($slug) . $scope
        . ' --until=page-plan --no-serve ' . escapeshellarg((string) $brief['prompt']);
    $footer = ($brief['footer'] ?? '') === ''
        ? 'true'
        : "{$php} {$patch} " . escapeshellarg($slug) . ' ' . escapeshellarg((string) $brief['footer']);
    $rest = "{$env}{$php} {$build} --slug=" . escapeshellarg($slug)
        . ' --from=sections --with-images --no-serve';

    return "{$plan} && {$footer} && {$rest}";
}

$queue = $entries;
$running = [];
$failed = [];
// proc_open, not popen: a popen handle is only readable with feof(), which
// BLOCKS on a child that writes nothing to the pipe (each build writes to its
// own log file). One slow first build would then hold every free slot shut
// until it exited. proc_get_status() polls without blocking.
while ($queue !== [] || $running !== []) {
    while ($queue !== [] && count($running) < $parallel) {
        $brief = array_shift($queue);
        $slug = (string) $brief['slug'];
        $log = repo_path('projects') . '/' . $slug . '.cohort.log';
        @mkdir(dirname($log), 0o777, true);
        $pipes = [];
        $process = proc_open(
            ['bash', '-c', build_command($brief)],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
            $pipes
        );
        if (!is_resource($process)) {
            $failed[] = $slug;
            echo "  could not start {$slug}\n";
            continue;
        }
        $running[$slug] = ['process' => $process, 'log' => $log];
        echo "  started {$slug}\n";
    }
    foreach ($running as $slug => $child) {
        $status = proc_get_status($child['process']);
        if ($status['running']) {
            continue;
        }
        proc_close($child['process']);
        unset($running[$slug]);
        if ($status['exitcode'] !== 0) {
            $failed[] = $slug;
            echo "  FAILED {$slug} (exit {$status['exitcode']}) — see {$child['log']}\n";
        } else {
            echo "  done {$slug}\n";
        }
    }
    if ($running !== []) {
        sleep(5);
    }
}

/* ------------------------------------------------ what each build delivered */

echo "\nDelivered assignments (a degraded one means the contract refused the request):\n";
$drift = 0;
foreach ($entries as $brief) {
    $slug = (string) $brief['slug'];
    $dir = repo_path('projects') . '/' . $slug;
    $read = static function (string $path): array {
        return is_file($path) ? (array) json_decode((string) file_get_contents($path), true) : [];
    };
    $aboveFold = $read($dir . '/aboveFold.json');
    $pages = $read($dir . '/pages.json');
    $delivered = [
        'header' => (string) ($aboveFold['header']['archetype'] ?? '—'),
        'hero' => (string) ($aboveFold['recipe'] ?? '—'),
        'footer' => (string) ($pages['footer_archetype'] ?? '—'),
    ];
    $notes = [];
    foreach ($delivered as $family => $value) {
        $wanted = (string) ($brief[$family] ?? '');
        if ($wanted !== '' && $wanted !== $value) {
            $notes[] = "{$family}: asked {$wanted}, got {$value}";
            $drift++;
        }
    }
    printf(
        "  %-16s header=%-18s hero=%-22s footer=%s%s\n",
        $slug,
        $delivered['header'],
        $delivered['hero'],
        $delivered['footer'],
        $notes === [] ? '' : "\n      ! " . implode('; ', $notes)
    );
}

if ($failed !== []) {
    echo "\nFailed builds: " . implode(', ', $failed) . "\n";
}
echo "\nNow capture the catalog: php bin/section-catalog.php\n";
exit($failed === [] && $drift === 0 ? 0 : 1);
