<?php
declare(strict_types=1);

/**
 * Unit tests for the batch retry orchestration (WpcomImageClient::retryBatch).
 * The transport is faked so we exercise the transient-retry accounting without
 * any network or real backoff sleeps (delays are [0, 0]).
 */

test('retryBatch returns one result per body, keyed and ordered by index', function () {
    $bodies = [0 => ['b' => 0], 1 => ['b' => 1], 2 => ['b' => 2]];
    $transport = fn (array $subset) => array_map(fn () => ['ok' => true, 'bytes' => 'X'], $subset);

    $out = WpcomImageClient::retryBatch($bodies, $transport, [0, 0]);

    assert_eq([0, 1, 2], array_keys($out['results']));
    assert_eq(3, $out['succeeded']);
    foreach ($out['results'] as $r) {
        assert_eq(true, $r['ok']);
    }
});

test('retryBatch retries only the transient failures, then succeeds', function () {
    $bodies = [0 => ['b' => 0], 1 => ['b' => 1], 2 => ['b' => 2]];

    // Round 1: index 1 fails transiently, others succeed. Round 2: only index 1
    // is retried (assert the subset), and it succeeds.
    $round = 0;
    $seenSubsets = [];
    $transport = function (array $subset) use (&$round, &$seenSubsets) {
        $seenSubsets[] = array_keys($subset);
        $round++;
        $out = [];
        foreach ($subset as $i => $_) {
            $out[$i] = ($round === 1 && $i === 1)
                ? ['ok' => false, 'transient' => true, 'error' => 'temporary']
                : ['ok' => true, 'bytes' => 'X'];
        }
        return $out;
    };

    $out = WpcomImageClient::retryBatch($bodies, $transport, [0, 0, 0]);

    assert_eq([[0, 1, 2], [1]], $seenSubsets, 'second round retries only the failed index');
    assert_eq(3, $out['succeeded']);
    assert_eq(true, $out['results'][1]['ok']);
});

test('retryBatch gives up after the configured retries and marks failed', function () {
    $bodies = [0 => ['b' => 0]];
    $calls = 0;
    // Always transient — should be tried 1 + 2 retries = 3 times, then fail.
    $transport = function (array $subset) use (&$calls) {
        $calls++;
        return [array_key_first($subset) => ['ok' => false, 'transient' => true, 'error' => 'always down']];
    };

    $out = WpcomImageClient::retryBatch($bodies, $transport, [0, 0]); // 2 retries

    assert_eq(3, $calls, 'initial attempt + 2 retries');
    assert_eq(0, $out['succeeded']);
    assert_eq(false, $out['results'][0]['ok']);
    assert_eq('always down', $out['results'][0]['error']);
});

test('retryBatch does not retry permanent failures', function () {
    $bodies = [0 => ['b' => 0]];
    $calls = 0;
    $transport = function (array $subset) use (&$calls) {
        $calls++;
        return [array_key_first($subset) => ['ok' => false, 'transient' => false, 'error' => 'HTTP 400']];
    };

    $out = WpcomImageClient::retryBatch($bodies, $transport, [0, 0]);

    assert_eq(1, $calls, 'permanent failure tried exactly once');
    assert_eq(false, $out['results'][0]['ok']);
    assert_eq('HTTP 400', $out['results'][0]['error']);
});

/**
 * The 480-token input cap (WpcomImageClient::fitToTokens). ImagePromptComposer
 * leans on this to keep a fully-composed prompt under the model's hard limit.
 */

test('fitToTokens returns the text unchanged when it is within the cap', function () {
    $text = 'A sourdough loaf on a board. Style: photorealistic';
    assert_eq($text, WpcomImageClient::fitToTokens($text, WpcomImageClient::MAX_PROMPT_TOKENS));
});

test('fitToTokens trims from the end to fit the cap, keeping the lead intact', function () {
    $lead = 'A specific sourdough loaf on a floured board';
    $text = $lead . ' ' . str_repeat('trailing context word ', 2000); // far over budget
    $out = WpcomImageClient::fitToTokens($text, WpcomImageClient::MAX_PROMPT_TOKENS);

    assert_true(WpcomImageClient::estimateTokens($out) <= WpcomImageClient::MAX_PROMPT_TOKENS, 'within token cap');
    assert_contains($lead, $out);                  // the leading text survives
    assert_true($out !== '', 'still returns something');
});

test('sampleImageSize renders wide (full-bleed) images at 2K, the rest at 1K', function () {
    assert_eq('2K', WpcomImageClient::sampleImageSize('16:9'));
    assert_eq('1K', WpcomImageClient::sampleImageSize('1:1'));
    assert_eq('1K', WpcomImageClient::sampleImageSize('9:16'));
});

test('estimateTokens is conservative and grows with length', function () {
    assert_eq(0, WpcomImageClient::estimateTokens('   '));
    assert_true(WpcomImageClient::estimateTokens('a b c d e') >= 5, 'at least one token per short word');
});
