<?php
declare(strict_types=1);

use Automattic\SiteBuild\WpcomImageClient;

test('WPCOM batch bodies request each asset MIME from Vertex', function () {
    $client = new WpcomImageClient('test-token');
    $build = new ReflectionMethod(WpcomImageClient::class, 'batchRequests');
    $build->setAccessible(true);

    [$bodies, $mimes] = $build->invoke($client, [
        3 => [
            'prompt' => 'A photographic hero',
            'aspect_ratio' => '16:9',
            'sample_image_size' => '2K',
            'mime' => 'image/jpeg',
        ],
        7 => [
            'prompt' => 'A line ornament',
            'aspect_ratio' => '4:3',
            'sample_image_size' => '1K',
            'mime' => 'image/png',
        ],
    ]);

    assert_eq([3 => 'image/jpeg', 7 => 'image/png'], $mimes);
    assert_eq([
        'mimeType' => 'image/jpeg',
        'compressionQuality' => 85,
    ], $bodies[3]['generationConfig']['imageConfig']['imageOutputOptions']);
    assert_eq(
        ['mimeType' => 'image/png'],
        $bodies[7]['generationConfig']['imageConfig']['imageOutputOptions'],
    );
});

test('WPCOM delivery boundary accepts only bytes matching the requested MIME', function () {
    $client = new WpcomImageClient('test-token');
    $encode = new ReflectionMethod(WpcomImageClient::class, 'encodeForMime');
    $encode->setAccessible(true);

    $jpeg = (string) base64_decode(
        '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AVN//2Q==',
        true,
    );
    $png = (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        true,
    );

    assert_eq($jpeg, $encode->invoke($client, [
        'bytes' => $jpeg,
        'mime' => 'image/png', // byte magic remains authoritative.
    ], 'image/jpeg'));
    assert_eq($png, $encode->invoke($client, [
        'bytes' => $png,
        'mime' => null,
    ], 'image/png'));

    $error = assert_throws(fn () => $encode->invoke($client, [
        'bytes' => $jpeg,
        'mime' => 'image/jpeg',
    ], 'image/png'));
    assert_contains('requested image/png', $error->getMessage());
    assert_contains('detected image/jpeg', $error->getMessage());
});

test('WPCOM request headers carry host-supplied extras and skip empty ones', function () {
    $headers = new ReflectionMethod(WpcomImageClient::class, 'requestHeaders');

    $plain = new WpcomImageClient('test-token');
    assert_eq(
        [
            'authorization: Bearer test-token',
            'x-wpcom-ai-feature: builder-theme-image',
            'content-type: application/json',
        ],
        $headers->invoke($plain),
        'no extras: the three headers the proxy has always seen'
    );

    $tagged = new WpcomImageClient('test-token', extraHeaders: ['X-WPCOM-Session-ID' => 'run-1', 'X-Empty' => '']);
    assert_eq(
        [
            'authorization: Bearer test-token',
            'x-wpcom-ai-feature: builder-theme-image',
            'content-type: application/json',
            'x-wpcom-session-id: run-1',
        ],
        $headers->invoke($tagged),
        'extras append after the fixed three, lowercased like them; an empty value is dropped'
    );
});
