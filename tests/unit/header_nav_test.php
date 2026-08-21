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

test('HeaderNav footer warnings point at the footer part', function () {
    $markup = '<!-- wp:navigation --><!-- wp:home-link --><!-- /wp:navigation -->';

    $result = HeaderNav::withoutHomeItems($markup, header_nav_pages(), 'footer');

    assert_contains('wp:home-link', $result['markup']);
    assert_contains("file='theme/parts/footer.html'", $result['warnings'][0]);
});
