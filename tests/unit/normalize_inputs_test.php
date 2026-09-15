<?php
declare(strict_types=1);

use Automattic\SiteBuild\Patterns\ComposeLayoutsStep;
use Automattic\SiteBuild\Patterns\NormalizeInputsStep;
use Automattic\SiteBuild\Patterns\PatternArtifacts;
use Automattic\SiteBuild\Project;

/**
 * A version 2 request with every required field, so a test only names what
 * it changes.
 *
 * @param array<string, mixed> $overrides
 */
function normalize_request(array $overrides = []): array
{
    return $overrides + [
        'version' => 2,
        'theme' => 'twentytwentyfive',
        'site' => ['title' => 'Summit'],
        'pages' => [['slug' => 'home', 'title' => 'Home', 'intent' => 'Open the site']],
    ];
}

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
        normalize_request([
            'locale' => 'es',
            'navigation' => [['title' => 'Home', 'slug' => 'home']],
            'capabilities' => ['classes' => ['card'], 'page_template' => 'page-no-title'],
        ]),
        normalize_inventory(),
        ['name' => 'Summit', 'config' => ['settings' => ['color' => []]]],
    );

    (new NormalizeInputsStep())->run($project);

    $inputs = $project->readJson(PatternArtifacts::NORMALIZED);
    assert_eq(NormalizeInputsStep::INPUTS_VERSION, $inputs['version']);
    assert_eq('twentytwentyfive', $inputs['theme']);
    assert_eq('es', $inputs['locale']);
    assert_eq(1, count($inputs['inventory']));
    assert_eq(array('card'), $inputs['capabilities']['classes']);
    assert_eq('page-no-title', $inputs['capabilities']['page_template']);
    assert_eq(1, count($inputs['navigation']));
    assert_eq('Summit', $inputs['site']['title']);
    assert_eq('Summit', $inputs['brand']['name']);
});

/**
 * Content is composed for a theme. Without one there is nothing to compose
 * against, and every pattern choice downstream would be made against nothing.
 */
test('a request with no theme is refused', function () {
    $project = normalize_project(normalize_request(['theme' => '']), normalize_inventory());

    $thrown = assert_throws(static fn () => (new NormalizeInputsStep())->run($project));

    assert_contains('no theme', $thrown->getMessage());
});

test('an empty inventory is refused rather than composed from', function () {
    $project = normalize_project(normalize_request(), ['patterns' => []]);

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

    $project = normalize_project(normalize_request(), $inventory);

    $thrown = assert_throws(static fn () => (new NormalizeInputsStep())->run($project));

    assert_contains('theme/empty', $thrown->getMessage());
});

test('an inventory that lists the same pattern twice is refused', function () {
    $inventory = normalize_inventory();
    $inventory['patterns'][] = $inventory['patterns'][0];

    $project = normalize_project(normalize_request(), $inventory);

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
        normalize_request(),
        normalize_inventory(),
        ['config' => ['settings' => [], 'logo' => 'https://example.com/logo.png']],
    );

    $thrown = assert_throws(static fn () => (new NormalizeInputsStep())->run($project));

    assert_contains('logo', $thrown->getMessage());
});

/**
 * Everything wrong at once, so a host fixes its request in one pass.
 */
test('every problem is reported together', function () {
    $project = normalize_project(normalize_request(['theme' => '']), ['patterns' => [['id' => '', 'content' => '']]]);

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

    $project = normalize_project(normalize_request(), $inventory);

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

/**
 * theme.json requires a name on every preset, and the save filter a site
 * running Gutenberg applies to global styles drops each nameless one before it
 * persists. Observed on an Atomic site: three colours written, a 52-byte post
 * saved, and the apply reporting success.
 */
test('a Brand preset with no name is refused by name', function () {
    $project = normalize_project(
        normalize_request(),
        normalize_inventory(),
        ['config' => ['settings' => ['color' => ['palette' => [['slug' => 'base', 'color' => '#FFF']]]]]],
    );

    $thrown = assert_throws(static fn () => (new NormalizeInputsStep())->run($project));

    assert_contains('settings.color.palette[0] has no name', $thrown->getMessage());
});

test('a Brand whose presets are named is accepted', function () {
    $project = normalize_project(
        normalize_request(),
        normalize_inventory(),
        ['config' => ['settings' => ['color' => ['palette' => [['slug' => 'base', 'name' => 'Base', 'color' => '#FFF']]]]]],
    );

    (new NormalizeInputsStep())->run($project);

    assert_eq('Base', $project->readJson(PatternArtifacts::NORMALIZED)['brand']['config']['settings']['color']['palette'][0]['name']);
});

/**
 * The Brand's context is prose the operator wrote about the brand. The model
 * reads facts, so it travels as one, unless the host already set it.
 */
test('the Brand context becomes a fact the model can read', function () {
    $project = normalize_project(
        normalize_request(),
        normalize_inventory(),
        ['name' => 'Summit', 'context' => 'Warm, direct, never salesy.', 'config' => []],
    );

    (new NormalizeInputsStep())->run($project);

    assert_eq('Warm, direct, never salesy.', $project->readJson(PatternArtifacts::NORMALIZED)['facts']['brand_context']);
});

/**
 * A request from the first contract has no version, its Brand is a bare
 * theme.json partial, and its pages have no way to be supplied. Reading it
 * anyway would compose a site with no Brand and call it one.
 */
test('a version 1 request is refused by name', function () {
    $project = normalize_project(
        ['theme' => 'twentytwentyfive', 'pages' => []],
        normalize_inventory(),
        ['settings' => []],
    );

    $thrown = assert_throws(static fn () => (new NormalizeInputsStep())->run($project));

    assert_contains('version NULL', $thrown->getMessage());
    assert_contains('version 2', $thrown->getMessage());
});

/**
 * A supplied page arrives as markup and settles with its slots as the host
 * declared them: absent means discover, a list means those, [] means frozen.
 */
test('supplied and composed pages settle so later stages need not guess', function () {
    $markup = '<!-- wp:paragraph --><p>Approved copy.</p><!-- /wp:paragraph -->';
    $project = normalize_project(
        normalize_request(['pages' => [
            ['slug' => 'home', 'title' => 'Home', 'intent' => 'Open the site'],
            ['slug' => 'about', 'title' => 'About', 'markup' => $markup],
            ['slug' => 'legal', 'title' => 'Legal', 'markup' => $markup, 'slots' => []],
        ]]),
        normalize_inventory(),
    );

    (new NormalizeInputsStep())->run($project);

    $pages = $project->readJson(PatternArtifacts::NORMALIZED)['pages'];
    assert_eq(false, NormalizeInputsStep::isSupplied($pages[0]));
    assert_eq(true, NormalizeInputsStep::isSupplied($pages[1]));
    assert_eq(true, array_key_exists('slots', $pages[1]) && $pages[1]['slots'] === null);
    assert_eq([], $pages[2]['slots']);
});

/**
 * With every page supplied there is nothing to compose, so an inventory is
 * not required. With one composed page it is.
 */
test('the inventory is optional only when every page is supplied', function () {
    $markup = '<!-- wp:paragraph --><p>Approved copy.</p><!-- /wp:paragraph -->';
    $supplied = normalize_project(
        normalize_request(['pages' => [['slug' => 'home', 'title' => 'Home', 'markup' => $markup]]]),
        ['patterns' => []],
    );
    (new NormalizeInputsStep())->run($supplied);
    assert_eq([], $supplied->readJson(PatternArtifacts::NORMALIZED)['inventory']);

    $mixed = normalize_project(
        normalize_request(['pages' => [
            ['slug' => 'home', 'title' => 'Home', 'markup' => $markup],
            ['slug' => 'blog', 'title' => 'Blog', 'intent' => 'List the posts'],
        ]]),
        ['patterns' => []],
    );
    $thrown = assert_throws(static fn () => (new NormalizeInputsStep())->run($mixed));
    assert_contains('inventory is empty', $thrown->getMessage());
});
