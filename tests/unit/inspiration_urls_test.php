<?php
declare(strict_types=1);

use Automattic\SiteBuild\InspirationUrls;

test('detect finds a scheme-ful url', function () {
    assert_eq(
        ['https://gumroad.com'],
        InspirationUrls::detect('a candy shop like https://gumroad.com please')
    );
});

test('detect finds a bare host and adds https', function () {
    assert_eq(['https://gumroad.com'], InspirationUrls::detect('make it like gumroad.com'));
});

test('detect strips trailing punctuation', function () {
    assert_eq(['https://gumroad.com'], InspirationUrls::detect('like gumroad.com, but darker.'));
});

test('detect keeps paths', function () {
    assert_eq(
        ['https://example.com/about'],
        InspirationUrls::detect('port https://example.com/about over')
    );
});

test('detect returns several in order', function () {
    assert_eq(
        ['https://a.com', 'https://b.org'],
        InspirationUrls::detect('blend a.com and b.org')
    );
});

test('detect preserves URLs when unrelated prompt bytes are invalid UTF-8', function () {
    $clean = 'blend gumroad.com and stripe.com';
    $damaged = "broken \xff input before " . $clean;

    assert_eq(
        ['https://gumroad.com', 'https://stripe.com'],
        InspirationUrls::detect($damaged),
    );
    assert_eq(
        InspirationUrls::detect($clean),
        InspirationUrls::detect($damaged),
        'invalid UTF-8 outside URL tokens must not wipe otherwise unchanged detection',
    );
});

test('invalid UTF-8 does not turn an email domain into a reference', function () {
    $clean = 'mail hello@example.com only';
    $damaged = "broken \xff input before " . $clean;

    assert_eq([], InspirationUrls::detect($clean));
    assert_eq([], InspirationUrls::detect($damaged));
});

test('detect caps at three', function () {
    assert_eq(3, count(InspirationUrls::detect('a.com b.com c.com d.com e.com')));
});

test('detect deduplicates by host and path', function () {
    assert_eq(
        ['https://gumroad.com'],
        InspirationUrls::detect('gumroad.com and https://gumroad.com/ again')
    );
});

test('detect rejects mailto and emails', function () {
    assert_eq([], InspirationUrls::detect('write to hello@example.com or mailto:a@b.com'));
});

test('detect rejects asset urls', function () {
    assert_eq([], InspirationUrls::detect('use https://cdn.example.com/logo.png and a.com/f.pdf'));
});

test('detect rejects loopback and private literals', function () {
    assert_eq([], InspirationUrls::detect('http://localhost:8080 http://127.0.0.1 http://192.168.1.5'));
});

test('detect rejects prose that looks like a host', function () {
    assert_eq([], InspirationUrls::detect('e.g. a bakery, version 1.2.3, see fig.4'));
});

test('detect returns empty for no urls', function () {
    assert_eq([], InspirationUrls::detect('a bakery in Lisbon'));
});

test('detect rejects a missing-space design tld false positive', function () {
    assert_eq([], InspirationUrls::detect('make the copy pop.Design should be bold'));
});

test('detect requires whitespace before a bare host', function () {
    assert_eq([], InspirationUrls::detect('copy/gumroad.com should stay prose'));
});

test('detect accepts a bare host at string start', function () {
    assert_eq(['https://gumroad.com'], InspirationUrls::detect('gumroad.com is the reference'));
});

test('detect rejects a missing-space store tld false positive', function () {
    assert_eq([], InspirationUrls::detect('ship it.Store hours listed below'));
});

test('detect rejects an English phrase shaped like a us domain', function () {
    assert_eq([], InspirationUrls::detect('we win vs.us next week'));
});

test('detect rejects an English phrase shaped like an it domain', function () {
    assert_eq([], InspirationUrls::detect('finish it.It should be dark'));
});

test('detect keeps an at-handle in a scheme-ful path', function () {
    assert_eq(
        ['https://www.tiktok.com/@nike'],
        InspirationUrls::detect('https://www.tiktok.com/@nike vibes')
    );
});

test('detect keeps an at-handle in a bare-host path', function () {
    assert_eq(['https://medium.com/@dhh'], InspirationUrls::detect('like medium.com/@dhh'));
});

test('detect still rejects a standalone email', function () {
    assert_eq([], InspirationUrls::detect('mail hello@example.com only'));
});

test('detect preserves an explicit http scheme and port', function () {
    assert_eq(
        ['http://example.com:8080/x'],
        InspirationUrls::detect('http://example.com:8080/x')
    );
});

test('detect rejects a shorthand loopback literal', function () {
    assert_eq([], InspirationUrls::detect('http://127.1/admin'));
});

test('detect rejects a hexadecimal loopback literal', function () {
    assert_eq([], InspirationUrls::detect('http://0x7f.0.0.1/'));
});

test('detect rejects an ipv4-mapped ipv6 loopback literal', function () {
    assert_eq([], InspirationUrls::detect('http://[::ffff:127.0.0.1]/'));
});

test('detect keeps a balanced closing parenthesis before sentence punctuation', function () {
    assert_eq(
        ['https://example.com/Foo_(bar)'],
        InspirationUrls::detect('see https://example.com/Foo_(bar).')
    );
});

test('detect still accepts a bare com reference after prose', function () {
    assert_eq(
        ['https://gumroad.com'],
        InspirationUrls::detect('a candy shop like gumroad.com')
    );
});

test('detect still accepts a bare com reference with a path', function () {
    assert_eq(
        ['https://oldsite.com/blog'],
        InspirationUrls::detect('port my blog from oldsite.com/blog')
    );
});

test('detect accepts a parenthesized bare host', function () {
    assert_eq(['https://gumroad.com'], InspirationUrls::detect('like (gumroad.com) maybe'));
});

test('detect accepts a quoted bare host', function () {
    assert_eq(['https://gumroad.com'], InspirationUrls::detect('like "gumroad.com" ok'));
});

test('detect accepts an emphasized bare host', function () {
    assert_eq(['https://gumroad.com'], InspirationUrls::detect('*gumroad.com* bold'));
});

test('detect accepts comma-separated bare hosts', function () {
    assert_eq(
        ['https://gumroad.com', 'https://stripe.com'],
        InspirationUrls::detect('blend gumroad.com,stripe.com')
    );
});

test('detect accepts a hex-looking bare de domain', function () {
    assert_eq(['https://cafe.de'], InspirationUrls::detect('like cafe.de please'));
});

test('detect accepts a hex-looking scheme-ful domain', function () {
    assert_eq(['https://cafe.de'], InspirationUrls::detect('https://cafe.de'));
});

test('detect accepts a hex-looking bare ca domain', function () {
    assert_eq(['https://face.ca'], InspirationUrls::detect('see face.ca'));
});

test('detect accepts a longer hex-looking bare domain', function () {
    assert_eq(['https://decade.de'], InspirationUrls::detect('decade.de rocks'));
});

test('detect accepts a bare edu domain', function () {
    assert_eq(['https://mit.edu'], InspirationUrls::detect('mit.edu'));
});

test('detect accepts a bare gov domain', function () {
    assert_eq(['https://whitehouse.gov'], InspirationUrls::detect('whitehouse.gov'));
});
