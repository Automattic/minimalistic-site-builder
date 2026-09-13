<?php
declare(strict_types=1);

/**
 * Tiny zero-dependency test harness. Test files register cases with test();
 * run.php includes them and executes. Assertions throw on failure; the runner
 * reports pass/fail counts and exits non-zero if anything failed.
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/FakeLlm.php';
require_once __DIR__ . '/doubles.php';

/** @var array<int,array{0:string,1:callable,2:string}> */
$GLOBALS['__tests'] = [];

/** The test file being loaded, so a filter can name one. */
$GLOBALS['__test_file'] = '';

/** Raised by skip_test() so a missing optional capability is never a false pass. */
final class TestSkipped extends RuntimeException
{
}

function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][] = [$name, $fn, $GLOBALS['__test_file']];
}

/** Load a test file, recording which file the cases in it came from. */
function load_test_file(string $path): void
{
    $GLOBALS['__test_file'] = basename($path, '.php');
    require_once $path;
    $GLOBALS['__test_file'] = '';
}

/** Mark the current test as explicitly skipped, with a reviewable reason. */
function skip_test(string $reason): never
{
    throw new TestSkipped($reason);
}

function assert_true(bool $cond, string $msg = ''): void
{
    if (!$cond) {
        throw new RuntimeException('assert_true failed' . ($msg !== '' ? ": {$msg}" : ''));
    }
}

function assert_eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            'assert_eq failed: expected ' . var_export($expected, true)
            . ' got ' . var_export($actual, true) . ($msg !== '' ? " — {$msg}" : '')
        );
    }
}

function assert_contains(string $needle, string $haystack, string $msg = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException(
            "assert_contains failed: '{$needle}' not found" . ($msg !== '' ? " — {$msg}" : '')
        );
    }
}

/** Assert the callable throws, and return the Throwable so callers can inspect it. */
function assert_throws(callable $fn, string $msg = ''): Throwable
{
    try {
        $fn();
    } catch (TestSkipped $e) {
        throw $e;
    } catch (Throwable $e) {
        return $e;
    }
    throw new RuntimeException('assert_throws failed: no exception' . ($msg !== '' ? ": {$msg}" : ''));
}

/** Recursively delete a file or directory tree; missing paths are a no-op. */
function remove_tree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    @chmod($path, 0775);
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            remove_tree($path . '/' . $name);
        }
    }
    @rmdir($path);
}

/** Run $fn($dir) with a fresh temp dir, removing the tree even when the test fails. */
function with_temp_dir(string $prefix, callable $fn): mixed
{
    // uniqid() is a hex timestamp with no process entropy: two builders in the
    // same microsecond get the same path. The pid makes it per-process unique.
    $dir = sys_get_temp_dir() . '/' . $prefix . getmypid() . '_' . uniqid('', true);
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Could not create temp dir: {$dir}");
    }
    try {
        return $fn($dir);
    } finally {
        remove_tree($dir);
    }
}

/** Run $fn($project, $dir) with a throwaway project in a scoped temp dir. */
function with_project(string $prefix, callable $fn): mixed
{
    return with_temp_dir($prefix, function (string $dir) use ($fn): mixed {
        return $fn((new \Automattic\SiteBuild\ProjectStore($dir))->create('demo'), $dir);
    });
}

/**
 * PNG bytes: a $w x $h canvas of $bg with a centered 1/3-size $fg rectangle.
 * Callers must skip themselves when imagick is missing.
 */
function png_fixture(string $bg, string $fg, int $w = 60, int $h = 60): string
{
    $im = new Imagick();
    $im->newImage($w, $h, new ImagickPixel($bg));
    $draw = new ImagickDraw();
    $draw->setFillColor(new ImagickPixel($fg));
    $draw->rectangle($w / 3, $h / 3, 2 * $w / 3, 2 * $h / 3);
    $im->drawImage($draw);
    $im->setImageFormat('png');
    return $im->getImageBlob();
}

/** [width, height] of PNG bytes. */
function png_size(string $pngBytes): array
{
    $im = new Imagick();
    $im->readImageBlob($pngBytes);
    return [$im->getImageWidth(), $im->getImageHeight()];
}

/** The alpha (0..1) of the pixel at ($x, $y) in PNG bytes. */
function alpha_at(string $pngBytes, int $x, int $y): float
{
    $im = new Imagick();
    $im->readImageBlob($pngBytes);
    return $im->getImagePixelColor($x, $y)->getColorValue(Imagick::COLOR_ALPHA);
}

/** [r, g, b] of the pixel at ($x, $y), each 0..1. */
function rgb_at(string $pngBytes, int $x, int $y): array
{
    $im = new Imagick();
    $im->readImageBlob($pngBytes);
    $px = $im->getImagePixelColor($x, $y);
    return [
        $px->getColorValue(Imagick::COLOR_RED),
        $px->getColorValue(Imagick::COLOR_GREEN),
        $px->getColorValue(Imagick::COLOR_BLUE),
    ];
}

/**
 * The complete text one markup request sends: its cached prefix layers in
 * order, then the varying prompt. Assert against this whenever a test cares
 * about what the model was told, not about which layer carried it.
 *
 * @param array{prompt:string,cached_prefixes?:list<string>} $request
 */
function markup_request_text(array $request): string
{
    return implode('', $request['cached_prefixes'] ?? []) . $request['prompt'];
}

/**
 * The complete text one recorded FakeLlm call sent, layers included.
 *
 * @param array{prompt:string,opts:array<mixed>} $call
 */
function llm_call_text(array $call): string
{
    return markup_request_text([
        'prompt' => $call['prompt'],
        'cached_prefixes' => $call['opts']['cached_prefixes'] ?? [],
    ]);
}

/** Run $fn with its output buffered and discarded — even when it throws. */
function quietly(callable $fn): mixed
{
    ob_start();
    try {
        return $fn();
    } finally {
        ob_end_clean();
    }
}

/** Complete delivery-phase fixture for portable header/hero unit contracts. */
function test_above_fold_contract(
    string $recipe = 'foreground-split',
    string $headerArchetype = 'standard-row',
    ?array $action = null,
): array {
    $blueprint = \Automattic\SiteBuild\HeroBlueprint::defaultFor($recipe);
    $projection = \Automattic\SiteBuild\HeroComposition::planProjection($blueprint);
    $pages = [[
        'slug' => 'home',
        'title' => 'Home',
        'path' => '/',
        'front' => true,
        'sections' => [[
            'slug' => 'hero',
            'title' => 'Hero',
            'layout_archetype' => $projection['layout_archetype'],
            'background' => $projection['default_background'],
            'primary_action' => $action,
        ]],
    ]];
    return \Automattic\SiteBuild\AboveFoldContract::resolve(
        $pages,
        $blueprint,
        'full-bleed',
        ['base' => '#FFFFFF', 'contrast' => '#111111'],
        ['stable_id' => 'unit-contract', 'writing_direction' => 'ltr', 'page_count' => 1],
        ['archetype' => 'minimal-columns', 'surface' => 'base'],
        $headerArchetype,
    );
}

/** Complete persisted design-direction fixture for steps that consume the hero blueprint. */
function test_design_direction(string $recipe = 'cinematic-safe-zone', array $overrides = []): array
{
    return array_replace([
        'title' => 'Test direction',
        'description' => 'A clear, code-owned test direction.',
        'canvas' => 'full-bleed',
        'hero_blueprint' => \Automattic\SiteBuild\HeroBlueprint::defaultFor($recipe),
    ], $overrides);
}

/** Persist the complete design-direction fixture without hiding artifact reads in production code. */
function seed_test_design_direction(object $project, string $recipe = 'cinematic-safe-zone', array $overrides = []): void
{
    $project->writeJson('designDirection.json', test_design_direction($recipe, $overrides));
}

/** Run all registered tests, print results, return exit code. */
/**
 * @param list<string> $only Substrings. A case runs when one of them appears
 *                           in its file's name or its own name; no filter runs
 *                           everything.
 */
function run_tests(array $only = []): int
{
    $all = $GLOBALS['__tests'];
    $cases = $only === [] ? $all : select_tests($all, $only);

    // A filter that matches nothing would otherwise report "0 passed" and exit
    // zero, which reads exactly like a suite that ran and was clean.
    if ($cases === []) {
        fwrite(STDERR, sprintf(
            "No test matches %s. %d cases are registered; a filter matches a file's name or a case's name.\n",
            implode(' or ', array_map(static fn (string $p): string => '"' . $p . '"', $only)),
            count($all),
        ));
        return 1;
    }

    $pass = 0;
    $fail = 0;
    $skip = 0;
    foreach ($cases as [$name, $fn]) {
        $obLevel = ob_get_level();
        try {
            $fn();
            $line = "  PASS  {$name}\n";
            $pass++;
        } catch (TestSkipped $e) {
            $line = "  SKIP  {$name}\n        {$e->getMessage()}\n";
            $skip++;
        } catch (Throwable $e) {
            $line = "  FAIL  {$name}\n        {$e->getMessage()}\n";
            $fail++;
        }
        while (ob_get_level() > $obLevel) {
            ob_end_clean();
        }
        echo $line;
    }
    // A filtered run says so on the line people read, because a green count
    // from part of the suite reads the same as a green count from all of it.
    $scope = count($cases) === count($all)
        ? ''
        : sprintf(' — %d of %d cases, filtered by %s', count($cases), count($all), implode(' or ', $only));

    echo "\n{$pass} passed, {$fail} failed, {$skip} skipped{$scope}\n";
    return $fail === 0 ? 0 : 1;
}

/**
 * @param array<int,array{0:string,1:callable,2:string}> $all
 * @param list<string>                                   $only
 * @return array<int,array{0:string,1:callable,2:string}>
 */
function select_tests(array $all, array $only): array
{
    $wanted = array_map('strtolower', $only);

    return array_values(array_filter($all, static function (array $case) use ($wanted): bool {
        $haystack = strtolower($case[2] . ' ' . $case[0]);
        foreach ($wanted as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }));
}
