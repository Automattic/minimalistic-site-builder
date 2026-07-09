<?php
declare(strict_types=1);

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
