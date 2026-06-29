<?php
declare(strict_types=1);

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

test('concurrencyWindows caps each window at 5 and preserves keys in order', function () {
    $bodies = [];
    for ($i = 0; $i < 12; $i++) {
        $bodies["r{$i}"] = ['prompt' => "P{$i}"];
    }

    $windows = AnthropicClient::concurrencyWindows($bodies);

    assert_eq([5, 5, 2], array_map('count', $windows), 'no more than 5 in flight per window');
    foreach ($windows as $window) {
        assert_true(count($window) <= 5, 'window within the cap');
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
