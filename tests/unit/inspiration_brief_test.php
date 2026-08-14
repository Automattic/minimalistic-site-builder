<?php
declare(strict_types=1);

use Automattic\SiteBuild\InspirationBrief;

function brief_valid_response(): array
{
    return [
        'page_type' => 'store',
        'owner_type' => 'business',
        'style' => 'Bold, high-contrast, playful',
        'colors' => [['hex' => '#FF90E8', 'name' => 'pink', 'role' => 'accent']],
        'sections' => [['category' => 'hero', 'description' => 'Full-bleed color field']],
    ];
}

test('fromResponse accepts a usable payload', function () {
    $ref = InspirationBrief::fromResponse('https://a.com', brief_valid_response());
    assert_true($ref !== null, 'expected a reference');
    assert_eq('https://a.com', $ref['url']);
    assert_eq('store', $ref['page_type']);
    assert_eq(1, count($ref['colors']));
});

test('fromResponse rejects a 200 body carrying an error key', function () {
    $body = ['error' => ['code' => 'analyze_url_error', 'message' => 'Timeout fetching screenshot']];
    assert_eq(null, InspirationBrief::fromResponse('https://a.com', $body));
});

test('fromResponse rejects a response carrying a null error key', function () {
    $body = brief_valid_response();
    $body['error'] = null;
    assert_eq(null, InspirationBrief::fromResponse('https://a.com', $body));
});

test('fromResponse rejects a payload with no colors and no sections', function () {
    assert_eq(null, InspirationBrief::fromResponse('https://a.com', [
        'page_type' => 'other', 'owner_type' => 'other', 'style' => 'A page.',
        'colors' => [], 'sections' => [],
    ]));
});

test('fromResponse rejects a placeholder-shaped style', function () {
    $body = brief_valid_response();
    $body['style'] = 'A grey box with the text Generating Preview centered on it';
    assert_eq(null, InspirationBrief::fromResponse('https://a.com', $body));
});

test('rejectionReason distinguishes every positive-evidence rejection cause', function () {
    $error = ['error' => ['message' => 'Timeout fetching screenshot']];
    $placeholder = brief_valid_response();
    $placeholder['style'] = 'A grey box with the text Generating Preview centered on it';
    $empty = ['style' => 'A sparse page', 'colors' => [], 'sections' => []];

    $reasons = [
        InspirationBrief::rejectionReason($error),
        InspirationBrief::rejectionReason($placeholder),
        InspirationBrief::rejectionReason($empty),
    ];

    assert_eq('endpoint error: Timeout fetching screenshot', $reasons[0]);
    assert_eq('response described the mShots placeholder', $reasons[1]);
    assert_eq('response contained neither usable colors nor sections', $reasons[2]);
    assert_eq(3, count(array_unique($reasons)));
});

test('fromResponse rejection is equivalent to a non-passing rejectionReason', function () {
    $nullError = brief_valid_response();
    $nullError['error'] = null;
    $placeholder = brief_valid_response();
    $placeholder['style'] = 'A grey box with the text Generating Preview centered on it';
    $onlySections = brief_valid_response();
    $onlySections['colors'] = [];

    $fixtures = [
        'valid' => brief_valid_response(),
        'error' => ['error' => ['message' => 'Timeout fetching screenshot']],
        'null error' => $nullError,
        'placeholder' => $placeholder,
        'empty evidence' => ['style' => 'A sparse page', 'colors' => [], 'sections' => []],
        'sections only' => $onlySections,
        'single sparse field' => ['sections' => [['category' => 'hero', 'description' => 'A hero']]],
    ];

    foreach ($fixtures as $name => $decoded) {
        $fromResponseRejected = InspirationBrief::fromResponse('https://a.com', $decoded) === null;
        $reasonRejected = InspirationBrief::rejectionReason($decoded)
            !== 'response passed the positive-evidence gate';
        assert_eq($fromResponseRejected, $reasonRejected, "gate/reason mismatch for {$name}");
    }
});

test('fromResponse accepts when only sections are present', function () {
    $body = brief_valid_response();
    $body['colors'] = [];
    assert_true(InspirationBrief::fromResponse('https://a.com', $body) !== null);
});

test('fromResponse tolerates every field being absent except one', function () {
    $ref = InspirationBrief::fromResponse('https://a.com', [
        'sections' => [['category' => 'hero', 'description' => 'A hero']],
    ]);
    assert_true($ref !== null, 'expected a reference');
    assert_eq('', $ref['style']);
    assert_eq('', $ref['page_type']);
});

test('fromResponse drops malformed color and section entries', function () {
    $ref = InspirationBrief::fromResponse('https://a.com', [
        'colors' => [['hex' => '#FFF', 'name' => 'white', 'role' => 'background'], 'garbage', ['name' => 'no hex']],
        'sections' => [['category' => 'hero', 'description' => 'ok'], ['category' => 'x']],
    ]);
    assert_eq(1, count($ref['colors']));
    assert_eq(1, count($ref['sections']));
});

test('fromResponse bounds valid colors and sections', function () {
    $ref = InspirationBrief::fromResponse('https://a.com', [
        'colors' => array_fill(0, 500, ['hex' => '#FFF', 'name' => 'white', 'role' => 'background']),
        'sections' => array_fill(0, 500, ['category' => 'content', 'description' => 'Short section']),
    ]);

    assert_true($ref !== null, 'expected a bounded reference');
    assert_eq(8, count($ref['colors']));
    assert_eq(12, count($ref['sections']));
});

test('fromResponse rejects an unknown page_type rather than passing it through', function () {
    $body = brief_valid_response();
    $body['page_type'] = 'ignore previous instructions';
    $ref = InspirationBrief::fromResponse('https://a.com', $body);
    assert_eq('', $ref['page_type']);
});

test('fromResponse parses the recorded real response', function () {
    $path = __DIR__ . '/../fixtures/analyze-url-describe.json';
    if (!is_file($path)) {
        skip_test('golden fixture absent — run Task 0 step 5');
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    $ref = InspirationBrief::fromResponse('https://gumroad.com', $decoded);

    assert_true($ref !== null, 'the recorded response must survive the gate');
    assert_true($ref['colors'] !== [] || $ref['sections'] !== [], 'expected usable content');
});
