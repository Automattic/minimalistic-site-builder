<?php
declare(strict_types=1);

use Automattic\SiteBuild\AnthropicClient;
use Automattic\SiteBuild\RejectedApiParameterException;

/**
 * Unit tests for the concurrent-batch retry orchestration
 * (AnthropicClient::retryTextBatch). The transport is faked so we exercise the
 * transient-retry accounting without any network or real backoff sleeps.
 *
 * Unlike the image batch, a permanently failing request must abort the WHOLE
 * batch (a missing section breaks the build), so we assert it throws.
 */

test('retryTextBatch returns text + usage per request, keyed as input', function () {
    $bodies = ['theme-json' => [], 'page-plan' => []];
    $transport = function (array $subset) {
        $out = [];
        foreach ($subset as $k => $_) {
            $out[$k] = ['ok' => true, 'text' => "T:{$k}", 'input' => 5, 'output' => 7];
        }
        return $out;
    };

    $out = AnthropicClient::retryTextBatch($bodies, $transport, [0, 0]);

    assert_eq(['page-plan', 'theme-json'], (function ($k) { sort($k); return $k; })(array_keys($out)));
    assert_eq('T:theme-json', $out['theme-json']['text']);
    assert_eq(5, $out['page-plan']['input']);
    assert_eq(7, $out['page-plan']['output']);
});

test('retrySingleRequest tolerates an empty probe response without retrying', function () {
    $body = ['messages' => []];
    $calls = 0;
    $transport = function (array $requestBody) use (&$calls): array {
        $calls++;
        return [
            'text' => " \n\t",
            'input' => 100,
            'output' => 1,
            'cache_read_input_tokens' => 0,
            'cache_creation_input_tokens' => 100,
            'time' => 0.25,
        ];
    };

    $result = AnthropicClient::retrySingleRequest($body, $transport, [0, 0, 0], true);

    assert_eq(1, $calls, 'successful empty probe makes exactly one transport attempt');
    assert_eq('', $result['text'], 'tolerated whitespace is normalized to the empty-string contract');
});

test('retryTextBatch retries only the transient failures, then succeeds', function () {
    $bodies = ['a' => [], 'b' => [], 'c' => []];
    $round = 0;
    $seen = [];
    $transport = function (array $subset) use (&$round, &$seen) {
        $seen[] = array_keys($subset);
        $round++;
        $out = [];
        foreach ($subset as $k => $_) {
            $out[$k] = ($round === 1 && $k === 'b')
                ? ['ok' => false, 'transient' => true, 'error' => 'temporary']
                : ['ok' => true, 'text' => 'X', 'input' => 0, 'output' => 0];
        }
        return $out;
    };

    $out = AnthropicClient::retryTextBatch($bodies, $transport, [0, 0, 0]);

    assert_eq([['a', 'b', 'c'], ['b']], $seen, 'second round retries only the failed key');
    $keys = array_keys($out);
    sort($keys);
    assert_eq(['a', 'b', 'c'], $keys, 'every request returned a result');
});

test('retryTextBatch throws after exhausting retries on a transient failure', function () {
    $bodies = ['a' => []];
    $calls = 0;
    $transport = function (array $subset) use (&$calls) {
        $calls++;
        return ['a' => ['ok' => false, 'transient' => true, 'error' => 'always down']];
    };

    assert_throws(function () use ($bodies, $transport) {
        AnthropicClient::retryTextBatch($bodies, $transport, [0, 0]); // 2 retries
    });
    assert_eq(3, $calls, 'initial attempt + 2 retries before giving up');
});

test('retryTextBatch throws immediately on a permanent failure', function () {
    $bodies = ['a' => []];
    $calls = 0;
    $transport = function (array $subset) use (&$calls) {
        $calls++;
        return ['a' => ['ok' => false, 'transient' => false, 'error' => 'HTTP 400']];
    };

    assert_throws(function () use ($bodies, $transport) {
        AnthropicClient::retryTextBatch($bodies, $transport, [0, 0]);
    });
    assert_eq(1, $calls, 'permanent failure tried exactly once');
});

test('retryTextBatch reports a permanent failure to onFailure before aborting', function () {
    $bodies = ['a' => [], 'b' => []];
    $transport = function (array $subset) {
        $out = [];
        foreach ($subset as $k => $_) {
            $out[$k] = $k === 'b'
                ? ['ok' => false, 'transient' => false, 'error' => 'HTTP 400', 'time' => 1.5]
                : ['ok' => true, 'text' => 'X', 'input' => 0, 'output' => 0];
        }
        return $out;
    };

    $reported = [];
    assert_throws(function () use ($bodies, $transport, &$reported) {
        AnthropicClient::retryTextBatch($bodies, $transport, [0, 0], function ($key, $error, $time) use (&$reported) {
            $reported[] = [$key, $error, $time];
        });
    });
    assert_eq([['b', 'HTTP 400', 1.5]], $reported, 'the failing call is handed to onFailure with key, error, and time');
});

test('retryTextBatch also reports a transient failure that exhausts its retries', function () {
    $bodies = ['a' => []];
    $transport = fn (array $subset) => ['a' => ['ok' => false, 'transient' => true, 'error' => 'always down']];

    $reported = [];
    assert_throws(function () use ($bodies, $transport, &$reported) {
        AnthropicClient::retryTextBatch($bodies, $transport, [0, 0], function ($key, $error) use (&$reported) {
            $reported[] = [$key, $error];
        });
    });
    assert_eq([['a', 'always down']], $reported, 'a call that gives up after retries is reported once');
});

test('retryTextBatch handles integer-keyed bodies end to end', function () {
    // Numeric keys are ints in PHP whatever the caller wrote, and the image
    // prompt-repair pass keys its rewrite batch by image index — so the whole
    // path, including the string|int onFailure contract the clients' logging
    // closures rely on, must accept them.
    $bodies = [0 => [], 7 => []];
    $transport = function (array $subset) {
        $out = [];
        foreach ($subset as $k => $_) {
            $out[$k] = $k === 7
                ? ['ok' => false, 'transient' => false, 'error' => 'HTTP 400']
                : ['ok' => true, 'text' => "T:{$k}", 'input' => 0, 'output' => 0];
        }
        return $out;
    };

    $reported = [];
    assert_throws(function () use ($bodies, $transport, &$reported) {
        AnthropicClient::retryTextBatch($bodies, $transport, [0], function (string|int $key, string $error, float $time) use (&$reported) {
            $reported[] = $key;
        });
    });
    assert_eq([7], $reported, 'the int key reaches a string|int-typed onFailure intact');
});

test('concurrencyWindows caps each window at 10 and preserves keys in order', function () {
    $bodies = [];
    for ($i = 0; $i < 12; $i++) {
        $bodies["r{$i}"] = ['prompt' => "P{$i}"];
    }

    $windows = AnthropicClient::concurrencyWindows($bodies);

    assert_eq([10, 2], array_map('count', $windows), 'no more than 10 in flight per window');
    foreach ($windows as $window) {
        assert_true(count($window) <= 10, 'window within the cap');
    }

    // Every request appears exactly once, with its key and order intact.
    $flat = [];
    foreach ($windows as $window) {
        $flat += $window;
    }
    assert_eq(array_keys($bodies), array_keys($flat), 'keys preserved across windows');
    assert_eq(['prompt' => 'P7'], $flat['r7'], 'request body intact');
});

test('concurrencyWindows leaves a small batch as a single window', function () {
    $bodies = ['a' => [], 'b' => [], 'c' => []];
    $windows = AnthropicClient::concurrencyWindows($bodies);
    assert_eq(1, count($windows), 'a sub-cap batch is one window');
    assert_eq(['a', 'b', 'c'], array_keys($windows[0]));
});

test('bodyFor sends temperature only when set and supported, and applies model/token defaults', function () {
    $body = AnthropicClient::bodyFor(
        ['prompt' => 'Hi', 'temperature' => 0.9, 'system' => 'Be terse.'],
        'claude-sonnet-4-6',
        16000,
    );
    assert_eq('claude-sonnet-4-6', $body['model']);
    assert_eq(16000, $body['max_tokens']);
    assert_eq(0.9, $body['temperature']);
    assert_eq(AnthropicClient::systemPreamble() . "\n\nBe terse.", $body['system']);
    assert_eq(true, $body['stream']);
    assert_eq('Hi', $body['messages'][0]['content']);

    $body = AnthropicClient::bodyFor(
        ['prompt' => 'Hi', 'model' => 'claude-haiku-4-5', 'max_tokens' => 512],
        'claude-opus-4-8',
        16000,
    );
    assert_eq('claude-haiku-4-5', $body['model']);
    assert_eq(512, $body['max_tokens']);
    assert_true(!array_key_exists('temperature', $body), 'no temperature key when unset');
    assert_eq(AnthropicClient::systemPreamble(), $body['system'], 'preamble alone when the caller sets no system');

    // Sampling-less models (Opus 4.7/4.8, Fable) never get a temperature key -
    // the API removed the parameter and 400s on it.
    $body = AnthropicClient::bodyFor(
        ['prompt' => 'Hi', 'temperature' => 0.9],
        'claude-opus-4-8',
        16000,
    );
    assert_true(!array_key_exists('temperature', $body), 'temperature omitted on a sampling-less model');
});

test('bodyFor keeps the uncached request body byte-identical to the original string-content shape', function () {
    $expected = [
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => 16000,
        'stream'     => true,
        'messages'   => [
            ['role' => 'user', 'content' => 'Build a hero section'],
        ],
        'system'     => AnthropicClient::systemPreamble(),
    ];

    $body = AnthropicClient::bodyFor(
        ['prompt' => 'Build a hero section'],
        'claude-sonnet-4-6',
        16000,
    );

    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    assert_eq(json_encode($expected, $flags), json_encode($body, $flags));
    assert_true(!str_contains((string) json_encode($body), 'cache_control'), 'uncached body has no cache marker');
});

test('bodyFor keeps an explicit empty cached_prefixes list byte-identical to uncached', function () {
    $uncached = AnthropicClient::bodyFor(
        ['prompt' => 'Build a hero section'],
        'claude-sonnet-4-6',
        16000,
    );
    $empty = AnthropicClient::bodyFor(
        ['prompt' => 'Build a hero section', 'cached_prefixes' => []],
        'claude-sonnet-4-6',
        16000,
    );

    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    assert_eq(json_encode($uncached, $flags), json_encode($empty, $flags));
});

test('bodyFor skips blank cached prefixes while preserving nonblank prefix bytes', function () {
    $body = AnthropicClient::bodyFor(
        [
            'prompt' => 'Build this section.',
            'cached_prefixes' => ["  Build bytes stay padded.  ", " \n\t ", 'Page context.'],
        ],
        'claude-sonnet-4-6',
        16000,
    );

    assert_eq('  Build bytes stay padded.  ', $body['messages'][0]['content'][0]['text']);
    assert_eq('Page context.', $body['messages'][0]['content'][1]['text']);
    assert_eq('Build this section.', $body['messages'][0]['content'][2]['text']);
});

test('bodyFor makes an all-blank cached_prefixes list byte-identical to uncached', function () {
    $uncached = AnthropicClient::bodyFor(['prompt' => 'Build it.'], 'claude-sonnet-4-6', 16000);
    $allBlank = AnthropicClient::bodyFor(
        ['prompt' => 'Build it.', 'cached_prefixes' => ['', " \n\t "]],
        'claude-sonnet-4-6',
        16000,
    );

    assert_eq($uncached, $allBlank);
    assert_true(is_string($allBlank['messages'][0]['content']), 'all-empty list retains string content');
});

test('bodyFor rejects null, non-array, non-list, and non-string cached_prefixes with useful messages', function () {
    foreach ([null, 'build context', ['build' => 'context']] as $invalid) {
        try {
            AnthropicClient::bodyFor(
                ['prompt' => 'Build it.', 'cached_prefixes' => $invalid],
                'claude-sonnet-4-6',
                16000,
            );
            throw new \RuntimeException('expected bodyFor to reject cached_prefixes');
        } catch (\RuntimeException $e) {
            assert_contains('cached_prefixes must be a list of strings', $e->getMessage());
        }
    }

    try {
        AnthropicClient::bodyFor(
            ['prompt' => 'Build it.', 'cached_prefixes' => ['valid', 42]],
            'claude-sonnet-4-6',
            16000,
        );
        throw new \RuntimeException('expected bodyFor to reject cached_prefixes[1]');
    } catch (\RuntimeException $e) {
        assert_contains('cached_prefixes[1]', $e->getMessage());
    }
});

test('bodyFor renders cached prefixes as marked leading text blocks before the varying prompt', function () {
    $body = AnthropicClient::bodyFor(
        [
            'prompt' => 'Build this section.',
            'cached_prefixes' => ['Shared build context.', 'Shared page context.'],
        ],
        'claude-sonnet-4-6',
        16000,
    );

    assert_eq([
        [
            'type' => 'text',
            'text' => 'Shared build context.',
            'cache_control' => ['type' => 'ephemeral'],
        ],
        [
            'type' => 'text',
            'text' => 'Shared page context.',
            'cache_control' => ['type' => 'ephemeral'],
        ],
        [
            'type' => 'text',
            'text' => 'Build this section.',
        ],
    ], $body['messages'][0]['content']);
});

test('bodyFor rejects more than three cached prefixes', function () {
    assert_throws(function () {
        AnthropicClient::bodyFor(
            [
                'prompt' => 'Build this section.',
                'cached_prefixes' => ['one', 'two', 'three', 'four'],
            ],
            'claude-sonnet-4-6',
            16000,
        );
    });

    $body = AnthropicClient::bodyFor(
        [
            'prompt' => 'Build this section.',
            'cached_prefixes' => ['one', '', 'two', " \n", 'three'],
        ],
        'claude-sonnet-4-6',
        16000,
    );
    assert_eq(4, count($body['messages'][0]['content']), 'blank layers do not count toward the three-layer cap');
});

test('bodyFor puts the language preamble on every request, before any per-request system', function () {
    // The respect-the-prompt-language rule must ride on EVERY call — even a
    // step that sets no system prompt of its own.
    $body = AnthropicClient::bodyFor(['prompt' => 'Hi'], 'claude-opus-4-8', 16000);
    assert_true(str_contains((string) $body['system'], 'language of the original user prompt'), 'language rule present');

    // A caller's system text (e.g. the JSON steering instruction) is appended
    // after the preamble, never replacing it.
    $body = AnthropicClient::bodyFor(['prompt' => 'Hi', 'system' => 'Respond with JSON.'], 'claude-opus-4-8', 16000);
    assert_true(str_starts_with((string) $body['system'], AnthropicClient::systemPreamble()), 'preamble comes first');
    assert_true(str_ends_with((string) $body['system'], 'Respond with JSON.'), 'per-request system follows');
});

test('supportsSampling is false for the Claude 5 family and Opus 4.7/4.8, true otherwise', function () {
    assert_eq(false, AnthropicClient::supportsSampling('claude-opus-4-8'));
    assert_eq(false, AnthropicClient::supportsSampling('claude-opus-4-7'));
    assert_eq(false, AnthropicClient::supportsSampling('claude-fable-5'));
    assert_eq(false, AnthropicClient::supportsSampling('claude-fable-5[1m]'));
    assert_eq(false, AnthropicClient::supportsSampling('claude-sonnet-5'));
    assert_eq(false, AnthropicClient::supportsSampling('claude-mythos-5'));
    // The packaged large-tier default. Bare and in the dated/vendor spellings
    // a host may resolve it to, so narrowing the pattern cannot silently start
    // sending sampling parameters the model rejects.
    assert_eq(false, AnthropicClient::supportsSampling('claude-opus-5'));
    assert_eq(false, AnthropicClient::supportsSampling('claude-opus-5-20260101'));
    assert_eq(false, AnthropicClient::supportsSampling('us.anthropic.claude-opus-5-v1:0'));
    assert_eq(true, AnthropicClient::supportsSampling('claude-haiku-4-5'));
    assert_eq(true, AnthropicClient::supportsSampling('claude-sonnet-4-6'));
    assert_eq(true, AnthropicClient::supportsSampling('claude-opus-4-6'));
});

test('rejectedParam detects the removed-sampling-parameter 400, null otherwise', function () {
    $apiError = 'HTTP 400: {"type":"error","error":{"type":"invalid_request_error",'
        . '"message":"`temperature` is deprecated for this model."},"request_id":"req_x"}';
    assert_eq('temperature', AnthropicClient::rejectedParam($apiError));
    assert_eq('top_p', AnthropicClient::rejectedParam('`top_p` is not supported on this model'));
    assert_eq(null, AnthropicClient::rejectedParam('HTTP 400: {"error":{"message":"messages: roles must alternate"}}'));
    assert_eq(null, AnthropicClient::rejectedParam('temperature must be between 0 and 1'));
});

test('retryTextBatch strips a rejected sampling parameter and retries without backoff', function () {
    $bodies = [
        'header' => ['model' => 'claude-next-1', 'temperature' => 0.9, 'messages' => []],
        'footer' => ['model' => 'claude-next-1', 'temperature' => 0.9, 'messages' => []],
    ];
    $sent = [];
    $transport = function (array $subset) use (&$sent) {
        $sent[] = $subset;
        $out = [];
        foreach ($subset as $k => $body) {
            $out[$k] = array_key_exists('temperature', $body)
                ? ['ok' => false, 'transient' => false, 'error' => 'HTTP 400: `temperature` is deprecated for this model.', 'retry_without' => 'temperature']
                : ['ok' => true, 'text' => "T:{$k}", 'input' => 1, 'output' => 1];
        }
        return $out;
    };

    // Delays [] = zero transient retries allowed - the strip retry must not need one.
    $out = AnthropicClient::retryTextBatch($bodies, $transport, []);

    assert_eq('T:header', $out['header']['text']);
    assert_eq('T:footer', $out['footer']['text']);
    assert_eq(2, count($sent), 'one failed round, one stripped retry round');
    assert_true(!array_key_exists('temperature', $bodies['header']), 'caller sees the stripped body');
});

test('retryTextBatch fails loud when the rejected parameter is not in the body', function () {
    $bodies = ['x' => ['model' => 'claude-next-1', 'messages' => []]];
    $transport = fn (array $subset): array => ['x' => [
        'ok' => false, 'transient' => false,
        'error' => 'HTTP 400: `temperature` is deprecated for this model.',
        'retry_without' => 'temperature',
    ]];
    assert_throws(function () use (&$bodies, $transport) {
        AnthropicClient::retryTextBatch($bodies, $transport, []);
    });
});

test('rejectedParam detects a cache_control rejection', function () {
    $apiError = 'HTTP 400: {"type":"error","error":{"type":"invalid_request_error",'
        . '"message":"messages.0.content.0.cache_control: Extra inputs are not permitted"}}';
    assert_eq('cache_control', AnthropicClient::rejectedParam($apiError));
});

test('rejectedParam detects a thinking rejection so the build degrades instead of aborting', function () {
    // `thinking: {type: "disabled"}` is only valid at effort <= high. Raising
    // effort later would 400 every large-tier call; stripping the control runs
    // the model with adaptive thinking rather than failing the build.
    $apiError = 'HTTP 400: {"type":"error","error":{"type":"invalid_request_error",'
        . '"message":"thinking.type: `disabled` is not supported at this effort level"}}';
    assert_eq('thinking', AnthropicClient::rejectedParam($apiError));
    assert_eq('thinking', AnthropicClient::rejectedParamForHttpError(400, $apiError));
    // Unrelated errors still fall through untouched.
    assert_eq(null, AnthropicClient::rejectedParam('HTTP 400: messages must alternate'));
});

test('rejectedParamForHttpError classifies the full body only for HTTP 400', function () {
    $lateRejection = str_repeat('x', 350) . ' cache_control is not supported';
    $longUnrelated = str_repeat('x', 350) . ' messages must alternate';
    assert_eq('cache_control', AnthropicClient::rejectedParamForHttpError(400, $lateRejection));
    assert_eq(null, AnthropicClient::rejectedParamForHttpError(500, $lateRejection));
    assert_eq(null, AnthropicClient::rejectedParamForHttpError(400, $longUnrelated));
});

test('RejectedApiParameterException exposes readonly parameter metadata', function () {
    $exception = new RejectedApiParameterException('truncated diagnostic', 'cache_control');

    assert_eq('cache_control', $exception->parameter);
    assert_eq('truncated diagnostic', $exception->getMessage());
    assert_true(
        (new ReflectionProperty($exception, 'parameter'))->isReadOnly(),
        'parameter metadata is readonly',
    );
});

test('retryTextBatch strips every cache marker and retries exactly once', function () {
    $bodies = [
        'section' => [
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'build', 'cache_control' => ['type' => 'ephemeral']],
                    ['type' => 'text', 'text' => 'page', 'cache_control' => ['type' => 'ephemeral']],
                    ['type' => 'text', 'text' => 'section'],
                ],
            ]],
        ],
    ];
    $sent = [];
    $transport = function (array $subset) use (&$sent): array {
        $sent[] = $subset;
        $encoded = (string) json_encode($subset['section']);
        if (str_contains($encoded, 'cache_control')) {
            return ['section' => [
                'ok' => false,
                'transient' => false,
                'error' => 'HTTP 400: cache_control is not supported',
                'retry_without' => 'cache_control',
            ]];
        }
        return ['section' => [
            'ok' => true,
            'text' => 'done',
            'input' => 12,
            'output' => 3,
            'cache_read_input_tokens' => 8,
            'cache_creation_input_tokens' => 2,
        ]];
    };

    $out = AnthropicClient::retryTextBatch($bodies, $transport, []);

    assert_eq('done', $out['section']['text']);
    assert_eq(8, $out['section']['cache_read_input_tokens']);
    assert_eq(2, $out['section']['cache_creation_input_tokens']);
    assert_eq(2, count($sent), 'one rejected request followed by one immediate retry');
    assert_true(!str_contains((string) json_encode($bodies), 'cache_control'), 'all nested cache markers were stripped');
});

test('retryTextBatch does not recur when cache_control is rejected after stripping', function () {
    $bodies = [
        'section' => [
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'shared', 'cache_control' => ['type' => 'ephemeral']],
                    ['type' => 'text', 'text' => 'varying'],
                ],
            ]],
        ],
    ];
    $calls = 0;
    $transport = function (array $subset) use (&$calls): array {
        $calls++;
        return ['section' => [
            'ok' => false,
            'transient' => false,
            'error' => 'HTTP 400: cache_control is not supported',
            'retry_without' => 'cache_control',
        ]];
    };

    assert_throws(function () use (&$bodies, $transport) {
        AnthropicClient::retryTextBatch($bodies, $transport, []);
    });
    assert_eq(2, $calls, 'the rejection is retried once and cannot recur');
});

test('retryTextBatch completes a strip retry before backing off a transient sibling', function () {
    $bodies = [
        'cached' => [
            'messages' => [[
                'role' => 'user',
                'content' => [[
                    'type' => 'text',
                    'text' => 'shared',
                    'cache_control' => ['type' => 'ephemeral'],
                ]],
            ]],
        ],
        'transient' => ['messages' => []],
    ];
    $seen = [];
    $transientFailures = 0;
    $transport = function (array $subset) use (&$seen, &$transientFailures): array {
        $seen[] = array_keys($subset);
        $out = [];
        foreach ($subset as $key => $body) {
            if ($key === 'cached' && str_contains((string) json_encode($body), 'cache_control')) {
                $out[$key] = [
                    'ok' => false,
                    'transient' => false,
                    'error' => 'HTTP 400: cache_control is not supported',
                    'retry_without' => 'cache_control',
                ];
            } elseif ($key === 'transient' && $transientFailures++ === 0) {
                $out[$key] = ['ok' => false, 'transient' => true, 'error' => 'temporary'];
            } else {
                $out[$key] = ['ok' => true, 'text' => "T:{$key}", 'input' => 0, 'output' => 0];
            }
        }
        return $out;
    };

    $out = AnthropicClient::retryTextBatch($bodies, $transport, [0]);

    assert_eq([
        ['cached', 'transient'],
        ['cached'],
        ['transient'],
    ], $seen, 'the stripped key retries immediately before the transient retry round');
    assert_eq('T:cached', $out['cached']['text']);
    assert_eq('T:transient', $out['transient']['text']);
});

test('bodyFor maps json_schema to Anthropic output_config.format', function () {
    $schema = [
        'type' => 'object',
        'properties' => ['sections' => ['type' => 'array', 'items' => ['type' => 'string']]],
        'required' => ['sections'],
        'additionalProperties' => false,
    ];

    $body = AnthropicClient::bodyFor(
        [
            'prompt' => 'Plan a page.',
            'json_schema' => ['name' => 'page_plan', 'schema' => $schema],
        ],
        'claude-haiku-4-5',
        16000,
    );

    assert_eq([
        'format' => [
            'type' => 'json_schema',
            'schema' => $schema,
        ],
    ], $body['output_config']);
    assert_true(!array_key_exists('json_schema', $body), 'provider-neutral metadata does not leak onto the wire');
});

test('bodyFor disables thinking only on models that think by default (Opus 5)', function () {
    // Opus 5 runs adaptive thinking when the param is omitted; the pipeline
    // opts out explicitly to keep 4.8-era latency and token budgets.
    $body = AnthropicClient::bodyFor(['prompt' => 'Hi'], 'claude-opus-5', 16000);
    assert_eq(['type' => 'disabled'], $body['thinking']);

    // Opus 4.8 and earlier already run without thinking when omitted, and
    // Fable 5 400s on an explicit "disabled" - neither may get the key.
    foreach (['claude-opus-4-8', 'claude-haiku-4-5', 'claude-fable-5'] as $model) {
        $body = AnthropicClient::bodyFor(['prompt' => 'Hi'], $model, 16000);
        assert_true(!array_key_exists('thinking', $body), "no thinking key for {$model}");
    }
});

test('rollingPool starts the next pending request the moment one completes', function () {
    // 5 requests, cap 2. Completion order: b, a, then c+d together, then e.
    // A freed slot must be refilled immediately - c starts on b's completion
    // while a is still in flight, never waiting for the rest of a "window".
    $bodies = [
        'a' => ['prompt' => 'A'],
        'b' => ['prompt' => 'B'],
        'c' => ['prompt' => 'C'],
        'd' => ['prompt' => 'D'],
        'e' => ['prompt' => 'E'],
    ];
    $started = [];
    $inFlight = [];
    $maxInFlight = 0;
    $start = function (string|int $key, array $body) use (&$started, &$inFlight, &$maxInFlight): void {
        $started[] = $key;
        $inFlight[$key] = true;
        $maxInFlight = max($maxInFlight, count($inFlight));
    };
    $script = [['b'], ['a'], ['c', 'd'], ['e']];
    $await = function () use (&$script, &$inFlight): array {
        $completed = array_shift($script);
        $out = [];
        foreach ($completed as $key) {
            unset($inFlight[$key]);
            $out[$key] = ['ok' => true, 'text' => strtoupper((string) $key)];
        }
        return $out;
    };

    $out = AnthropicClient::rollingPool($bodies, $start, $await, 2);

    assert_eq(['a', 'b', 'c', 'd', 'e'], $started, 'requests start in input order as slots free up');
    assert_eq(2, $maxInFlight, 'the cap holds while slots roll');
    assert_eq(array_keys($bodies), array_keys($out), 'results are keyed and ordered as the input');
    assert_eq(['ok' => true, 'text' => 'C'], $out['c'], 'each result reaches its own key');
});

test('rollingPool starts a sub-cap batch all at once and an empty batch not at all', function () {
    $calls = 0;
    $started = [];
    $out = AnthropicClient::rollingPool(
        ['x' => ['prompt' => 'X'], 'y' => ['prompt' => 'Y']],
        function (string|int $key, array $body) use (&$started): void {
            $started[] = $key;
        },
        function () use (&$calls): array {
            $calls++;
            return ['x' => 'RX', 'y' => 'RY'];
        },
        10,
    );
    assert_eq(['x', 'y'], $started, 'both start before the first await');
    assert_eq(1, $calls, 'one await drains the whole sub-cap batch');
    assert_eq(['x' => 'RX', 'y' => 'RY'], $out);

    $out = AnthropicClient::rollingPool(
        [],
        function (): void {
            throw new RuntimeException('start must not be called for an empty batch');
        },
        function (): array {
            throw new RuntimeException('await must not be called for an empty batch');
        },
        10,
    );
    assert_eq([], $out, 'an empty batch resolves without any transport calls');
});

test('rollingPool rejects an await that returns nothing or an unknown key', function () {
    $none = fn (): array => [];
    $err = null;
    try {
        AnthropicClient::rollingPool(['a' => []], function (): void {}, $none, 2);
    } catch (RuntimeException $e) {
        $err = $e->getMessage();
    }
    assert_true(is_string($err) && str_contains($err, 'no transfer'), 'an empty await result is a hang, not progress');

    $err = null;
    try {
        AnthropicClient::rollingPool(
            ['a' => []],
            function (): void {},
            fn (): array => ['ghost' => 'R'],
            2,
        );
    } catch (RuntimeException $e) {
        $err = $e->getMessage();
    }
    assert_true(is_string($err) && str_contains($err, 'ghost'), 'a completion for a key not in flight is a transport bug');
});
