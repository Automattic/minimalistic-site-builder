<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\StorefrontDegrade;
use Automattic\SiteBuild\Steps\NormalizeLayoutStep;
use Automattic\SiteBuild\Steps\SiteSpecStep;

test('StorefrontDegrade rewrites a cart page to a catalog storefront', function () {
    [$pages, $warnings] = StorefrontDegrade::pages([
        ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Front', 'children' => []],
        ['title' => 'Cart', 'slug' => 'cart', 'purpose' => 'Shopping cart and checkout', 'children' => []],
    ]);
    $bySlug = array_column($pages, 'title', 'slug');
    assert_eq('Home', $bySlug['home']);
    assert_true(isset($bySlug['shop']));
    assert_eq('Shop', $bySlug['shop']);
    assert_true(!isset($bySlug['cart']));
    assert_contains('catalog storefront', implode(' ', $warnings));
});

test('StorefrontDegrade page rewrite reaches a fixed point', function () {
    [$once] = StorefrontDegrade::pages([
        ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Front', 'children' => []],
        ['title' => 'Cart', 'slug' => 'cart', 'purpose' => 'Shopping cart and checkout', 'children' => []],
    ]);
    [$twice, $warnings] = StorefrontDegrade::pages($once);

    assert_eq($once, $twice);
    assert_eq([], $warnings);
});

test('StorefrontDegrade strips add-to-cart forms from markup', function () {
    $markup = '<!-- wp:group --><div class="wp-block-group">'
        . '<form><input type="number" name="quantity" />'
        . '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link">Add to cart</a></div><!-- /wp:button -->'
        . '</form></div><!-- /wp:group -->';
    [$out, $warnings] = StorefrontDegrade::markup($markup, 'theme/parts/shop.html');
    assert_true(!str_contains(strtolower($out), '<form'));
    assert_true(!str_contains(strtolower($out), '<input'));
    assert_contains('Enquire', $out);
    assert_contains('stripped unbuildable cart', implode(' ', $warnings));
});

test('site-spec normalizePages degrades a cart page to a catalog storefront', function () {
    $warnings = [];
    $pages = SiteSpecStep::normalizePages(
        [
            ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Front'],
            ['title' => 'Checkout', 'slug' => 'checkout', 'purpose' => 'Pay for the cart'],
        ],
        ['description' => 'A shop'],
        true,
        $warnings,
    );
    assert_eq('shop', $pages[1]['slug']);
    assert_contains('checkout', implode(' ', $warnings));
});

test('normalize-layout degrades cart markup on a part and records a warning', function () {
    $tmp = sys_get_temp_dir() . '/builder_cart_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('theme/theme.json', ['settings' => ['color' => ['palette' => []]]]);
    $project->writeText(
        'theme/parts/shop.html',
        '<!-- wp:group --><div class="wp-block-group"><form><input name="quantity" />'
        . '<a class="wp-block-button__link">Add to cart</a></form></div><!-- /wp:group -->',
    );

    (new NormalizeLayoutStep())->run($project);

    $out = $project->readText('theme/parts/shop.html');
    assert_true(!str_contains(strtolower($out), '<form'));
    $joined = implode(' ', $project->readJson('warnings.json')['normalize-layout'] ?? []);
    assert_contains('cart', $joined);

    exec('rm -rf ' . escapeshellarg($tmp));
});
