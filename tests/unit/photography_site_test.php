<?php
declare(strict_types=1);

use Automattic\SiteBuild\PhotographySite;

test('PhotographySite matches a photographer portfolio from area and topic', function () {
    assert_true(PhotographySite::matches([
        'name'        => 'Stillrange',
        'site_type'   => 'portfolio',
        'topic'       => 'fine-art landscape photography',
        'area'        => 'photography',
        'description' => 'A studio for large-format landscape work.',
    ]));
});

test('PhotographySite matches photographer as a word in the user prompt', function () {
    assert_true(PhotographySite::matches(
        ['name' => 'Ada', 'site_type' => 'portfolio', 'area' => 'art'],
        'A minimalist photography portfolio for a fine-art landscape photographer',
    ));
});

test('PhotographySite rejects a bakery even when the copy mentions photos', function () {
    assert_true(!PhotographySite::matches([
        'name'        => 'Hearth & Crumb',
        'site_type'   => 'business storefront',
        'topic'       => 'sourdough bakery',
        'area'        => 'bakery',
        'description' => 'Photos of our loaves and pastry case.',
    ]));
});

test('PhotographySite rejects a bakery even when the description mentions photography', function () {
    assert_true(!PhotographySite::matches([
        'name'        => 'Hearth & Crumb',
        'site_type'   => 'business storefront',
        'topic'       => 'sourdough bakery',
        'area'        => 'bakery',
        'description' => 'Warm photography of the pastry case.',
    ]));
});

test('PhotographySite rejects architecture and food portfolios that are visual work but not photography sites', function () {
    assert_true(!PhotographySite::matches([
        'name'                    => 'Atelier',
        'site_type'               => 'portfolio',
        'topic'                   => 'residential architecture',
        'area'                    => 'architecture',
        'subject_is_visual_work'  => true,
        'description'             => 'Built work and drawings.',
    ]));
    assert_true(!PhotographySite::matches([
        'name'                   => 'Plate',
        'site_type'              => 'portfolio',
        'area'                   => 'food styling',
        'subject_is_visual_work' => true,
    ]));
});

test('PhotographySite matches an art gallery from area and site type', function () {
    assert_true(PhotographySite::matches([
        'name'        => 'Northlight',
        'site_type'   => 'gallery',
        'topic'       => 'contemporary painting',
        'area'        => 'art gallery',
        'description' => 'Exhibitions of living painters.',
    ]));
});

test('PhotographySite matches gallery as a word in the user prompt', function () {
    assert_true(PhotographySite::matches(
        ['name' => 'Vera', 'site_type' => 'portfolio', 'area' => 'art'],
        'A contemporary art gallery in Brooklyn for rotating sculpture shows',
    ));
});

test('PhotographySite rejects a bakery even when the copy mentions a gallery of loaves', function () {
    assert_true(!PhotographySite::matches([
        'name'        => 'Hearth & Crumb',
        'site_type'   => 'business storefront',
        'topic'       => 'sourdough bakery',
        'area'        => 'bakery',
        'description' => 'A gallery of our loaves and pastry case.',
    ]));
});

test('PhotographySite rejects an empty spec', function () {
    assert_true(!PhotographySite::matches([]));
    assert_true(!PhotographySite::matches(['name' => 'Solo']));
});

