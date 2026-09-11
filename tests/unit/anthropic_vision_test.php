<?php
declare(strict_types=1);

use Automattic\SiteBuild\AnthropicClient;
use Automattic\SiteBuild\LlmRequestRejected;
use Automattic\SiteBuild\VisionLlm;

/**
 * The Anthropic client carries one image beside a prompt (VisionLlm), for
 * the post-generation hero check (BIGR-979).
 */

test('Anthropic image request leads with a base64 image block, then the prompt', function () {
    assert_true(is_a(AnthropicClient::class, VisionLlm::class, true), 'AnthropicClient implements VisionLlm');

    $body = AnthropicClient::bodyForImage(
        ['prompt' => 'Is the view upright?', 'model' => 'claude-haiku-4-5', 'max_tokens' => 300],
        'JPEGBYTES',
        'image/jpeg',
        'claude-opus-5',
        16000,
    );

    assert_eq('claude-haiku-4-5', $body['model']);
    assert_eq(300, $body['max_tokens']);
    $content = $body['messages'][0]['content'];
    assert_eq(2, count($content));
    assert_eq('image', $content[0]['type']);
    assert_eq('base64', $content[0]['source']['type']);
    assert_eq('image/jpeg', $content[0]['source']['media_type']);
    assert_eq(base64_encode('JPEGBYTES'), $content[0]['source']['data']);
    assert_eq(['type' => 'text', 'text' => 'Is the view upright?'], $content[1]);
});

test('Anthropic image request refuses cached prefixes and empty images', function () {
    $rejected = assert_throws(fn () => AnthropicClient::bodyForImage(
        ['prompt' => 'p', 'cached_prefixes' => ['layer']],
        'JPEGBYTES',
        'image/jpeg',
        'claude-opus-5',
        16000,
    ));
    assert_true($rejected instanceof LlmRequestRejected, 'cached_prefixes are refused, not dropped');

    $empty = assert_throws(fn () => AnthropicClient::bodyForImage(['prompt' => 'p'], '', 'image/jpeg', 'claude-opus-5', 16000));
    assert_true($empty instanceof LlmRequestRejected);
});

test('Anthropic transcript replaces the image payload with its size', function () {
    $body = AnthropicClient::bodyForImage(['prompt' => 'p'], str_repeat('x', 3000), 'image/jpeg', 'claude-opus-5', 16000);
    $logged = AnthropicClient::redactImages($body);

    assert_eq('<image/jpeg, 3000 bytes>', $logged['messages'][0]['content'][0]['source']['data']);
    assert_eq('p', $logged['messages'][0]['content'][1]['text']);
    assert_eq(base64_encode(str_repeat('x', 3000)), $body['messages'][0]['content'][0]['source']['data'], 'the sent body is untouched');
});

test('Anthropic completeWithImage sends the image body through the single transport', function () {
    $seen = null;
    $client = new AnthropicClient('test-key', 'claude-opus-5', 16000, function (array $body) use (&$seen): array {
        $seen = $body;
        return ['text' => '{"upright": true}', 'input' => 10, 'output' => 5, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0, 'time' => 0.1, 'stop_reason' => 'end_turn'];
    });

    $answer = $client->completeWithImage('Look.', 'JPEGBYTES', 'image/jpeg', ['model' => 'claude-haiku-4-5']);

    assert_eq('{"upright": true}', $answer);
    assert_eq('image', $seen['messages'][0]['content'][0]['type']);
    assert_eq('claude-haiku-4-5', $seen['model']);
    assert_eq('end_turn', $client->lastFinishReason());
    assert_eq(1, $client->usageTotals()['requests']);
});

test('Anthropic image batch retains successful siblings and redacts image bytes', function () {
    $client = new AnthropicClient('test-key', 'claude-haiku-4-5');
    $method = new ReflectionMethod($client, 'imageBatch');
    $method->setAccessible(true);
    $requests = [
        'hero' => ['prompt' => 'Look.', 'image_bytes' => 'HERO', 'mime' => 'image/jpeg'],
        'band' => ['prompt' => 'Look.', 'image_bytes' => 'BAND', 'mime' => 'image/jpeg'],
    ];
    $batches = [];
    $result = $method->invoke($client, $requests, function (array $bodies) use (&$batches): array {
        $batches[] = $bodies;
        assert_eq(base64_encode('HERO'), $bodies['hero']['messages'][0]['content'][0]['source']['data']);
        return [
            'hero' => ['ok' => true, 'text' => '{"upright":true}', 'input' => 12, 'output' => 4],
            'band' => ['ok' => false, 'error' => 'unavailable'],
        ];
    });
    assert_eq(1, count($batches));
    assert_eq(['hero' => '{"upright":true}', 'band' => null], $result);
    assert_eq(1, $client->usageTotals()['requests']);
    assert_eq(12, $client->usageTotals()['input_tokens']);
});

test('image checks retry transient siblings after a permanent sibling failure', function () {
    $bodies = ['bad' => [], 'slow' => [], 'good' => []];
    $seen = [];
    $failed = [];
    $result = AnthropicClient::retryTextBatch($bodies, function (array $subset) use (&$seen): array {
        $seen[] = array_keys($subset);
        if (count($seen) === 1) {
            return ['bad' => ['ok' => false, 'error' => 'bad'], 'slow' => ['ok' => false, 'transient' => true],
                'good' => ['ok' => true, 'text' => 'good']];
        }
        return ['slow' => ['ok' => true, 'text' => 'slow']];
    }, [0], onFailure: function ($key) use (&$failed) { $failed[] = $key; }, sleeper: static function () {}, tolerateFailures: true);
    assert_eq([['bad', 'slow', 'good'], ['slow']], $seen);
    assert_eq(['bad'], $failed);
    assert_eq(['good', 'slow'], array_keys($result));
});
