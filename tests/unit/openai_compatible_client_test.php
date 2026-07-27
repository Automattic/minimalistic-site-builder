<?php
declare(strict_types=1);

use Automattic\SiteBuild\AnthropicClient;
use Automattic\SiteBuild\JsonBatchRecovery;
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
    // Legacy OpenAI model: keeps max_tokens and an explicit temperature.
    $body = OpenAiCompatibleClient::bodyFor(
        ['prompt' => 'Hi', 'temperature' => 0.9, 'system' => 'Be terse.'],
        'gpt-4o',
        16000,
        'openai',
    );

    assert_eq('gpt-4o', $body['model']);
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
        ['prompt' => 'Hi', 'model' => 'gpt-4o-mini', 'max_tokens' => 512],
        'gpt-4o',
        16000,
        'openai',
    );
    assert_eq('gpt-4o-mini', $body['model']);
    assert_eq(512, $body['max_tokens']);
    assert_true(!array_key_exists('temperature', $body), 'no temperature when unset');
    assert_eq(AnthropicClient::systemPreamble(), $body['messages'][0]['content']);
});

test('bodyFor prepends cached prefixes to the OpenAI user content without cache markers', function () {
    $body = OpenAiCompatibleClient::bodyFor(
        [
            'prompt' => 'Varying section prompt.',
            'cached_prefixes' => ['Build context.', '  ', 'Page context.'],
        ],
        'gpt-4o',
        16000,
        'openai',
    );

    assert_eq(
        "Build context.\n\nPage context.\n\nVarying section prompt.",
        $body['messages'][1]['content'],
    );
    assert_true(!str_contains((string) json_encode($body), 'cache_control'), 'OpenAI body has no explicit cache marker');
});

test('retrySingleRequest tolerates an empty truncated probe response without retrying', function () {
    $body = ['messages' => []];
    $calls = 0;
    $transport = function (array $requestBody) use (&$calls): array {
        $calls++;
        return [
            'text' => " \n\t",
            'input' => 100,
            'output' => 1,
            'time' => 0.25,
            'stop_reason' => 'length',
        ];
    };

    $result = OpenAiCompatibleClient::retrySingleRequest($body, $transport, [0, 0, 0], true);

    assert_eq(1, $calls, 'successful empty probe makes exactly one transport attempt');
    assert_eq('', $result['text'], 'tolerated whitespace is normalized to the empty-string contract');
});

test('retrySingleRequest accepts a non-empty truncated cache-warm probe', function () {
    foreach (['length', 'max_tokens'] as $stopReason) {
        $body = ['messages' => []];
        $calls = 0;
        $transport = function (array $requestBody) use (&$calls, $stopReason): array {
            $calls++;
            return [
                'text' => 'x',
                'input' => 100,
                'output' => 1,
                'time' => 0.25,
                'stop_reason' => $stopReason,
            ];
        };

        $result = OpenAiCompatibleClient::retrySingleRequest(
            $body,
            $transport,
            [0, 0, 0],
            true,
        );

        assert_eq(1, $calls, "{$stopReason} probe makes exactly one transport attempt");
        assert_eq('x', $result['text'], 'the successful probe token is preserved');
    }
});

test('retrySingleRequest rejects truncated ordinary text without retrying', function () {
    foreach (['length', 'max_tokens'] as $stopReason) {
        $body = ['messages' => []];
        $calls = 0;
        $transport = function (array $requestBody) use (&$calls, $stopReason): array {
            $calls++;
            return [
                'text' => 'partial response',
                'input' => 100,
                'output' => 1,
                'time' => 0.25,
                'stop_reason' => $stopReason,
            ];
        };

        assert_throws(
            fn () => OpenAiCompatibleClient::retrySingleRequest($body, $transport, [0, 0, 0]),
            'ordinary truncated text is never accepted as a complete response',
        );
        assert_eq(1, $calls, 'retrying unchanged cannot fix an exhausted output budget');
    }
});

test('retrySingleRequest does not hide a context-window failure in a cache probe', function () {
    foreach (['partial response', ''] as $text) {
        $body = ['messages' => []];
        $transport = fn (array $requestBody): array => [
            'text' => $text,
            'input' => 100,
            'output' => 1,
            'time' => 0.25,
            'stop_reason' => 'model_context_window_exceeded',
        ];

        assert_throws(
            fn () => OpenAiCompatibleClient::retrySingleRequest($body, $transport, [], true),
            'tolerate_empty only relaxes the expected one-token output-limit stop reasons',
        );
    }
});

test('maxTokensParam picks the right token key per provider and model', function () {
    // OpenAI reasoning / gpt-5+ → max_completion_tokens.
    assert_eq(['max_completion_tokens' => 100], OpenAiCompatibleClient::maxTokensParam('openai', 'gpt-5.5', 100));
    assert_eq(['max_completion_tokens' => 100], OpenAiCompatibleClient::maxTokensParam('openai', 'o1-mini', 100));
    // Legacy OpenAI → max_tokens.
    assert_eq(['max_tokens' => 100], OpenAiCompatibleClient::maxTokensParam('openai', 'gpt-4o', 100));
    assert_eq(['max_tokens' => 100], OpenAiCompatibleClient::maxTokensParam('openai', 'gpt-4.1', 100));
    assert_eq(['max_tokens' => 100], OpenAiCompatibleClient::maxTokensParam('openai', 'gpt-3.5-turbo', 100));
    // xAI (Grok) → always max_completion_tokens.
    assert_eq(['max_completion_tokens' => 100], OpenAiCompatibleClient::maxTokensParam('xai', 'grok-4.5', 100));
});

test('bodyFor uses max_completion_tokens and drops temperature for GPT-5 reasoning models', function () {
    $body = OpenAiCompatibleClient::bodyFor(
        ['prompt' => 'Hi', 'temperature' => 0.9, 'max_tokens' => 16000],
        'gpt-5.5',
        16000,
        'openai',
    );
    assert_eq('gpt-5.5', $body['model']);
    assert_eq(16000, $body['max_completion_tokens']);
    assert_true(!array_key_exists('max_tokens', $body), 'no legacy max_tokens for gpt-5');
    assert_true(!array_key_exists('temperature', $body), 'temperature dropped for gpt-5');
});

test('bodyFor keeps a custom temperature for xAI Grok (uses max_completion_tokens)', function () {
    $body = OpenAiCompatibleClient::bodyFor(
        ['prompt' => 'Hi', 'temperature' => 0.9],
        'grok-4.5',
        16000,
        'xai',
    );
    assert_eq(0.9, $body['temperature']);
    assert_eq(16000, $body['max_completion_tokens']);
});

test('bodyFor gives OpenRouter Kimi K3 its configured budget and omits unsupported temperature', function () {
    $body = OpenAiCompatibleClient::bodyFor(
        ['prompt' => 'Hi', 'temperature' => 0.9],
        'moonshotai/kimi-k3',
        16000,
        'openrouter',
    );
    assert_eq(65536, $body['max_tokens']);
    assert_true(!array_key_exists('max_completion_tokens', $body), 'OpenRouter uses max_tokens');
    assert_true(!array_key_exists('temperature', $body), 'K3 profile keeps the provider sampling default');

    $explicit = OpenAiCompatibleClient::bodyFor(
        ['prompt' => 'Hi', 'max_tokens' => 32000],
        'moonshotai/kimi-k3',
        16000,
        'openrouter',
    );
    assert_eq(32000, $explicit['max_tokens'], 'an explicit per-request budget still wins');

    $k2 = OpenAiCompatibleClient::bodyFor(
        ['prompt' => 'Hi'],
        'moonshotai/kimi-k2.5:nitro',
        16000,
        'openrouter',
    );
    assert_eq(16000, $k2['max_tokens'], 'the larger implicit budget is K3-only');
    assert_eq(['enabled' => false], $k2['reasoning'], 'K2.5 optional reasoning is disabled');
});

test('Kimi K3 JSON recovery doubles its effective 65k budget', function () {
    $client = new OpenAiCompatibleClient(
        'key',
        'moonshotai/kimi-k3',
        'https://openrouter.ai/api/v1',
        16000,
        'openrouter',
    );
    $method = new ReflectionMethod(OpenAiCompatibleClient::class, 'withEffectiveMaxTokens');
    $method->setAccessible(true);
    $requests = $method->invoke($client, [
        'direction' => ['prompt' => 'Choose a complete design direction.'],
    ]);
    assert_eq(65536, $requests['direction']['max_tokens'], 'recovery sees K3’s real first-attempt budget');

    $round = 0;
    $repair = null;
    $out = JsonBatchRecovery::run(
        $requests,
        function (array $subset) use (&$round, &$repair): array {
            $request = $subset['direction'];
            if ($round++ === 0) {
                return ['direction' => [
                    'text' => '{"direction":"cut off',
                    'stop_reason' => 'length',
                ]];
            }
            $repair = $request;
            return ['direction' => ['text' => '{"direction":"complete"}', 'stop_reason' => 'stop']];
        },
    );

    assert_eq('complete', $out['direction']['direction']);
    assert_eq(131072, $repair['max_tokens'], 'the retry grows rather than shrinking to the generic 32k default');
});

test('restrictsTemperature flags OpenAI reasoning models and OpenRouter K3', function () {
    assert_true(OpenAiCompatibleClient::restrictsTemperature('openai', 'gpt-5.5'), 'gpt-5.5 restricted');
    assert_true(OpenAiCompatibleClient::restrictsTemperature('openai', 'o3'), 'o3 restricted');
    assert_true(!OpenAiCompatibleClient::restrictsTemperature('openai', 'gpt-4o'), 'gpt-4o free');
    assert_true(!OpenAiCompatibleClient::restrictsTemperature('xai', 'grok-4.5'), 'grok free');
    assert_true(OpenAiCompatibleClient::restrictsTemperature('openrouter', 'moonshotai/kimi-k3'), 'K3 restricted');
    assert_true(!OpenAiCompatibleClient::restrictsTemperature('openrouter', 'moonshotai/kimi-k2.5'), 'K2.5 free');
});

test('rejectedParam catches OpenAI temperature errors and falls back to Anthropic wording', function () {
    $openaiTemp = "Unsupported value: 'temperature' does not support 0.9 with this model. "
        . 'Only the default (1) value is supported.';
    assert_eq('temperature', OpenAiCompatibleClient::rejectedParam($openaiTemp));

    $openaiTopP = "Unsupported parameter: 'top_p' is not supported with this model.";
    assert_eq('top_p', OpenAiCompatibleClient::rejectedParam($openaiTopP));

    // max_tokens is keyed correctly up front, so it must NOT be treated as a
    // droppable sampling param here.
    $maxTokens = "Unsupported parameter: 'max_tokens' is not supported with this model.";
    assert_eq(null, OpenAiCompatibleClient::rejectedParam($maxTokens));

    // Delegates to AnthropicClient for the shared Anthropic phrasing.
    assert_eq('temperature', OpenAiCompatibleClient::rejectedParam('`temperature` is not supported'));
    assert_eq(null, OpenAiCompatibleClient::rejectedParam('some unrelated error'));
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

test('token-limited output reaches JSON recovery but is rejected as ordinary text', function () {
    $raw = implode("\n", [
        'data: {"choices":[{"delta":{"content":"<!-- wp:group"},"index":0}]}',
        'data: {"choices":[{"delta":{},"finish_reason":"length"}],"usage":{"prompt_tokens":10,"completion_tokens":16000}}',
        'data: [DONE]',
        '',
    ]);

    $parsed = OpenAiCompatibleClient::parseSse($raw);
    assert_eq('<!-- wp:group', $parsed['text'], 'partial text is retained for diagnostics');
    assert_eq('length', $parsed['stop_reason']);
    assert_eq(null, $parsed['error'], 'the recovery layer, not the parser, owns truncation policy');

    $method = new ReflectionMethod(OpenAiCompatibleClient::class, 'interpretStream');
    $method->setAccessible(true);

    $jsonResult = $method->invoke(null, $raw, 0, '', 200, 0.25, true);
    assert_eq(true, $jsonResult['ok'], 'JSON recovery receives the partial response and stop reason');
    assert_eq('length', $jsonResult['stop_reason']);

    $textResult = $method->invoke(null, $raw, 0, '', 200, 0.25, false);
    assert_eq(false, $textResult['ok'], 'ordinary text must not accept truncated markup');
    assert_eq(false, $textResult['transient'], 'retrying unchanged cannot fix an exhausted budget');
    assert_contains('truncated', (string) $textResult['error']);
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
    assert_eq(true, OpenAiCompatibleClient::isTransientStreamError($parsed));
});

test('OpenAiCompatibleClient implements Llm', function () {
    $c = new OpenAiCompatibleClient('k', 'm', 'https://api.x.ai/v1');
    assert_true($c instanceof Automattic\SiteBuild\Llm);
    foreach (['complete', 'completeJson', 'completeBatch', 'completeJsonBatch'] as $method) {
        assert_true(method_exists($c, $method), "missing {$method}");
    }
});

test('bodyFor maps json_schema to a strict OpenAI response_format', function () {
    $schema = [
        'type' => 'object',
        'properties' => ['sections' => ['type' => 'array', 'items' => ['type' => 'string']]],
        'required' => ['sections'],
        'additionalProperties' => false,
    ];

    $body = OpenAiCompatibleClient::bodyFor(
        [
            'prompt' => 'Plan a page.',
            'json_schema' => ['name' => 'page_plan', 'schema' => $schema],
        ],
        'gpt-5.5',
        16000,
        'openai',
    );

    assert_eq([
        'type' => 'json_schema',
        'json_schema' => [
            'name' => 'page_plan',
            'strict' => true,
            'schema' => $schema,
        ],
    ], $body['response_format']);
});

test('bodyFor uses JSON-object response_format only for generic JSON calls', function () {
    $jsonBody = OpenAiCompatibleClient::bodyFor(
        ['prompt' => 'Return an object.'],
        'moonshotai/kimi-k2.5',
        16000,
        'openrouter',
        true,
    );
    assert_eq(['type' => 'json_object'], $jsonBody['response_format']);

    $textBody = OpenAiCompatibleClient::bodyFor(
        ['prompt' => 'Return prose.'],
        'moonshotai/kimi-k2.5',
        16000,
        'openrouter',
    );
    assert_true(!array_key_exists('response_format', $textBody), 'ordinary text does not request JSON mode');
});

test('bodyFor keeps json_schema response_format when JSON mode is enabled', function () {
    $schema = [
        'type' => 'object',
        'properties' => ['ok' => ['type' => 'boolean']],
        'required' => ['ok'],
        'additionalProperties' => false,
    ];
    $body = OpenAiCompatibleClient::bodyFor(
        [
            'prompt' => 'Return the result.',
            'json_schema' => ['name' => 'result', 'schema' => $schema],
        ],
        'moonshotai/kimi-k2.5',
        16000,
        'openrouter',
        true,
    );

    assert_eq('json_schema', $body['response_format']['type']);
    assert_eq($schema, $body['response_format']['json_schema']['schema']);
});

test('bodyFor maps json_schema to the same strict xAI response_format', function () {
    $schema = [
        'type' => 'object',
        'properties' => ['sections' => ['type' => 'array', 'items' => ['type' => 'string']]],
        'required' => ['sections'],
        'additionalProperties' => false,
    ];

    $body = OpenAiCompatibleClient::bodyFor(
        [
            'prompt' => 'Plan a page.',
            'json_schema' => ['name' => 'page_plan', 'schema' => $schema],
        ],
        'grok-4.5',
        16000,
        'xai',
    );

    assert_eq([
        'type' => 'json_schema',
        'json_schema' => [
            'name' => 'page_plan',
            'strict' => true,
            'schema' => $schema,
        ],
    ], $body['response_format']);
});

test('parseSse classifies OpenAI refusal fields without leaking refusal text as content', function () {
    $nonStream = json_encode([
        'choices' => [[
            'message' => ['role' => 'assistant', 'content' => null, 'refusal' => 'I cannot help with that.'],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 1],
    ], JSON_UNESCAPED_SLASHES);

    $parsed = OpenAiCompatibleClient::parseSse((string) $nonStream);
    assert_eq('', $parsed['text']);
    assert_eq(8, $parsed['input']);
    assert_eq(1, $parsed['output']);
    assert_eq(null, $parsed['error']);
    assert_eq('refusal', $parsed['stop_reason']);

    $stream = implode("\n", [
        'data: {"choices":[{"delta":{"refusal":"I cannot help"},"index":0}]}',
        'data: {"choices":[{"delta":{},"finish_reason":"stop"}],"usage":{"prompt_tokens":8,"completion_tokens":1}}',
        'data: [DONE]',
        '',
    ]);
    $parsed = OpenAiCompatibleClient::parseSse($stream);
    assert_eq('', $parsed['text']);
    assert_eq('refusal', $parsed['stop_reason'], 'a later finish_reason does not erase the refusal classification');
});

test('OpenAI batch transport preserves abnormal empty responses but retries ordinary empty successes', function () {
    $raw = json_encode([
        'choices' => [[
            'message' => ['role' => 'assistant', 'content' => null, 'refusal' => 'I cannot help with that.'],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 1],
    ], JSON_UNESCAPED_SLASHES);

    $method = new ReflectionMethod(OpenAiCompatibleClient::class, 'interpretStream');
    $method->setAccessible(true);

    $abnormal = $method->invoke(null, (string) $raw, 0, '', 200, 0.25);
    assert_eq(true, $abnormal['ok'], 'raw-text recovery receives the refusal without transport retries');
    assert_eq('', $abnormal['text']);
    assert_eq('refusal', $abnormal['stop_reason']);

    $ordinaryRaw = json_encode([
        'choices' => [[
            'message' => ['role' => 'assistant', 'content' => null],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 0],
    ], JSON_UNESCAPED_SLASHES);
    $ordinary = $method->invoke(null, (string) $ordinaryRaw, 0, '', 200, 0.25);
    assert_eq(false, $ordinary['ok'], 'ordinary empty successes retain transport retry behavior');
    assert_eq(true, $ordinary['transient']);
});

test('parseSse preserves OpenRouter mid-stream error code and type for retry classification', function () {
    $raw = 'data: {"error":{"code":520,"message":"error code: 520",'
        . '"metadata":{"error_type":"provider_unavailable"}},'
        . '"choices":[{"delta":{},"finish_reason":"error"}]}' . "\n\n";

    $parsed = OpenAiCompatibleClient::parseSse($raw);
    assert_eq('error code: 520', $parsed['error']);
    assert_eq(520, $parsed['error_code']);
    assert_eq('provider_unavailable', $parsed['error_type']);
    assert_eq('error', $parsed['stop_reason']);
    assert_eq(true, OpenAiCompatibleClient::isTransientStreamError($parsed));
});

test('parseSse preserves an OpenRouter error nested in a non-stream choice', function () {
    $raw = json_encode([
        'choices' => [[
            'message' => ['role' => 'assistant', 'content' => null],
            'finish_reason' => 'error',
            'error' => ['code' => 503, 'message' => 'upstream unavailable'],
        ]],
    ]);

    $parsed = OpenAiCompatibleClient::parseSse((string) $raw);
    assert_eq('upstream unavailable', $parsed['error']);
    assert_eq(503, $parsed['error_code']);
    assert_eq('error', $parsed['stop_reason']);
    assert_eq(true, OpenAiCompatibleClient::isTransientStreamError($parsed));
});

test('OpenAiCompatibleClient concurrencyWindows applies a provider-specific cap', function () {
    $bodies = [];
    for ($i = 0; $i < 9; $i++) {
        $bodies["r{$i}"] = ['model' => 'm'];
    }
    $windows = OpenAiCompatibleClient::concurrencyWindows($bodies, 4);
    assert_eq([4, 4, 1], array_map('count', $windows));
    assert_eq(array_keys($bodies), array_keys(array_merge(...$windows)), 'keys and order are preserved');
});
