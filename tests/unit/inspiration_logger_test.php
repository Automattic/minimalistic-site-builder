<?php
declare(strict_types=1);

use Automattic\SiteBuild\InspirationLogger;

/** @return array{endpoint:string,url:string,status:int} */
function inspiration_log_request(string $url): array
{
    return [
        'endpoint' => 'https://public-api.wordpress.com/wpcom/v2/analyze-url/describe',
        'url' => $url,
        'status' => 200,
    ];
}

test('log writes a file per url under the configured dir', function () {
    with_temp_dir('inspiration_log', function (string $dir) {
        InspirationLogger::setDir($dir);
        InspirationLogger::log(
            'https://gumroad.com',
            inspiration_log_request('https://gumroad.com'),
            ['style' => 'Bold']
        );
        InspirationLogger::setDir(null);

        $files = glob($dir . '/*.txt') ?: [];
        assert_eq(1, count($files), 'expected one log file');
        $body = (string) file_get_contents($files[0]);
        assert_contains('https://gumroad.com', $body);
        assert_contains('Bold', $body);
    });
});

test('log records the error when one is given', function () {
    with_temp_dir('inspiration_log', function (string $dir) {
        InspirationLogger::setDir($dir);
        InspirationLogger::log(
            'https://a.com',
            inspiration_log_request('https://a.com'),
            [],
            'rejected by gate'
        );
        InspirationLogger::setDir(null);

        $files = glob($dir . '/*.txt') ?: [];
        assert_contains('rejected by gate', (string) file_get_contents($files[0]));
    });
});

test('log is a no-op when no dir is set', function () {
    InspirationLogger::setDir(null);
    InspirationLogger::log('https://a.com', inspiration_log_request('https://a.com'), ['style' => 'x']);
    assert_eq(null, InspirationLogger::dir());
});

test('log gives two calls for the same url distinct files', function () {
    with_temp_dir('inspiration_log', function (string $dir) {
        InspirationLogger::setDir($dir);
        InspirationLogger::log('https://a.com', inspiration_log_request('https://a.com'), ['style' => 'one']);
        InspirationLogger::log('https://a.com', inspiration_log_request('https://a.com'), ['style' => 'two']);
        InspirationLogger::setDir(null);

        assert_eq(2, count(glob($dir . '/*.txt') ?: []), 'expected two files');
    });
});

test('format redacts authorization keys at any depth', function () {
    $request = inspiration_log_request('https://a.com');
    $request += [
        'authorization' => 'Bearer sk-SECRET',
        'context' => [
            'Authorization' => 'Basic nested-SECRET',
        ],
    ];
    $body = InspirationLogger::format('https://a.com', $request);

    assert_true(!str_contains($body, 'sk-SECRET'), 'top-level credential must be absent');
    assert_true(!str_contains($body, 'Bearer sk-SECRET'), 'raw top-level header must be absent');
    assert_true(!str_contains($body, 'nested-SECRET'), 'nested credential must be absent');
    assert_true(!str_contains($body, 'Basic nested-SECRET'), 'raw nested header must be absent');
    assert_contains('[REDACTED]', $body);
});

test('log redacts bearer credentials from a header list', function () {
    with_temp_dir('inspiration_log', function (string $dir) {
        InspirationLogger::setDir($dir);
        $request = inspiration_log_request('https://a.com');
        $request['headers'] = ['authorization: Bearer sk-SECRET-123'];
        InspirationLogger::log('https://a.com', $request);
        InspirationLogger::setDir(null);

        $files = glob($dir . '/*.txt') ?: [];
        assert_eq(1, count($files), 'expected one log file');
        $body = (string) file_get_contents($files[0]);
        assert_true(!str_contains($body, 'sk-SECRET-123'), 'bearer credential must be absent');
        assert_true(!str_contains($body, 'Bearer sk-'), 'raw bearer header must be absent');
        assert_contains('[REDACTED]', $body);
    });
});
