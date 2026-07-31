<?php
declare(strict_types=1);

use Automattic\SiteBuild\AnthropicClient;
use Automattic\SiteBuild\FinishReasonAwareLlm;
use Automattic\SiteBuild\JsonBatchRecovery;
use Automattic\SiteBuild\OpenAiCompatibleClient;
use Automattic\SiteBuild\TextBatchRecovery;
use Automattic\SiteBuild\TransientApiException;

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

test('OpenAiCompatibleClient implements the frozen finish-reason capability', function () {
    $client = new OpenAiCompatibleClient('key', 'gpt-4o');

    assert_true(
        $client instanceof FinishReasonAwareLlm,
        'single-completion clients implement the frozen finish-reason capability',
    );
    assert_eq(null, $client->lastFinishReason(), 'finish reason starts unknown');
});

test('OpenAiCompatibleClient has no client-local truncation classifier', function () {
    assert_true(
        !method_exists(OpenAiCompatibleClient::class, 'isTruncationStopReason'),
        'single and batch paths must share TextBatchRecovery::isTruncation()',
    );
});

test('OpenAiCompatibleClient single complete exposes the transport finish reason', function () {
    $client = new OpenAiCompatibleClient(
        'key',
        'gpt-4o',
        singleTransport: fn (array $requestBody): array => [
            'text' => 'complete response',
            'input' => 12,
            'output' => 3,
            'time' => 0.01,
            'stop_reason' => 'stop',
        ],
    );

    assert_eq('complete response', $client->complete('Generate the page.'));
    assert_eq('stop', $client->lastFinishReason());
});

test('OpenRouter single complete returns a truncated partial without regenerating from scratch', function () {
    $requests = [];
    $client = new OpenAiCompatibleClient(
        'key',
        'openai/gpt-5.5',
        'https://openrouter.ai/api/v1',
        16000,
        'openrouter',
        singleTransport: function (array $requestBody) use (&$requests): array {
            $requests[] = $requestBody;
            return [
                'text' => '<!-- wp:group {"tagName":"main"} -->',
                'input' => 100,
                'output' => 16000,
                'time' => 0.01,
                'stop_reason' => 'length',
            ];
        },
    );

    assert_eq(
        '<!-- wp:group {"tagName":"main"} -->',
        $client->complete('Generate the page.'),
        'caller receives the paid-for partial so ContinuationRecovery can stitch it',
    );
    assert_eq('length', $client->lastFinishReason());
    assert_eq(1, count($requests), 'single completion does not use the old from-scratch regeneration');
    assert_true(
        !str_contains(
            (string) $requests[0]['messages'][1]['content'],
            'Regenerate the COMPLETE response from scratch',
        ),
        'request remains the authored prompt',
    );
});

test('OpenRouter single complete keeps refusal and filter finish reasons fatal', function () {
    foreach (['refusal', 'content_filter', 'safety'] as $stopReason) {
        $calls = 0;
        $client = new OpenAiCompatibleClient(
            'key',
            'openai/gpt-5.5',
            'https://openrouter.ai/api/v1',
            16000,
            'openrouter',
            singleTransport: function (array $requestBody) use (&$calls, $stopReason): array {
                $calls++;
                return [
                    'text' => 'partial response',
                    'input' => 100,
                    'output' => 1,
                    'time' => 0.01,
                    'stop_reason' => $stopReason,
                ];
            },
        );

        assert_throws(
            fn () => $client->complete('Generate the page.'),
            "{$stopReason}: refusal/filter remains fatal",
        );
        assert_eq(1, $calls, "{$stopReason}: failed terminal response is not retried");
        assert_eq(null, $client->lastFinishReason(), "{$stopReason}: failed attempt exposes no stale finish reason");
    }
});

test('OpenAiCompatibleClient clears the last finish reason before a failed complete attempt', function () {
    $attempt = 0;
    $client = new OpenAiCompatibleClient(
        'key',
        'gpt-4o',
        singleTransport: function (array $requestBody) use (&$attempt): array {
            $attempt++;
            if ($attempt === 1) {
                return [
                    'text' => 'first response',
                    'input' => 1,
                    'output' => 1,
                    'time' => 0.01,
                    'stop_reason' => 'stop',
                ];
            }
            throw new RuntimeException('injected transport failure');
        },
    );

    assert_eq('first response', $client->complete('First prompt.'));
    assert_eq('stop', $client->lastFinishReason());

    assert_throws(
        fn () => $client->complete('Second prompt.'),
        'injected transport failure stays fatal',
    );
    assert_eq(null, $client->lastFinishReason(), 'failed attempt clears prior successful finish reason');
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

test('retrySingleRequest preserves legacy handling while OpenRouter regenerates one truncation', function () {
    foreach (['length', 'max_tokens'] as $stopReason) {
        $legacyBody = ['messages' => []];
        $legacy = OpenAiCompatibleClient::retrySingleRequest(
            $legacyBody,
            fn (array $requestBody): array => [
                'text' => 'partial response',
                'input' => 100,
                'output' => 16000,
                'time' => 0.25,
                'stop_reason' => $stopReason,
            ],
            [0, 0, 0],
        );
        assert_eq('partial response', $legacy['text'], 'existing providers retain their previous behavior');

        $body = [
            'max_tokens' => 16000,
            'messages' => [['role' => 'user', 'content' => 'Generate the page.']],
        ];
        $requests = [];
        $transport = function (array $requestBody) use (&$requests, $stopReason): array {
            $requests[] = $requestBody;
            if (count($requests) === 1) {
                return [
                    'text' => 'partial response',
                    'input' => 100,
                    'output' => 16000,
                    'time' => 0.25,
                    'stop_reason' => $stopReason,
                ];
            }
            return [
                'text' => 'complete response',
                'input' => 100,
                'output' => 20000,
                'time' => 0.25,
                'stop_reason' => 'stop',
            ];
        };

        $result = OpenAiCompatibleClient::retrySingleRequest(
            $body,
            $transport,
            [0, 0, 0],
            false,
            true,
        );
        assert_eq('complete response', $result['text']);
        assert_eq(2, count($requests), "{$stopReason}: OpenRouter regenerates exactly once");
        assert_eq(16000, $requests[0]['max_tokens']);
        assert_eq(32000, $requests[1]['max_tokens'], 'the exhausted output budget doubles');
        assert_contains(
            'Regenerate the COMPLETE response from scratch',
            $requests[1]['messages'][0]['content'],
            'the retry asks for a fresh complete response',
        );
    }
});

test('retrySingleRequest fails after one persistent OpenRouter truncation', function () {
    $body = [
        'max_tokens' => 65536,
        'messages' => [['role' => 'user', 'content' => 'Generate the page.']],
    ];
    $requests = [];
    $transport = function (array $requestBody) use (&$requests): array {
        $requests[] = $requestBody;
        return [
            'text' => 'partial response',
            'input' => 100,
            'output' => (int) $requestBody['max_tokens'],
            'time' => 0.25,
            'stop_reason' => 'max_tokens',
        ];
    };

    assert_throws(
        fn () => OpenAiCompatibleClient::retrySingleRequest($body, $transport, [], false, true),
        'a second truncation is not accepted as complete',
    );
    assert_eq(2, count($requests), 'only one provider-specific regeneration is attempted');
    assert_eq(65536, $requests[0]['max_tokens']);
    assert_eq(131072, $requests[1]['max_tokens'], 'K3 retries from its real effective budget');
});

test('retrySingleRequest rejects OpenRouter refusals and filters even when text is present', function () {
    foreach (['refusal', 'content_filter', 'safety'] as $stopReason) {
        foreach (['', 'partial response'] as $text) {
            foreach ([false, true] as $tolerateEmpty) {
                $body = [
                    'max_tokens' => 16000,
                    'messages' => [['role' => 'user', 'content' => 'Generate the page.']],
                ];
                $calls = 0;
                $transport = function (array $requestBody) use (&$calls, $stopReason, $text): array {
                    $calls++;
                    return [
                        'text' => $text,
                        'input' => 100,
                        'output' => 1,
                        'time' => 0.25,
                        'stop_reason' => $stopReason,
                    ];
                };

                assert_throws(
                    fn () => OpenAiCompatibleClient::retrySingleRequest(
                        $body,
                        $transport,
                        [],
                        $tolerateEmpty,
                        true,
                    ),
                    "{$stopReason}: semantic failure is never returned as a successful completion",
                );
                assert_eq(1, $calls, "{$stopReason}: refusal/filter is not retried unchanged");
            }
        }
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
            fn () => OpenAiCompatibleClient::retrySingleRequest($body, $transport, [], true, true),
            'tolerate_empty only relaxes the expected one-token output-limit stop reasons',
        );
    }
});

test('OpenRouter single retries honor Retry-After through the injectable delay seams', function () {
    $body = ['messages' => []];
    $calls = 0;
    $now = 100;
    $retryAfterAt = null;
    $transport = function (array $requestBody) use (&$calls, &$retryAfterAt, &$now): array {
        $calls++;
        if ($calls === 1) {
            $retryAfterAt = 107;
            $now = 105;
            throw new TransientApiException('HTTP 429');
        }
        return ['text' => 'ok', 'input' => 1, 'output' => 1, 'time' => 0.1];
    };
    $delay = static function (int $fallback) use (&$retryAfterAt, &$now): int {
        return max($fallback, ($retryAfterAt ?? $now) - $now);
    };
    $slept = [];

    $result = OpenAiCompatibleClient::retrySingleRequest(
        $body,
        $transport,
        [2],
        false,
        false,
        $delay,
        function (int $seconds) use (&$slept): void {
            $slept[] = $seconds;
        },
    );

    assert_eq('ok', $result['text']);
    assert_eq(2, $calls, 'one transient response is retried once');
    assert_eq([2], $slept, 'time elapsed while receiving the response is not slept again');
});

test('OpenRouter batch retries wait only until the latest Retry-After deadline', function () {
    $bodies = ['a' => [], 'b' => []];
    $round = 0;
    $now = 100;
    $transport = function (array $subset) use (&$round, &$now): array {
        $round++;
        if ($round === 1) {
            $now = 105;
            return [
                'a' => ['ok' => false, 'transient' => true, 'error' => 'rate limited', 'retry_after_at' => 103],
                'b' => ['ok' => false, 'transient' => true, 'error' => 'rate limited', 'retry_after_at' => 107],
            ];
        }
        return [
            'a' => ['ok' => true, 'text' => 'A', 'input' => 0, 'output' => 0],
            'b' => ['ok' => true, 'text' => 'B', 'input' => 0, 'output' => 0],
        ];
    };
    $slept = [];

    $result = OpenAiCompatibleClient::retryOpenRouterBatch(
        $bodies,
        $transport,
        [0],
        null,
        function (int $seconds) use (&$slept): void {
            $slept[] = $seconds;
        },
        static function () use (&$now): int {
            return $now;
        },
    );

    assert_eq('A', $result['a']['text']);
    assert_eq('B', $result['b']['text']);
    assert_eq([2], $slept, 'expired and elapsed portions of server delays are not slept again');
});

test('a huge Retry-After is clamped so a quota 429 cannot park the build', function () {
    // A daily/credit 429 answering `Retry-After: 3600` — or an absolute-date
    // header read against a skewed clock — would otherwise sleep for an hour
    // per round behind one STDERR line. Past the cap the request takes the
    // ordinary transient path and fails in seconds.
    $bodies = ['a' => []];
    $round = 0;
    $now = 100;
    $transport = function (array $subset) use (&$round, &$now): array {
        $round++;
        if ($round === 1) {
            return ['a' => [
                'ok' => false,
                'transient' => true,
                'error' => 'rate limited',
                'retry_after_at' => $now + 3600,
            ]];
        }
        return ['a' => ['ok' => true, 'text' => 'A', 'input' => 0, 'output' => 0]];
    };
    $slept = [];

    $result = OpenAiCompatibleClient::retryOpenRouterBatch(
        $bodies,
        $transport,
        [0],
        null,
        function (int $seconds) use (&$slept): void {
            $slept[] = $seconds;
        },
        static function () use (&$now): int {
            return $now;
        },
    );

    assert_eq('A', $result['a']['text']);
    assert_eq(1, count($slept), 'one honored wait');
    assert_eq(120, $slept[0], 'the hour-long server delay is capped');
});

test('Retry-After parsing supports seconds and HTTP dates as absolute deadlines', function () {
    $parse = new ReflectionMethod(OpenAiCompatibleClient::class, 'retryAfterDeadline');
    $parse->setAccessible(true);
    $capture = new ReflectionMethod(OpenAiCompatibleClient::class, 'captureRetryAfterHeader');
    $capture->setAccessible(true);

    assert_eq(1007, $parse->invoke(null, '7', 1000));
    $future = gmdate('D, d M Y H:i:s \G\M\T', 1017);
    assert_eq(1017, $parse->invoke(null, $future, 1000));
    assert_eq(1000, $parse->invoke(null, gmdate('D, d M Y H:i:s \G\M\T', 999), 1000));
    assert_eq(null, $parse->invoke(null, 'not-a-delay', 1000));

    $captured = null;
    $args = ["rEtRy-AfTeR: 11\r\n", &$captured, 1000];
    assert_eq(strlen($args[0]), $capture->invokeArgs(null, $args));
    assert_eq(1011, $captured);
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

    $versionedK3 = OpenAiCompatibleClient::bodyFor(
        ['prompt' => 'Hi', 'temperature' => 0.9],
        'moonshotai/kimi-k3-20260715:nitro',
        16000,
        'openrouter',
    );
    assert_eq(65536, $versionedK3['max_tokens'], 'canonical K3 ids retain the larger budget');
    assert_true(!array_key_exists('temperature', $versionedK3), 'canonical K3 ids retain sampling safeguards');

    $versionedK2 = OpenAiCompatibleClient::bodyFor(
        ['prompt' => 'Hi'],
        'moonshotai/kimi-k2.5-0127:nitro',
        16000,
        'openrouter',
    );
    assert_eq(['enabled' => false], $versionedK2['reasoning'], 'canonical K2.5 ids disable optional reasoning');

    foreach ([
        'moonshotai/kimi-k3-preview',
        'moonshotai/kimi-k3-123',
        'moonshotai/kimi-k30',
        'moonshotai/kimi-k3x',
        'moonshotai/kimi-k3:',
        'moonshotai/kimi-k3:nitro:free',
    ] as $nearMatch) {
        $near = OpenAiCompatibleClient::bodyFor(
            ['prompt' => 'Hi', 'temperature' => 0.9],
            $nearMatch,
            16000,
            'openrouter',
        );
        assert_eq(16000, $near['max_tokens'], "{$nearMatch}: does not receive K3’s token floor");
        assert_eq(0.9, $near['temperature'], "{$nearMatch}: does not receive K3’s sampling policy");
    }

    foreach ([
        'moonshotai/kimi-k2.5-preview',
        'moonshotai/kimi-k2.5-123',
        'moonshotai/kimi-k2.50',
        'moonshotai/kimi-k2.5x',
        'moonshotai/kimi-k2.5:',
        'moonshotai/kimi-k2.5:nitro:free',
    ] as $nearMatch) {
        $near = OpenAiCompatibleClient::bodyFor(
            ['prompt' => 'Hi'],
            $nearMatch,
            16000,
            'openrouter',
        );
        assert_true(
            !array_key_exists('reasoning', $near),
            "{$nearMatch}: does not receive K2.5’s reasoning policy",
        );
    }
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

test('Kimi K3 text recovery doubles its effective 65k budget without changing other providers', function () {
    $kimi = new OpenAiCompatibleClient(
        'key',
        'moonshotai/kimi-k3',
        'https://openrouter.ai/api/v1',
        16000,
        'openrouter',
    );
    $method = new ReflectionMethod(OpenAiCompatibleClient::class, 'withEffectiveMaxTokens');
    $method->setAccessible(true);
    $requests = $method->invoke($kimi, [
        'section' => ['prompt' => 'Generate complete block markup.'],
    ]);
    assert_eq(65536, $requests['section']['max_tokens'], 'text recovery sees K3’s real first-attempt budget');

    $round = 0;
    $retry = null;
    $out = TextBatchRecovery::run(
        $requests,
        function (array $subset) use (&$round, &$retry): array {
            if ($round++ === 0) {
                return ['section' => ['text' => '<!-- wp:group', 'stop_reason' => 'max_tokens']];
            }
            $retry = $subset['section'];
            return ['section' => ['text' => '<!-- wp:group --><!-- /wp:group -->', 'stop_reason' => 'stop']];
        },
    )->texts;

    assert_eq('<!-- wp:group --><!-- /wp:group -->', $out['section']);
    assert_eq(131072, $retry['max_tokens'], 'the text retry grows from K3’s effective budget');

    $openai = new OpenAiCompatibleClient('key', 'gpt-4o', defaultMaxTokens: 4096);
    $unchanged = $method->invoke($openai, ['request' => ['prompt' => 'Hi']]);
    assert_true(
        !array_key_exists('max_tokens', $unchanged['request']),
        'existing providers keep their previous implicit request budget',
    );
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

    foreach (['openai' => 'gpt-4o', 'xai' => 'grok-4.5'] as $provider => $model) {
        $existingProviderBody = OpenAiCompatibleClient::bodyFor(
            ['prompt' => 'Return an object.'],
            $model,
            16000,
            $provider,
            true,
        );
        assert_true(
            !array_key_exists('response_format', $existingProviderBody),
            "{$provider} keeps its pre-OpenRouter schema-less JSON request shape",
        );
    }
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

test('OpenRouter typed terminal SSE errors preserve partial text for batch recovery', function () {
    $method = new ReflectionMethod(OpenAiCompatibleClient::class, 'interpretStream');
    $method->setAccessible(true);

    $cases = [
        'max_tokens_exceeded' => ['max_tokens', 400],
        'refusal' => ['refusal', 403],
        'content_policy_violation' => ['content_filter', 403],
    ];
    foreach ($cases as $errorType => [$stopReason, $code]) {
        $raw = implode("\n", [
            'data: {"choices":[{"delta":{"content":"partial output"},"index":0}]}',
            'data: {"error":{"code":' . $code . ',"message":"typed terminal event",'
                . '"metadata":{"error_type":"' . $errorType . '"}},'
                . '"choices":[{"delta":{},"finish_reason":"error"}],'
                . '"usage":{"prompt_tokens":12,"completion_tokens":4}}',
            'data: [DONE]',
            '',
        ]);

        $parsed = OpenAiCompatibleClient::parseSse($raw);
        assert_eq('partial output', $parsed['text'], "{$errorType}: parser retains text before the error event");
        assert_eq($errorType, $parsed['error_type'], "{$errorType}: typed metadata is retained");

        $outcome = $method->invoke(null, $raw, 0, '', 200, 0.25, true, 'openrouter');
        assert_eq(true, $outcome['ok'], "{$errorType}: semantic termination reaches batch recovery");
        assert_eq('partial output', $outcome['text'], "{$errorType}: recovery receives the partial candidate");
        assert_eq($stopReason, $outcome['stop_reason'], "{$errorType}: normalized stop reason");
        assert_eq(12, $outcome['input'], "{$errorType}: usage is retained");
        assert_eq(4, $outcome['output'], "{$errorType}: usage is retained");
        assert_true(
            JsonBatchRecovery::terminationError($outcome['stop_reason']) !== null,
            "{$errorType}: existing recovery class recognizes the normalized reason",
        );
    }
});

test('OpenRouter typed terminal HTTP 400 responses reach provider recovery', function () {
    $interpretBatch = new ReflectionMethod(OpenAiCompatibleClient::class, 'interpretStream');
    $interpretBatch->setAccessible(true);
    $interpretSingle = new ReflectionMethod(OpenAiCompatibleClient::class, 'interpretSingleStream');
    $interpretSingle->setAccessible(true);

    $cases = [
        'max_tokens_exceeded' => 'max_tokens',
        'refusal' => 'refusal',
        'content_policy_violation' => 'content_filter',
    ];
    foreach ($cases as $errorType => $stopReason) {
        $raw = json_encode([
            'error' => [
                'code' => 400,
                'message' => 'typed terminal response',
                'metadata' => ['error_type' => $errorType],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $batch = $interpretBatch->invoke(
            null,
            (string) $raw,
            0,
            '',
            400,
            0.25,
            true,
            'openrouter',
        );
        assert_eq(true, $batch['ok'], "{$errorType}: HTTP status does not bypass batch recovery");
        assert_eq($stopReason, $batch['stop_reason'], "{$errorType}: batch stop reason is normalized");

        $single = $interpretSingle->invoke(null, (string) $raw, 0.25, 'openrouter', 400);
        assert_eq($stopReason, $single['stop_reason'], "{$errorType}: single-call policy receives the stop reason");
    }

    $ordinaryError = json_encode(['error' => ['code' => 400, 'message' => 'invalid request']]);
    $batch = $interpretBatch->invoke(
        null,
        (string) $ordinaryError,
        0,
        '',
        400,
        0.25,
        true,
        'openrouter',
    );
    assert_eq(false, $batch['ok'], 'untyped OpenRouter HTTP 400 remains a transport failure');
    assert_eq(false, $batch['transient']);

    foreach (['openai', 'xai'] as $provider) {
        $typed = json_encode([
            'error' => [
                'code' => 400,
                'message' => 'provider-specific metadata',
                'metadata' => ['error_type' => 'max_tokens_exceeded'],
            ],
        ]);
        $legacy = $interpretBatch->invoke(
            null,
            (string) $typed,
            0,
            '',
            400,
            0.25,
            true,
            $provider,
        );
        assert_eq(false, $legacy['ok'], "{$provider}: HTTP 400 handling stays trunk-equivalent");
        assert_eq(false, $legacy['transient']);
        assert_throws(
            fn () => $interpretSingle->invoke(null, (string) $typed, 0.25, $provider, 400),
            "{$provider}: typed OpenRouter metadata is not reinterpreted",
        );
    }
});

test('OpenRouter max_tokens_exceeded reaches the single-request cache-probe policy', function () {
    $raw = 'data: {"error":{"code":400,"message":"one-token probe reached its limit",'
        . '"metadata":{"error_type":"max_tokens_exceeded"}},'
        . '"choices":[{"delta":{},"finish_reason":"error"}],'
        . '"usage":{"prompt_tokens":12,"completion_tokens":1}}' . "\n\n";
    $interpret = new ReflectionMethod(OpenAiCompatibleClient::class, 'interpretSingleStream');
    $interpret->setAccessible(true);
    $result = $interpret->invoke(null, $raw, 0.25, 'openrouter', 400);

    assert_eq('', $result['text']);
    assert_eq('max_tokens', $result['stop_reason']);
    assert_eq(12, $result['input']);
    assert_eq(1, $result['output']);

    $body = ['messages' => []];
    $probe = OpenAiCompatibleClient::retrySingleRequest(
        $body,
        fn (array $requestBody): array => $result,
        [],
        true,
        true,
    );
    assert_eq('', $probe['text'], 'the expected one-token probe termination is accepted');

    $body = [
        'max_tokens' => 16000,
        'messages' => [['role' => 'user', 'content' => 'Generate the page.']],
    ];
    $requests = [];
    $ordinary = OpenAiCompatibleClient::retrySingleRequest(
        $body,
        function (array $requestBody) use (&$requests, $result): array {
            $requests[] = $requestBody;
            return count($requests) === 1
                ? $result
                : [
                    'text' => 'complete response',
                    'input' => 12,
                    'output' => 20,
                    'time' => 0.25,
                    'stop_reason' => 'stop',
                ];
        },
        [],
        false,
        true,
    );
    assert_eq('complete response', $ordinary['text']);
    assert_eq(32000, $requests[1]['max_tokens'], 'an ordinary completion gets one larger regeneration');

    assert_throws(
        fn () => $interpret->invoke(null, $raw, 0.25, 'openai', 400),
        'existing providers do not reinterpret OpenRouter typed errors',
    );
});

test('OpenRouter typed context and credit limits remain permanent stream failures', function () {
    $method = new ReflectionMethod(OpenAiCompatibleClient::class, 'interpretStream');
    $method->setAccessible(true);

    foreach (['context_length_exceeded', 'token_limit_exceeded'] as $errorType) {
        $raw = 'data: {"error":{"code":400,"message":"request cannot be repaired by regeneration",'
            . '"metadata":{"error_type":"' . $errorType . '"}},'
            . '"choices":[{"delta":{},"finish_reason":"error"}]}' . "\n\n";

        $outcome = $method->invoke(null, $raw, 0, '', 400, 0.25, true, 'openrouter');
        assert_eq(false, $outcome['ok'], "{$errorType}: remains a failure");
        assert_eq(false, $outcome['transient'], "{$errorType}: does not retry unchanged");
    }
});

test('OpenRouter typed terminal mapping does not change existing provider behavior', function () {
    $raw = implode("\n", [
        'data: {"choices":[{"delta":{"content":"partial output"},"index":0}]}',
        'data: {"error":{"code":400,"message":"typed terminal event",'
            . '"metadata":{"error_type":"max_tokens_exceeded"}},'
            . '"choices":[{"delta":{},"finish_reason":"error"}]}',
        '',
    ]);
    $method = new ReflectionMethod(OpenAiCompatibleClient::class, 'interpretStream');
    $method->setAccessible(true);

    foreach (['openai', 'xai'] as $provider) {
        $outcome = $method->invoke(null, $raw, 0, '', 200, 0.25, true, $provider);
        assert_eq(false, $outcome['ok'], "{$provider}: OpenRouter metadata remains a stream error");
        assert_eq(false, $outcome['transient'], "{$provider}: existing retry classification is unchanged");
    }
});

test('parseSse recognizes nested choice errors only for OpenRouter', function () {
    $nonStream = json_encode([
        'choices' => [[
            'message' => ['role' => 'assistant', 'content' => 'partial response'],
            'finish_reason' => 'error',
            'error' => ['code' => 503, 'message' => 'upstream unavailable'],
        ]],
        'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 4],
    ]);

    $parsed = OpenAiCompatibleClient::parseSse((string) $nonStream, 'openrouter');
    assert_eq('upstream unavailable', $parsed['error'], 'OpenRouter non-stream extension is recognized');
    assert_eq(503, $parsed['error_code']);
    assert_eq('error', $parsed['stop_reason']);
    assert_eq('partial response', $parsed['text'], 'partial content is retained for semantic recovery');
    assert_eq(12, $parsed['input']);
    assert_eq(4, $parsed['output']);
    assert_eq(true, OpenAiCompatibleClient::isTransientStreamError($parsed));

    $stream = 'data: {"choices":[{"delta":{},"finish_reason":"error",'
        . '"error":{"code":503,"message":"nested stream error"}}]}' . "\n\n";
    $parsed = OpenAiCompatibleClient::parseSse($stream, 'openrouter');
    assert_eq('nested stream error', $parsed['error'], 'OpenRouter SSE extension is recognized');
    assert_eq(503, $parsed['error_code']);

    foreach (['openai', 'xai'] as $provider) {
        $legacyNonStream = OpenAiCompatibleClient::parseSse((string) $nonStream, $provider);
        assert_eq(null, $legacyNonStream['error'], "{$provider}: non-stream parsing stays trunk-equivalent");
        assert_eq('error', $legacyNonStream['stop_reason']);

        $legacyStream = OpenAiCompatibleClient::parseSse($stream, $provider);
        assert_eq(null, $legacyStream['error'], "{$provider}: SSE parsing stays trunk-equivalent");
        assert_eq('error', $legacyStream['stop_reason']);
    }
});

test('OpenAiCompatibleClient concurrencyWindows applies a provider-specific cap', function () {
    $bodies = [];
    for ($i = 0; $i < 9; $i++) {
        $bodies["r{$i}"] = ['model' => 'm'];
    }
    assert_eq([9], array_map('count', OpenAiCompatibleClient::concurrencyWindows($bodies)));
    $windows = OpenAiCompatibleClient::concurrencyWindows($bodies, 4);
    assert_eq([4, 4, 1], array_map('count', $windows));
    assert_eq(array_keys($bodies), array_keys(array_merge(...$windows)), 'keys and order are preserved');
});

test('OpenAiCompatibleClient interpretStream classifies a transfer with no response at all as transient', function () {
    // The CURLM-failure loop exit leaves unfinished handles with errno 0 and
    // HTTP status 0. That is operational - retry it; a permanent "HTTP 0"
    // outcome aborts the batch and discards every sibling response.
    $method = new ReflectionMethod(OpenAiCompatibleClient::class, 'interpretStream');
    $method->setAccessible(true);
    $out = $method->invoke(null, '', 0, '', 0, 0.0, true);
    assert_eq(false, $out['ok'], 'no response is not a success');
    assert_eq(true, $out['transient'] ?? false, 'no response at all must be retryable, not a batch abort');
});
