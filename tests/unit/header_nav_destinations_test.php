<?php
declare(strict_types=1);

use Automattic\SiteBuild\HeaderNavDestinations;

/** @return list<array<string,mixed>> */
function header_nav_pages(): array
{
    return [
        ['slug' => 'home', 'title' => 'Início', 'path' => '/', 'front' => true],
        ['slug' => 'visit', 'title' => 'Visita', 'path' => '/visit/', 'front' => false],
        ['slug' => 'collections', 'title' => 'Acervos', 'path' => '/collections/', 'front' => false],
        ['slug' => 'kids', 'title' => 'Infantil', 'path' => '/kids/', 'front' => false],
        ['slug' => 'agenda', 'title' => 'Agenda', 'path' => '/agenda/', 'front' => false],
        ['slug' => 'about', 'title' => 'Sobre', 'path' => '/about/', 'front' => false],
    ];
}

test('HeaderNavDestinations rewrites dummy navigation-link urls onto page paths', function () {
    $markup = '<!-- wp:navigation {"overlayMenu":"always"} -->'
        . '<!-- wp:navigation-link {"label":"Início","url":"#hero","kind":"custom"} /-->'
        . '<!-- wp:navigation-link {"label":"Visita","url":"#hero","kind":"custom"} /-->'
        . '<!-- wp:navigation-link {"label":"Acervo","url":"#hero","kind":"custom"} /-->'
        . '<!-- wp:navigation-link {"label":"Sobre","url":"#hero","kind":"custom"} /-->'
        . '<!-- /wp:navigation -->';
    [$out, $repairs] = HeaderNavDestinations::rewrite($markup, header_nav_pages());
    assert_contains('"url":"/"', $out);
    assert_contains('"url":"/visit/"', $out);
    assert_contains('"url":"/collections/"', $out);
    assert_contains('"url":"/about/"', $out);
    assert_true(!str_contains($out, '#hero'));
    assert_true($repairs !== []);
});

test('HeaderNavDestinations rewrites list anchors and carteirinha CTAs', function () {
    $markup = '<ul class="navlist">'
        . '<li><a href="#hero">Início</a></li>'
        . '<li><a href="#hero">Visita</a></li>'
        . '</ul>'
        . '<a class="nav-card" href="#hero">Carteirinha</a>';
    [$out] = HeaderNavDestinations::rewrite($markup, header_nav_pages());
    assert_contains('href="/"', $out);
    assert_contains('href="/visit/"', $out);
    assert_eq(2, substr_count($out, 'href="/visit/"'));
    assert_true(!str_contains($out, '#hero'));
});

test('HeaderNavDestinations leaves mailto and already-correct paths alone', function () {
    $markup = '<a href="mailto:hello@example.com">Email</a>'
        . '<!-- wp:navigation-link {"label":"Agenda","url":"/agenda/","kind":"custom"} /-->';
    [$out, $repairs] = HeaderNavDestinations::rewrite($markup, header_nav_pages());
    assert_contains('mailto:hello@example.com', $out);
    assert_contains('"url":"/agenda/"', $out);
    assert_eq([], $repairs);
});

test('HeaderNavDestinations folds anchorClassName and strips hardcoded is-current', function () {
    $markup = '<!-- wp:navigation-link {"className":"is-current","label":"Início","url":"/","kind":"custom","anchorClassName":"is-current"} /-->'
        . '<!-- wp:navigation-link {"label":"Faça sua carteirinha","url":"/visit/","kind":"custom","anchorClassName":"nav-cta"} /-->';
    [$out, $repairs] = HeaderNavDestinations::rewrite($markup, header_nav_pages());
    assert_true(!str_contains($out, 'anchorClassName'), 'unregistered key is gone');
    assert_true(!str_contains($out, 'is-current'), 'static current marker is gone');
    assert_contains('"className":"nav-cta"', $out);
    assert_true($repairs !== []);
});

test('HeaderNavDestinations wraps a nested brand span as a home link', function () {
    $markup = '<p><span class="brand"><span class="brand-mark">BPP</span><span class="brand-full">Biblioteca Pública do Paraná</span></span></p>';
    [$out, $repairs] = HeaderNavDestinations::rewrite($markup, header_nav_pages());
    assert_contains('<a class="brand" href="/">', $out);
    assert_contains('<span class="brand-mark">BPP</span>', $out);
    assert_contains('<span class="brand-full">Biblioteca Pública do Paraná</span></a></p>', $out);
    assert_true(!str_contains($out, '<span class="brand">'));
    assert_true($repairs !== []);
});

test('HeaderNavDestinations lifts a convert-authored brand out of overlay navigation', function () {
    $markup = '<!-- wp:group {"tagName":"header"} -->'
        . '<header class="wp-block-group">'
        . '<!-- wp:navigation {"overlayMenu":"always"} -->'
        . '<!-- wp:navigation-link {"label":"\u003cspan data-blocks-engine-richtext-marker=\u0022x\u0022\u003eBiblioteca Pública\u003csmall\u003edo Paraná\u003c/small\u003e\u003c/span\u003e","url":"/","kind":"custom","className":"brand"} /-->'
        . '<!-- wp:navigation-link {"label":"Visite","url":"/visit/","kind":"custom"} /-->'
        . '<!-- wp:navigation-link {"label":"Carteirinha","url":"/visit/","kind":"custom","className":"nav-cta"} /-->'
        . '<!-- /wp:navigation -->'
        . '</header><!-- /wp:group -->';
    [$out, $repairs] = HeaderNavDestinations::rewrite($markup, header_nav_pages());
    assert_contains('<a class="brand" href="/">', $out);
    assert_contains('Biblioteca Pública', $out);
    assert_contains('<small>do Paraná</small>', $out);
    assert_true(!str_contains($out, 'data-blocks-engine-richtext-marker'), 'convert marker is gone');
    assert_contains('"overlayMenu":"always"', $out);
    assert_contains('"label":"Visite"', $out);
    assert_true(
        preg_match('/wp:navigation-link[^>]*"className":"brand"/', $out) !== 1,
        'brand is no longer a navigation-link',
    );
    assert_contains('nav-cta', $out);
    assert_contains('Carteirinha', $out);
    assert_true($repairs !== []);
});

test('HeaderNavDestinations leaves an already-linked brand alone', function () {
    $markup = '<a class="brand" href="/">Studio</a>';
    [$out, $repairs] = HeaderNavDestinations::rewrite($markup, header_nav_pages());
    assert_eq($markup, $out);
    assert_eq([], $repairs);
});
