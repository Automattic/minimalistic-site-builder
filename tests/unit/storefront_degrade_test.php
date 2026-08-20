<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\StorefrontDegrade;
use Automattic\SiteBuild\Steps\NormalizeLayoutStep;
use Automattic\SiteBuild\Steps\SiteSpecStep;
use Automattic\SiteBuild\Tests\FakeLlm;

test('shop prompt rules never require invented prices', function () {
    assert_contains(
        'prices only when the user supplied them',
        file_get_contents(repo_path('prompts/site-spec.md')) ?: '',
    );
    assert_contains(
        'prices only when SITE SPEC supplies them',
        file_get_contents(repo_path('prompts/page-plan.md')) ?: '',
    );
});

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

test('StorefrontDegrade keeps rewritten slugs unique across the whole tree', function () {
    [$pages] = StorefrontDegrade::pages([
        ['title' => 'Shop', 'slug' => 'shop', 'purpose' => 'Catalog', 'children' => [
            ['title' => 'Cart', 'slug' => 'cart', 'purpose' => 'Shopping cart', 'children' => []],
        ]],
        ['title' => 'Products', 'slug' => 'products', 'purpose' => 'Catalog', 'children' => []],
        ['title' => 'Catalog', 'slug' => 'catalog', 'purpose' => 'Catalog', 'children' => []],
        ['title' => 'Checkout', 'slug' => 'checkout', 'purpose' => 'Pay for the cart', 'children' => []],
    ]);

    assert_eq('catalog-2', $pages[0]['children'][0]['slug']);
    assert_eq('catalog-3', $pages[3]['slug']);
    $slugs = [
        $pages[0]['slug'],
        $pages[0]['children'][0]['slug'],
        $pages[1]['slug'],
        $pages[2]['slug'],
        $pages[3]['slug'],
    ];
    assert_eq(count($slugs), count(array_unique($slugs)));
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
    assert_contains('kept a catalog storefront', implode(' ', $warnings));
});

test('StorefrontDegrade preserves original markup when a cleanup regex fails', function () {
    $originalLimit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '1000');
    try {
        $markup = '<!-- wp:group --><div><form><input name="quantity">'
            . str_repeat('<span>x</span>', 300)
            . '</form></div><!-- /wp:group -->';
        [$out, $warnings] = StorefrontDegrade::markup($markup, 'theme/parts/shop.html');

        assert_eq($markup, $out, 'a failed pattern never wipes the page');
        assert_contains("file='theme/parts/shop.html'", implode(' ', $warnings));
        assert_contains('delivered="unchanged"', implode(' ', $warnings));
        assert_contains('cart cleanup pattern failed', implode(' ', $warnings));
    } finally {
        if ($originalLimit !== false) {
            ini_set('pcre.backtrack_limit', (string) $originalLimit);
        }
    }
});

test('StorefrontDegrade removes a nested vendor block at its real boundary', function () {
    // A lazy `.*?` under /s stopped at the FIRST closing comment, so the outer
    // block's closer survived as an orphan and broke the page.
    $markup = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:woocommerce/cart --><div class="cart">'
        . '<!-- wp:woocommerce/cart-line-items-block --><div>lines</div><!-- /wp:woocommerce/cart-line-items-block -->'
        . '</div><!-- /wp:woocommerce/cart -->'
        . '<!-- wp:paragraph --><p>Fresh bread daily.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    [$out, $warnings] = StorefrontDegrade::markup($markup, 'theme/parts/shop.html');

    assert_true(!str_contains($out, 'woocommerce'), 'no vendor block or delimiter survives');
    assert_true(!str_contains($out, '/wp:woocommerce'), 'no orphan closing comment is left behind');
    assert_contains('<p>Fresh bread daily.</p>', $out, 'the sibling paragraph is untouched');
    assert_eq(
        substr_count($out, '<!-- wp:group -->'),
        substr_count($out, '<!-- /wp:group -->'),
        'the surrounding group stays balanced',
    );
    assert_contains('removed woocommerce/cart', implode(' ', $warnings));
});

test('StorefrontDegrade leaves an unprovable vendor boundary alone and says so', function () {
    // BlockMarkup::endOffset() returns null exactly so a caller can decline to
    // half-rewrite bytes it cannot delimit.
    $markup = '<!-- wp:woocommerce/cart --><div class="cart">no closing delimiter';
    [$out, $warnings] = StorefrontDegrade::markup($markup, 'theme/parts/shop.html');

    assert_eq($markup, $out);
    assert_contains('delimiter boundary could not be proven', implode(' ', $warnings));
});

test('StorefrontDegrade relabels every purchase CTA, not just add-to-cart', function () {
    foreach (['Add to cart', 'Checkout', 'Buy now', 'Proceed to checkout', 'Place order'] as $label) {
        $markup = '<!-- wp:button --><div class="wp-block-button">'
            . '<a class="wp-block-button__link" href="/shop">' . $label . '</a>'
            . '</div><!-- /wp:button -->';
        [$out] = StorefrontDegrade::markup($markup, 'theme/parts/shop.html');
        assert_contains('Enquire', $out, "'{$label}' is relabelled");
        assert_true(!str_contains(strtolower($out), strtolower($label)), "'{$label}' does not survive");
    }
});

test('StorefrontDegrade points a relabelled CTA at the contact page', function () {
    $markup = '<!-- wp:button --><div class="wp-block-button">'
        . '<a class="wp-block-button__link" href="/cart">Add to cart</a>'
        . '</div><!-- /wp:button -->';
    [$out, $warnings] = StorefrontDegrade::markup($markup, 'theme/parts/shop.html', '/contact');

    assert_contains('Enquire', $out);
    assert_contains('href="/contact"', $out, 'the button no longer points at the cart');
    assert_true(!str_contains($out, '/cart'), 'no cart route survives');
    assert_contains('pointed /cart at /contact', implode(' ', $warnings));
});

test('StorefrontDegrade reports a cart destination it cannot repair', function () {
    // Without a contact page there is nowhere to send an enquiry, so the row
    // names the destination rather than the button silently staying broken.
    $markup = '<!-- wp:button --><div class="wp-block-button">'
        . '<a class="wp-block-button__link" href="/checkout">Buy now</a>'
        . '</div><!-- /wp:button -->';
    [$out, $warnings] = StorefrontDegrade::markup($markup, 'theme/parts/shop.html');

    assert_contains('Enquire', $out);
    $joined = implode(' ', $warnings);
    assert_contains('/checkout', $joined);
    assert_contains('no contact page', $joined);
});

test('StorefrontDegrade markup is idempotent over what it delivered', function () {
    $markup = '<!-- wp:group --><div><form><input name="quantity">'
        . '<!-- wp:button --><div class="wp-block-button">'
        . '<a class="wp-block-button__link" href="/cart">Add to cart</a></div><!-- /wp:button -->'
        . '</form></div><!-- /wp:group -->';
    [$once] = StorefrontDegrade::markup($markup, 'theme/parts/shop.html', '/contact');
    [$twice, $repeat] = StorefrontDegrade::markup($once, 'theme/parts/shop.html', '/contact');
    assert_eq($once, $twice, 'a degraded part degrades unchanged');
    assert_eq([], $repeat);
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

test('site-spec does not warn about a discarded model cart page', function () {
    with_project('builder_cart_pages_', function ($project): void {
        $project->writeJson('meta.json', [
            'prompt' => 'A catalog site',
            'multi_page' => true,
            'pages' => ['Home', 'About'],
            'site_spec' => [
                'name' => 'Catalog',
                'language' => 'en',
                'pages' => [
                    ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Front'],
                    ['title' => 'Checkout', 'slug' => 'checkout', 'purpose' => 'Pay for the cart'],
                ],
            ],
        ]);

        (new SiteSpecStep(new FakeLlm(), new PromptRenderer(repo_path('prompts'))))->run($project);

        assert_eq(['home', 'about'], array_column($project->readJson('siteSpec.json')['pages'], 'slug'));
        assert_true(!$project->exists('warnings.json'));
    });
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
