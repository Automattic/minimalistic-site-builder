<?php
declare(strict_types=1);

use Automattic\SiteBuild\AnthropicClient;

/**
 * Unit tests for the concurrent-batch retry orchestration
 * (AnthropicClient::retryTextBatch). The transport is faked so we exercise the
 * transient-retry accounting without any network or real backoff sleeps.
 *
 * Unlike the image batch, a permanently failing request must abort the WHOLE
 * batch (a missing section breaks the build), so we assert it throws.
 */

test('retryTextBatch returns text + usage per request, keyed as input', function () {
    $bodies = ['theme-json' => [], 'section-plan' => []];
    $transport = function (array $subset) {
        $out = [];
        foreach ($subset as $k => $_) {
            $out[$k] = ['ok' => true, 'text' => "T:{$k}", 'input' => 5, 'output' => 7];
        }
        return $out;
    };

    $out = AnthropicClient::retryTextBatch($bodies, $transport, [0, 0]);

    assert_eq(['section-plan', 'theme-json'], (function ($k) { sort($k); return $k; })(array_keys($out)));
    assert_eq('T:theme-json', $out['theme-json']['text']);
    assert_eq(5, $out['section-plan']['input']);
    assert_eq(7, $out['section-plan']['output']);
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
    assert_eq('Be terse.', $body['system']);
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
    assert_true(!array_key_exists('system', $body), 'no system key when empty');

    // Sampling-less models (Opus 4.7/4.8, Fable) never get a temperature key -
    // the API removed the parameter and 400s on it.
    $body = AnthropicClient::bodyFor(
        ['prompt' => 'Hi', 'temperature' => 0.9],
        'claude-opus-4-8',
        16000,
    );
    assert_true(!array_key_exists('temperature', $body), 'temperature omitted on a sampling-less model');
});

test('supportsSampling is false for Opus 4.7/4.8 and Fable, true otherwise', function () {
    assert_eq(false, AnthropicClient::supportsSampling('claude-opus-4-8'));
    assert_eq(false, AnthropicClient::supportsSampling('claude-opus-4-7'));
    assert_eq(false, AnthropicClient::supportsSampling('claude-fable-5'));
    assert_eq(false, AnthropicClient::supportsSampling('claude-fable-5[1m]'));
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
