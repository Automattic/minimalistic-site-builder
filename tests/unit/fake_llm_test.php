<?php
declare(strict_types=1);

use Automattic\SiteBuild\Tests\FakeLlm;

test('FakeLlm applies configured permanent failures to single JSON calls without consuming the queue', function () {
    $llm = new FakeLlm();
    $llm->queueJson(['status' => 'queued']);
    $llm->failPromptSubstrings = ['reject me'];

    $error = assert_throws(fn () => $llm->completeJson('please reject me'));

    assert_contains('permanent failure', $error->getMessage());
    assert_eq(1, $llm->completeJsonCalls);
    assert_eq(1, $llm->remaining(), 'a rejected call must not consume its queued response');
    assert_eq(['status' => 'queued'], $llm->completeJson('safe prompt'));
    assert_eq(0, $llm->remaining());
});

test('FakeLlm JSON batches fail atomically before recording calls or consuming responses', function () {
    $llm = new FakeLlm();
    $llm->queueJson(['id' => 'first']);
    $llm->queueJson(['id' => 'second']);
    $llm->failPromptSubstrings = ['broken request'];

    $error = assert_throws(fn () => $llm->completeJsonBatch([
        'good' => ['prompt' => 'safe request'],
        'bad' => ['prompt' => 'broken request'],
    ]));

    assert_contains("batch request 'bad' failed", $error->getMessage());
    assert_eq(1, $llm->completeJsonBatchCalls);
    assert_eq([], $llm->calls, 'a rejected batch must not record a partially executed call set');
    assert_eq(2, $llm->remaining(), 'a rejected batch must not consume any queued response');
});

test('FakeLlm remaining counts both text and JSON queues', function () {
    $llm = new FakeLlm();
    $llm->queueText('text');
    $llm->queueJson(['json' => true]);

    assert_eq(2, $llm->remaining());
    assert_eq('text', $llm->complete('text prompt'));
    assert_eq(1, $llm->remaining());
    assert_eq(['json' => true], $llm->completeJson('json prompt'));
    assert_eq(0, $llm->remaining());
});
