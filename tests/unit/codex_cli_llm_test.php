<?php
declare(strict_types=1);

use Automattic\SiteBuild\CodexCliLlm;
use Automattic\SiteBuild\HarnessCallFailed;
use Automattic\SiteBuild\HarnessCliLlm;
use Automattic\SiteBuild\LlmConformance;
use Automattic\SiteBuild\Narrator;

function codex_cli_fixture(string $name = 'codex-harness.sh'): string
{
    return dirname(__DIR__) . '/fixtures/fake-harness/' . $name;
}

/**
 * @param callable(string,string):void $callback receives copied binary and dedicated scratch root
 */
function codex_cli_environment(callable $callback, string $fixture = 'codex-harness.sh'): void
{
    with_temp_dir('codex-cli-', function (string $dir) use ($callback, $fixture): void {
        $binary = $dir . '/fake-codex';
        $scratch = $dir . '/scratch';
        assert_true(copy(codex_cli_fixture($fixture), $binary), 'could not copy fake Codex binary');
        assert_true(chmod($binary, 0755), 'could not make fake Codex binary executable');
        assert_true(mkdir($scratch, 0775), 'could not create dedicated harness scratch root');

        $previousTmpdir = getenv('TMPDIR');
        putenv("TMPDIR={$scratch}");
        try {
            $callback($binary, $scratch);
        } finally {
            $previousTmpdir === false ? putenv('TMPDIR') : putenv("TMPDIR={$previousTmpdir}");
        }
    });
}

/** @return list<array{argv:list<string>,stdin:string,output_path:?string,schema_path:?string,schema_bytes:mixed}> */
function codex_cli_calls(string $binary): array
{
    $path = $binary . '.calls.jsonl';
    if (!is_file($path)) {
        return [];
    }
    $raw = trim((string) file_get_contents($path));
    if ($raw === '') {
        return [];
    }

    $calls = [];
    foreach (preg_split('/\R/', $raw) ?: [] as $line) {
        $call = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        assert_true(is_array($call), 'fake Codex call record must be an object');
        $calls[] = $call;
    }
    return $calls;
}

/** @param list<array<string,mixed>> $calls @return array<string,mixed> */
function codex_cli_call_for_stdin(array $calls, string $stdin): array
{
    $matches = array_values(array_filter(
        $calls,
        static fn (array $call): bool => ($call['stdin'] ?? null) === $stdin,
    ));
    assert_eq(1, count($matches), "expected exactly one fake Codex call for {$stdin}");
    return $matches[0];
}

/** @param array{argv:list<string>} $call */
function codex_cli_assert_model(array $call, string $expected): void
{
    $indexes = [];
    foreach ($call['argv'] as $index => $arg) {
        if ($arg === '-m') {
            $indexes[] = $index;
        }
    }
    assert_eq(1, count($indexes), 'Codex argv must contain exactly one -m');
    assert_eq($expected, $call['argv'][$indexes[0] + 1] ?? null, 'Codex -m value');
}

/** @return list<string> */
function codex_cli_scratch_entries(string $scratch): array
{
    return array_values(array_diff(scandir($scratch) ?: [], ['.', '..']));
}

/** @return array{name:string,schema:array<string,mixed>} */
function codex_cli_schema(): array
{
    return [
        'name' => 'codex_unit_record',
        'schema' => [
            'type' => 'object',
            'properties' => ['ok' => ['type' => 'boolean']],
            'required' => ['ok'],
            'additionalProperties' => false,
        ],
    ];
}

/** Run one assertion with this option absent from process-wide disclosure history. */
function codex_cli_with_fresh_disclosure(string $option, callable $callback): mixed
{
    $reflection = new ReflectionClass(HarnessCliLlm::class);
    $property = $reflection->getProperty('disclosedUnsupportedOptions');
    $property->setAccessible(true);
    $original = $property->getValue();
    assert_true(is_array($original));
    $fresh = $original;
    unset($fresh[$option]);
    $property->setValue(null, $fresh);
    try {
        return $callback();
    } finally {
        $property->setValue(null, $original);
    }
}

test('W19 Codex discloses non-blank system once and never transports its bytes', function (): void {
    codex_cli_with_fresh_disclosure('system', function (): void {
        codex_cli_environment(function (string $binary): void {
            $stream = fopen('php://memory', 'w+');
            assert_true(is_resource($stream));
            Narrator::setStream($stream);
            try {
                $system = 'CODEX_SYSTEM_SECRET_47';
                for ($index = 1; $index <= 5; $index++) {
                    $prompt = "codex-system-prompt-{$index}";
                    $result = (new CodexCliLlm('m', $binary))->completeBatch([
                        'job' => ['prompt' => $prompt, 'system' => $system],
                    ]);
                    assert_eq($prompt, $result->texts['job']);
                    assert_contains('system', implode("\n", $result->notesFor('job')));
                }

                $calls = codex_cli_calls($binary);
                assert_eq(5, count($calls));
                foreach ($calls as $call) {
                    assert_true(!str_contains(implode("\0", $call['argv']), $system));
                    assert_true(!str_contains($call['stdin'], $system));
                }
                rewind($stream);
                $narration = stream_get_contents($stream);
                assert_true(is_string($narration));
                assert_eq(1, substr_count($narration, 'system'));
                assert_true(!str_contains($narration, $system));
            } finally {
                Narrator::reset();
                fclose($stream);
            }
        });
    });
});

test('W19 Codex leaves blank system undisclosed and out of transport bytes', function (): void {
    codex_cli_with_fresh_disclosure('system', function (): void {
        codex_cli_environment(function (string $binary): void {
            $stream = fopen('php://memory', 'w+');
            assert_true(is_resource($stream));
            Narrator::setStream($stream);
            try {
                $result = (new CodexCliLlm('m', $binary))->completeBatch([
                    'job' => ['prompt' => 'codex-blank-system', 'system' => " \t\n"],
                ]);
                assert_eq([], $result->notesFor('job'));
                $call = codex_cli_calls($binary)[0];
                assert_eq('codex-blank-system', $call['stdin']);
                rewind($stream);
                assert_true(!str_contains((string) stream_get_contents($stream), 'system'));
            } finally {
                Narrator::reset();
                fclose($stream);
            }
        });
    });
});

test('Codex complete pins exactly one default or overridden model', function (): void {
    codex_cli_environment(function (string $binary): void {
        $llm = new CodexCliLlm('default-complete', $binary);
        assert_eq('complete-default', $llm->complete('complete-default'));
        assert_eq('complete-override', $llm->complete('complete-override', ['model' => 'override-complete']));
        $calls = codex_cli_calls($binary);
        codex_cli_assert_model(codex_cli_call_for_stdin($calls, 'complete-default'), 'default-complete');
        codex_cli_assert_model(codex_cli_call_for_stdin($calls, 'complete-override'), 'override-complete');
    });
});

test('Codex completeJson pins exactly one default or overridden model', function (): void {
    codex_cli_environment(function (string $binary): void {
        $llm = new CodexCliLlm('default-json', $binary);
        assert_eq(['call' => 'default'], $llm->completeJson('{"call":"default"}'));
        assert_eq(
            ['call' => 'override'],
            $llm->completeJson('{"call":"override"}', ['model' => 'override-json']),
        );
        $calls = codex_cli_calls($binary);
        codex_cli_assert_model(codex_cli_call_for_stdin($calls, '{"call":"default"}'), 'default-json');
        codex_cli_assert_model(codex_cli_call_for_stdin($calls, '{"call":"override"}'), 'override-json');
    });
});

test('Codex completeJsonBatch pins exactly one default or overridden model per member', function (): void {
    codex_cli_environment(function (string $binary): void {
        $llm = new CodexCliLlm('default-json-batch', $binary, 2);
        $result = $llm->completeJsonBatch([
            'default' => ['prompt' => '{"member":"default"}'],
            'override' => ['prompt' => '{"member":"override"}', 'model' => 'override-json-batch'],
        ]);
        assert_eq(['member' => 'default'], $result['default']);
        assert_eq(['member' => 'override'], $result['override']);
        $calls = codex_cli_calls($binary);
        codex_cli_assert_model(codex_cli_call_for_stdin($calls, '{"member":"default"}'), 'default-json-batch');
        codex_cli_assert_model(codex_cli_call_for_stdin($calls, '{"member":"override"}'), 'override-json-batch');
    });
});

test('Codex completeBatch pins exactly one default or overridden model per member', function (): void {
    codex_cli_environment(function (string $binary): void {
        $llm = new CodexCliLlm('default-text-batch', $binary, 2);
        $result = $llm->completeBatch([
            'default' => ['prompt' => 'text-default'],
            'override' => ['prompt' => 'text-override', 'model' => 'override-text-batch'],
        ]);
        assert_eq('text-default', $result->texts['default']);
        assert_eq('text-override', $result->texts['override']);
        $calls = codex_cli_calls($binary);
        codex_cli_assert_model(codex_cli_call_for_stdin($calls, 'text-default'), 'default-text-batch');
        codex_cli_assert_model(codex_cli_call_for_stdin($calls, 'text-override'), 'override-text-batch');
    });
});

test('Codex keeps the injection payload byte exact on stdin and out of argv', function (): void {
    codex_cli_environment(function (string $binary): void {
        $payload = '"; rm -rf ~; echo "';
        assert_eq($payload, (new CodexCliLlm('m', $binary))->complete($payload));
        $call = codex_cli_call_for_stdin(codex_cli_calls($binary), $payload);
        assert_eq($payload, $call['stdin']);
        assert_eq(0, substr_count(implode("\0", $call['argv']), $payload));
        assert_true(!file_exists($binary . '.canary'), 'fake fixture saw the prompt in argv');
    });
});

test('Codex removes output and schema scratch files after success', function (): void {
    codex_cli_environment(function (string $binary, string $scratch): void {
        assert_eq([], codex_cli_scratch_entries($scratch));
        $result = (new CodexCliLlm('m', $binary))->completeJson('{"ok":true}', [
            'json_schema' => codex_cli_schema(),
        ]);
        assert_eq(['ok' => true], $result);
        assert_eq([], codex_cli_scratch_entries($scratch));
        $call = codex_cli_calls($binary)[0];
        assert_true(!file_exists((string) $call['output_path']));
        assert_true(!file_exists((string) $call['schema_path']));
    });
});

test('Codex removes output and schema scratch files after non-zero exit', function (): void {
    codex_cli_environment(function (string $binary, string $scratch): void {
        $error = assert_throws(fn () => (new CodexCliLlm('m', $binary))->completeJson('{"ok":true}', [
            'json_schema' => codex_cli_schema(),
        ]));
        assert_true($error instanceof HarnessCallFailed);
        assert_contains('exit 7', $error->getMessage());
        assert_contains('fake codex diagnostic', $error->getMessage());
        assert_eq([], codex_cli_scratch_entries($scratch));
    }, 'codex-nonzero.sh');
});

test('Codex removes output and schema scratch files after parse HarnessCallFailed', function (): void {
    codex_cli_environment(function (string $binary, string $scratch): void {
        $error = assert_throws(fn () => (new CodexCliLlm('m', $binary))->completeJson('{"ok":true}', [
            'json_schema' => codex_cli_schema(),
        ]));
        assert_true($error instanceof HarnessCallFailed);
        assert_contains('JSON', $error->getMessage());
        assert_eq([], codex_cli_scratch_entries($scratch));
    }, 'codex-malformed.sh');
});

test('Codex uses eight unique output paths without batch cross-talk', function (): void {
    codex_cli_environment(function (string $binary, string $scratch): void {
        $requests = [];
        foreach (range(1, 8) as $index) {
            $requests["member-{$index}"] = ['prompt' => "answer-{$index}"];
        }
        $result = (new CodexCliLlm('m', $binary, 8))->completeBatch($requests);
        foreach (range(1, 8) as $index) {
            assert_eq("answer-{$index}", $result->texts["member-{$index}"]);
        }

        $calls = codex_cli_calls($binary);
        assert_eq(8, count($calls));
        $paths = array_column($calls, 'output_path');
        assert_eq(8, count(array_unique($paths)), 'concurrent calls reused an output path');
        foreach ($paths as $path) {
            assert_true(is_string($path) && str_starts_with($path, $scratch . DIRECTORY_SEPARATOR));
            assert_true(!file_exists($path), "Codex output survived cleanup: {$path}");
        }
        assert_eq([], codex_cli_scratch_entries($scratch));
    });
});

test('Codex parses usage only from turn.completed without double counting cached input', function (): void {
    codex_cli_environment(function (string $binary): void {
        $llm = new CodexCliLlm('m', $binary);
        $llm->complete('usage-probe');
        $usage = $llm->usageTotals();

        // Codex reports cached_input_tokens as a subset of raw input_tokens.
        // Raw input already includes those 11,008 cached tokens; adding again would double bill them.
        assert_eq(1, $usage['requests']);
        assert_eq(17357, $usage['input_tokens']);
        assert_eq(11008, $usage['cache_read_input_tokens']);
        assert_eq(0, $usage['cache_creation_input_tokens']);
        assert_eq(5, $usage['output_tokens']);
        assert_eq(17362, $usage['total_tokens']);
    });
});

test('Codex final output file wins when item.completed text disagrees', function (): void {
    codex_cli_environment(function (string $binary): void {
        $answer = (new CodexCliLlm('m', $binary))->complete('FILE_ANSWER_WINS');
        assert_eq('FILE_ANSWER_WINS', $answer);
        assert_true($answer !== 'EVENT_STREAM_MUST_NOT_WIN');
    });
});

test('Codex structural conformance passes both checks with zero spawns or scratch', function (): void {
    codex_cli_environment(function (string $binary, string $scratch): void {
        assert_true(copy(codex_cli_fixture('spawn-counter.sh'), $binary));
        assert_true(chmod($binary, 0755));
        $llm = new CodexCliLlm('m', $binary);
        $findings = LlmConformance::structural($llm);
        assert_eq(2, count($findings));
        assert_true(LlmConformance::passed($findings));
        assert_eq(0, $llm->usageTotals()['requests']);
        assert_true(!file_exists($binary . '.count'), 'structural tier spawned fake Codex');
        assert_eq([], codex_cli_scratch_entries($scratch));
    });
});

test('Codex writes exact JSON schema bytes to a unique --output-schema file then cleans it', function (): void {
    codex_cli_environment(function (string $binary, string $scratch): void {
        $schema = codex_cli_schema();
        (new CodexCliLlm('m', $binary))->completeJson('{"ok":true}', ['json_schema' => $schema]);
        $call = codex_cli_calls($binary)[0];
        $schemaIndexes = [];
        foreach ($call['argv'] as $index => $arg) {
            if ($arg === '--output-schema') {
                $schemaIndexes[] = $index;
            }
        }
        assert_eq(1, count($schemaIndexes));
        assert_eq($call['schema_path'], $call['argv'][$schemaIndexes[0] + 1] ?? null);
        assert_eq(
            json_encode($schema['schema'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $call['schema_bytes'],
        );
        assert_true(is_string($call['schema_path']) && str_starts_with($call['schema_path'], $scratch));
        assert_true($call['schema_path'] !== $call['output_path']);
        assert_true(!file_exists($call['schema_path']));
        assert_true(!file_exists((string) $call['output_path']));
        assert_eq([], codex_cli_scratch_entries($scratch));
    });
});

test('Codex rejects JSONL without a turn.completed event', function (): void {
    codex_cli_environment(function (string $binary, string $scratch): void {
        $error = assert_throws(fn () => (new CodexCliLlm('m', $binary))->complete('prompt'));
        assert_true($error instanceof HarnessCallFailed);
        assert_contains('turn.completed', $error->getMessage());
        assert_eq([], codex_cli_scratch_entries($scratch));
    }, 'codex-missing-event.sh');
});

test('Codex rejects a missing final output file', function (): void {
    codex_cli_environment(function (string $binary, string $scratch): void {
        $error = assert_throws(fn () => (new CodexCliLlm('m', $binary))->complete('prompt'));
        assert_true($error instanceof HarnessCallFailed);
        assert_contains('output', strtolower($error->getMessage()));
        assert_eq([], codex_cli_scratch_entries($scratch));
    }, 'codex-missing-output.sh');
});

test('Codex pins reasoning effort instead of inheriting the CLI default', function (): void {
    codex_cli_environment(function (string $binary): void {
        (new CodexCliLlm('m', $binary))->complete('effort-probe');
        $argv = codex_cli_calls($binary)[0]['argv'];
        $indexes = array_keys($argv, '-c', true);
        assert_eq(1, count($indexes), 'Codex argv must contain exactly one -c');
        // The value is parsed as TOML, so the level stays quoted. "none" is a
        // real enum member, not a fallthrough to the CLI default.
        assert_eq(
            'model_reasoning_effort="none"',
            $argv[$indexes[0] + 1] ?? null
        );
        assert_true(HarnessCliLlm::THINKING_OFF, 'thinking is off on the harness path');
        assert_eq('low', HarnessCliLlm::REASONING_EFFORT);
    });
});

test('Codex argv matches the measured exec invocation and carries no prompt', function (): void {
    codex_cli_environment(function (string $binary): void {
        (new CodexCliLlm('m', $binary))->complete('argv-probe');
        $call = codex_cli_calls($binary)[0];
        assert_eq('exec', $call['argv'][0] ?? null);
        foreach (['--ignore-user-config', '--skip-git-repo-check', '--json', '-o', '-m'] as $flag) {
            assert_true(in_array($flag, $call['argv'], true), "missing Codex flag {$flag}");
        }
        assert_eq(0, substr_count(implode("\0", $call['argv']), 'argv-probe'));
    });
});
