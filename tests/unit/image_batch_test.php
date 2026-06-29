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
 * Prompt composition + the 480-token input cap (WpcomImageClient::composePrompt).
 */

test('composePrompt prepends site context to the image prompt', function () {
    $out = WpcomImageClient::composePrompt('Image for the website “Acme”. A bakery.', 'A sourdough loaf. Style: photorealistic');
    assert_contains('Image for the website “Acme”', $out);
    assert_contains('A sourdough loaf. Style: photorealistic', $out);
});

test('composePrompt with empty context returns the image prompt unchanged', function () {
    assert_eq('A sourdough loaf.', WpcomImageClient::composePrompt('', 'A sourdough loaf.'));
});

test('composePrompt keeps the whole prompt within the 480-token model limit', function () {
    $hugeContext = str_repeat('context word ', 2000);          // far over budget
    $imagePrompt = 'A specific sourdough loaf on a board. Style: photorealistic';

    $out = WpcomImageClient::composePrompt($hugeContext, $imagePrompt);

    assert_true(WpcomImageClient::estimateTokens($out) <= WpcomImageClient::MAX_PROMPT_TOKENS, 'within token cap');
    // The per-image prompt is the priority and is preserved in full.
    assert_contains($imagePrompt, $out);
});

test('composePrompt truncates the image prompt when it alone exceeds the limit', function () {
    $imagePrompt = str_repeat('loaf ', 2000); // image prompt itself over budget, no context
    $out = WpcomImageClient::composePrompt('', $imagePrompt);
    assert_true(WpcomImageClient::estimateTokens($out) <= WpcomImageClient::MAX_PROMPT_TOKENS, 'within token cap');
    assert_true($out !== '', 'still returns something');
});

test('estimateTokens is conservative and grows with length', function () {
    assert_eq(0, WpcomImageClient::estimateTokens('   '));
    assert_true(WpcomImageClient::estimateTokens('a b c d e') >= 5, 'at least one token per short word');
});
