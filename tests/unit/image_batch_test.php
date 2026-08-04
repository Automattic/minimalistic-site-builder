<?php
declare(strict_types=1);

use Automattic\SiteBuild\GeminiImage;
use Automattic\SiteBuild\ImageFilteredException;
use Automattic\SiteBuild\TransientApiException;

/** A valid 1x1 PNG for byte-level delivery checks. */
function gemini_image_fixture_png(): string
{
    return (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        true,
    );
}

/** A valid 1x1 JPEG for byte-level delivery checks. */
function gemini_image_fixture_jpeg(): string
{
    return (string) base64_decode(
        '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AVN//2Q==',
        true,
    );
}

/**
 * Unit tests for the batch retry orchestration (GeminiImage::retryBatch).
 * The transport is faked so we exercise the transient-retry accounting without
 * any network or real backoff sleeps (delays are [0, 0]).
 */

test('retryBatch returns one result per body, keyed and ordered by index', function () {
    $bodies = [0 => ['b' => 0], 1 => ['b' => 1], 2 => ['b' => 2]];
    $transport = fn (array $subset) => array_map(fn () => ['ok' => true, 'bytes' => 'X'], $subset);

    $out = GeminiImage::retryBatch($bodies, $transport, [0, 0]);

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

    $out = GeminiImage::retryBatch($bodies, $transport, [0, 0, 0]);

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

    $out = GeminiImage::retryBatch($bodies, $transport, [0, 0]); // 2 retries

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

    $out = GeminiImage::retryBatch($bodies, $transport, [0, 0]);

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

    $out = GeminiImage::retryBatch($bodies, $transport, [0, 0, 0]); // 3 retries

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

    $out = GeminiImage::retryBatch($bodies, $transport, [0, 0]);

    assert_eq(true, $out['results'][0]['ok']);
    assert_eq(1, $out['succeeded']);
});

test('filteredReason spots each Gemini rejection shape, null otherwise', function () {
    // Prompt-level block: no candidates at all, just promptFeedback.
    $blocked = json_decode('{"promptFeedback":{"blockReason":"PROHIBITED_CONTENT"}}', true);
    assert_contains('PROHIBITED_CONTENT', (string) GeminiImage::filteredReason($blocked));

    // Candidate-level safety finish without an image part.
    $safety = ['candidates' => [['finishReason' => 'IMAGE_SAFETY', 'content' => ['parts' => []]]]];
    assert_contains('IMAGE_SAFETY', (string) GeminiImage::filteredReason($safety));

    // Text-only refusal that finishes STOP — still a repairable rejection.
    $refusal = ['candidates' => [[
        'finishReason' => 'STOP',
        'content' => ['parts' => [['text' => 'I cannot generate that image.']]],
    ]]];
    assert_contains('cannot generate', (string) GeminiImage::filteredReason($refusal));

    // A response that carries image data is never filtered, whatever rides along.
    $ok = ['candidates' => [[
        'finishReason' => 'STOP',
        'content' => ['parts' => [
            ['text' => 'Here is your image.'],
            ['inlineData' => ['mimeType' => 'image/png', 'data' => 'QUJD']],
        ]],
    ]]];
    assert_eq(null, GeminiImage::filteredReason($ok));
    assert_eq(null, GeminiImage::filteredReason(['candidates' => []]));
    assert_eq(null, GeminiImage::filteredReason(null));
});

test('imageData finds the inline image part and skips narration text', function () {
    $ok = ['candidates' => [['content' => ['parts' => [
        ['text' => 'Sure — here it is.'],
        ['inlineData' => ['mimeType' => 'image/png', 'data' => 'QUJD']],
    ]]]]];
    assert_eq('QUJD', GeminiImage::imageData($ok));

    // snake_case variant of the inline-data key is accepted too.
    $snake = ['candidates' => [['content' => ['parts' => [
        ['inline_data' => ['mime_type' => 'image/png', 'data' => 'QUJD']],
    ]]]]];
    assert_eq('QUJD', GeminiImage::imageData($snake));
    assert_eq(
        ['data' => 'QUJD', 'mime' => 'image/png'],
        GeminiImage::imagePart($snake),
        'the declared MIME survives extraction',
    );

    // Gemini 3 may expose intermediate thought images. Only the final authored
    // image is a deliverable asset.
    $thoughts = ['candidates' => [['content' => ['parts' => [
        ['thought' => true, 'inlineData' => ['mimeType' => 'image/png', 'data' => 'VEhPVUdIVA==']],
        ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => 'RklOQUw=']],
    ]]]]];
    assert_eq(['data' => 'RklOQUw=', 'mime' => 'image/jpeg'], GeminiImage::imagePart($thoughts));

    assert_eq(null, GeminiImage::imageData(['candidates' => []]));
    assert_eq(null, GeminiImage::imageData(null));
});

test('interpret decodes a Gemini success and classifies the failure shapes', function () {
    $png = gemini_image_fixture_png();
    $ok = json_encode(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [
        ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($png)]],
    ]]]]]);
    assert_eq(
        ['bytes' => $png, 'mime' => 'image/png'],
        GeminiImage::interpret((string) $ok, 200),
    );

    try {
        GeminiImage::interpret((string) json_encode(['promptFeedback' => ['blockReason' => 'SAFETY']]), 200);
        assert_true(false, 'expected ImageFilteredException');
    } catch (ImageFilteredException $e) {
        assert_contains('SAFETY', $e->getMessage());
    }

    try {
        GeminiImage::interpret('{"candidates":[]}', 200);
        assert_true(false, 'expected RuntimeException for missing image data');
    } catch (\RuntimeException $e) {
        assert_contains('no image data', $e->getMessage());
    }

    try {
        GeminiImage::interpret('overloaded', 529);
        assert_true(false, 'expected TransientApiException');
    } catch (TransientApiException $e) {
        assert_contains('529', $e->getMessage());
    }
});

/**
 * The 480-token input cap (GeminiImage::fitToTokens). ImagePromptComposer
 * leans on this to keep a fully-composed prompt under the model's hard limit.
 */

test('fitToTokens returns the text unchanged when it is within the cap', function () {
    $text = 'A sourdough loaf on a board. Style: photorealistic';
    assert_eq($text, GeminiImage::fitToTokens($text, GeminiImage::MAX_PROMPT_TOKENS));
});

test('fitToTokens trims from the end to fit the cap, keeping the lead intact', function () {
    $lead = 'A specific sourdough loaf on a floured board';
    $text = $lead . ' ' . str_repeat('trailing context word ', 2000); // far over budget
    $out = GeminiImage::fitToTokens($text, GeminiImage::MAX_PROMPT_TOKENS);

    assert_true(GeminiImage::estimateTokens($out) <= GeminiImage::MAX_PROMPT_TOKENS, 'within token cap');
    assert_contains($lead, $out);                  // the leading text survives
    assert_true($out !== '', 'still returns something');
});

test('sampleImageSize renders wide (full-bleed) images at 2K, the rest at 1K', function () {
    assert_eq('2K', GeminiImage::sampleImageSize('16:9'));
    assert_eq('2K', GeminiImage::sampleImageSize('21:9'));
    assert_eq('1K', GeminiImage::sampleImageSize('1:1'));
    assert_eq('1K', GeminiImage::sampleImageSize('9:16'));
    assert_eq('1K', GeminiImage::sampleImageSize('4:3'));
});

test('aspectRatio emits only supported ratios and clamps arbitrary shapes', function () {
    foreach (['1:1', '3:4', '4:3', '9:16', '16:9', '21:9'] as $supported) {
        assert_eq($supported, GeminiImage::aspectRatio($supported));
    }
    assert_eq('1:1', GeminiImage::aspectRatio('square'));
    assert_eq('9:16', GeminiImage::aspectRatio('portrait'));
    assert_eq('16:9', GeminiImage::aspectRatio('landscape'));
    assert_eq('21:9', GeminiImage::aspectRatio('ultrawide'));
    assert_eq('4:3', GeminiImage::aspectRatio('card-landscape'));
    assert_eq('3:4', GeminiImage::aspectRatio('card-portrait'));

    assert_eq('21:9', GeminiImage::aspectRatio('32:9'));
    assert_eq('16:9', GeminiImage::aspectRatio('2:1'));
    assert_eq('4:3', GeminiImage::aspectRatio('8:6'));
    assert_eq('3:4', GeminiImage::aspectRatio('6:8'));
    assert_eq('1:1', GeminiImage::aspectRatio('2:2'));
    assert_eq('16:9', GeminiImage::aspectRatio('0:0'));
    assert_eq('16:9', GeminiImage::aspectRatio('not-a-ratio'));
});

test('buildBody normalizes an invalid ratio at the transport boundary', function () {
    $body = GeminiImage::buildBody('A wide landscape', ['aspect_ratio' => '2:1']);
    assert_eq('16:9', $body['generationConfig']['imageConfig']['aspectRatio']);
    assert_eq('A wide landscape', $body['contents'][0]['parts'][0]['text']);
    assert_eq([
        'mimeType' => 'image/jpeg',
        'compressionQuality' => 85,
    ], $body['generationConfig']['imageConfig']['imageOutputOptions']);
});

test('buildBody requests PNG without the JPEG-only compression option', function () {
    $body = GeminiImage::buildBody('A line ornament', [
        'aspect_ratio' => 'landscape',
        'mime' => 'image/png',
    ]);
    assert_eq(
        ['mimeType' => 'image/png'],
        $body['generationConfig']['imageConfig']['imageOutputOptions'],
    );
});

test('ensureMime trusts byte magic and keeps server-honored output byte-identical', function () {
    $jpeg = gemini_image_fixture_jpeg();
    $png = gemini_image_fixture_png();

    assert_eq('image/jpeg', GeminiImage::mimeFromBytes($jpeg));
    assert_eq('image/png', GeminiImage::mimeFromBytes($png));
    assert_eq($jpeg, GeminiImage::ensureMime($jpeg, 'image/jpeg', 'image/jpeg'));
    assert_eq($png, GeminiImage::ensureMime($png, null, 'image/png'));
    assert_eq(
        $jpeg,
        GeminiImage::ensureMime($jpeg, 'image/png', 'image/jpeg'),
        'actual magic wins over stale response metadata',
    );
});

test('ensureMime converts a server PNG only as a verified JPEG fallback', function () {
    $png = gemini_image_fixture_png();
    $jpeg = gemini_image_fixture_jpeg();
    $seen = null;

    $out = GeminiImage::ensureMime(
        $png,
        'image/png',
        'image/jpeg',
        function (string $bytes) use (&$seen, $jpeg): string {
            $seen = $bytes;
            return $jpeg;
        },
    );

    assert_eq($png, $seen, 'fallback receives the server bytes');
    assert_eq($jpeg, $out);
    assert_eq('image/jpeg', GeminiImage::mimeFromBytes($out));
});

test('ensureMime refuses an unconverted PNG instead of labeling it JPEG', function () {
    $png = gemini_image_fixture_png();
    $error = assert_throws(fn () => GeminiImage::ensureMime(
        $png,
        'image/jpeg', // even a lying declaration cannot override byte magic
        'image/jpeg',
        fn (string $bytes): string => $bytes,
    ));

    assert_contains('requested image/jpeg', $error->getMessage());
    assert_contains('declared image/jpeg', $error->getMessage());
    assert_contains('detected image/png', $error->getMessage());
    assert_contains('delivered removed', $error->getMessage());
});

test('ensureMime refuses unknown bytes and a JPEG response requested as PNG', function () {
    $unknown = assert_throws(fn () => GeminiImage::ensureMime(
        'not an image',
        'image/jpeg',
        'image/jpeg',
    ));
    assert_contains('detected unrecognized', $unknown->getMessage());

    $jpegAsPng = assert_throws(fn () => GeminiImage::ensureMime(
        gemini_image_fixture_jpeg(),
        'image/jpeg',
        'image/png',
    ));
    assert_contains('requested image/png', $jpegAsPng->getMessage());
    assert_contains('detected image/jpeg', $jpegAsPng->getMessage());
});

test('mimeFromBytes rejects truncated signature-only payloads', function () {
    $truncatedJpeg = "\xFF\xD8\xFFtruncated";
    $truncatedPng = "\x89PNG\r\n\x1A\ntruncated";

    assert_eq(null, GeminiImage::mimeFromBytes($truncatedJpeg));
    assert_eq(null, GeminiImage::mimeFromBytes($truncatedPng));

    $error = assert_throws(fn () => GeminiImage::ensureMime(
        $truncatedJpeg,
        'image/jpeg',
        'image/jpeg',
    ));
    assert_contains('detected unrecognized', $error->getMessage());
    assert_contains('delivered removed', $error->getMessage());
});

test('mimeFromBytes rejects real images truncated after their dimension headers', function () {
    $png = gemini_image_fixture_png();
    $jpeg = gemini_image_fixture_jpeg();

    assert_true(
        is_array(getimagesizefromstring(substr($png, 0, 32))),
        'the PHP header probe accepts this truncated PNG regression input',
    );
    assert_true(
        is_array(getimagesizefromstring(substr($jpeg, 0, 100))),
        'the PHP header probe accepts this truncated JPEG regression input',
    );
    assert_eq(null, GeminiImage::mimeFromBytes(substr($png, 0, 32)));
    assert_eq(null, GeminiImage::mimeFromBytes(substr($jpeg, 0, 100)));
});

test('sampleImageSize keeps transparent decoratives at 1K even when wide', function () {
    assert_eq('1K', GeminiImage::sampleImageSize('16:9', true));
    assert_eq('1K', GeminiImage::sampleImageSize('1:1', true));
    assert_eq('2K', GeminiImage::sampleImageSize('16:9', false));
});

test('mimeForFilename maps .png assets to PNG and everything else to JPEG', function () {
    assert_eq('image/png', GeminiImage::mimeForFilename('grapevine-flourish.png'));
    assert_eq('image/png', GeminiImage::mimeForFilename('ORNAMENT.PNG'));
    assert_eq('image/jpeg', GeminiImage::mimeForFilename('hero-dawn.jpg'));
    assert_eq('image/jpeg', GeminiImage::mimeForFilename('hero-dawn.jpeg'));
});

test('estimateTokens is conservative and grows with length', function () {
    assert_eq(0, GeminiImage::estimateTokens('   '));
    assert_true(GeminiImage::estimateTokens('a b c d e') >= 5, 'at least one token per short word');
});

test('retryBatch retries held launches without burning the transient budget', function () {
    // held => true means the pool never sent the request; it must not consume
    // the finite retry rounds, and a twice-held image still gets a real
    // attempt instead of degrading to a never-attempted placeholder failure.
    $round = 0;
    $out = GeminiImage::retryBatch(
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

test('retryBatch gives a previously held image its own transient retry budget', function () {
    $round = 0;
    $out = GeminiImage::retryBatch(
        [0 => ['p' => 'real'], 1 => ['p' => 'held']],
        function (array $subset) use (&$round): array {
            $round++;
            $res = [];
            foreach (array_keys($subset) as $i) {
                $res[$i] = match (true) {
                    $i === 0 && $round === 1 => [
                        'ok' => false, 'transient' => true, 'error' => 'HTTP 429',
                    ],
                    $i === 1 && $round <= 2 => [
                        'ok' => false, 'transient' => true, 'held' => true, 'error' => 'launch held',
                    ],
                    $i === 1 && $round === 3 => [
                        'ok' => false, 'transient' => true, 'error' => 'first real attempt timed out',
                    ],
                    default => ['ok' => true, 'bytes' => "IMG{$i}"],
                };
            }
            return $res;
        },
        [0],
    );
    assert_eq('IMG1', $out['results'][1]['bytes'], 'held rounds do not consume the image retry');
    assert_eq(4, $round, 'the first real transient gets its configured retry');
    assert_eq(2, $out['succeeded']);
});

test('retryBatch survives a held launch with an empty delay schedule', function () {
    $round = 0;
    $out = GeminiImage::retryBatch(
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
    $out = GeminiImage::retryBatch(
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
    assert_eq(true, $out['results'][0]['ok'], 'the outcome survives in the return value (bytes ride the callback)');
});

test('retryBatch releases image bytes after onResult delivers them', function () {
    // With onResult, bytes reach the caller (and disk) the moment each image
    // finishes - retaining a second copy of every image in the returned
    // results held ~150MB on a 52-image build and hit PHP's memory limit.
    // The returned record keeps the outcome, not the payload.
    $onResultBytes = [];
    $out = GeminiImage::retryBatch(
        [0 => ['p' => 'a'], 1 => ['p' => 'b']],
        fn (array $subset): array => array_map(fn ($body): array => ['ok' => true, 'bytes' => 'BYTES-' . $body['p']], $subset),
        [],
        null,
        function (int $i, array $result) use (&$onResultBytes): void {
            $onResultBytes[$i] = $result['bytes'] ?? null;
        },
    );
    assert_eq(['BYTES-a', 'BYTES-b'], [$onResultBytes[0], $onResultBytes[1]], 'the callback receives the bytes');
    assert_true(!array_key_exists('bytes', $out['results'][0]), 'the returned record does not retain a second copy');
    assert_eq(true, $out['results'][0]['ok'], 'the outcome survives');
    assert_eq(2, $out['succeeded']);

    // Without onResult the return value is the only delivery path - bytes stay.
    $out = GeminiImage::retryBatch(
        [0 => ['p' => 'a']],
        fn (array $subset): array => [0 => ['ok' => true, 'bytes' => 'BYTES']],
        [],
    );
    assert_eq('BYTES', $out['results'][0]['bytes'], 'no callback, bytes returned as before');
});

test('retryBatch accepts a success outcome whose bytes were already delivered out-of-band', function () {
    // The pooled transport hands success bytes to the caller the moment a
    // transfer is classified (success is always final) and returns a light
    // outcome without bytes - the pool must never accumulate every image.
    // retryBatch records the outcome without warning and without inventing
    // a bytes key.
    $out = GeminiImage::retryBatch(
        [0 => ['p' => 'a']],
        fn (array $subset): array => [0 => ['ok' => true]],
        [],
    );
    assert_eq(true, $out['results'][0]['ok']);
    assert_true(!array_key_exists('bytes', $out['results'][0]), 'no phantom bytes key');
    assert_eq(1, $out['succeeded']);
});
