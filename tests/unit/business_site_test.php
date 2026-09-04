<?php
declare(strict_types=1);

use Automattic\SiteBuild\BusinessSite;

test('BusinessSite matches a bakery storefront', function () {
    assert_true(BusinessSite::matches([
        'name'      => 'Hearth & Crumb',
        'site_type' => 'business storefront',
        'topic'     => 'artisan bread and pastries',
        'area'      => 'bakery',
    ]));
});

test('BusinessSite matches restaurant, cafe, café, salon, firm, hotel', function () {
    foreach ([
        'restaurant', 'cafe', 'café', 'hair salon', 'consulting firm', 'hotel',
        'storefront', 'shop', 'store', 'retail', 'bakery', 'bar', 'spa', 'clinic',
        'gym', 'studio', 'agency', 'consultancy', 'saas', 'boutique',
    ] as $area) {
        assert_true(BusinessSite::matches(['area' => $area, 'site_type' => '']), "area={$area}");
    }
});

test('BusinessSite matches an all-caps café title via Unicode lowercasing', function () {
    assert_true(BusinessSite::matches(['title' => 'CAFÉ MODERNE']));
});

test('BusinessSite rejects an empty spec', function () {
    assert_true(!BusinessSite::matches([]));
    assert_true(!BusinessSite::matches(['name' => 'Solo']));
});

test('BusinessSite rejects a personal site with persona_name', function () {
    assert_true(!BusinessSite::matches([
        'name'         => 'Ada',
        'persona_name' => 'Ada Lovelace',
        'site_type'    => 'portfolio',
        'area'         => 'studio',
    ]));
});

test('BusinessSite rejects portfolio, blog, landing page', function () {
    assert_true(!BusinessSite::matches(['site_type' => 'portfolio', 'area' => 'art']));
    assert_true(!BusinessSite::matches(['site_type' => 'blog', 'topic' => 'essays']));
    assert_true(!BusinessSite::matches(['site_type' => 'landing page', 'topic' => 'product launch']));
});

test('BusinessSite rejects photography and gallery via PhotographySite', function () {
    assert_true(!BusinessSite::matches([
        'name'      => 'Stillrange',
        'site_type' => 'portfolio',
        'topic'     => 'fine-art landscape photography',
        'area'      => 'photography',
    ]));
    assert_true(!BusinessSite::matches([
        'name'      => 'Northlight',
        'site_type' => 'gallery',
        'area'      => 'art gallery',
    ]));
});

test('BusinessSite rejects a studio whose only photographer signal is the prompt', function () {
    assert_true(!BusinessSite::matches(
        ['name' => 'Ada', 'site_type' => 'studio', 'area' => 'studio'],
        'A minimalist photography portfolio for a fine-art landscape photographer',
    ));
});

test('BusinessSite still matches a bakery whose prompt asks for photographic imagery', function () {
    assert_true(BusinessSite::matches(
        [
            'name'         => 'Fantinel',
            'title'        => 'Fantinel',
            'site_type'    => '',
            'topic'        => 'Bakery',
            'area'         => 'business',
            'persona_name' => '',
        ],
        'Fantinel is a professional New York City bakery website with a green color scheme '
        . 'and photographic imagery. All images are to be photographic in style.',
    ));
});

test('BusinessSite does not match topic prose that only says services', function () {
    assert_true(!BusinessSite::matches([
        'site_type' => 'portfolio',
        'topic'     => 'design services for nonprofits',
        'area'      => 'advocacy',
    ]));
});
