<?php
declare(strict_types=1);

use Automattic\SiteBuild\ClaudeCliLlm;
use Automattic\SiteBuild\GeneratedJsonException;
use Automattic\SiteBuild\HarnessCallFailed;
use Automattic\SiteBuild\HarnessCliLlm;
use Automattic\SiteBuild\Narrator;

function claude_cli_fixture(string $name = 'claude-envelope.sh'): string
{
    return dirname(__DIR__) . '/fixtures/fake-harness/' . $name;
}

function claude_cli_llm(string $model = 'claude-haiku-4-5', string $fixture = 'claude-envelope.sh'): ClaudeCliLlm
{
    return new ClaudeCliLlm($model, claude_cli_fixture($fixture));
}

/** @return array{argv:list<string>,stdin:string,anthropic_api_key:mixed,anthropic_auth_token:mixed} */
function claude_cli_record(string $text): array
{
    $record = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    assert_true(is_array($record), 'fake Claude result must decode to a record');
    return $record;
}

function claude_cli_pinned_model(array $record): string
{
    $index = array_search('--model', $record['argv'], true);
    assert_true($index !== false, '--model missing from argv');
    assert_true(isset($record['argv'][$index + 1]), '--model has no value');
    return $record['argv'][$index + 1];
}

test('unsupported temperature and max_tokens are disclosed once and returned as degradation notes', function (): void {
    $stream = fopen('php://memory', 'w+');
    assert_true(is_resource($stream));
    Narrator::setStream($stream);
    try {
        $opts = ['temperature' => 0.4, 'max_tokens' => 321];
        claude_cli_llm()->complete('one', $opts);
        claude_cli_llm()->completeJson('two', $opts);
        claude_cli_llm()->completeJsonBatch(['three' => ['prompt' => 'three'] + $opts]);
        $first = claude_cli_llm()->completeBatch(['four' => ['prompt' => 'four'] + $opts]);
        $second = claude_cli_llm()->completeBatch(['five' => ['prompt' => 'five'] + $opts]);

        rewind($stream);
        $narration = stream_get_contents($stream);
        assert_true(is_string($narration));
        assert_eq(1, substr_count($narration, 'temperature'), 'temperature narration must be process-once');
        assert_eq(1, substr_count($narration, 'max_tokens'), 'max_tokens narration must be process-once');
        assert_contains('temperature', implode("\n", $first->notesFor('four')));
        assert_contains('max_tokens', implode("\n", $first->notesFor('four')));
        assert_contains('temperature', implode("\n", $second->notesFor('five')));
        assert_contains('max_tokens', implode("\n", $second->notesFor('five')));
    } finally {
        Narrator::reset();
        fclose($stream);
    }
});

test('complete pins the injected default model', function (): void {
    $record = claude_cli_record(claude_cli_llm('default-complete')->complete('prompt'));
    assert_eq('default-complete', claude_cli_pinned_model($record));
});

test('completeJson pins the per-request model override', function (): void {
    $record = claude_cli_llm('default-json')->completeJson('prompt', ['model' => 'override-json']);
    assert_eq('override-json', claude_cli_pinned_model($record));
});

test('completeJsonBatch pins the injected default model', function (): void {
    $records = claude_cli_llm('default-json-batch')->completeJsonBatch([
        'json' => ['prompt' => 'prompt'],
    ]);
    assert_eq('default-json-batch', claude_cli_pinned_model($records['json']));
});

test('completeBatch pins each per-request model override', function (): void {
    $result = claude_cli_llm('default-text-batch')->completeBatch([
        'text' => ['prompt' => 'prompt', 'model' => 'override-text-batch'],
    ]);
    $record = claude_cli_record($result->texts['text']);
    assert_eq('override-text-batch', claude_cli_pinned_model($record));
});

test('C-G15/C-G17 Claude allows two turns with all tools disabled and keeps optional inputs', function (): void {
    $record = claude_cli_record(claude_cli_llm()->complete('prompt', [
        'system' => 'system text',
        'json_schema' => ['name' => 'record', 'schema' => ['type' => 'object']],
    ]));
    assert_true(in_array('-p', $record['argv'], true));
    assert_true(in_array('--safe-mode', $record['argv'], true));
    assert_true(in_array('--output-format', $record['argv'], true));
    assert_true(!in_array('--bare', $record['argv'], true));
    $turns = array_search('--max-turns', $record['argv'], true);
    assert_true($turns !== false);
    assert_eq('2', $record['argv'][$turns + 1] ?? null);
    $tools = array_search('--tools', $record['argv'], true);
    assert_true($tools !== false);
    assert_true(array_key_exists($tools + 1, $record['argv']));
    assert_eq('', $record['argv'][$tools + 1]);
    $system = array_search('--system-prompt', $record['argv'], true);
    assert_true($system !== false);
    assert_eq('system text', $record['argv'][$system + 1]);
    $schema = array_search('--json-schema', $record['argv'], true);
    assert_true($schema !== false);
    assert_eq(['type' => 'object'], json_decode($record['argv'][$schema + 1], true, 512, JSON_THROW_ON_ERROR));
});

test('Claude pins reasoning effort instead of inheriting the developer settings', function (): void {
    $record = claude_cli_record(claude_cli_llm()->complete('prompt'));
    $indexes = array_keys($record['argv'], '--effort', true);
    assert_eq(1, count($indexes), 'Claude argv must contain exactly one --effort');
    assert_eq(HarnessCliLlm::REASONING_EFFORT, $record['argv'][$indexes[0] + 1] ?? null);
    assert_eq('low', HarnessCliLlm::REASONING_EFFORT);
});

test('Claude disables thinking through the env lever --effort cannot reach', function (): void {
    $record = claude_cli_record(claude_cli_llm()->complete('prompt'));
    $indexes = array_keys($record['argv'], '--settings', true);
    assert_eq(1, count($indexes), 'Claude argv must contain exactly one --settings');
    assert_eq(
        ['env' => ['MAX_THINKING_TOKENS' => '0']],
        json_decode($record['argv'][$indexes[0] + 1] ?? '', true, 512, JSON_THROW_ON_ERROR),
    );
    assert_true(HarnessCliLlm::THINKING_OFF, 'thinking is off on the harness path');
});

test('W20 Claude honours system exactly without an unsupported-option disclosure', function (): void {
    $stream = fopen('php://memory', 'w+');
    assert_true(is_resource($stream));
    Narrator::setStream($stream);
    try {
        $system = 'CLAUDE_SYSTEM_EXACT_20';
        $result = claude_cli_llm()->completeBatch([
            'job' => ['prompt' => 'claude-system-prompt', 'system' => $system],
        ]);
        $record = claude_cli_record($result->texts['job']);
        $indexes = array_keys($record['argv'], '--system-prompt', true);
        assert_eq(1, count($indexes));
        assert_eq($system, $record['argv'][$indexes[0] + 1] ?? null);
        assert_eq('claude-system-prompt', $record['stdin']);
        assert_eq([], $result->notesFor('job'));
        rewind($stream);
        assert_true(!str_contains((string) stream_get_contents($stream), 'system'));
    } finally {
        Narrator::reset();
        fclose($stream);
    }
});

test('Claude usage includes cache creation and reads in billed input', function (): void {
    $llm = claude_cli_llm();
    $llm->complete('one');
    $llm->complete('two');
    $usage = $llm->usageTotals();
    assert_eq(2, $usage['requests']);
    assert_eq(42, $usage['input_tokens']);
    assert_eq(10, $usage['output_tokens']);
    assert_eq(6, $usage['cache_creation_input_tokens']);
    assert_eq(14, $usage['cache_read_input_tokens']);
    assert_eq(52, $usage['total_tokens']);
});

test('Claude child environment excludes API credentials', function (): void {
    putenv('ANTHROPIC_API_KEY=must-not-leak');
    putenv('ANTHROPIC_AUTH_TOKEN=must-not-leak');
    try {
        $record = claude_cli_record(claude_cli_llm()->complete('prompt'));
        assert_eq(false, $record['anthropic_api_key']);
        assert_eq(false, $record['anthropic_auth_token']);
    } finally {
        putenv('ANTHROPIC_API_KEY');
        putenv('ANTHROPIC_AUTH_TOKEN');
    }
});

test('Claude non-zero exit carries binary exit code and captured stderr', function (): void {
    $binary = claude_cli_fixture('fail.sh');
    $error = assert_throws(fn () => (new ClaudeCliLlm('m', $binary))->complete('prompt'));
    assert_true($error instanceof HarnessCallFailed);
    assert_contains($binary, $error->getMessage());
    assert_contains('exit 7', $error->getMessage());
    assert_contains('diagnostic detail', $error->getMessage());
});

test('C-G16 non-zero exit carries a diagnostic written only to stdout', function (): void {
    with_temp_dir('claude-stdout-error-', function (string $dir): void {
        $binary = $dir . '/claude';
        $script = '#!' . PHP_BINARY . "\n" . <<<'PHP'
<?php
fwrite(STDOUT, '{"subtype":"error_max_turns","marker":"STDOUT_ONLY_DIAGNOSTIC"}');
exit(9);
PHP;
        assert_true(file_put_contents($binary, $script) !== false);
        assert_true(chmod($binary, 0755));

        $error = assert_throws(fn () => (new ClaudeCliLlm('m', $binary))->complete('prompt'));
        assert_true($error instanceof HarnessCallFailed);
        assert_contains('STDOUT_ONLY_DIAGNOSTIC', $error->getMessage());
        assert_contains('error_max_turns', $error->getMessage());
    });
});

test('Claude is_error envelope raises HarnessCallFailed with stderr', function (): void {
    $error = assert_throws(fn () => claude_cli_llm('m', 'claude-error-envelope.sh')->complete('prompt'));
    assert_true($error instanceof HarnessCallFailed);
    assert_contains('is_error', $error->getMessage());
    assert_contains('diagnostic from error envelope', $error->getMessage());
});

test('Claude unparseable stdout raises HarnessCallFailed', function (): void {
    $error = assert_throws(fn () => claude_cli_llm('m', 'echo-stdin.sh')->complete('not-json'));
    assert_true($error instanceof HarnessCallFailed);
    assert_contains('JSON', $error->getMessage());
});

test('T14 completeJsonBatch repairs only malformed JSON and retains its sibling', function (): void {
    with_temp_dir('harness-json-repair-', function (string $dir): void {
        $binary = $dir . '/fake-harness';
        assert_true(copy(claude_cli_fixture('claude-json-recovery.sh'), $binary));
        assert_true(chmod($binary, 0755));
        $llm = new ClaudeCliLlm('m', $binary, 2);

        $result = $llm->completeJsonBatch([
            'bad' => ['prompt' => 'T14_REPAIR'],
            'good' => ['prompt' => 'T14_GOOD'],
        ]);

        assert_eq(['repaired' => true], $result['bad']);
        assert_eq(['good' => true], $result['good']);
        assert_eq('2', file_get_contents($binary . '.repair-count'));
        assert_eq(3, $llm->usageTotals()['requests']);
    });
});

test('T14 completeJsonBatch exposes successful siblings after bounded content repair', function (): void {
    with_temp_dir('harness-json-partial-', function (string $dir): void {
        $binary = $dir . '/fake-harness';
        assert_true(copy(claude_cli_fixture('claude-json-recovery.sh'), $binary));
        assert_true(chmod($binary, 0755));
        $llm = new ClaudeCliLlm('m', $binary, 2);

        $error = assert_throws(fn () => $llm->completeJsonBatch([
            'bad' => ['prompt' => 'T14_ALWAYS_BAD'],
            'good' => ['prompt' => 'T14_GOOD'],
        ]));

        assert_true($error instanceof GeneratedJsonException);
        assert_eq(['good' => ['good' => true]], $error->partialResults);
        assert_true(array_key_exists('bad', $error->failures));
        assert_eq('2', file_get_contents($binary . '.persistent-count'));
        assert_eq(3, $llm->usageTotals()['requests']);
    });
});

test('T14 completeBatch does not retry truncated text and returns a degradation note', function (): void {
    $llm = claude_cli_llm('m', 'claude-truncated.sh');
    $result = $llm->completeBatch(['cut' => ['prompt' => 'partial', 'max_tokens' => 5]]);
    assert_eq('partial', $result->texts['cut']);
    assert_eq(1, $llm->usageTotals()['requests'], 'zero retries means one process');
    assert_contains('truncated', implode("\n", $result->notesFor('cut')));
});
