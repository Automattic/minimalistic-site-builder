<?php
declare(strict_types=1);

use Automattic\SiteBuild\JsonBatchRecovery;

/** Run a callback and return the RuntimeException it is expected to throw. */
function jbr_exception(callable $callback): RuntimeException
{
    try {
        $callback();
    } catch (RuntimeException $e) {
        return $e;
    }

    throw new RuntimeException('Expected JsonBatchRecovery to throw');
}

test('JsonBatchRecovery returns an all-valid batch without a retry', function () {
    $requests = [
        'theme' => ['prompt' => 'Generate the theme'],
        'page'  => ['prompt' => 'Plan the page'],
    ];
    $rounds = [];

    $send = function (array $subset) use (&$rounds): array {
        $rounds[] = array_keys($subset);
        return [
            'theme' => ['text' => '{"kind":"theme"}', 'stop_reason' => 'end_turn'],
            'page'  => ['text' => '{"kind":"page"}', 'stop_reason' => 'end_turn'],
        ];
    };

    $out = JsonBatchRecovery::run($requests, $send);

    assert_eq([['theme', 'page']], $rounds, 'an all-valid batch is sent exactly once');
    assert_eq(['theme', 'page'], array_keys($out), 'input order is preserved');
    assert_eq('theme', $out['theme']['kind']);
    assert_eq('page', $out['page']['kind']);
});

test('JsonBatchRecovery retries only one malformed sibling', function () {
    $requests = [
        'theme' => ['prompt' => 'Generate the theme'],
        'menu'  => ['prompt' => 'Plan the menu page'],
        'about' => ['prompt' => 'Plan the about page'],
    ];
    $rounds = [];

    $send = function (array $subset) use (&$rounds): array {
        $rounds[] = array_keys($subset);
        if (count($rounds) === 1) {
            return [
                'theme' => ['text' => '{"ok":"theme"}'],
                'menu'  => ['text' => '{"title":"Menu" "sections":[]}'],
                'about' => ['text' => '{"ok":"about"}'],
            ];
        }

        return ['menu' => ['text' => '{"ok":"menu"}']];
    };

    $out = JsonBatchRecovery::run($requests, $send);

    assert_eq(
        [['theme', 'menu', 'about'], ['menu']],
        $rounds,
        'the valid siblings are retained rather than regenerated',
    );
    assert_eq(['theme', 'menu', 'about'], array_keys($out), 'repair does not move the key to the end');
    assert_eq('theme', $out['theme']['ok']);
    assert_eq('menu', $out['menu']['ok']);
    assert_eq('about', $out['about']['ok']);
});

test('JsonBatchRecovery retries multiple malformed siblings together', function () {
    $requests = [
        'home'    => ['prompt' => 'Plan home'],
        'theme'   => ['prompt' => 'Generate theme'],
        'contact' => ['prompt' => 'Plan contact'],
    ];
    $rounds = [];

    $send = function (array $subset) use (&$rounds): array {
        $rounds[] = array_keys($subset);
        if (count($rounds) === 1) {
            return [
                'home'    => ['text' => '{"sections":[}'],
                'theme'   => ['text' => '{"ok":"theme"}'],
                'contact' => ['text' => '{"heading":"Say "hello""}'],
            ];
        }

        return [
            'home'    => ['text' => '{"ok":"home"}'],
            'contact' => ['text' => '{"ok":"contact"}'],
        ];
    };

    $out = JsonBatchRecovery::run($requests, $send);

    assert_eq(
        [['home', 'theme', 'contact'], ['home', 'contact']],
        $rounds,
        'all and only malformed keys share the repair round',
    );
    assert_eq('home', $out['home']['ok']);
    assert_eq('theme', $out['theme']['ok']);
    assert_eq('contact', $out['contact']['ok']);
});

test('JsonBatchRecovery reports a concise diagnostic when the retry is still malformed', function () {
    $requests = ['reservations' => ['prompt' => 'Plan reservations']];
    $round = 0;
    $rawMarker = 'RAW_RESPONSE_MUST_NOT_APPEAR_' . str_repeat('x', 800);

    $send = function (array $subset) use (&$round, $rawMarker): array {
        $round++;
        return ['reservations' => [
            'text'        => '{"content_notes":"' . $rawMarker . '" "layout":"stack"}',
            'stop_reason' => 'end_turn',
            'model'       => 'claude-haiku-4-5',
            'log_path'    => $round === 1 ? '/tmp/reservations.log' : '/tmp/reservations-retry.log',
        ]];
    };

    $error = jbr_exception(fn () => JsonBatchRecovery::run($requests, $send));
    $message = $error->getMessage();
    $lower = strtolower($message);

    assert_eq(2, $round, 'the configured one repair attempt is honored');
    assert_contains('reservations', $message);
    assert_contains('syntax error', $lower, 'the JSON parser error is actionable');
    assert_contains('claude-haiku-4-5', $message);
    assert_contains('end_turn', $message);
    assert_contains('/tmp/reservations-retry.log', $message, 'the latest transcript is identified');
    assert_true(!str_contains($message, 'RAW_RESPONSE_MUST_NOT_APPEAR'), 'raw model output is kept out of the exception');
    assert_true(strlen($message) < 600, 'the terminal diagnostic stays concise');
});

test('JsonBatchRecovery fails loud when a sender omits an expected key', function () {
    $requests = [
        'theme' => ['prompt' => 'Generate theme'],
        'page'  => ['prompt' => 'Plan page'],
    ];
    $calls = 0;
    $send = function (array $subset) use (&$calls): array {
        $calls++;
        return ['theme' => ['text' => '{"ok":true}']];
    };

    $error = jbr_exception(fn () => JsonBatchRecovery::run($requests, $send));
    $message = strtolower($error->getMessage());

    assert_eq(1, $calls, 'a broken sender contract is not treated as malformed model output');
    assert_true(
        str_contains($message, 'missing') || str_contains($message, 'omitted'),
        'the sender contract failure is identified',
    );
    assert_contains('page', $message, 'the omitted key is named');
});

test('JsonBatchRecovery preserves order and integer keys', function () {
    $requests = [
        7       => ['prompt' => 'Seven'],
        'theme' => ['prompt' => 'Theme'],
        2       => ['prompt' => 'Two'],
    ];

    $send = function (array $subset): array {
        $out = [];
        foreach ($subset as $key => $_request) {
            $out[$key] = ['text' => (string) json_encode(['key' => (string) $key])];
        }
        return $out;
    };

    $out = JsonBatchRecovery::run($requests, $send);

    assert_eq([7, 'theme', 2], array_keys($out));
    assert_eq('7', $out[7]['key']);
    assert_eq('theme', $out['theme']['key']);
    assert_eq('2', $out[2]['key']);
});

test('JsonBatchRecovery classifies max_tokens output as truncated JSON', function () {
    $requests = ['long-page' => ['prompt' => 'Plan a long page']];
    $rawMarker = 'TRUNCATED_RAW_BODY_MUST_NOT_LEAK';
    $send = fn (array $subset): array => ['long-page' => [
        'text'        => '{"sections":["' . $rawMarker,
        'stop_reason' => 'max_tokens',
        'model'       => 'claude-haiku-4-5',
        'log_path'    => '/tmp/long-page.log',
    ]];

    // Zero retries isolates classification of the first response from the
    // separate policy of whether a caller wants to regenerate it.
    $error = jbr_exception(fn () => JsonBatchRecovery::run($requests, $send, 0));
    $message = $error->getMessage();
    $lower = strtolower($message);

    assert_contains('long-page', $message);
    assert_contains('max_tokens', $message);
    assert_true(
        str_contains($lower, 'truncat') || str_contains($lower, 'token limit'),
        'the termination reason is classified rather than reported as generic malformed JSON',
    );
    assert_contains('/tmp/long-page.log', $message);
    assert_true(!str_contains($message, $rawMarker), 'truncated output is available in the log, not the exception');
});
