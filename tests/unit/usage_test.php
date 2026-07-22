<?php
declare(strict_types=1);

use Automattic\SiteBuild\AnthropicClient;

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
        'data: {"type":"message_delta","usage":{"output_tokens":7}}',
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
