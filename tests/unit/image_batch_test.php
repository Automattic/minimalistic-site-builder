<?php
declare(strict_types=1);

use Automattic\SiteBuild\Imagen;

/**
 * Unit tests for the batch retry orchestration (Imagen::retryBatch).
 * The transport is faked so we exercise the transient-retry accounting without
 * any network or real backoff sleeps (delays are [0, 0]).
 */

test('retryBatch returns one result per body, keyed and ordered by index', function () {
    $bodies = [0 => ['b' => 0], 1 => ['b' => 1], 2 => ['b' => 2]];
    $transport = fn (array $subset) => array_map(fn () => ['ok' => true, 'bytes' => 'X'], $subset);

    $out = Imagen::retryBatch($bodies, $transport, [0, 0]);

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

    $out = Imagen::retryBatch($bodies, $transport, [0, 0, 0]);

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

    $out = Imagen::retryBatch($bodies, $transport, [0, 0]); // 2 retries

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

    $out = Imagen::retryBatch($bodies, $transport, [0, 0]);

    assert_eq(1, $calls, 'permanent failure tried exactly once');
    assert_eq(false, $out['results'][0]['ok']);
    assert_eq('HTTP 400', $out['results'][0]['error']);
});

test('retryBatch retries safety-filtered failures and keeps the flag on give-up', function () {
    $bodies = [0 => ['b' => 0]];
    $calls = 0;
    // Filtered every time — retried like a transient failure (the filter is
    // non-deterministic), and the final failure keeps the filtered flag so the
    // caller can repair the prompt.
    $transport = function (array $subset) use (&$calls) {
        $calls++;
        return [array_key_first($subset) => [
            'ok' => false, 'transient' => true, 'filtered' => true, 'error' => 'rai: blocked',
        ]];
    };

    $out = Imagen::retryBatch($bodies, $transport, [0, 0, 0]); // 3 retries

    assert_eq(4, $calls, 'initial attempt + 3 retries');
    assert_eq(false, $out['results'][0]['ok']);
    assert_eq(true, $out['results'][0]['filtered']);
    assert_eq('rai: blocked', $out['results'][0]['error']);
});

test('retryBatch marks a filtered prompt that passes on a retry as a plain success', function () {
    $bodies = [0 => ['b' => 0]];
    $round = 0;
    $transport = function (array $subset) use (&$round) {
        $round++;
        return [array_key_first($subset) => $round === 1
            ? ['ok' => false, 'transient' => true, 'filtered' => true, 'error' => 'rai: blocked']
            : ['ok' => true, 'bytes' => 'X']];
    };

    $out = Imagen::retryBatch($bodies, $transport, [0, 0]);

    assert_eq(true, $out['results'][0]['ok']);
    assert_eq(1, $out['succeeded']);
});

test('filteredReason spots an Imagen RAI rejection, null otherwise', function () {
    // The exact shape the proxy returned for a real filtered request.
    $filtered = json_decode('{"predictions":[{"raiFilteredReason":'
        . '"Unable to show generated images. Support codes: 29578790"}]}', true);
    assert_contains('29578790', (string) Imagen::filteredReason($filtered));

    assert_eq(null, Imagen::filteredReason(['predictions' => [['bytesBase64Encoded' => 'QUJD']]]));
    assert_eq(null, Imagen::filteredReason(['predictions' => []]));
    assert_eq(null, Imagen::filteredReason(null));
});

/**
 * The 480-token input cap (Imagen::fitToTokens). ImagePromptComposer
 * leans on this to keep a fully-composed prompt under the model's hard limit.
 */

test('fitToTokens returns the text unchanged when it is within the cap', function () {
    $text = 'A sourdough loaf on a board. Style: photorealistic';
    assert_eq($text, Imagen::fitToTokens($text, Imagen::MAX_PROMPT_TOKENS));
});

test('fitToTokens trims from the end to fit the cap, keeping the lead intact', function () {
    $lead = 'A specific sourdough loaf on a floured board';
    $text = $lead . ' ' . str_repeat('trailing context word ', 2000); // far over budget
    $out = Imagen::fitToTokens($text, Imagen::MAX_PROMPT_TOKENS);

    assert_true(Imagen::estimateTokens($out) <= Imagen::MAX_PROMPT_TOKENS, 'within token cap');
    assert_contains($lead, $out);                  // the leading text survives
    assert_true($out !== '', 'still returns something');
});

test('sampleImageSize renders wide (full-bleed) images at 2K, the rest at 1K', function () {
    assert_eq('2K', Imagen::sampleImageSize('16:9'));
    assert_eq('1K', Imagen::sampleImageSize('1:1'));
    assert_eq('1K', Imagen::sampleImageSize('9:16'));
});

test('aspectRatio emits only Imagen-supported ratios and clamps arbitrary shapes', function () {
    foreach (['1:1', '3:4', '4:3', '9:16', '16:9'] as $supported) {
        assert_eq($supported, Imagen::aspectRatio($supported));
    }
    assert_eq('1:1', Imagen::aspectRatio('square'));
    assert_eq('9:16', Imagen::aspectRatio('portrait'));
    assert_eq('16:9', Imagen::aspectRatio('landscape'));

    assert_eq('16:9', Imagen::aspectRatio('21:9'));
    assert_eq('4:3', Imagen::aspectRatio('8:6'));
    assert_eq('3:4', Imagen::aspectRatio('6:8'));
    assert_eq('1:1', Imagen::aspectRatio('2:2'));
    assert_eq('16:9', Imagen::aspectRatio('0:0'));
    assert_eq('16:9', Imagen::aspectRatio('not-a-ratio'));
});

test('buildBody normalizes an invalid ratio at the transport boundary', function () {
    $body = Imagen::buildBody('A wide landscape', ['aspect_ratio' => '21:9']);
    assert_eq('16:9', $body['parameters']['aspectRatio']);
});

test('sampleImageSize keeps transparent decoratives at 1K even when wide', function () {
    assert_eq('1K', Imagen::sampleImageSize('16:9', true));
    assert_eq('1K', Imagen::sampleImageSize('1:1', true));
    assert_eq('2K', Imagen::sampleImageSize('16:9', false));
});

test('mimeForFilename maps .png assets to PNG and everything else to JPEG', function () {
    assert_eq('image/png', Imagen::mimeForFilename('grapevine-flourish.png'));
    assert_eq('image/png', Imagen::mimeForFilename('ORNAMENT.PNG'));
    assert_eq('image/jpeg', Imagen::mimeForFilename('hero-dawn.jpg'));
    assert_eq('image/jpeg', Imagen::mimeForFilename('hero-dawn.jpeg'));
});

test('estimateTokens is conservative and grows with length', function () {
    assert_eq(0, Imagen::estimateTokens('   '));
    assert_true(Imagen::estimateTokens('a b c d e') >= 5, 'at least one token per short word');
});

test('retryBatch retries held launches without burning the transient budget', function () {
    // held => true means the pool never sent the request; it must not consume
    // the finite retry rounds, and a twice-held image still gets a real
    // attempt instead of degrading to a never-attempted placeholder failure.
    $round = 0;
    $out = Imagen::retryBatch(
        [0 => ['p' => 'real'], 1 => ['p' => 'held']],
        function (array $subset) use (&$round): array {
            $round++;
            $res = [];
            foreach (array_keys($subset) as $i) {
                $res[$i] = match (true) {
                    $i === 0 && $round === 1 => ['ok' => false, 'transient' => true, 'error' => 'HTTP 429'],
                    $i === 1 && $round <= 2 => ['ok' => false, 'transient' => true, 'held' => true, 'error' => 'launch held: a sibling request was rate-limited (HTTP 429)'],
                    default => ['ok' => true, 'bytes' => "IMG{$i}"],
                };
            }
            return $res;
        },
        [0],
    );
    assert_eq('IMG0', $out['results'][0]['bytes']);
    assert_eq('IMG1', $out['results'][1]['bytes'], 'a twice-held image still generates after the budget');
    assert_eq(3, $round, 'held keys retry in their own round instead of failing');
    assert_eq(2, $out['succeeded']);
});

test('retryBatch survives a held launch with an empty delay schedule', function () {
    $round = 0;
    $out = Imagen::retryBatch(
        [0 => ['p' => 'h']],
        function (array $subset) use (&$round): array {
            $round++;
            return [0 => $round === 1
                ? ['ok' => false, 'transient' => true, 'held' => true, 'error' => 'launch held: a sibling request was rate-limited (HTTP 429)']
                : ['ok' => true, 'bytes' => 'IMG']];
        },
        [],
    );
    assert_eq('IMG', $out['results'][0]['bytes'], 'a held image retries even with no transient rounds configured');
});

test('retryBatch reports each result once, at its final state, via onResult', function () {
    // Incremental persistence hook: fires when a result is FINAL (success or
    // out-of-retries failure), never for a transient outcome that will retry.
    $seen = [];
    $round = 0;
    $out = Imagen::retryBatch(
        [0 => ['p' => 'slow-ok'], 1 => ['p' => 'ok'], 2 => ['p' => 'dies']],
        function (array $subset) use (&$round): array {
            $round++;
            $res = [];
            foreach (array_keys($subset) as $i) {
                $res[$i] = match (true) {
                    $i === 0 && $round === 1 => ['ok' => false, 'transient' => true, 'error' => 'HTTP 503'],
                    $i === 2 => ['ok' => false, 'transient' => false, 'error' => 'permanent'],
                    default => ['ok' => true, 'bytes' => "IMG{$i}"],
                };
            }
            return $res;
        },
        [0],
        null,
        function (int $i, array $result) use (&$seen): void {
            $seen[] = [$i, $result['ok']];
        },
    );
    sort($seen);
    assert_eq([[0, true], [1, true], [2, false]], $seen, 'one final callback per image, no transient intermediates');
    assert_eq('IMG0', $out['results'][0]['bytes'], 'return shape is unchanged');
});
