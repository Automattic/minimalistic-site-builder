<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\AssemblePagesStep;

/**
 * Unit tests for AssemblePagesStep: inlines every page's (already fixed)
 * section parts into the content plugin's page files, writes the plugin
 * manifest and the deterministic theme templates, registers the chrome
 * template parts, and removes the transient page-* parts from the theme.
 */

function assemble_fixture(): array
{
    $tmp = sys_get_temp_dir() . '/builder_asm_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('theme/theme.json', ['version' => 3]);
    $project->writeJson('pages.json', ['pages' => [
        [
            'slug' => 'home', 'title' => 'Home', 'path' => '/', 'front' => true,
            'parent' => null, 'menu_order' => 0, 'purpose' => 'Welcome',
            'sections' => [
                ['slug' => 'hero', 'title' => 'Hero', 'type' => 'hero'],
                ['slug' => 'about', 'title' => 'About', 'type' => 'about'],
            ],
        ],
        [
            'slug' => 'menu', 'title' => 'Menu', 'path' => '/menu/', 'front' => false,
            'parent' => null, 'menu_order' => 10, 'purpose' => 'What we bake',
            'sections' => [
                ['slug' => 'breads', 'title' => 'Breads', 'type' => 'features'],
            ],
        ],
    ]]);
    $project->writeText('theme/parts/header.html', '<!-- wp:group --><!-- wp:site-title /--><!-- /wp:group -->' . "\n");
    $project->writeText('theme/parts/footer.html', '<!-- wp:group --><!-- wp:paragraph --><p>(c)</p><!-- /wp:paragraph --><!-- /wp:group -->' . "\n");
    $project->writeText('theme/parts/page-home--hero.html', '<!-- wp:heading --><h2>Hero</h2><!-- /wp:heading -->' . "\n");
    $project->writeText('theme/parts/page-home--about.html', '<!-- wp:heading --><h2>About</h2><!-- /wp:heading -->' . "\n");
    $project->writeText('theme/parts/page-menu--breads.html', '<!-- wp:heading --><h2>Breads</h2><!-- /wp:heading -->' . "\n");
    return [$project, $tmp];
}

test('assemble-pages inlines fixed parts into plugin pages in plan order', function () {
    [$project, $tmp] = assemble_fixture();

    (new AssemblePagesStep())->run($project);

    $home = $project->readText('plugin/pages/home.html');
    assert_contains('<h2>Hero</h2>', $home);
    assert_contains('<h2>About</h2>', $home);
    assert_true(strpos($home, '<h2>Hero</h2>') < strpos($home, '<h2>About</h2>'), 'sections in plan order');
    assert_true(!str_contains($home, 'wp:template-part'), 'content is inline markup, not part references');

    assert_contains('<h2>Breads</h2>', $project->readText('plugin/pages/menu.html'));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('assemble-pages writes the plugin manifest from the plan', function () {
    [$project, $tmp] = assemble_fixture();

    (new AssemblePagesStep())->run($project);

    $manifest = $project->readJson('plugin/pages.json');
    assert_eq(2, count($manifest['pages']));
    assert_eq(
        ['slug' => 'home', 'title' => 'Home', 'front' => true, 'menu_order' => 0, 'parent' => null],
        $manifest['pages'][0]
    );
    assert_eq(
        ['slug' => 'menu', 'title' => 'Menu', 'front' => false, 'menu_order' => 10, 'parent' => null],
        $manifest['pages'][1]
    );

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('assemble-pages removes the transient page parts and keeps the chrome', function () {
    [$project, $tmp] = assemble_fixture();

    (new AssemblePagesStep())->run($project);

    assert_true(!$project->exists('theme/parts/page-home--hero.html'), 'transient part removed');
    assert_true(!$project->exists('theme/parts/page-home--about.html'), 'transient part removed');
    assert_true(!$project->exists('theme/parts/page-menu--breads.html'), 'transient part removed');
    assert_true($project->exists('theme/parts/header.html'), 'header kept');
    assert_true($project->exists('theme/parts/footer.html'), 'footer kept');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('assemble-pages writes the page and index templates and registers the chrome parts', function () {
    [$project, $tmp] = assemble_fixture();

    (new AssemblePagesStep())->run($project);

    $page = $project->readText('theme/templates/page.html');
    assert_contains('wp:template-part {"slug":"header","tagName":"header"}', $page);
    // Bare post-content: sections carry their own layout, so the template must
    // not constrain them (that would break full-bleed bands).
    assert_contains('<!-- wp:post-content /-->', $page);
    assert_contains('wp:template-part {"slug":"footer","tagName":"footer"}', $page);

    $index = $project->readText('theme/templates/index.html');
    assert_contains('wp:post-content', $index);

    assert_true(!$project->exists('theme/templates/front-page.html'), 'no front-page template — the seeded homepage owns the front');

    $theme = $project->readJson('theme/theme.json');
    assert_eq([
        ['name' => 'header', 'title' => 'Header', 'area' => 'header'],
        ['name' => 'footer', 'title' => 'Footer', 'area' => 'footer'],
    ], $theme['templateParts']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('assemble-pages throws when a section part is missing', function () {
    [$project, $tmp] = assemble_fixture();
    unlink($project->themePath('parts/page-menu--breads.html'));

    assert_throws(function () use ($project) {
        (new AssemblePagesStep())->run($project);
    }, 'missing part');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('assemble-pages throws when the chrome parts are missing', function () {
    [$project, $tmp] = assemble_fixture();
    unlink($project->themePath('parts/header.html'));

    assert_throws(function () use ($project) {
        (new AssemblePagesStep())->run($project);
    });
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('assemble-pages throws when pages.json is empty', function () {
    [$project, $tmp] = assemble_fixture();
    $project->writeJson('pages.json', ['pages' => []]);

    assert_throws(function () use ($project) {
        (new AssemblePagesStep())->run($project);
    }, 'no pages');
    exec('rm -rf ' . escapeshellarg($tmp));
});
