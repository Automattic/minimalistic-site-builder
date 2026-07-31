<?php
declare(strict_types=1);

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;

test('slugify lowercases, hyphenates and trims', function () {
    assert_eq('tbilisi-tavern', ProjectStore::slugify('  Tbilisi Tavern!! '));
    assert_eq('site', ProjectStore::slugify('   '));
    assert_eq('naturaleza-sabia', ProjectStore::slugify('Naturaleza Sabia'));
});

test('randomSlug is a short, slug-safe two-word name', function () {
    for ($i = 0; $i < 50; $i++) {
        $slug = ProjectStore::randomSlug();
        // adjective-noun, all lowercase alnum + a single hyphen, and stable
        // under slugify() (so create()/freeSlug() never rewrite it).
        assert_true((bool) preg_match('/^[a-z]+-[a-z]+$/', $slug), "unexpected slug: {$slug}");
        assert_eq($slug, ProjectStore::slugify($slug));
    }
});

test('freeSlug returns the base slug when its folder is free', function () {
    $base = sys_get_temp_dir() . '/builder-store-' . getmypid() . '-' . uniqid();
    mkdir($base, 0775, true);
    $store = new ProjectStore($base);

    assert_eq('tbilisi-tavern', $store->freeSlug('tbilisi-tavern'));

    rmdir($base);
});

test('freeSlug appends 2, 3, 4 … against existing folders', function () {
    $base = sys_get_temp_dir() . '/builder-store-' . getmypid() . '-' . uniqid();
    mkdir($base . '/tbilisi-tavern', 0775, true);
    $store = new ProjectStore($base);

    assert_eq('tbilisi-tavern2', $store->freeSlug('tbilisi-tavern'));

    mkdir($base . '/tbilisi-tavern2', 0775, true);
    assert_eq('tbilisi-tavern3', $store->freeSlug('tbilisi-tavern'));

    mkdir($base . '/tbilisi-tavern3', 0775, true);
    assert_eq('tbilisi-tavern4', $store->freeSlug('tbilisi-tavern'));

    rmdir($base . '/tbilisi-tavern3');
    rmdir($base . '/tbilisi-tavern2');
    rmdir($base . '/tbilisi-tavern');
    rmdir($base);
});

test('claimNew creates the base dir, then claims the next suffix when taken', function () {
    $base = sys_get_temp_dir() . '/builder-store-' . getmypid() . '-' . uniqid();
    mkdir($base, 0775, true);
    $store = new ProjectStore($base);

    $first = $store->claimNew('Tbilisi Tavern');
    assert_eq('tbilisi-tavern', $first->slug());
    assert_true(is_dir($base . '/tbilisi-tavern'));

    $second = $store->claimNew('tbilisi-tavern');
    assert_eq('tbilisi-tavern2', $second->slug());

    rmdir($base . '/tbilisi-tavern2');
    rmdir($base . '/tbilisi-tavern');
    rmdir($base);
});

test('freeSlug slugifies its input before checking, like create()', function () {
    $base = sys_get_temp_dir() . '/builder-store-' . getmypid() . '-' . uniqid();
    mkdir($base . '/tbilisi-tavern', 0775, true);
    $store = new ProjectStore($base);

    // "Tbilisi Tavern" slugifies to the existing folder, so the next free wins.
    assert_eq('tbilisi-tavern2', $store->freeSlug('Tbilisi Tavern'));

    rmdir($base . '/tbilisi-tavern');
    rmdir($base);
});

test('markupFiles collects theme parts, templates, and plugin pages', function () {
    $base = sys_get_temp_dir() . '/builder-store-' . getmypid() . '-' . uniqid();
    $project = (new ProjectStore($base))->create('demo');
    $project->writeText('theme/parts/header.html', '<!-- wp:site-title /-->');
    $project->writeText('theme/templates/page.html', '<!-- wp:post-content /-->');
    $project->writeText('plugin/pages/home.html', '<!-- wp:heading --><h2>x</h2><!-- /wp:heading -->');
    $project->writeText('plugin/pages.json', '{}');            // not markup — excluded
    $project->writeText('theme/parts/notes.txt', 'not markup'); // wrong extension — excluded

    $names = array_map('basename', $project->markupFiles());
    sort($names);
    assert_eq(['header.html', 'home.html', 'page.html'], $names);

    exec('rm -rf ' . escapeshellarg($base));
});

test('Project::path refuses to resolve outside the project', function () {
    $project = new Project(sys_get_temp_dir() . '/project-containment-' . getmypid());

    foreach (['../evil', 'theme/assets/fonts/../../../evil', "theme/x\0.php"] as $rel) {
        assert_throws(
            static fn () => $project->path($rel),
            'escaping path rejected: ' . $rel
        );
    }

    // Ordinary paths — including the leading slash callers sometimes pass and
    // the dots inside a filename — keep working.
    assert_eq($project->root, $project->path());
    assert_eq($project->root . '/theme/style.css', $project->path('theme/style.css'));
    assert_eq($project->root . '/theme/style.css', $project->path('/theme/style.css'));
    assert_eq($project->root . '/theme/assets/fonts/inter-400.woff2', $project->themePath('assets/fonts/inter-400.woff2'));
    assert_eq($project->root . '/plugin/pages/home.html', $project->pluginPath('pages/home.html'));
    // Dots inside a filename are not a traversal — this is what segment-wise
    // checking buys over a naive str_contains($rel, '..').
    assert_eq($project->root . '/theme/re..entry.css', $project->path('theme/re..entry.css'));

    // exists() is a predicate its callers use to skip work, so an uncontained
    // path answers false instead of aborting their step.
    assert_true(!$project->exists('../outside.txt'), 'exists() degrades to false');

    // logPath() builds its own path after creating logs/, so it has to route
    // through the same guard rather than concatenating past it.
    $logged = new Project(sys_get_temp_dir() . '/project-containment-logs-' . getmypid());
    try {
        assert_throws(static fn () => $logged->logPath('../escaped.log'), 'logPath is contained too');
        assert_eq($logged->root . '/logs/fix-blocks.log', $logged->logPath('fix-blocks.log'));
    } finally {
        exec('rm -rf ' . escapeshellarg($logged->root));
    }
});
