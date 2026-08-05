<?php
declare(strict_types=1);

use Automattic\SiteBuild\ModelConfig;

test('parse_pages_flags returns no pages when --pages is absent', function () {
    assert_eq([], parse_pages_flags(null, false));
    assert_eq([], parse_pages_flags(null, true));
});

test('parse_pages_flags keeps a single page as the homepage', function () {
    assert_eq(['Home'], parse_pages_flags('Home', true));
});

test('parse_pages_flags trims the spacing around each title', function () {
    assert_eq(
        ['Home', 'Menu', 'About Us'],
        parse_pages_flags('  Home ,Menu ,  About Us  ', true)
    );
});

test('parse_pages_flags drops the blanks left by stray commas', function () {
    assert_eq(['Home', 'Menu'], parse_pages_flags('Home, Menu,', true));
    assert_eq(['Home', 'Menu'], parse_pages_flags(',Home,, ,Menu, ', true));
});

test('parse_pages_flags returns a list, so the first title is always the homepage', function () {
    $pages = parse_pages_flags(', ,Menu,About', true);
    assert_eq('Menu', $pages[0]);
    assert_eq([0, 1], array_keys($pages));
});

test('parse_pages_flags gives an empty list when every title is blank', function () {
    assert_eq([], parse_pages_flags(' , , ', true));
});

test('parse_pages_flags rejects a page list without --multi-page', function () {
    $e = assert_throws(static fn () => parse_pages_flags('Home, Menu', false));
    assert_true($e instanceof InvalidArgumentException, get_class($e));
    // The CLI prints the message verbatim, so it is the user-facing error text.
    assert_eq('--pages requires --multi-page.', $e->getMessage());
});

test('parse_pages_flags rejects even an all-blank page list without --multi-page', function () {
    // The contradiction is in the flags, not in what the list happens to hold.
    assert_throws(static fn () => parse_pages_flags(' , ', false));
});

test('normalize_provider passes null through when the flag is absent', function () {
    assert_eq(null, normalize_provider(null));
});

test('normalize_provider lowercases and trims a configured provider', function () {
    assert_eq('anthropic', normalize_provider('  Anthropic '));
});

test('normalize_provider accepts every provider config/models.json declares', function () {
    foreach (ModelConfig::providerNames() as $name) {
        assert_eq($name, normalize_provider($name));
    }
});

test('normalize_provider rejects an unknown provider and lists the known ones', function () {
    $e = assert_throws(static fn () => normalize_provider('Gemini'));
    assert_true($e instanceof InvalidArgumentException, get_class($e));
    assert_eq(
        "Unknown --provider 'gemini'. Known: " . implode(', ', ModelConfig::providerNames()),
        $e->getMessage()
    );
});

test('normalize_provider rejects an empty provider rather than silently ignoring it', function () {
    assert_throws(static fn () => normalize_provider(''));
    assert_throws(static fn () => normalize_provider('   '));
});
