<?php
declare(strict_types=1);

use Automattic\SiteBuild\JsonBatchRecovery;
use Automattic\SiteBuild\StopReasons;

/**
 * Unit tests for the shared stop-reason vocabularies (StopReasons) — the one
 * home for classifying abnormal generation termination across providers and
 * recovery layers.
 */

test('StopReasons classifies truncation, output-limit, and refusal vocabularies', function () {
    foreach (['max_tokens', 'length', 'model_context_window_exceeded', ' length '] as $reason) {
        assert_true(StopReasons::isTruncation($reason), "{$reason} is truncation");
    }
    foreach (['max_tokens', 'length'] as $reason) {
        assert_true(StopReasons::isOutputLimit($reason), "{$reason} is an output-limit stop");
    }
    // A context-window overflow is NOT an output-limit stop: no output budget
    // can repair an oversized input, and the one-token cache probe must not
    // accept it as its expected termination.
    assert_true(!StopReasons::isOutputLimit('model_context_window_exceeded'), 'context window is not the output cap');

    foreach (['refusal', 'content_filter', 'safety'] as $reason) {
        assert_true(StopReasons::isRefusal($reason), "{$reason} is a refusal");
        assert_true(!StopReasons::isTruncation($reason), "{$reason} is not truncation");
    }

    foreach (['stop', 'end_turn', '', null, 42, ['length']] as $reason) {
        assert_true(!StopReasons::isTruncation($reason), 'normal/invalid reasons are not truncation');
        assert_true(!StopReasons::isRefusal($reason), 'normal/invalid reasons are not refusal');
        assert_true(!StopReasons::isOutputLimit($reason), 'normal/invalid reasons are not output-limit');
    }
});

test('StopReasons terminationError words truncation and refusal, null otherwise', function () {
    assert_eq('generation was truncated (stop reason: max_tokens)', StopReasons::terminationError('max_tokens'));
    assert_eq('generation was truncated (stop reason: length)', StopReasons::terminationError(' length '));
    assert_eq(
        'generation was refused or filtered (stop reason: content_filter)',
        StopReasons::terminationError('content_filter'),
    );
    assert_eq(null, StopReasons::terminationError('stop'));
    assert_eq(null, StopReasons::terminationError(null));
    assert_eq(null, StopReasons::terminationError(''));
});

test('JsonBatchRecovery delegates its classification to the shared vocabulary', function () {
    foreach (['max_tokens', 'length', 'model_context_window_exceeded', 'refusal', 'content_filter', 'safety', 'stop', null] as $reason) {
        assert_eq(
            StopReasons::isTruncation($reason),
            JsonBatchRecovery::isTruncation($reason),
            'isTruncation stays aligned for ' . var_export($reason, true),
        );
        assert_eq(
            StopReasons::terminationError($reason),
            JsonBatchRecovery::terminationError($reason),
            'terminationError stays aligned for ' . var_export($reason, true),
        );
    }
});
