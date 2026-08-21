<?php
declare(strict_types=1);

use Automattic\SiteBuild\AnthropicClient;
use Automattic\SiteBuild\OpenAiCompatibleClient;

test('extractUsage sums input, cache, and output tokens', function () {
    $resp = ['usage' => [
        'input_tokens' => 100,
        'cache_read_input_tokens' => 20,
        'cache_creation_input_tokens' => 5,
        'output_tokens' => 50,
    ]];
    $u = AnthropicClient::extractUsage($resp);
    assert_eq(125, $u['input']);
    assert_eq(50, $u['output']);
    assert_eq(20, $u['cache_read_input_tokens']);
    assert_eq(5, $u['cache_creation_input_tokens']);
});

test('extractUsage tolerates missing usage', function () {
    $u = AnthropicClient::extractUsage([]);
    assert_eq(0, $u['input']);
    assert_eq(0, $u['output']);
    assert_eq(0, $u['cache_read_input_tokens']);
    assert_eq(0, $u['cache_creation_input_tokens']);
});

test('usageTotals starts at zero', function () {
    $c = new AnthropicClient('k', 'claude-opus-4-8');
    $t = $c->usageTotals();
    assert_eq(0, $t['requests']);
    assert_eq(0, $t['total_tokens']);
    assert_eq(0, $t['cache_read_input_tokens']);
    assert_eq(0, $t['cache_creation_input_tokens']);
});

test('Anthropic usageTotals retains successful calls from a batch that later aborts', function () {
    $client = new AnthropicClient('k', 'claude-opus-4-8');
    $responseBatch = new ReflectionMethod(AnthropicClient::class, 'responseBatch');
    $responseBatch->setAccessible(true);
    $requests = [
        'bad' => ['prompt' => 'bad'],
        'good' => ['prompt' => 'good'],
    ];
    $transport = static fn (array $subset): array => [
        'bad' => ['ok' => false, 'transient' => false, 'error' => 'HTTP 400', 'time' => 1.5],
        'good' => [
            'ok' => true,
            'text' => 'kept',
            'input' => 11,
            'output' => 7,
            'cache_read_input_tokens' => 3,
            'cache_creation_input_tokens' => 2,
            'time' => 0.5,
        ],
    ];

    assert_throws(static function () use ($responseBatch, $client, $requests, $transport): void {
        $responseBatch->invoke($client, $requests, false, $transport);
    });

    assert_eq([
        'requests' => 1,
        'input_tokens' => 11,
        'output_tokens' => 7,
        'total_tokens' => 18,
        'cache_read_input_tokens' => 3,
        'cache_creation_input_tokens' => 2,
    ], $client->usageTotals());
});

test('OpenAI-compatible usageTotals retains successful calls from an aborted batch', function () {
    $responseBatch = new ReflectionMethod(OpenAiCompatibleClient::class, 'responseBatch');
    $responseBatch->setAccessible(true);
    $requests = [
        'bad' => ['prompt' => 'bad'],
        'good' => ['prompt' => 'good'],
    ];
    $transport = static fn (array $subset): array => [
        'bad' => ['ok' => false, 'transient' => false, 'error' => 'HTTP 400', 'time' => 1.5],
        'good' => ['ok' => true, 'text' => 'kept', 'input' => 13, 'output' => 5, 'time' => 0.5],
    ];

    foreach (['openai', 'openrouter'] as $provider) {
        $client = new OpenAiCompatibleClient('k', 'model', provider: $provider);
        assert_throws(static function () use ($responseBatch, $client, $requests, $transport): void {
            $responseBatch->invoke($client, $requests, false, $transport);
        });
        assert_eq([
            'requests' => 1,
            'input_tokens' => 13,
            'output_tokens' => 5,
            'total_tokens' => 18,
        ], $client->usageTotals(), "{$provider} keeps the successful sibling's billed usage");
    }
});

test('parseSse assembles text and usage from an SSE body', function () {
    $sse = implode("\n", [
        'event: message_start',
        'data: {"type":"message_start","message":{"usage":{"input_tokens":40,"cache_read_input_tokens":10,"cache_creation_input_tokens":3,"output_tokens":1}}}',
        '',
        'event: content_block_delta',
        'data: {"type":"content_block_delta","delta":{"type":"text_delta","text":"Hello "}}',
        'data: {"type":"content_block_delta","delta":{"type":"text_delta","text":"world"}}',
        '',
        'event: message_delta',
        'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":7}}',
        '',
        'event: message_stop',
        'data: {"type":"message_stop"}',
        '',
    ]);
    $p = AnthropicClient::parseSse($sse);
    assert_eq('Hello world', $p['text']);
    assert_eq(53, $p['input']);   // 40 + 10 read + 3 creation
    assert_eq(7, $p['output']);   // final from message_delta
    assert_eq(10, $p['cache_read_input_tokens']);
    assert_eq(3, $p['cache_creation_input_tokens']);
    assert_eq(null, $p['error']);
    assert_eq('end_turn', $p['stop_reason']);
});

test('usageTotals exposes accrued cache read and creation totals by API field name', function () {
    $client = new AnthropicClient('k', 'claude-opus-4-8');
    foreach ([
        'requests' => 2,
        'inputTokens' => 125,
        'outputTokens' => 50,
        'cacheReadInputTokens' => 20,
        'cacheCreationInputTokens' => 5,
    ] as $property => $value) {
        $reflection = new ReflectionProperty($client, $property);
        $reflection->setValue($client, $value);
    }

    assert_eq([
        'requests' => 2,
        'input_tokens' => 125,
        'output_tokens' => 50,
        'total_tokens' => 175,
        'cache_read_input_tokens' => 20,
        'cache_creation_input_tokens' => 5,
    ], $client->usageTotals());
});

test('parseSse surfaces a stream error', function () {
    $sse = "event: error\ndata: {\"type\":\"error\",\"error\":{\"type\":\"overloaded_error\",\"message\":\"overloaded\"}}\n";
    $p = AnthropicClient::parseSse($sse);
    assert_eq('overloaded', $p['error']);
    assert_eq('overloaded_error', $p['error_type']);
});

test('Anthropic batch transport preserves abnormal empty responses but retries ordinary empty successes', function () {
    $sse = implode("\n", [
        'data: {"type":"message_start","message":{"usage":{"input_tokens":5,"output_tokens":1}}}',
        'data: {"type":"message_delta","delta":{"stop_reason":"max_tokens"},"usage":{"output_tokens":9}}',
        'data: {"type":"message_stop"}',
        '',
    ]);

    $method = new ReflectionMethod(AnthropicClient::class, 'interpretStream');
    $method->setAccessible(true);

    $abnormal = $method->invoke(null, $sse, 0, '', 200, 0.25);
    assert_eq(true, $abnormal['ok'], 'raw-text recovery receives the terminal response without transport retries');
    assert_eq('', $abnormal['text']);
    assert_eq('max_tokens', $abnormal['stop_reason']);

    $ordinary = $method->invoke(null, str_replace('max_tokens', 'end_turn', $sse), 0, '', 200, 0.25);
    assert_eq(false, $ordinary['ok'], 'ordinary empty successes retain transport retry behavior');
    assert_eq(true, $ordinary['transient']);
});

test('Anthropic batch transport retries a stream severed before its terminal message_delta', function () {
    // A dropped connection can cut every multiplexed stream mid-response with
    // no cURL error and HTTP 200: text arrived, but the message_delta carrying
    // stop_reason never did. The partial text must not be mistaken for a
    // completion — downstream salvage would trim it and quietly ship a mostly
    // empty section.
    $severed = implode("\n", [
        'data: {"type":"message_start","message":{"usage":{"input_tokens":40,"output_tokens":5}}}',
        'data: {"type":"content_block_delta","delta":{"type":"text_delta","text":"<!-- wp:group -->"}}',
        'data: {"type":"content_block_delta","delta":{"type":"text_delta","text":"<div class=\"wp-block-gro"}}',
        '',
    ]);

    $method = new ReflectionMethod(AnthropicClient::class, 'interpretStream');
    $method->setAccessible(true);

    $outcome = $method->invoke(null, $severed, 0, '', 200, 0.25);
    assert_eq(false, $outcome['ok'], 'a severed stream is not a completion');
    assert_eq(true, $outcome['transient'], 'severed streams are retried, not fatal');
    assert_contains('severed before completion', $outcome['error']);
});
