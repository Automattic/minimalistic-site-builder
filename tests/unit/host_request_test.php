<?php
declare(strict_types=1);

use Automattic\SiteBuild\Patterns\HostRequest;

function host_request(array $overrides = []): array
{
    return $overrides + [
        'version' => 2,
        'theme' => 'ollie',
        'site' => ['title' => 'Northwind'],
        'pages' => [['slug' => 'home', 'title' => 'Home', 'intent' => 'Open the site']],
    ];
}

function host_patterns(): array
{
    return ['patterns' => [[
        'id' => 'ollie/hero',
        'categories' => ['hero'],
        'content' => '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->',
    ]]];
}

function host_markup(): string
{
    return '<!-- wp:heading --><h2 class="wp-block-heading">Our services</h2><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Approved example copy.</p><!-- /wp:paragraph -->';
}

test('a complete request has no problems', function () {
    assert_eq([], HostRequest::problems(host_request(), host_patterns(), ['name' => 'N', 'config' => []]));
});

/**
 * A page that is both would build twice and a page that is neither cannot
 * build at all. The rule is stated in the message so the host can fix it.
 */
test('a page carries exactly one of intent or markup', function () {
    $both = host_request(['pages' => [['slug' => 'home', 'title' => 'Home', 'intent' => 'x', 'markup' => host_markup()]]]);
    $neither = host_request(['pages' => [['slug' => 'home', 'title' => 'Home']]]);

    assert_contains('exactly one of', implode("\n", HostRequest::problems($both, host_patterns(), [])));
    assert_contains('exactly one of', implode("\n", HostRequest::problems($neither, host_patterns(), [])));
});

test('page slugs are unique and URL-safe', function () {
    $request = host_request(['pages' => [
        ['slug' => 'home', 'title' => 'Home', 'intent' => 'x'],
        ['slug' => 'home', 'title' => 'Home again', 'intent' => 'y'],
        ['slug' => 'Not Safe', 'title' => 'Nope', 'intent' => 'z'],
    ]]);

    $problems = implode("\n", HostRequest::problems($request, host_patterns(), []));

    assert_contains('listed twice', $problems);
    assert_contains('URL-safe slug', $problems);
});

/**
 * A slot id is how a recorded answer finds its slot. Two pages sharing one
 * would replay each other's copy.
 */
test('slot ids are unique across the request', function () {
    $slot = ['id' => 'headline', 'block_path' => [0], 'field' => 'text', 'fallback' => 'Our services', 'instruction' => 'A heading'];
    $request = host_request(['pages' => [
        ['slug' => 'a', 'title' => 'A', 'markup' => host_markup(), 'slots' => [$slot]],
        ['slug' => 'b', 'title' => 'B', 'markup' => host_markup(), 'slots' => [$slot]],
    ]]);

    assert_contains('unique across the request', implode("\n", HostRequest::problems($request, ['patterns' => []], [])));
});

/**
 * A declared slot is compiled before anything runs, so a path that points
 * nowhere or a field the block cannot hold is reported against the page.
 */
test('a slot that cannot be compiled is reported against its page', function () {
    $request = host_request(['pages' => [
        ['slug' => 'a', 'title' => 'A', 'markup' => host_markup(), 'slots' => [
            ['id' => 'nowhere', 'block_path' => [7], 'field' => 'text', 'fallback' => 'x'],
        ]],
    ]]);

    $problems = implode("\n", HostRequest::problems($request, ['patterns' => []], []));

    assert_contains('a: ', $problems);
    assert_contains('unknown block_path', $problems);
});

test('slots must be a list, and the message says what the alternatives mean', function () {
    $request = host_request(['pages' => [['slug' => 'a', 'title' => 'A', 'markup' => host_markup(), 'slots' => 'all']]]);

    $problems = implode("\n", HostRequest::problems($request, ['patterns' => []], []));

    assert_contains('omit the key to discover', $problems);
    assert_contains('[] to freeze', $problems);
});

test('an inventory is required when any page composes, or when none is named', function () {
    $mixed = host_request(['pages' => [
        ['slug' => 'a', 'title' => 'A', 'markup' => host_markup()],
        ['slug' => 'b', 'title' => 'B', 'intent' => 'compose me'],
    ]]);
    $none = host_request(['pages' => []]);
    $allSupplied = host_request(['pages' => [['slug' => 'a', 'title' => 'A', 'markup' => host_markup()]]]);

    assert_contains('inventory is empty', implode("\n", HostRequest::problems($mixed, ['patterns' => []], [])));
    assert_contains('inventory is empty', implode("\n", HostRequest::problems($none, ['patterns' => []], [])));
    assert_eq([], HostRequest::problems($allSupplied, ['patterns' => []], []));
});

/**
 * The Brand is a record: what identifies it, plus its theme.json partial
 * under config. A theme.json key at the top level is the old contract.
 */
test('Brand fields outside the record and config keys outside theme.json are refused', function () {
    $problems = implode("\n", HostRequest::problems(
        host_request(),
        host_patterns(),
        ['settings' => [], 'config' => ['styles' => [], 'logo' => 'x']],
    ));

    assert_contains('Brand carries "settings"', $problems);
    assert_contains('Brand config carries "logo"', $problems);
});

test('every problem is reported together', function () {
    $problems = HostRequest::problems(
        ['theme' => '', 'pages' => [['slug' => '', 'title' => 'x']]],
        ['patterns' => [['id' => '', 'content' => '']]],
        ['config' => ['settings' => ['color' => ['palette' => [['slug' => 'base']]]]]],
    );

    $joined = implode("\n", $problems);
    assert_true(count($problems) >= 6, 'six or more problems, got ' . count($problems));
    assert_contains('version', $joined);
    assert_contains('no theme', $joined);
    assert_contains('site.title', $joined);
    assert_contains('has no slug', $joined);
    assert_contains('has no id', $joined);
    assert_contains('has no name', $joined);
});

test('validate throws with the same list', function () {
    $thrown = assert_throws(static fn () => HostRequest::validate(host_request(['theme' => '']), host_patterns(), []));

    assert_contains('cannot produce a site', $thrown->getMessage());
    assert_contains('no theme', $thrown->getMessage());
});

test('a request with no pages key is told what pages are', function () {
    $request = host_request();
    unset($request['pages']);

    $problems = implode("\n", HostRequest::problems($request, host_patterns(), []));

    assert_contains('request.pages', $problems);
    assert_contains('intent', $problems);
});
