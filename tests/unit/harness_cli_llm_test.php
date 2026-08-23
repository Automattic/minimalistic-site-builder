<?php
declare(strict_types=1);

use Automattic\SiteBuild\ClaudeCliLlm;
use Automattic\SiteBuild\HarnessCallFailed;
use Automattic\SiteBuild\LlmConformance;

function harness_cli_fixture(string $name): string
{
    return dirname(__DIR__) . '/fixtures/fake-harness/' . $name;
}

/** @return array<string,mixed> */
function harness_cli_record(string $text): array
{
    $record = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    assert_true(is_array($record));
    return $record;
}

test('prompt injection payload reaches stdin only and remains byte exact', function (): void {
    with_temp_dir('harness-injection-', function (string $dir): void {
        $binary = $dir . '/fake-harness';
        assert_true(copy(harness_cli_fixture('claude-envelope.sh'), $binary));
        assert_true(chmod($binary, 0755));
        $payload = '"; rm -rf ~; echo "';
        $record = harness_cli_record((new ClaudeCliLlm('m', $binary))->complete($payload));
        assert_eq($payload, $record['stdin']);
        assert_eq(0, substr_count(implode("\0", $record['argv']), $payload));
        assert_true(!file_exists($binary . '.canary'));
    });
});

test('three cached_prefix layers reach completeBatch transport in order', function (): void {
    $result = (new ClaudeCliLlm('m', harness_cli_fixture('claude-envelope.sh')))->completeBatch([
        'layered' => [
            'prompt' => 'TAIL',
            'cached_prefixes' => ["ONE\n", "TWO\n", "THREE\n"],
        ],
    ]);
    $record = harness_cli_record($result->texts['layered']);
    assert_eq("ONE\nTWO\nTHREE\nTAIL", $record['stdin']);
});

test('malformed cached_prefixes are refused before transport', function (): void {
    with_temp_dir('harness-conformance-', function (string $dir): void {
        $binary = $dir . '/fake-harness';
        assert_true(copy(harness_cli_fixture('spawn-counter.sh'), $binary));
        assert_true(chmod($binary, 0755));
        $llm = new ClaudeCliLlm('m', $binary);
        $error = assert_throws(fn () => $llm->complete('prompt', ['cached_prefixes' => ['valid', 42]]));
        assert_true($error instanceof \Automattic\SiteBuild\LlmRequestRejected);
        assert_true(!file_exists($binary . '.count'), 'malformed prefix spawned a subprocess');
    });
});

test('ClaudeCliLlm structural conformance passes both checks with zero subprocess spawns', function (): void {
    with_temp_dir('harness-structural-', function (string $dir): void {
        $binary = $dir . '/fake-harness';
        assert_true(copy(harness_cli_fixture('spawn-counter.sh'), $binary));
        assert_true(chmod($binary, 0755));
        $llm = new ClaudeCliLlm('m', $binary);
        $findings = LlmConformance::structural($llm);
        assert_eq(2, count($findings));
        assert_true(LlmConformance::passed($findings));
        assert_eq(0, $llm->usageTotals()['requests']);
        assert_true(!file_exists($binary . '.count'), 'structural tier spawned a subprocess');
    });
});

test('batch process failure degrades one member and preserves its sibling', function (): void {
    $good = harness_cli_fixture('claude-envelope.sh');
    $bad = harness_cli_fixture('fail.sh');
    $llm = new class ('m', $good) extends \Automattic\SiteBuild\HarnessCliLlm {
        public function __construct(string $model, string $binary)
        {
            parent::__construct($binary, $model, 2, 10);
        }

        protected function argvFor(array $request, string $model): array
        {
            return [(string) ($request['binary_override'] ?? $this->binary)];
        }

        protected function parseResponse(string $stdout, string $stderr, int $exit): array
        {
            $envelope = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
            return [
                'text' => (string) ($envelope['result'] ?? ''),
                'stop_reason' => $envelope['stop_reason'] ?? null,
                'usage' => $envelope['usage'] ?? [],
            ];
        }
    };

    $result = $llm->completeBatch([
        'ok' => ['prompt' => 'survivor'],
        'broken' => ['prompt' => 'doomed', 'binary_override' => $bad],
    ]);
    assert_eq('survivor', harness_cli_record($result->texts['ok'])['stdin']);
    assert_eq('', $result->texts['broken']);
    assert_contains('exit 7', implode("\n", $result->notesFor('broken')));
    assert_eq([], $result->notesFor('ok'));
});

test('single process failure still raises HarnessCallFailed', function (): void {
    $error = assert_throws(
        fn () => (new ClaudeCliLlm('m', harness_cli_fixture('fail.sh')))->complete('prompt')
    );
    assert_true($error instanceof HarnessCallFailed);
});

test('blank cached prefix layers are dropped without separators', function (): void {
    $text = (new ClaudeCliLlm('m', harness_cli_fixture('claude-envelope.sh')))->complete('TAIL', [
        'cached_prefixes' => ['', " \n", 'HEAD'],
    ]);
    assert_eq('HEADTAIL', harness_cli_record($text)['stdin']);
});

test('empty batches return empty keyed results without spawning', function (): void {
    $llm = new ClaudeCliLlm('m', harness_cli_fixture('spawn-counter.sh'));
    assert_eq([], $llm->completeJsonBatch([]));
    assert_eq([], $llm->completeBatch([])->texts);
    assert_eq(0, $llm->usageTotals()['requests']);
});

test('completeBatch throws HarnessCallFailed when every member has a missing binary', function (): void {
    $binary = '/nonexistent/definitely-not-claude';
    $error = assert_throws(fn () => (new ClaudeCliLlm('m', $binary))->completeBatch([
        'first' => ['prompt' => 'one'],
        'second' => ['prompt' => 'two'],
    ]));

    assert_true($error instanceof HarnessCallFailed);
    assert_contains($binary, $error->getMessage());
    assert_contains('executable not found or not executable', $error->getMessage());
});

test('completeBatch throws HarnessCallFailed when every member exits non-zero', function (): void {
    $binary = harness_cli_fixture('fail.sh');
    $error = assert_throws(fn () => (new ClaudeCliLlm('m', $binary))->completeBatch([
        'first' => ['prompt' => 'one'],
        'second' => ['prompt' => 'two'],
    ]));

    assert_true($error instanceof HarnessCallFailed);
    assert_contains($binary, $error->getMessage());
    assert_contains('exit 7', $error->getMessage());
    assert_contains('diagnostic detail', $error->getMessage());
});

test('completeBatch throws HarnessCallFailed when every member returns an error envelope', function (): void {
    $binary = harness_cli_fixture('claude-error-envelope.sh');
    $error = assert_throws(fn () => (new ClaudeCliLlm('m', $binary))->completeBatch([
        'first' => ['prompt' => 'one'],
        'second' => ['prompt' => 'two'],
    ]));

    assert_true($error instanceof HarnessCallFailed);
    assert_contains($binary, $error->getMessage());
    assert_contains('is_error', $error->getMessage());
    assert_contains('diagnostic from error envelope', $error->getMessage());
});
