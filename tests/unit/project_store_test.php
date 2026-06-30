<?php
declare(strict_types=1);

test('slugify lowercases, hyphenates and trims', function () {
    assert_eq('tbilisi-tavern', ProjectStore::slugify('  Tbilisi Tavern!! '));
    assert_eq('site', ProjectStore::slugify('   '));
    assert_eq('naturaleza-sabia', ProjectStore::slugify('Naturaleza Sabia'));
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

test('freeSlug slugifies its input before checking, like create()', function () {
    $base = sys_get_temp_dir() . '/builder-store-' . getmypid() . '-' . uniqid();
    mkdir($base . '/tbilisi-tavern', 0775, true);
    $store = new ProjectStore($base);

    // "Tbilisi Tavern" slugifies to the existing folder, so the next free wins.
    assert_eq('tbilisi-tavern2', $store->freeSlug('Tbilisi Tavern'));

    rmdir($base . '/tbilisi-tavern');
    rmdir($base);
});
