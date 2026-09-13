<?php
declare(strict_types=1);

use Automattic\SiteBuild\Patterns\ComposeLayoutsStep;
use Automattic\SiteBuild\Patterns\NormalizeInputsStep;
use Automattic\SiteBuild\Patterns\PatternArtifacts;
use Automattic\SiteBuild\Project;

/**
 * @param array<string, mixed> $request
 * @param array<string, mixed> $inventory
 * @param array<string, mixed> $brand
 */
function normalize_project(array $request, array $inventory, array $brand = array()): Project
{
    $dir = sys_get_temp_dir() . '/normalize-inputs-' . bin2hex(random_bytes(4));
    mkdir($dir, 0o777, true);

    $project = new Project($dir, basename($dir));
    $project->writeJson(PatternArtifacts::REQUEST, $request);
    $project->writeJson(PatternArtifacts::INVENTORY, $inventory);
    $project->writeJson(PatternArtifacts::BRAND, $brand);

    return $project;
}

function normalize_inventory(): array
{
    return ['patterns' => [[
        'id' => 'theme/hero',
        'categories' => ['hero'],
        'content' => '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->',
    ]]];
}

test('valid inputs settle into the shape the later stages read', function () {
    $project = normalize_project(
        [
            'theme' => 'twentytwentyfive',
            'locale' => 'es',
            'navigation' => [['title' => 'Home', 'slug' => 'home']],
            'capabilities' => ['classes' => ['card'], 'template_parts' => [['name' => 'header']]],
        ],
        normalize_inventory(),
        ['settings' => ['color' => []]],
    );

    (new NormalizeInputsStep())->run($project);

    $inputs = $project->readJson(PatternArtifacts::NORMALIZED);
    assert_eq(NormalizeInputsStep::INPUTS_VERSION, $inputs['version']);
    assert_eq('twentytwentyfive', $inputs['theme']);
    assert_eq('es', $inputs['locale']);
    assert_eq(1, count($inputs['inventory']));
    assert_eq(array('card'), $inputs['capabilities']['classes']);
    assert_eq(1, count($inputs['navigation']));
});

/**
 * Content is composed for a theme. Without one there is nothing to compose
 * against, and every pattern choice downstream would be made against nothing.
 */
test('a request with no theme is refused', function () {
    $project = normalize_project(['theme' => ''], normalize_inventory());

    $thrown = assert_throws(static fn () => (new NormalizeInputsStep())->run($project));

    assert_contains('no theme', $thrown->getMessage());
});

test('an empty inventory is refused rather than composed from', function () {
    $project = normalize_project(['theme' => 'twentytwentyfive'], ['patterns' => []]);

    $thrown = assert_throws(static fn () => (new NormalizeInputsStep())->run($project));

    assert_contains('empty', $thrown->getMessage());
});

/**
 * Reported, not skipped. Dropping it would shrink the vocabulary silently and
 * the build would fail later at whichever section needed it, pointing at the
 * plan — which is not where the problem is.
 */
test('an inventory entry with no markup is reported by name', function () {
    $inventory = normalize_inventory();
    $inventory['patterns'][] = ['id' => 'theme/empty', 'content' => ''];

    $project = normalize_project(['theme' => 'twentytwentyfive'], $inventory);

    $thrown = assert_throws(static fn () => (new NormalizeInputsStep())->run($project));

    assert_contains('theme/empty', $thrown->getMessage());
});

test('an inventory that lists the same pattern twice is refused', function () {
    $inventory = normalize_inventory();
    $inventory['patterns'][] = $inventory['patterns'][0];

    $project = normalize_project(['theme' => 'twentytwentyfive'], $inventory);

    $thrown = assert_throws(static fn () => (new NormalizeInputsStep())->run($project));

    assert_contains('twice', $thrown->getMessage());
});

/**
 * A Brand is a theme.json partial. A key outside that lands in a global-styles
 * post WordPress strips on save, so the Brand would apply with part of itself
 * missing and nothing would say so.
 */
test('a Brand carrying a key theme.json does not have is refused', function () {
    $project = normalize_project(
        ['theme' => 'twentytwentyfive'],
        normalize_inventory(),
        ['settings' => [], 'logo' => 'https://example.com/logo.png'],
    );

    $thrown = assert_throws(static fn () => (new NormalizeInputsStep())->run($project));

    assert_contains('logo', $thrown->getMessage());
});

/**
 * Everything wrong at once, so a host fixes its request in one pass.
 */
test('every problem is reported together', function () {
    $project = normalize_project(['theme' => ''], ['patterns' => [['id' => '', 'content' => '']]]);

    $thrown = assert_throws(static fn () => (new NormalizeInputsStep())->run($project));

    assert_contains('no theme', $thrown->getMessage());
    assert_contains('has no id', $thrown->getMessage());
});

/**
 * Optional metadata is not held to the same standard: a pattern with no
 * categories is one the plan can still name outright, not a broken input.
 */
test('a pattern with no categories is kept', function () {
    $inventory = normalize_inventory();
    unset($inventory['patterns'][0]['categories']);

    $project = normalize_project(['theme' => 'twentytwentyfive'], $inventory);

    (new NormalizeInputsStep())->run($project);

    $inputs = $project->readJson(PatternArtifacts::NORMALIZED);
    assert_eq(array(), $inputs['inventory'][0]['categories']);
});

/**
 * A run started partway through takes its normalized inputs from a fixture or
 * an earlier run, which may predate the shape the stages now expect. Reading
 * them anyway composes from whatever still lines up and reports success.
 */
test('inputs from an older contract are refused rather than half-read', function () {
    $dir = sys_get_temp_dir() . '/stale-inputs-' . bin2hex(random_bytes(4));
    mkdir($dir, 0o777, true);
    $project = new Project($dir, basename($dir));
    $project->writeJson(PatternArtifacts::NORMALIZED, array('version' => 0, 'inventory' => array()));
    $project->writeJson(PatternArtifacts::PLAN, array('pages' => array()));

    $thrown = assert_throws(static fn () => (new ComposeLayoutsStep())->run($project));

    exec('rm -rf ' . escapeshellarg($dir));

    assert_contains('version', $thrown->getMessage());
    assert_contains('normalize-inputs', $thrown->getMessage());
});
