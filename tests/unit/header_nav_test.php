<?php
declare(strict_types=1);

use Automattic\SiteBuild\HeaderNav;
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
        . '<nav><ul><li><a href="#hero">About</a></li></ul></nav>'
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
