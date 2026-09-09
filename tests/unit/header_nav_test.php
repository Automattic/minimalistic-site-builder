<?php
declare(strict_types=1);

use Automattic\SiteBuild\HeaderNav;
use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\Steps\HeaderHeroStep;
use Automattic\SiteBuild\AboveFoldContract;

/**
 * The header's site title / logo already link home. A Home item in the
 * primary nav is redundant (BIGR-863).
 *
 * @return list<array<string,mixed>>
 */
function header_nav_pages(): array
{
    return [
        ['title' => 'Home', 'slug' => 'home', 'path' => '/', 'front' => true],
        ['title' => 'Menu', 'slug' => 'menu', 'path' => '/menu/', 'front' => false],
        ['title' => 'Visit', 'slug' => 'visit', 'path' => '/visit/', 'front' => false],
    ];
}

test('HeaderNav removes a wp:home-link and keeps inner-page links', function () {
    $markup = '<!-- wp:navigation -->'
        . '<!-- wp:home-link /-->'
        . '<!-- wp:navigation-link {"label":"Menu","url":"/menu/","kind":"custom"} /-->'
        . '<!-- /wp:navigation -->';

    $result = HeaderNav::withoutHomeItems($markup, header_nav_pages());

    assert_true(!str_contains($result['markup'], 'wp:home-link'), 'home-link block is gone');
    assert_contains('{"label":"Menu","url":"/menu/","kind":"custom"}', $result['markup']);
    assert_true($result['notes'] !== [], 'the removal is a recorded repair');
    assert_eq(
        HeaderNav::withoutHomeItems($result['markup'], header_nav_pages())['markup'],
        $result['markup'],
        'a second pass is a fixed point',
    );
});

test('HeaderNav removes a Home navigation-link and keeps a brand link to /', function () {
    $markup = '<!-- wp:navigation -->'
        . '<!-- wp:navigation-link {"label":"Home","url":"/","kind":"custom"} /-->'
        . '<!-- wp:navigation-link {"label":"Hearth & Crumb","url":"/","kind":"custom"} /-->'
        . '<!-- wp:navigation-link {"label":"Menu","url":"/menu/","kind":"custom"} /-->'
        . '<!-- /wp:navigation -->';

    $result = HeaderNav::withoutHomeItems($markup, header_nav_pages());

    assert_true(!str_contains($result['markup'], '"label":"Home"'), 'the Home item is gone');
    assert_contains('"label":"Hearth & Crumb"', $result['markup'], 'brand identity in the nav stays');
    assert_contains('"label":"Menu"', $result['markup']);
});

test('HeaderNav matches the front page title in another language', function () {
    $pages = [
        ['title' => 'Inicio', 'slug' => 'home', 'path' => '/', 'front' => true],
        ['title' => 'Carta', 'slug' => 'carta', 'path' => '/carta/', 'front' => false],
    ];
    $markup = '<!-- wp:navigation -->'
        . '<!-- wp:navigation-link {"label":"Inicio","url":"/","kind":"custom"} /-->'
        . '<!-- wp:navigation-link {"label":"Carta","url":"/carta/","kind":"custom"} /-->'
        . '<!-- /wp:navigation -->';

    $result = HeaderNav::withoutHomeItems($markup, $pages);

    assert_true(!str_contains($result['markup'], '"label":"Inicio"'));
    assert_contains('"label":"Carta"', $result['markup']);
});

test('HeaderNav keeps a homepage section jump whose label is not the front page title', function () {
    $markup = '<!-- wp:navigation -->'
        . '<!-- wp:navigation-link {"label":"Menu","url":"/#menu","kind":"custom"} /-->'
        . '<!-- /wp:navigation -->';

    $result = HeaderNav::withoutHomeItems($markup, header_nav_pages());

    assert_eq($markup, $result['markup']);
    assert_eq([], $result['notes']);
});

test('HeaderNav does not touch site-title or site-logo', function () {
    $markup = '<!-- wp:site-logo /-->'
        . '<!-- wp:site-title /-->'
        . '<!-- wp:navigation -->'
        . '<!-- wp:navigation-link {"label":"Home","url":"/","kind":"custom"} /-->'
        . '<!-- /wp:navigation -->';

    $result = HeaderNav::withoutHomeItems($markup, header_nav_pages());

    assert_contains('<!-- wp:site-logo /-->', $result['markup']);
    assert_contains('<!-- wp:site-title /-->', $result['markup']);
    assert_true(!str_contains($result['markup'], '"label":"Home"'));
});

test('HeaderNav replaces a header page-list with inner-page links', function () {
    $markup = '<!-- wp:navigation -->'
        . '<!-- wp:page-list /-->'
        . '<!-- /wp:navigation -->';

    $result = HeaderNav::withoutHomeItems($markup, header_nav_pages());

    assert_true(!str_contains($result['markup'], 'wp:page-list'), 'page-list would render Home at runtime');
    assert_true(!str_contains($result['markup'], '"label":"Home"'));
    assert_contains('{"label":"Menu","url":"/menu/","kind":"custom"}', $result['markup']);
    assert_contains('{"label":"Visit","url":"/visit/","kind":"custom"}', $result['markup']);
});

test('HeaderNav serializes replaced page-list labels the way Gutenberg comments require', function () {
    $pages = [
        ['title' => 'Home', 'slug' => 'home', 'path' => '/', 'front' => true],
        ['title' => 'A -- B <i>', 'slug' => 'ab', 'path' => '/a-b/', 'front' => false],
    ];
    $markup = '<!-- wp:navigation --><!-- wp:page-list /--><!-- /wp:navigation -->';

    $result = HeaderNav::withoutHomeItems($markup, $pages);

    assert_true(!str_contains($result['markup'], 'wp:page-list'));
    assert_true(!str_contains($result['markup'], 'A -- B'), 'double hyphen stays escaped in the comment JSON');
    assert_contains('\\u002d\\u002d', $result['markup']);
    assert_contains('\\u003C', $result['markup']);
    assert_contains('<!-- wp:navigation-link', $result['markup']);
});

test('HeaderNav replacing a nested page-list does not eat the following sibling', function () {
    $markup = '<!-- wp:navigation -->'
        . '<!-- wp:page-list -->'
        . '<!-- wp:navigation-link {"label":"Home","url":"/","kind":"custom"} /-->'
        . '<!-- /wp:page-list -->'
        . '<!-- /wp:navigation -->'
        . '<!-- wp:paragraph --><p>keep me</p><!-- /wp:paragraph -->';

    $result = HeaderNav::withoutHomeItems($markup, header_nav_pages());

    assert_true(!str_contains($result['markup'], 'wp:page-list'));
    assert_contains('<p>keep me</p>', $result['markup']);
    assert_contains('"label":"Menu"', $result['markup']);
});

test('HeaderNav does not re-emit an inner page whose title matches the front page', function () {
    $pages = [
        ['title' => 'Home', 'slug' => 'home', 'path' => '/', 'front' => true],
        ['title' => 'Home', 'slug' => 'home-again', 'path' => '/home-again/', 'front' => false],
        ['title' => 'Menu', 'slug' => 'menu', 'path' => '/menu/', 'front' => false],
    ];
    $markup = '<!-- wp:navigation --><!-- wp:page-list /--><!-- /wp:navigation -->';

    $result = HeaderNav::withoutHomeItems($markup, $pages);
    $again = HeaderNav::withoutHomeItems($result['markup'], $pages);

    assert_eq($result['markup'], $again['markup']);
    assert_true(!str_contains($result['markup'], '"url":"/home-again/"'));
    assert_contains('"label":"Menu"', $result['markup']);
});

test('HeaderNav drops a one-page page-list instead of leaving a self Home link', function () {
    $pages = [
        ['title' => 'Home', 'slug' => 'home', 'path' => '/', 'front' => true],
    ];
    $markup = '<!-- wp:navigation --><!-- wp:page-list /--><!-- /wp:navigation -->';

    $result = HeaderNav::withoutHomeItems($markup, $pages);

    assert_true(!str_contains($result['markup'], 'wp:page-list'));
    assert_true(!str_contains($result['markup'], 'wp:navigation-link'));
});

test('HeaderNav leaves an unprovable home-link boundary and records a warning', function () {
    $markup = '<!-- wp:navigation --><!-- wp:home-link --><!-- /wp:navigation -->';

    $result = HeaderNav::withoutHomeItems($markup, header_nav_pages());

    assert_contains('wp:home-link', $result['markup']);
    assert_eq([], $result['notes']);
    assert_true($result['warnings'] !== []);
    assert_contains('delivered=unchanged', $result['warnings'][0]);
    assert_contains('could not be proven', $result['warnings'][0]);
});

test('HeaderNav strips a Home anchor from HTML nav and keeps the brand and inner page', function () {
    $markup = '<header><a href="/">Hearth &amp; Crumb</a>'
        . '<nav><ul>'
        . '<li><a href="/">Home</a></li>'
        . '<li><a href="/menu/">Menu</a></li>'
        . '</ul></nav></header>';

    $result = HeaderNav::withoutHomeItems($markup, header_nav_pages());

    assert_contains('<a href="/">Hearth &amp; Crumb</a>', $result['markup']);
    assert_contains('<a href="/menu/">Menu</a>', $result['markup']);
    assert_true(!str_contains($result['markup'], '>Home</a>'));
    assert_true(!str_contains($result['markup'], '<li></li>'));
});

test('HeaderNav strips a Home item from an HTML-first flex row that is not a nav landmark', function () {
    $markup = '<!-- wp:group --><div class="wp-block-group site-nav">'
        . '<a class="brand" href="/">Northstar</a>'
        . '<div class="navlinks">'
        . '<p class="blocks-engine-synthetic-paragraph"><a class="active" href="/">Home</a></p>'
        . '<p class="blocks-engine-synthetic-paragraph"><a href="/menu/">Menu</a></p>'
        . '</div></div><!-- /wp:group -->';

    $result = HeaderNav::withoutHomeItems($markup, header_nav_pages());

    assert_contains('<a class="brand" href="/">Northstar</a>', $result['markup']);
    assert_contains('<a href="/menu/">Menu</a>', $result['markup']);
    assert_true(!str_contains($result['markup'], '>Home</a>'));
    assert_true(!str_contains($result['markup'], 'blocks-engine-synthetic-paragraph"><a class="active"'));
});

test('HeaderNav strips a transformed navigation-link whose label is wrapped HTML', function () {
    $label = json_encode(
        '<span data-blocks-engine-richtext-marker="x">Home</span>',
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
    );
    $markup = '<!-- wp:navigation -->'
        . '<!-- wp:navigation-link {"label":' . $label . ',"url":"/","kind":"custom"} /-->'
        . '<!-- wp:navigation-link {"label":"About","url":"/about/","kind":"custom"} /-->'
        . '<!-- /wp:navigation -->';

    $result = HeaderNav::withoutHomeItems($markup, header_nav_pages());

    assert_true(!str_contains($result['markup'], '"url":"/"'), 'the Home navigation-link is gone');
    assert_contains('"label":"About"', $result['markup']);
});

test('HeaderNav does not strip a site-title that happens to render the word Home', function () {
    $markup = '<!-- wp:site-title -->'
        . '<h1 class="wp-block-site-title"><a href="/">Home</a></h1>'
        . '<!-- /wp:site-title -->'
        . '<!-- wp:navigation --><!-- wp:navigation-link {"label":"Home","url":"/","kind":"custom"} /--><!-- /wp:navigation -->';

    $result = HeaderNav::withoutHomeItems($markup, header_nav_pages());

    assert_contains('<h1 class="wp-block-site-title"><a href="/">Home</a></h1>', $result['markup']);
    assert_true(!str_contains($result['markup'], 'wp:navigation-link'));
});

test('fixHeader applies HeaderNav before measuring the row', function () {
    $inner = '<!-- wp:site-title /--><!-- wp:navigation -->'
        . '<!-- wp:page-list /-->'
        . '<!-- /wp:navigation -->';
    $markup = '<!-- wp:group {"backgroundColor":"base","layout":{"type":"constrained"}} -->' . "\n"
        . '<div class="wp-block-group">' . $inner . '</div>' . "\n"
        . '<!-- /wp:group -->';
    $pages = header_nav_pages();

    $result = HeaderHeroStep::fixHeader(
        $markup,
        AboveFoldContract::MODE_STACKED,
        'Demo',
        array_map(static fn (array $p): string => (string) $p['title'], $pages),
        false,
        '',
        '',
        '',
        null,
        [],
        $pages,
    );

    assert_true(!str_contains($result['markup'], 'wp:page-list'));
    assert_true(!str_contains($result['markup'], '"label":"Home"'));
    assert_contains('"label":"Menu"', $result['markup']);
});

test('HeaderNav strips Home from footer nav and names the footer in repairs', function () {
    $markup = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:site-title /-->'
        . '<!-- wp:navigation -->'
        . '<!-- wp:page-list /-->'
        . '<!-- /wp:navigation -->'
        . '</div><!-- /wp:group -->';

    $result = HeaderNav::withoutHomeItems($markup, header_nav_pages(), 'footer');

    assert_contains('<!-- wp:site-title /-->', $result['markup']);
    assert_true(!str_contains($result['markup'], 'wp:page-list'));
    assert_true(!str_contains($result['markup'], '"label":"Home"'));
    assert_contains('"label":"Menu"', $result['markup']);
    assert_contains('"label":"Visit"', $result['markup']);
    assert_contains('footer navigation', $result['notes'][0]);
    assert_true(!str_contains(implode("\n", $result['notes']), 'header navigation'));
});

test('HeaderNav does not strip a wordmark whose label is the site name even when that is the front title', function () {
    $pages = [
        ['title' => 'Northstar', 'slug' => 'home', 'path' => '/', 'front' => true],
        ['title' => 'Menu', 'slug' => 'menu', 'path' => '/menu/', 'front' => false],
    ];
    $markup = '<header><a class="brand" href="/">Northstar</a>'
        . '<nav><a href="/">Northstar</a><a href="/menu/">Menu</a></nav></header>';

    $withoutName = HeaderNav::withoutHomeItems($markup, $pages);
    assert_true(!str_contains($withoutName['markup'], '>Northstar</a>'), 'front title matching the brand is Home without a site name');

    $result = HeaderNav::withoutHomeItems($markup, $pages, 'header', 'Northstar');

    assert_contains('<a class="brand" href="/">Northstar</a>', $result['markup']);
    assert_contains('<a href="/">Northstar</a>', $result['markup']);
    assert_contains('<a href="/menu/">Menu</a>', $result['markup']);
});

test('HeaderNav keeps a front-title navigation-link that does not point home', function () {
    $markup = '<!-- wp:navigation -->'
        . '<!-- wp:navigation-link {"label":"Home","url":"/menu/","kind":"custom"} /-->'
        . '<!-- /wp:navigation -->';

    $result = HeaderNav::withoutHomeItems($markup, header_nav_pages());

    assert_contains('"label":"Home"', $result['markup']);
    assert_contains('"url":"/menu/"', $result['markup']);
    assert_eq([], $result['notes']);
});

test('HeaderNav splits inner pages across two page-lists instead of duplicating them', function () {
    $markup = '<!-- wp:navigation -->'
        . '<!-- wp:page-list /-->'
        . '<!-- /wp:navigation -->'
        . '<!-- wp:site-title /-->'
        . '<!-- wp:navigation -->'
        . '<!-- wp:page-list /-->'
        . '<!-- /wp:navigation -->';

    $result = HeaderNav::withoutHomeItems($markup, header_nav_pages());

    assert_true(!str_contains($result['markup'], 'wp:page-list'));
    assert_eq(1, substr_count($result['markup'], '"label":"Menu"'));
    assert_eq(1, substr_count($result['markup'], '"label":"Visit"'));
    $menuAt = strpos($result['markup'], '"label":"Menu"');
    $visitAt = strpos($result['markup'], '"label":"Visit"');
    $titleAt = strpos($result['markup'], 'wp:site-title');
    assert_true($menuAt !== false && $visitAt !== false && $titleAt !== false);
    assert_true($menuAt < $titleAt && $titleAt < $visitAt, 'left nav gets the first inner page, right nav the rest');
});

test('HeaderNav unwraps a Home navigation parent and keeps nested destinations', function () {
    $markup = '<!-- wp:navigation -->'
        . '<!-- wp:navigation-link {"label":"Home","url":"/","kind":"custom"} -->'
        . '<!-- wp:navigation-link {"label":"Menu","url":"/menu/","kind":"custom"} /-->'
        . '<!-- /wp:navigation-link -->'
        . '<!-- /wp:navigation -->';

    $result = HeaderNav::withoutHomeItems($markup, header_nav_pages());

    assert_contains('"label":"Menu"', $result['markup']);
    assert_true(!str_contains($result['markup'], '"label":"Home"'));
    assert_contains('unwrapped Home', $result['notes'][0]);
});

test('HeaderNav footer warnings point at the footer part', function () {
    $markup = '<!-- wp:navigation --><!-- wp:home-link --><!-- /wp:navigation -->';

    $result = HeaderNav::withoutHomeItems($markup, header_nav_pages(), 'footer');

    assert_contains('wp:home-link', $result['markup']);
    assert_contains("file='theme/parts/footer.html'", $result['warnings'][0]);
});

test('HeaderNav fills an empty header navigation with every inner page (BIGR-872)', function () {
    $markup = '<!-- wp:site-title /-->'
        . '<!-- wp:navigation -->'
        . '<!-- /wp:navigation -->';

    $result = HeaderNav::withCompleteInnerPages($markup, header_nav_pages());

    assert_contains('{"label":"Menu","url":"/menu/","kind":"custom"}', $result['markup']);
    assert_contains('{"label":"Visit","url":"/visit/","kind":"custom"}', $result['markup']);
    assert_true(!str_contains($result['markup'], '"label":"Home"'), 'Home stays out of the filled nav');
    assert_true($result['notes'] !== [], 'the fill is a recorded repair');
    assert_eq(
        HeaderNav::withCompleteInnerPages($result['markup'], header_nav_pages())['markup'],
        $result['markup'],
        'a second pass is a fixed point',
    );
});

test('HeaderNav fills a void header navigation with inner-page links', function () {
    $markup = '<!-- wp:site-title /--><!-- wp:navigation /-->';

    $result = HeaderNav::withCompleteInnerPages($markup, header_nav_pages());

    assert_true(!str_contains($result['markup'], '<!-- wp:navigation /-->'), 'the void nav is opened');
    assert_contains('"url":"/menu/"', $result['markup']);
    assert_contains('"url":"/visit/"', $result['markup']);
    assert_contains('<!-- /wp:navigation -->', $result['markup']);
});

test('HeaderNav only adds the inner pages a partial header nav is missing', function () {
    $markup = '<!-- wp:navigation -->'
        . '<!-- wp:navigation-link {"label":"Menu","url":"/menu/","kind":"custom"} /-->'
        . '<!-- /wp:navigation -->';

    $result = HeaderNav::withCompleteInnerPages($markup, header_nav_pages());

    assert_eq(1, substr_count($result['markup'], '"url":"/menu/"'), 'existing Menu is not duplicated');
    assert_contains('"url":"/visit/"', $result['markup']);
    assert_contains('"label":"Visit"', $result['markup']);
});

test('HeaderNav inserts a header navigation after the site title when the bar has none', function () {
    $markup = '<!-- wp:group {"layout":{"type":"flex","justifyContent":"space-between"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:site-title /-->'
        . '</div><!-- /wp:group -->';

    $result = HeaderNav::withCompleteInnerPages($markup, header_nav_pages());

    $titleAt = strpos($result['markup'], 'wp:site-title');
    $navAt = strpos($result['markup'], 'wp:navigation');
    assert_true($titleAt !== false && $navAt !== false && $titleAt < $navAt, 'nav follows the wordmark');
    assert_contains('"url":"/menu/"', $result['markup']);
    assert_contains('"url":"/visit/"', $result['markup']);
    assert_contains('inserted header navigation', implode("\n", $result['notes']));
});

test('HeaderNav fills an empty HTML header nav with inner-page anchors', function () {
    $markup = '<a class="brand" href="/">Atelier</a><nav aria-label="Primary"></nav>';

    $result = HeaderNav::withCompleteInnerPages($markup, header_nav_pages(), 'header', 'Atelier');

    assert_contains('<a href="/menu/">Menu</a>', $result['markup']);
    assert_contains('<a href="/visit/">Visit</a>', $result['markup']);
    assert_contains('<a class="brand" href="/">Atelier</a>', $result['markup']);
});

test('HeaderNav does not invent header links on a one-page site', function () {
    $pages = [
        ['title' => 'Home', 'slug' => 'home', 'path' => '/', 'front' => true],
    ];
    $markup = '<!-- wp:site-title /--><!-- wp:navigation --><!-- /wp:navigation -->';

    $result = HeaderNav::withCompleteInnerPages($markup, $pages);

    assert_eq($markup, $result['markup']);
    assert_eq([], $result['notes']);
});

test('HeaderNav does not fill footer navigation (BIGR-872 is header-only)', function () {
    $markup = '<!-- wp:navigation --><!-- /wp:navigation -->';

    $result = HeaderNav::withCompleteInnerPages($markup, header_nav_pages(), 'footer');

    assert_eq($markup, $result['markup']);
    assert_eq([], $result['notes']);
});

test('HeaderNav does not duplicate an inner page already present as an HTML nav anchor', function () {
    $markup = '<!-- wp:navigation -->'
        . '<nav><ul><li><a href="/about/">About</a></li></ul></nav>'
        . '<!-- /wp:navigation -->';
    $pages = [
        ['title' => 'Home', 'slug' => 'home', 'path' => '/', 'front' => true],
        ['title' => 'About', 'slug' => 'about', 'path' => '/about/', 'front' => false],
    ];

    $result = HeaderNav::withCompleteInnerPages($markup, $pages);

    assert_eq($markup, $result['markup']);
    assert_eq([], $result['notes']);
});

test('HeaderNav adds a missing inner page to split-nav once, not in both halves', function () {
    $markup = '<!-- wp:navigation -->'
        . '<!-- wp:navigation-link {"label":"Menu","url":"/menu/","kind":"custom"} /-->'
        . '<!-- /wp:navigation -->'
        . '<!-- wp:site-title /-->'
        . '<!-- wp:navigation -->'
        . '<!-- /wp:navigation -->';

    $result = HeaderNav::withCompleteInnerPages($markup, header_nav_pages());

    assert_eq(1, substr_count($result['markup'], '"url":"/menu/"'));
    assert_eq(1, substr_count($result['markup'], '"url":"/visit/"'));
});

test('header-hero fills a header that shipped with no inner-page links (BIGR-872)', function () {
    $pages = header_nav_pages();
    $markup = '<!-- wp:group {"backgroundColor":"base","layout":{"type":"constrained"}} -->' . "\n"
        . '<div class="wp-block-group"><!-- wp:site-title /--></div>' . "\n"
        . '<!-- /wp:group -->';

    $result = HeaderHeroStep::fixHeader(
        $markup,
        AboveFoldContract::MODE_STACKED,
        'Demo',
        array_map(static fn (array $p): string => (string) $p['title'], $pages),
        false,
        '',
        '',
        '',
        null,
        [],
        $pages,
    );

    assert_contains('"url":"/menu/"', $result['markup']);
    assert_contains('"url":"/visit/"', $result['markup']);
    assert_true(!str_contains($result['markup'], '"label":"Home"'));
});

test('HeaderNav leaves an unprovable navigation alone and warns (BIGR-872)', function () {
    // A truncated wp:navigation: no closing delimiter, so its span cannot be
    // proven. Filling next to it would ship two navs and one hamburger each.
    $markup = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:site-title /-->'
        . '<!-- wp:navigation -->'
        . '<!-- wp:navigation-link {"label":"Menu","url":"/menu/","kind":"custom"} /-->'
        . '</div><!-- /wp:group -->';

    $result = HeaderNav::withCompleteInnerPages($markup, header_nav_pages());

    assert_eq($markup, $result['markup'], 'the unprovable nav is left untouched');
    assert_eq([], $result['notes']);
    assert_true($result['warnings'] !== [], 'the skipped fill is a recorded warning');
    assert_contains('wp:navigation', implode("\n", $result['warnings']));
    assert_eq(1, substr_count($result['markup'], '<!-- wp:navigation -->'), 'no second navigation');
});

test('HeaderNav does not let a homepage-anchor link mask the inner page it shares a label with', function () {
    $pages = [
        ['title' => 'Home', 'slug' => 'home', 'path' => '/', 'front' => true],
        ['title' => 'Visit', 'slug' => 'visit', 'path' => '/visit/', 'front' => false],
    ];
    // A "Visit" CTA aimed at a homepage section is not the Visit page.
    $markup = '<!-- wp:site-title /--><!-- wp:navigation -->'
        . '<!-- wp:navigation-link {"label":"Visit","url":"/#visit-us","kind":"custom"} /-->'
        . '<!-- /wp:navigation -->';

    $result = HeaderNav::withCompleteInnerPages($markup, $pages);

    assert_contains('"url":"/visit/"', $result['markup'], 'the real Visit page is reachable');
});

test('HeaderNav does not let a matching label mask a different inner-page destination', function () {
    $pages = [
        ['title' => 'Home', 'slug' => 'home', 'path' => '/', 'front' => true],
        ['title' => 'Visit', 'slug' => 'visit', 'path' => '/visit/', 'front' => false],
    ];
    // A matching label cannot prove that a different path reaches this page.
    $markup = '<!-- wp:site-title /--><!-- wp:navigation -->'
        . '<!-- wp:navigation-link {"label":"Visit","url":"/visit-us/","kind":"custom"} /-->'
        . '<!-- /wp:navigation -->';

    $result = HeaderNav::withCompleteInnerPages($markup, $pages);

    assert_contains('"url":"/visit-us/"', $result['markup'], 'the authored destination is retained');
    assert_contains('"url":"/visit/"', $result['markup'], 'the required page is independently reachable');
    assert_true($result['notes'] !== [], 'the reachability repair is recorded');
});

test('HeaderNav does not let an external URL mask an inner page with the same path', function () {
    $pages = [
        ['title' => 'Home', 'slug' => 'home', 'path' => '/', 'front' => true],
        ['title' => 'Visit', 'slug' => 'visit', 'path' => '/visit/', 'front' => false],
    ];

    foreach (['https://elsewhere.example/visit/', '//elsewhere.example/visit/'] as $url) {
        $markup = '<!-- wp:navigation -->'
            . '<!-- wp:navigation-link {"label":"Visit","url":"' . $url . '","kind":"custom"} /-->'
            . '<!-- /wp:navigation -->';

        $result = HeaderNav::withCompleteInnerPages($markup, $pages);

        assert_contains('"url":"' . $url . '"', $result['markup'], 'the external link is retained');
        assert_contains('"url":"/visit/"', $result['markup'], 'the internal Visit page is also reachable');
    }
});

test('HeaderNav adds HTML anchors inside the existing nav list, not beside it', function () {
    $markup = '<a class="brand" href="/">Atelier</a>'
        . '<nav aria-label="Primary"><ul><li><a href="/menu/">Menu</a></li></ul></nav>';

    $result = HeaderNav::withCompleteInnerPages($markup, header_nav_pages(), 'header', 'Atelier');

    assert_contains('<li><a href="/visit/">Visit</a></li>', $result['markup']);
    assert_true(
        !str_contains($result['markup'], '</ul><a href="/visit/">'),
        'a bare anchor beside the list escapes every `nav ul li a` rule',
    );
});

test('HeaderNav spreads missing links across both split-nav halves', function () {
    $pages = [
        ['title' => 'Home', 'slug' => 'home', 'path' => '/', 'front' => true],
        ['title' => 'Menu', 'slug' => 'menu', 'path' => '/menu/', 'front' => false],
        ['title' => 'Visit', 'slug' => 'visit', 'path' => '/visit/', 'front' => false],
        ['title' => 'Press', 'slug' => 'press', 'path' => '/press/', 'front' => false],
        ['title' => 'Contact', 'slug' => 'contact', 'path' => '/contact/', 'front' => false],
    ];
    $markup = '<!-- wp:navigation --><!-- /wp:navigation -->'
        . '<!-- wp:site-title /-->'
        . '<!-- wp:navigation --><!-- /wp:navigation -->';

    $result = HeaderNav::withCompleteInnerPages($markup, $pages);

    [$left, $right] = explode('<!-- wp:site-title /-->', $result['markup']);
    assert_true(substr_count($left, 'wp:navigation-link') > 0, 'the start half gets links');
    assert_true(substr_count($right, 'wp:navigation-link') > 0, 'the end half gets links');
    assert_eq(4, substr_count($result['markup'], 'wp:navigation-link'), 'every inner page exactly once');
});

test('HeaderNav balances missing split-nav links against the links already present', function () {
    $pages = [
        ['title' => 'Home', 'slug' => 'home', 'path' => '/', 'front' => true],
        ['title' => 'Menu', 'slug' => 'menu', 'path' => '/menu/', 'front' => false],
        ['title' => 'Visit', 'slug' => 'visit', 'path' => '/visit/', 'front' => false],
        ['title' => 'Press', 'slug' => 'press', 'path' => '/press/', 'front' => false],
        ['title' => 'Contact', 'slug' => 'contact', 'path' => '/contact/', 'front' => false],
    ];
    $markup = '<!-- wp:navigation -->'
        . '<!-- wp:navigation-link {"label":"Menu","url":"/menu/","kind":"custom"} /-->'
        . '<!-- wp:navigation-link {"label":"Visit","url":"/visit/","kind":"custom"} /-->'
        . '<!-- wp:navigation-link {"label":"Press","url":"/press/","kind":"custom"} /-->'
        . '<!-- /wp:navigation -->'
        . '<!-- wp:site-title /-->'
        . '<!-- wp:navigation --><!-- /wp:navigation -->';

    $result = HeaderNav::withCompleteInnerPages($markup, $pages);

    [$left, $right] = explode('<!-- wp:site-title /-->', $result['markup']);
    assert_eq(3, substr_count($left, 'wp:navigation-link'), 'the loaded half receives nothing');
    assert_eq(1, substr_count($right, 'wp:navigation-link'), 'the missing link goes to the empty half');
    assert_contains('"url":"/contact/"', $right);
});

test('HeaderNav inserts navigation into a row, never stacked under the wordmark (BIGR-872)', function () {
    // A constrained group stacks its children, so an inserted nav would land
    // on its own row: the maxwelldemo7 masthead this pass exists to remove.
    $markup = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group"><!-- wp:site-title /--></div>'
        . '<!-- /wp:group -->';

    $result = HeaderNav::withCompleteInnerPages($markup, header_nav_pages());

    assert_contains('"type":"flex"', $result['markup'], 'identity and nav share one row');
    $rowAt = strpos($result['markup'], '"type":"flex"');
    $titleAt = strpos($result['markup'], 'wp:site-title');
    $navAt = strpos($result['markup'], '<!-- wp:navigation -->');
    assert_true(
        $rowAt !== false && $titleAt !== false && $navAt !== false && $rowAt < $titleAt && $titleAt < $navAt,
        'the row wraps the wordmark and the new nav',
    );
    assert_contains('"url":"/menu/"', $result['markup']);
});

test('HeaderNav inserts navigation beside the whole nested identity lockup', function () {
    // This is the mode-aware fallback shape when the header displays a
    // tagline: the title and tagline form one identity unit inside the wide
    // header row. Navigation belongs beside that unit, not inside it.
    $markup = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:group {"align":"wide","layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between","verticalAlignment":"center"}} -->'
        . '<div class="wp-block-group alignwide">'
        . '<!-- wp:group {"style":{"spacing":{"blockGap":"0"}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group"><!-- wp:site-title /--><!-- wp:site-tagline /--></div>'
        . '<!-- /wp:group -->'
        . '</div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';

    $result = HeaderNav::withCompleteInnerPages($markup, header_nav_pages());
    $document = BlockMarkup::parse($result['markup']);
    $wideRow = null;
    foreach ($document->indices() as $index) {
        if ($document->name($index) === 'group' && (($document->attrs($index) ?? [])['align'] ?? '') === 'wide') {
            $wideRow = $index;
            break;
        }
    }

    assert_true($wideRow !== null, 'the existing wide header row survives');
    $rowChildren = array_map(
        static fn (int $index): string => $document->name($index),
        $document->children($wideRow),
    );
    assert_eq(['group', 'navigation'], $rowChildren, 'identity unit and navigation are row siblings');

    $identity = $document->children($wideRow)[0];
    $identityChildren = array_map(
        static fn (int $index): string => $document->name($index),
        $document->children($identity),
    );
    assert_eq(['site-title', 'site-tagline'], $identityChildren, 'the title/tagline lockup stays intact');
});

test('HeaderNav keeps a nested logo and title unit together when inserting navigation', function () {
    $markup = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:group {"align":"wide","layout":{"type":"flex","justifyContent":"space-between"}} -->'
        . '<div class="wp-block-group alignwide">'
        . '<!-- wp:group {"layout":{"type":"flex"}} --><div class="wp-block-group">'
        . '<!-- wp:site-logo /-->'
        . '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
        . '<!-- wp:site-title /--><!-- wp:site-tagline /-->'
        . '</div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';

    $result = HeaderNav::withCompleteInnerPages($markup, header_nav_pages());
    $document = BlockMarkup::parse($result['markup']);
    $wideRow = null;
    foreach ($document->indices() as $index) {
        if ($document->name($index) === 'group' && (($document->attrs($index) ?? [])['align'] ?? '') === 'wide') {
            $wideRow = $index;
            break;
        }
    }

    assert_true($wideRow !== null);
    assert_eq(
        ['group', 'navigation'],
        array_map(
            static fn (int $index): string => $document->name($index),
            $document->children($wideRow),
        ),
        'navigation is outside the complete logo/title/tagline lockup',
    );
    $identity = $document->children($wideRow)[0];
    $identityEnd = $document->endOffset($identity);
    assert_true($identityEnd !== null, 'identity lockup has a provable end');
    $identityMarkup = substr(
        $result['markup'],
        $document->openingOffset($identity),
        $identityEnd - $document->openingOffset($identity),
    );
    assert_contains('wp:site-logo', $identityMarkup);
    assert_contains('wp:site-title', $identityMarkup);
    assert_contains('wp:site-tagline', $identityMarkup);
    assert_true(!str_contains($identityMarkup, 'wp:navigation'), 'navigation did not split the lockup');
});

test('HeaderHero repairs a complete standard header that still stacks identity above navigation', function () {
    $markup = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:site-title /-->'
        . '<!-- wp:navigation -->'
        . '<!-- wp:navigation-link {"label":"Menu","url":"/menu/","kind":"custom"} /-->'
        . '<!-- wp:navigation-link {"label":"Visit","url":"/visit/","kind":"custom"} /-->'
        . '<!-- /wp:navigation -->'
        . '</div><!-- /wp:group -->';

    $result = HeaderHeroStep::fixHeader(
        $markup,
        AboveFoldContract::MODE_STACKED,
        'Atelier',
        ['Home', 'Menu', 'Visit'],
        false,
        'standard-row',
        pages: header_nav_pages(),
    );
    $document = BlockMarkup::parse($result['markup']);
    $root = $document->topLevel();

    assert_true($root !== null, 'the repaired header has one root');
    assert_eq('constrained', ($document->attrs($root) ?? [])['layout']['type'] ?? null);
    $rootChildren = $document->children($root);
    assert_eq(1, count($rootChildren), 'the constrained shell contains one header row');
    $row = $rootChildren[0];
    assert_eq('flex', ($document->attrs($row) ?? [])['layout']['type'] ?? null);
    assert_eq('nowrap', ($document->attrs($row) ?? [])['layout']['flexWrap'] ?? null);
    assert_eq('space-between', ($document->attrs($row) ?? [])['layout']['justifyContent'] ?? null);
    assert_eq(
        ['site-title', 'navigation'],
        array_map(
            static fn (int $index): string => $document->name($index),
            $document->children($row),
        ),
        'identity and the already-complete navigation are row siblings',
    );
    assert_contains('repaired complete header navigation into one row', implode("\n", $result['notes']));
    $second = HeaderHeroStep::fixHeader(
        $result['markup'],
        AboveFoldContract::MODE_STACKED,
        'Atelier',
        ['Home', 'Menu', 'Visit'],
        false,
        'standard-row',
        pages: header_nav_pages(),
    );
    assert_eq($result['markup'], $second['markup'], 'the complete-row repair reaches a fixed point');
});

test('HeaderNav corrects a required page link whose destination has the wrong label', function () {
    $pages = [
        ['title' => 'Home', 'slug' => 'home', 'path' => '/', 'front' => true],
        ['title' => 'About', 'slug' => 'about', 'path' => '/about/', 'front' => false],
    ];
    $markup = '<!-- wp:navigation -->'
        . '<!-- wp:navigation-link {"label":"Home","url":"/about/","kind":"custom"} /-->'
        . '<!-- /wp:navigation -->';

    $result = HeaderNav::withCompleteInnerPages($markup, $pages);

    assert_true(!str_contains($result['markup'], '"label":"Home"'), 'the misleading Home label is removed');
    assert_contains('"label":"About","url":"/about/"', $result['markup']);
    assert_eq(1, substr_count($result['markup'], '"url":"/about/"'), 'the destination is not duplicated');
    assert_contains('corrected inner-page navigation label', implode("\n", $result['notes']));
    assert_eq([], $result['warnings']);
    assert_eq(
        $result['markup'],
        HeaderNav::withCompleteInnerPages($result['markup'], $pages)['markup'],
        'the label repair reaches a fixed point',
    );
});

test('HeaderNav keeps a deep-link CTA and adds the exact required page destination', function () {
    $pages = [
        ['title' => 'Home', 'slug' => 'home', 'path' => '/', 'front' => true],
        ['title' => 'About', 'slug' => 'about', 'path' => '/about/', 'front' => false],
    ];
    $markup = '<!-- wp:navigation -->'
        . '<!-- wp:navigation-link {"label":"Meet the team","url":"/about/#team","kind":"custom"} /-->'
        . '<!-- /wp:navigation -->';

    $result = HeaderNav::withCompleteInnerPages($markup, $pages);

    assert_contains('"label":"Meet the team","url":"/about/#team"', $result['markup']);
    assert_contains('"label":"About","url":"/about/"', $result['markup']);
    assert_eq(1, substr_count($result['markup'], '"url":"/about/"'), 'canonical page appears once');
    assert_eq([], $result['warnings']);
    assert_eq(
        $result['markup'],
        HeaderNav::withCompleteInnerPages($result['markup'], $pages)['markup'],
        'deep-link completion reaches a fixed point',
    );
});

test('HeaderHero reorders a complete reverse standard header into identity then navigation', function () {
    $markup = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:navigation -->'
        . '<!-- wp:navigation-link {"label":"Menu","url":"/menu/","kind":"custom"} /-->'
        . '<!-- wp:navigation-link {"label":"Visit","url":"/visit/","kind":"custom"} /-->'
        . '<!-- /wp:navigation -->'
        . '<!-- wp:site-title /-->'
        . '</div><!-- /wp:group -->';

    $result = HeaderHeroStep::fixHeader(
        $markup,
        AboveFoldContract::MODE_STACKED,
        'Atelier',
        ['Home', 'Menu', 'Visit'],
        false,
        'standard-row',
        pages: header_nav_pages(),
    );
    $document = BlockMarkup::parse($result['markup']);
    $root = $document->topLevel();
    assert_true($root !== null);
    $row = $document->children($root)[0] ?? null;
    assert_true($row !== null);
    assert_eq(
        ['site-title', 'navigation'],
        array_map(
            static fn (int $index): string => $document->name($index),
            $document->children($row),
        ),
        'source and visual order are repaired together',
    );
    assert_eq(
        $result['markup'],
        HeaderHeroStep::fixHeader(
            $result['markup'],
            AboveFoldContract::MODE_STACKED,
            'Atelier',
            ['Home', 'Menu', 'Visit'],
            false,
            'standard-row',
            pages: header_nav_pages(),
        )['markup'],
        'reverse-order repair reaches a fixed point',
    );
});

test('HeaderNav keeps split root identity pieces together when inserting navigation', function () {
    $markup = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:site-logo /-->'
        . '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
        . '<!-- wp:site-title /--><!-- wp:site-tagline /-->'
        . '</div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';

    $result = HeaderNav::withCompleteInnerPages($markup, header_nav_pages());
    $document = BlockMarkup::parse($result['markup']);
    $root = $document->topLevel();
    assert_true($root !== null);
    $row = $document->children($root)[0] ?? null;
    assert_true($row !== null);
    assert_eq('flex', ($document->attrs($row) ?? [])['layout']['type'] ?? null);
    assert_eq(
        ['group', 'navigation'],
        array_map(
            static fn (int $index): string => $document->name($index),
            $document->children($row),
        ),
        'all identity pieces form the start-side lockup',
    );
    $identity = $document->children($row)[0];
    $identityEnd = $document->endOffset($identity);
    assert_true($identityEnd !== null);
    $identityMarkup = substr(
        $result['markup'],
        $document->openingOffset($identity),
        $identityEnd - $document->openingOffset($identity),
    );
    assert_contains('wp:site-logo', $identityMarkup);
    assert_contains('wp:site-title', $identityMarkup);
    assert_contains('wp:site-tagline', $identityMarkup);
    assert_true(!str_contains($identityMarkup, 'wp:navigation'));
});

test('HeaderNav keeps the complete branded identity together when repairing an existing navigation row', function () {
    $markup = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:site-logo /-->'
        . '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
        . '<!-- wp:site-title /--><!-- wp:site-tagline /-->'
        . '</div><!-- /wp:group -->'
        . '<!-- wp:navigation -->'
        . '<!-- wp:navigation-link {"label":"Menu","url":"/menu/","kind":"custom"} /-->'
        . '<!-- wp:navigation-link {"label":"Visit","url":"/visit/","kind":"custom"} /-->'
        . '<!-- /wp:navigation -->'
        . '</div><!-- /wp:group -->';

    $result = HeaderNav::withSingleRowForArchetype($markup, 'branded-lockup');
    $document = BlockMarkup::parse($result['markup']);
    $root = $document->topLevel();
    assert_true($root !== null);
    assert_eq(1, count($document->children($root)), 'the shell contains only the repaired row');
    $row = $document->children($root)[0];
    assert_eq(
        ['group', 'navigation'],
        array_map(
            static fn (int $index): string => $document->name($index),
            $document->children($row),
        ),
        'the whole branded identity is one start-side unit beside navigation',
    );
    $identity = $document->children($row)[0];
    $identityEnd = $document->endOffset($identity);
    assert_true($identityEnd !== null);
    $identityMarkup = substr(
        $result['markup'],
        $document->openingOffset($identity),
        $identityEnd - $document->openingOffset($identity),
    );
    assert_contains('wp:site-logo', $identityMarkup);
    assert_contains('wp:site-title', $identityMarkup);
    assert_contains('wp:site-tagline', $identityMarkup);
    assert_eq(
        $result['markup'],
        HeaderNav::withSingleRowForArchetype($result['markup'], 'branded-lockup')['markup'],
        'the complete branded-row repair reaches a fixed point',
    );
});

test('HeaderNav keeps an allowed CTA inside a repaired complete header row', function () {
    $markup = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:site-title /-->'
        . '<!-- wp:navigation -->'
        . '<!-- wp:navigation-link {"label":"Menu","url":"/menu/","kind":"custom"} /-->'
        . '<!-- /wp:navigation -->'
        . '<!-- wp:buttons --><div class="wp-block-buttons">'
        . '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link" href="/visit/">Visit</a></div><!-- /wp:button -->'
        . '</div><!-- /wp:buttons -->'
        . '</div><!-- /wp:group -->';

    $result = HeaderNav::withSingleRowForArchetype($markup, 'standard-row');
    $document = BlockMarkup::parse($result['markup']);
    $root = $document->topLevel();
    assert_true($root !== null);
    assert_eq(1, count($document->children($root)), 'the shell contains only the repaired row');
    $row = $document->children($root)[0];
    assert_eq('flex', ($document->attrs($row) ?? [])['layout']['type'] ?? null);
    assert_eq('nowrap', ($document->attrs($row) ?? [])['layout']['flexWrap'] ?? null);
    assert_eq(
        ['site-title', 'navigation', 'buttons'],
        array_map(
            static fn (int $index): string => $document->name($index),
            $document->children($row),
        ),
        'the CTA remains a sibling in the single header row',
    );
    assert_eq(
        $result['markup'],
        HeaderNav::withSingleRowForArchetype($result['markup'], 'standard-row')['markup'],
        'the CTA row repair reaches a fixed point',
    );
});

test('HeaderNav preserves and warns on an unknown complete-header sibling', function () {
    $markup = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:site-title /-->'
        . '<!-- wp:navigation -->'
        . '<!-- wp:navigation-link {"label":"Menu","url":"/menu/","kind":"custom"} /-->'
        . '<!-- /wp:navigation -->'
        . '<!-- wp:paragraph --><p>Unclassified header content</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';

    $result = HeaderNav::withSingleRowForArchetype($markup, 'standard-row');

    assert_eq($markup, $result['markup'], 'an unknown sibling is preserved byte-for-byte');
    assert_eq([], $result['notes']);
    assert_eq(1, count($result['warnings']), 'the unproven row is durable');
    assert_contains('delivered=unchanged', $result['warnings'][0]);
});

test('HeaderNav leaves an unterminated raw nav unchanged and warns instead of adding another', function () {
    $markup = '<!-- wp:site-title /--><nav aria-label="Primary"><a href="/menu/">Menu</a>';

    $result = HeaderNav::withCompleteInnerPages($markup, header_nav_pages());

    assert_eq($markup, $result['markup'], 'unproven raw nav bytes are preserved');
    assert_eq([], $result['notes']);
    assert_eq(1, substr_count(strtolower($result['markup']), '<nav'), 'no second navigation is added');
    assert_eq(1, count($result['warnings']), 'the retained defect is durable');
    assert_contains("block='nav'", $result['warnings'][0]);
    assert_contains('delivered=unchanged', $result['warnings'][0]);
});

test('HeaderNav restores the header-pill class on a floating-pill row the model left bare (frm W1a)', function () {
    $markup = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"center"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:site-title /-->'
        . '<!-- wp:navigation -->'
        . '<!-- wp:navigation-link {"label":"Menu","url":"/menu/","kind":"custom"} /-->'
        . '<!-- /wp:navigation -->'
        . '<!-- wp:buttons --><div class="wp-block-buttons">'
        . '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link" href="/visit/">Visit</a></div><!-- /wp:button -->'
        . '</div><!-- /wp:buttons -->'
        . '</div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';

    $result = HeaderNav::withPillRow($markup);
    assert_eq(1, count($result['notes']), 'the restoration is a recorded repair');
    assert_eq([], $result['warnings']);
    $document = BlockMarkup::parse($result['markup']);
    $row = $document->children($document->topLevel())[0];
    assert_eq('header-pill', ($document->attrs($row) ?? [])['className'] ?? null, 'the row comment carries the class');
    assert_contains('<div class="wp-block-group header-pill">', $result['markup'], 'the saved HTML mirrors it');
    assert_eq(
        $result['markup'],
        HeaderNav::withPillRow($result['markup'])['markup'],
        'a marked row is a fixed point',
    );

    // Already marked (with other classes): untouched.
    $marked = str_replace(
        '{"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"center"}}',
        '{"className":"custom-motion header-pill","layout":{"type":"flex"}}',
        $markup,
    );
    assert_eq($marked, HeaderNav::withPillRow($marked)['markup']);

    // No navigation at all: nothing provable, one durable warning, bytes kept.
    $bare = '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
        . '<!-- wp:site-title /--></div><!-- /wp:group -->';
    $unproven = HeaderNav::withPillRow($bare);
    assert_eq($bare, $unproven['markup']);
    assert_eq(1, count($unproven['warnings']));
    assert_contains('delivered=unchanged', $unproven['warnings'][0]);
});

test('fixHeader marks the floating-pill row after the single-row repair wraps bare root children', function () {
    $markup = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:site-title /-->'
        . '<!-- wp:navigation -->'
        . '<!-- wp:navigation-link {"label":"Menu","url":"/menu/","kind":"custom"} /-->'
        . '<!-- /wp:navigation -->'
        . '</div><!-- /wp:group -->';
    $result = HeaderHeroStep::fixHeader(
        $markup,
        AboveFoldContract::MODE_STACKED,
        'Northlight',
        ['Home', 'Menu'],
        false,
        'floating-pill',
        'contrast',
        'base',
        null,
        [],
        header_nav_pages(),
    );
    $document = BlockMarkup::parse($result['markup']);
    $root = $document->topLevel();
    $row = $document->children($root)[0];
    assert_eq('flex', ($document->attrs($row) ?? [])['layout']['type'] ?? null, 'the row repair ran first');
    assert_contains('header-pill', (string) (($document->attrs($row) ?? [])['className'] ?? ''), 'then the pill class landed on the wrapped row');
    assert_contains('header-archetype--floating-pill', $result['markup'], 'the root carries the archetype marker');
});

test('HeaderNav restores the header-bar-center class on a bar-center-cta row the model left bare (frm W1b)', function () {
    $markup = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:group {"align":"wide","layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between"}} -->'
        . '<div class="wp-block-group alignwide">'
        . '<!-- wp:site-title /-->'
        . '<!-- wp:navigation -->'
        . '<!-- wp:navigation-link {"label":"Menu","url":"/menu/","kind":"custom"} /-->'
        . '<!-- /wp:navigation -->'
        . '<!-- wp:buttons --><div class="wp-block-buttons">'
        . '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link" href="/visit/">Visit</a></div><!-- /wp:button -->'
        . '</div><!-- /wp:buttons -->'
        . '</div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';

    $result = HeaderNav::withBarCenterRow($markup);
    assert_eq(['bar-center-cta: restored the header-bar-center class on the identity/navigation row'], $result['notes']);
    assert_eq([], $result['warnings']);
    $document = BlockMarkup::parse($result['markup']);
    $row = $document->children($document->topLevel())[0];
    assert_eq('header-bar-center', ($document->attrs($row) ?? [])['className'] ?? null);
    assert_contains('class="wp-block-group header-bar-center alignwide"', $result['markup']);
    assert_true(!str_contains($result['markup'], 'header-pill'), 'the bar never takes the pill class');
    assert_eq($result['markup'], HeaderNav::withBarCenterRow($result['markup'])['markup'], 'a marked row is left alone');
});

test('fixHeader marks the bar-center-cta row after the single-row repair wraps bare root children (frm W1b)', function () {
    $markup = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:site-title /-->'
        . '<!-- wp:navigation -->'
        . '<!-- wp:navigation-link {"label":"Menu","url":"/menu/","kind":"custom"} /-->'
        . '<!-- /wp:navigation -->'
        . '</div><!-- /wp:group -->';
    $result = HeaderHeroStep::fixHeader(
        $markup,
        AboveFoldContract::MODE_STACKED,
        'Northlight',
        ['Home', 'Menu'],
        false,
        'bar-center-cta',
        'contrast',
        'base',
        null,
        [],
        header_nav_pages(),
    );
    $document = BlockMarkup::parse($result['markup']);
    $row = $document->children($document->topLevel())[0];
    assert_eq('flex', ($document->attrs($row) ?? [])['layout']['type'] ?? null, 'the row repair ran first');
    assert_contains('header-bar-center', (string) (($document->attrs($row) ?? [])['className'] ?? ''));
    assert_contains('header-archetype--bar-center-cta', $result['markup'], 'the root carries the archetype marker');
});

test('a kit-painted chrome makes its navigation and title inherit the proven foreground (frm PR-1g)', function () {
    $markup = '<!-- wp:group {"layout":{"type":"constrained"},"textColor":"contrast"} -->'
        . '<div class="wp-block-group has-contrast-color has-text-color">'
        . '<!-- wp:group {"className":"header-pill","layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"center"}} -->'
        . '<div class="wp-block-group header-pill">'
        . '<!-- wp:site-title {"textColor":"primary"} /-->'
        . '<!-- wp:navigation {"textColor":"secondary","style":{"color":{"text":"#5A6472"},"elements":{"link":{"color":{"text":"#5A6472"}}}}} -->'
        . '<!-- wp:navigation-link {"label":"Services","url":"#services","kind":"custom"} /-->'
        . '<!-- /wp:navigation -->'
        . '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"textColor":"base"} --><div class="wp-block-button"><a class="wp-block-button__link has-base-color" href="/start/">Start</a></div><!-- /wp:button --></div><!-- /wp:buttons -->'
        . '</div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';
    $result = HeaderNav::inheritProvenInk($markup, 'contrast');
    assert_eq(2, count($result['notes']), 'the navigation and the title each record a repair');
    assert_true(!str_contains($result['markup'], '"textColor":"secondary"'), 'the nav token colour is gone');
    assert_true(!str_contains($result['markup'], '"textColor":"primary"'), 'the title token colour is gone');
    assert_true(!str_contains($result['markup'], '#5A6472'), 'inline nav text colours are gone');
    assert_contains('"textColor":"base"', $result['markup'], 'the button keeps its own proven pair');
    assert_contains('"textColor":"contrast"', $result['markup'], 'the root foreground is untouched');

    $clean = HeaderNav::inheritProvenInk('<!-- wp:navigation {"textColor":"contrast"} --><!-- wp:navigation-link {"label":"A","url":"#a","kind":"custom"} /--><!-- /wp:navigation -->', 'contrast');
    assert_eq([], $clean['notes'], 'a nav already in the proven ink is left alone');
    assert_eq([], HeaderNav::inheritProvenInk($markup, '')['notes'], 'no proven foreground, no change');
});

test('fixHeader applies the proven ink on the pill and the centered bar only (frm PR-1g)', function () {
    $header = static fn (): string => '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:site-title /-->'
        . '<!-- wp:navigation {"textColor":"secondary"} -->'
        . '<!-- wp:navigation-link {"label":"Menu","url":"/menu/","kind":"custom"} /-->'
        . '<!-- /wp:navigation -->'
        . '</div><!-- /wp:group -->';
    foreach (['floating-pill' => false, 'bar-center-cta' => false, 'standard-row' => true] as $archetype => $keeps) {
        $result = HeaderHeroStep::fixHeader($header(), AboveFoldContract::MODE_STACKED, 'Northlight', ['Home', 'Menu'], false, $archetype, 'contrast', 'base', null, [], header_nav_pages());
        assert_eq($keeps, str_contains($result['markup'], '"textColor":"secondary"'), $archetype);
    }
});

test('HeaderNav restores the header-spread class and fixHeader applies the proven ink on the spread bar (frm W1d)', function () {
    $markup = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:group {"align":"full","layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between"}} -->'
        . '<div class="wp-block-group alignfull">'
        . '<!-- wp:site-title /-->'
        . '<!-- wp:navigation {"textColor":"secondary"} -->'
        . '<!-- wp:navigation-link {"label":"Work","url":"#work","kind":"custom"} /-->'
        . '<!-- /wp:navigation -->'
        . '</div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';
    $result = HeaderNav::withSpreadRow($markup);
    assert_eq(['spread-nav: restored the header-spread class on the identity/navigation row'], $result['notes']);
    assert_contains('class="wp-block-group header-spread alignfull"', $result['markup']);
    $fixed = HeaderHeroStep::fixHeader($markup, AboveFoldContract::MODE_STACKED, 'Northlight', ['Home', 'Work'], false, 'spread-nav', 'contrast', 'base', null, [], header_nav_pages());
    assert_contains('header-archetype--spread-nav', $fixed['markup']);
    assert_contains('header-spread', $fixed['markup']);
    assert_true(!str_contains($fixed['markup'], '"textColor":"secondary"'), 'the spread bar inherits the proven ink');
});
