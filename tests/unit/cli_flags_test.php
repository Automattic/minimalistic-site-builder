<?php
declare(strict_types=1);

use Automattic\SiteBuild\ModelConfig;

test('split_csv_flag keeps a single value', function () {
    assert_eq(['Home'], split_csv_flag('Home'));
});

test('split_csv_flag trims the spacing around each item', function () {
    assert_eq(
        ['Home', 'Menu', 'About Us'],
        split_csv_flag('  Home ,Menu ,  About Us  ')
    );
});

test('split_csv_flag drops the blanks left by stray commas', function () {
    assert_eq(['Home', 'Menu'], split_csv_flag('Home, Menu,'));
    assert_eq(['Home', 'Menu'], split_csv_flag(',Home,, ,Menu, '));
});

test('split_csv_flag returns a list, so the first --pages title is always the homepage', function () {
    $pages = split_csv_flag(', ,Menu,About');
    assert_eq('Menu', $pages[0]);
    assert_eq([0, 1], array_keys($pages));
});

test('split_csv_flag gives an empty list when every item is blank', function () {
    assert_eq([], split_csv_flag(' , , '));
    assert_eq([], split_csv_flag(''));
});

test('require_multi_page_for_pages accepts a page list alongside --multi-page', function () {
    require_multi_page_for_pages('Home, Menu', true);
    require_multi_page_for_pages(null, true);
    require_multi_page_for_pages(null, false);
    assert_true(true, 'no contradiction to report');
});

test('require_multi_page_for_pages rejects a page list without --multi-page', function () {
    $e = assert_throws(static fn () => require_multi_page_for_pages('Home, Menu', false));
    assert_true($e instanceof InvalidArgumentException, get_class($e));
    // The CLI prints the message verbatim, so it is the user-facing error text.
    assert_eq('--pages requires --multi-page.', $e->getMessage());
});

test('require_multi_page_for_pages rejects even an all-blank page list without --multi-page', function () {
    // The contradiction is in the flags, not in what the list happens to hold.
    assert_throws(static fn () => require_multi_page_for_pages(' , ', false));
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
