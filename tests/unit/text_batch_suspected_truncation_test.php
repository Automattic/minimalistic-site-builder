<?php
declare(strict_types=1);

use Automattic\SiteBuild\TextBatchRecovery;

/**
 * Unit tests for inferring an output-budget truncation from usage alone.
 *
 * Every recovery path in TextBatchRecovery keys off `stop_reason`. A host whose
 * adapter omits the field turns a truncated generation into a silently accepted
 * one: the batch sees a clean termination, skips regeneration, and ships block
 * markup that stops mid-element.
 *
 * The tests below pin both halves of that. A budget filled to the token with no
 * stop reason must be recovered; a host that DOES report a reason must be
 * believed rather than second-guessed, so conforming transports keep their
 * existing behaviour exactly.
 */

test('a budget filled to the token with no stop reason is treated as truncated', function () {
    // The production case: 16,000 output tokens against a 16,000 budget,
    // no stop reason, no retry, nothing recorded.
    $suspected = TextBatchRecovery::suspectedOutputLimit(
        ['text' => '<!-- wp:group -->…', 'output' => 16000],
        ['prompt' => 'Generate the menu page.', 'max_tokens' => 16000],
        16000,
    );

    assert_true($suspected, 'an exactly-filled budget with no stop reason must be recovered');
});

test('a reported stop reason is authoritative and never second-guessed', function () {
    // A conforming host that says "end_turn" while happening to land on the
    // budget must be believed — otherwise every such response burns a
    // pointless doubled-budget regeneration.
    $suspected = TextBatchRecovery::suspectedOutputLimit(
        ['text' => 'done', 'output' => 16000, 'stop_reason' => 'end_turn'],
        ['prompt' => 'x', 'max_tokens' => 16000],
        16000,
    );

    assert_true(!$suspected, 'an explicit stop reason wins over the usage heuristic');
});

test('a response comfortably inside its budget is left alone', function () {
    $suspected = TextBatchRecovery::suspectedOutputLimit(
        ['text' => 'short', 'output' => 1200],
        ['prompt' => 'x', 'max_tokens' => 16000],
        16000,
    );

    assert_true(!$suspected, 'normal-length responses must not be regenerated');
});

test('the request default budget applies when the request set none', function () {
    $suspected = TextBatchRecovery::suspectedOutputLimit(
        ['text' => 'x', 'output' => 8000],
        ['prompt' => 'x'],
        8000,
    );

    assert_true($suspected, 'a request relying on the client default is judged against that default');
});

test('a host that reports no usage is unjudgeable and stays quiet', function () {
    assert_true(!TextBatchRecovery::suspectedOutputLimit(
        ['text' => 'x'],
        ['prompt' => 'x', 'max_tokens' => 16000],
        16000,
    ), 'no output count means no inference');

    assert_true(!TextBatchRecovery::suspectedOutputLimit(
        ['text' => 'x', 'output' => null],
        ['prompt' => 'x', 'max_tokens' => 16000],
        16000,
    ));
});

test('a blank stop reason counts as absent, not as a normal termination', function () {
    $suspected = TextBatchRecovery::suspectedOutputLimit(
        ['text' => 'x', 'output' => 500, 'stop_reason' => '   '],
        ['prompt' => 'x', 'max_tokens' => 500],
        500,
    );

    assert_true($suspected, 'whitespace is not a stop reason');
});

test('a stop-reason-blind host gets the doubled-budget regeneration end to end', function () {
    $sent = [];
    $send = function (array $subset) use (&$sent): array {
        $out = [];
        foreach ($subset as $key => $request) {
            $sent[] = $request;
            $budget = (int) ($request['max_tokens'] ?? 0);
            // The host never reports a stop reason. First pass fills the
            // budget exactly; the doubled retry finishes well inside it.
            $out[$key] = $budget > 16000
                ? ['text' => 'COMPLETE', 'output' => 900]
                : ['text' => 'CUT OFF', 'output' => $budget];
        }
        return $out;
    };

    $result = TextBatchRecovery::run(
        ['menu' => ['prompt' => 'Generate the menu page.', 'max_tokens' => 16000]],
        $send,
        maxRetries: 1,
        defaultMaxTokens: 16000,
    );

    assert_eq('COMPLETE', $result->texts['menu'], 'the regenerated response replaces the truncated one');
    assert_eq(2, count($sent), 'exactly one regeneration');
    assert_eq(32000, $sent[1]['max_tokens'], 'the retry doubles the output budget');
    assert_contains('CUT OFF BY THE OUTPUT LENGTH LIMIT', $sent[1]['prompt']);
    assert_eq([], $result->notesFor('menu'), 'a recovered member carries no degradation note');
});

test('a retry that finishes inside its doubled budget is accepted', function () {
    $calls = 0;
    $send = static function (array $subset) use (&$calls): array {
        $calls++;
        $out = [];
        foreach ($subset as $key => $request) {
            $out[$key] = $calls === 1
                ? ['text' => 'CUT OFF', 'output' => (int) $request['max_tokens']]
                : ['text' => 'COMPLETE', 'output' => 20000];
        }
        return $out;
    };

    $result = TextBatchRecovery::run(
        ['menu' => ['prompt' => 'Generate the menu page.', 'max_tokens' => 16000]],
        $send,
        maxRetries: 1,
        defaultMaxTokens: 16000,
    );

    assert_eq(2, $calls, 'exactly one regeneration');
    assert_eq('COMPLETE', $result->texts['menu'], 'the retry is judged against its doubled budget');
    assert_eq([], $result->notesFor('menu'), 'a complete retry carries no degradation note');
});

test('a still-truncated stop-reason-blind member is retained with an actionable note', function () {
    // Always fills whatever budget it is given: regeneration cannot help, so
    // the best partial must survive for salvage rather than aborting the batch.
    $send = static function (array $subset): array {
        $out = [];
        foreach ($subset as $key => $request) {
            $budget = (int) ($request['max_tokens'] ?? 0);
            $out[$key] = ['text' => str_repeat('x', 10), 'output' => $budget];
        }
        return $out;
    };

    $result = TextBatchRecovery::run(
        ['menu' => ['prompt' => 'Generate the menu page.', 'max_tokens' => 16000]],
        $send,
        maxRetries: 1,
        defaultMaxTokens: 16000,
    );

    assert_eq(str_repeat('x', 10), $result->texts['menu'], 'the best partial is kept, not discarded');
    $notes = $result->notesFor('menu');
    assert_true($notes !== [], 'a retained truncation must be recorded for warnings.json');
    assert_contains('32000-token output budget', $notes[0], 'the note names the retry budget that was filled');
    assert_contains('no stop reason', $notes[0], 'the note names the host defect that hid it');
});
