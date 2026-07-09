<?php
declare(strict_types=1);

use Automattic\SiteBuild\AnthropicClient;
use Automattic\SiteBuild\OpenAiCompatibleClient;

/**
 * Unit tests for OpenAiCompatibleClient pure helpers (body shape, SSE parse,
 * usage extraction). No network.
 */

test('OpenAiCompatibleClient endpoint joins baseUrl and /chat/completions', function () {
    $c = new OpenAiCompatibleClient('key', 'grok-4.5', 'https://api.x.ai/v1/');
    assert_eq('https://api.x.ai/v1/chat/completions', $c->endpoint());

    $c = new OpenAiCompatibleClient('key', 'gpt-4o', 'https://api.openai.com/v1');
    assert_eq('https://api.openai.com/v1/chat/completions', $c->endpoint());
});

test('bodyFor builds OpenAI chat messages with system preamble and stream_options', function () {
    $body = OpenAiCompatibleClient::bodyFor(
        ['prompt' => 'Hi', 'temperature' => 0.9, 'system' => 'Be terse.'],
        'grok-4.5',
        16000,
    );

    assert_eq('grok-4.5', $body['model']);
    assert_eq(16000, $body['max_tokens']);
    assert_eq(0.9, $body['temperature']);
    assert_eq(true, $body['stream']);
    assert_eq(['include_usage' => true], $body['stream_options']);

    assert_eq(2, count($body['messages']));
    assert_eq('system', $body['messages'][0]['role']);
    assert_eq('user', $body['messages'][1]['role']);
    assert_eq('Hi', $body['messages'][1]['content']);

    $system = (string) $body['messages'][0]['content'];
    assert_true(str_starts_with($system, AnthropicClient::systemPreamble()), 'preamble first');
    assert_true(str_ends_with($system, 'Be terse.'), 'per-request system follows');
    assert_true(str_contains($system, 'language of the original user prompt'), 'language rule present');
});

test('bodyFor applies per-request model/max_tokens and omits temperature when unset', function () {
    $body = OpenAiCompatibleClient::bodyFor(
        ['prompt' => 'Hi', 'model' => 'grok-mini', 'max_tokens' => 512],
        'grok-4.5',
        16000,
    );
    assert_eq('grok-mini', $body['model']);
    assert_eq(512, $body['max_tokens']);
    assert_true(!array_key_exists('temperature', $body), 'no temperature when unset');
    assert_eq(AnthropicClient::systemPreamble(), $body['messages'][0]['content']);
});

test('extractUsage reads OpenAI prompt/completion tokens and Anthropic-style aliases', function () {
    assert_eq(
        ['input' => 10, 'output' => 3],
        OpenAiCompatibleClient::extractUsage([
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 3, 'total_tokens' => 13],
        ]),
    );
    assert_eq(
        ['input' => 8, 'output' => 2],
        OpenAiCompatibleClient::extractUsage([
            'usage' => ['input_tokens' => 8, 'output_tokens' => 2],
        ]),
    );
    // Flat usage object (no nesting).
    assert_eq(
        ['input' => 1, 'output' => 2],
        OpenAiCompatibleClient::extractUsage(['prompt_tokens' => 1, 'completion_tokens' => 2]),
    );
});

test('parseSse concatenates delta content and reads final usage chunk', function () {
    $raw = implode("\n", [
        'data: {"id":"1","choices":[{"delta":{"role":"assistant"},"index":0}]}',
        'data: {"choices":[{"delta":{"content":"Hel"},"index":0}]}',
        'data: {"choices":[{"delta":{"content":"lo"},"index":0}]}',
        'data: {"choices":[{"delta":{},"finish_reason":"stop"}],"usage":{"prompt_tokens":12,"completion_tokens":2,"total_tokens":14}}',
        'data: [DONE]',
        '',
    ]);

    $parsed = OpenAiCompatibleClient::parseSse($raw);
    assert_eq('Hello', $parsed['text']);
    assert_eq(12, $parsed['input']);
    assert_eq(2, $parsed['output']);
    assert_eq(null, $parsed['error']);
});

test('parseSse accepts a non-stream JSON chat.completion body', function () {
    $raw = json_encode([
        'id' => 'chatcmpl-x',
        'object' => 'chat.completion',
        'choices' => [
            ['index' => 0, 'message' => ['role' => 'assistant', 'content' => '{"ok":true}'], 'finish_reason' => 'stop'],
        ],
        'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 4],
    ], JSON_UNESCAPED_SLASHES);

    $parsed = OpenAiCompatibleClient::parseSse((string) $raw);
    assert_eq('{"ok":true}', $parsed['text']);
    assert_eq(5, $parsed['input']);
    assert_eq(4, $parsed['output']);
    assert_eq(null, $parsed['error']);
});

test('parseSse surfaces error objects from JSON or SSE', function () {
    $jsonErr = '{"error":{"message":"Invalid API key","type":"invalid_request_error"}}';
    $parsed = OpenAiCompatibleClient::parseSse($jsonErr);
    assert_eq('Invalid API key', $parsed['error']);
    assert_eq('', $parsed['text']);

    $sseErr = "data: {\"error\":{\"message\":\"rate limit exceeded\"}}\n\n";
    $parsed = OpenAiCompatibleClient::parseSse($sseErr);
    assert_eq('rate limit exceeded', $parsed['error']);
});

test('OpenAiCompatibleClient implements Llm', function () {
    $c = new OpenAiCompatibleClient('k', 'm', 'https://api.x.ai/v1');
    assert_true($c instanceof Automattic\SiteBuild\Llm);
    foreach (['complete', 'completeJson', 'completeBatch', 'completeJsonBatch'] as $method) {
        assert_true(method_exists($c, $method), "missing {$method}");
    }
});
