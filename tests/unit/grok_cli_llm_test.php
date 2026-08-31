<?php
declare(strict_types=1);

use Automattic\SiteBuild\GrokCliLlm;
use Automattic\SiteBuild\HarnessCallFailed;
use Automattic\SiteBuild\HarnessCliLlm;
use Automattic\SiteBuild\LlmConformance;
use Automattic\SiteBuild\Narrator;

function grok_cli_fixture(string $name): string
{
    return dirname(__DIR__) . '/fixtures/fake-harness/' . $name;
}

/**
 * Run against a copied fake binary and a dedicated scratch root.
 *
 * @param callable(string,string):void $callback copied binary, scratch root
 */
function with_grok_cli_fixture(string $fixture, callable $callback): void
{
    with_temp_dir('grok-cli-', function (string $dir) use ($fixture, $callback): void {
        $binary = $dir . '/grok';
        $fixtureRuntime = $dir . '/grok-fixture.php';
        $scratchRoot = $dir . '/scratch';
        assert_true(copy(grok_cli_fixture($fixture), $binary));
        assert_true(copy(grok_cli_fixture('grok-fixture.php'), $fixtureRuntime));
        assert_true(chmod($binary, 0755));
        assert_true(mkdir($scratchRoot, 0700));

        $previousTmpdir = getenv('TMPDIR');
        putenv('TMPDIR=' . $scratchRoot);
        try {
            $callback($binary, $scratchRoot);
        } finally {
            if ($previousTmpdir === false) {
                putenv('TMPDIR');
            } else {
                putenv('TMPDIR=' . $previousTmpdir);
            }
        }
    });
}

/** @return list<array<string,mixed>> */
function grok_cli_records(string $binary): array
{
    $path = $binary . '.records.jsonl';
    if (!is_file($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    assert_true(is_array($lines), 'fake Grok record log must be readable');
    $records = [];
    foreach ($lines as $line) {
        $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        assert_true(is_array($record), 'fake Grok record line must decode to an object');
        $records[] = $record;
    }
    return $records;
}

/** @return array<string,array<string,mixed>> prompt => record */
function grok_cli_records_by_prompt(string $binary): array
{
    $byPrompt = [];
    foreach (grok_cli_records($binary) as $record) {
        $prompt = $record['prompt'] ?? null;
        assert_true(is_string($prompt), 'fake Grok record has no prompt');
        $byPrompt[$prompt] = $record;
    }
    return $byPrompt;
}

/** @param array<string,mixed> $record */
function grok_cli_pinned_model(array $record): string
{
    $argv = $record['argv'] ?? null;
    assert_true(is_array($argv), 'fake Grok record has no argv');
    $modelIndexes = array_keys($argv, '-m', true);
    assert_eq(1, count($modelIndexes), 'Grok argv must contain exactly one -m');
    $index = $modelIndexes[0];
    assert_true(isset($argv[$index + 1]), 'Grok -m has no value');
    return (string) $argv[$index + 1];
}

/** @return list<string> */
function grok_cli_scratch_entries(string $scratchRoot): array
{
    $entries = scandir($scratchRoot);
    assert_true(is_array($entries), 'scratch root must be readable');
    return array_values(array_filter(
        $entries,
        static fn (string $entry): bool => $entry !== '.' && $entry !== '..',
    ));
}

/** Run one assertion with this option absent from process-wide disclosure history. */
function grok_cli_with_fresh_disclosure(string $option, callable $callback): mixed
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

test('W19 Grok discloses non-blank system once and never transports its bytes', function (): void {
    grok_cli_with_fresh_disclosure('system', function (): void {
        with_grok_cli_fixture('grok-envelope.sh', function (string $binary): void {
            $stream = fopen('php://memory', 'w+');
            assert_true(is_resource($stream));
            Narrator::setStream($stream);
            try {
                $system = 'GROK_SYSTEM_SECRET_48';
                for ($index = 1; $index <= 5; $index++) {
                    $prompt = "grok-system-prompt-{$index}";
                    $result = (new GrokCliLlm('grok-4.5', $binary))->completeBatch([
                        'job' => ['prompt' => $prompt, 'system' => $system],
                    ]);
                    assert_eq($prompt, $result->texts['job']);
                    assert_contains('system', implode("\n", $result->notesFor('job')));
                }

                $records = grok_cli_records($binary);
                assert_eq(5, count($records));
                foreach ($records as $record) {
                    assert_true(!str_contains(implode("\0", $record['argv']), $system));
                    assert_true(!str_contains($record['prompt'], $system));
                    assert_eq('', $record['stdin']);
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

test('W19 Grok leaves blank system undisclosed and out of transport bytes', function (): void {
    grok_cli_with_fresh_disclosure('system', function (): void {
        with_grok_cli_fixture('grok-envelope.sh', function (string $binary): void {
            $stream = fopen('php://memory', 'w+');
            assert_true(is_resource($stream));
            Narrator::setStream($stream);
            try {
                $result = (new GrokCliLlm('grok-4.5', $binary))->completeBatch([
                    'job' => ['prompt' => 'grok-blank-system', 'system' => " \t\n"],
                ]);
                assert_eq([], $result->notesFor('job'));
                $record = grok_cli_records($binary)[0];
                assert_eq('grok-blank-system', $record['prompt']);
                assert_eq('', $record['stdin']);
                rewind($stream);
                assert_true(!str_contains((string) stream_get_contents($stream), 'system'));
            } finally {
                Narrator::reset();
                fclose($stream);
            }
        });
    });
});

test('W5 Grok complete pins exactly one default and override model', function (): void {
    with_grok_cli_fixture('grok-envelope.sh', function (string $binary): void {
        $llm = new GrokCliLlm('grok-default', $binary);
        $defaultPrompt = 'complete-default';
        $overridePrompt = 'complete-override';
        assert_eq($defaultPrompt, $llm->complete($defaultPrompt));
        assert_eq($overridePrompt, $llm->complete($overridePrompt, ['model' => 'grok-override']));

        $records = grok_cli_records_by_prompt($binary);
        assert_eq('grok-default', grok_cli_pinned_model($records[$defaultPrompt]));
        assert_eq('grok-override', grok_cli_pinned_model($records[$overridePrompt]));
    });
});

test('W5 Grok completeJson pins exactly one default and override model', function (): void {
    with_grok_cli_fixture('grok-envelope.sh', function (string $binary): void {
        $llm = new GrokCliLlm('grok-default', $binary);
        $defaultPrompt = '{"call":"json-default"}';
        $overridePrompt = '{"call":"json-override"}';
        assert_eq(['call' => 'json-default'], $llm->completeJson($defaultPrompt));
        assert_eq(
            ['call' => 'json-override'],
            $llm->completeJson($overridePrompt, ['model' => 'grok-override'])
        );

        $records = grok_cli_records_by_prompt($binary);
        assert_eq('grok-default', grok_cli_pinned_model($records[$defaultPrompt]));
        assert_eq('grok-override', grok_cli_pinned_model($records[$overridePrompt]));
    });
});

test('W5 Grok completeJsonBatch pins exactly one default and override model', function (): void {
    with_grok_cli_fixture('grok-envelope.sh', function (string $binary): void {
        $llm = new GrokCliLlm('grok-default', $binary, 2);
        $defaultPrompt = '{"call":"json-batch-default"}';
        $overridePrompt = '{"call":"json-batch-override"}';
        $result = $llm->completeJsonBatch([
            'default' => ['prompt' => $defaultPrompt],
            'override' => ['prompt' => $overridePrompt, 'model' => 'grok-override'],
        ]);
        assert_eq(['call' => 'json-batch-default'], $result['default']);
        assert_eq(['call' => 'json-batch-override'], $result['override']);

        $records = grok_cli_records_by_prompt($binary);
        assert_eq('grok-default', grok_cli_pinned_model($records[$defaultPrompt]));
        assert_eq('grok-override', grok_cli_pinned_model($records[$overridePrompt]));
    });
});

test('W5 Grok completeBatch pins exactly one default and override model', function (): void {
    with_grok_cli_fixture('grok-envelope.sh', function (string $binary): void {
        $llm = new GrokCliLlm('grok-default', $binary, 2);
        $defaultPrompt = 'text-batch-default';
        $overridePrompt = 'text-batch-override';
        $result = $llm->completeBatch([
            'default' => ['prompt' => $defaultPrompt],
            'override' => ['prompt' => $overridePrompt, 'model' => 'grok-override'],
        ]);
        assert_eq($defaultPrompt, $result->texts['default']);
        assert_eq($overridePrompt, $result->texts['override']);

        $records = grok_cli_records_by_prompt($binary);
        assert_eq('grok-default', grok_cli_pinned_model($records[$defaultPrompt]));
        assert_eq('grok-override', grok_cli_pinned_model($records[$overridePrompt]));
    });
});

test('W6 Grok keeps injection prompt byte exact in prompt file and out of argv and stdin', function (): void {
    with_grok_cli_fixture('grok-envelope.sh', function (string $binary, string $scratchRoot): void {
        $canary = $scratchRoot . '/injection-canary';
        $payload = '"; touch ' . $canary . '; echo " $HOME `whoami`';
        assert_eq($payload, (new GrokCliLlm('grok-4.6', $binary))->complete($payload));

        $records = grok_cli_records($binary);
        assert_eq(1, count($records));
        $record = $records[0];
        assert_eq($payload, $record['prompt']);
        assert_eq('', $record['stdin']);
        assert_eq(0, substr_count(implode("\0", $record['argv']), $payload));
        $promptFileIndexes = array_keys($record['argv'], '--prompt-file', true);
        assert_eq(1, count($promptFileIndexes));
        assert_eq($record['prompt_path'], $record['argv'][$promptFileIndexes[0] + 1]);
        assert_true(!in_array('-p', $record['argv'], true), 'Grok -p would put prompt in argv');
        assert_true(!in_array('--single', $record['argv'], true), 'Grok --single would put prompt in argv');
        assert_true(!file_exists($canary), 'prompt executed instead of remaining inert data');
    });
});

test('Grok passes json_schema as exact inline JSON', function (): void {
    with_grok_cli_fixture('grok-envelope.sh', function (string $binary): void {
        $schema = [
            'type' => 'object',
            'properties' => ['url' => ['type' => 'string', 'const' => 'https://example.com/a/b']],
            'required' => ['url'],
        ];
        (new GrokCliLlm('grok-4.6', $binary))->completeJson('{"url":"https://example.com/a/b"}', [
            'json_schema' => ['name' => 'url_record', 'schema' => $schema],
        ]);

        $argv = grok_cli_records($binary)[0]['argv'];
        $indexes = array_keys($argv, '--json-schema', true);
        assert_eq(1, count($indexes), 'Grok argv must contain exactly one --json-schema');
        assert_eq(
            json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $argv[$indexes[0] + 1]
        );
    });
});

test('Grok pins reasoning effort instead of inheriting the CLI default', function (): void {
    with_grok_cli_fixture('grok-envelope.sh', function (string $binary): void {
        (new GrokCliLlm('grok-4.6', $binary))->complete('success');
        $argv = grok_cli_records($binary)[0]['argv'];
        $indexes = array_keys($argv, '--reasoning-effort', true);
        assert_eq(1, count($indexes), 'Grok argv must contain exactly one --reasoning-effort');
        assert_eq(HarnessCliLlm::REASONING_EFFORT, $argv[$indexes[0] + 1] ?? null);
        assert_eq('low', HarnessCliLlm::REASONING_EFFORT);
        // Grok's enum has no "none", so THINKING_OFF must not leak a value its
        // CLI rejects with "unknown effort level" and a non-zero exit.
        assert_true(
            !in_array('none', $argv, true),
            'Grok has no none level; low is its floor',
        );
    });
});

test('W7 Grok removes scratch files after success', function (): void {
    with_grok_cli_fixture('grok-envelope.sh', function (string $binary, string $scratchRoot): void {
        $before = grok_cli_scratch_entries($scratchRoot);
        assert_eq('success', (new GrokCliLlm('grok-4.6', $binary))->complete('success'));
        assert_eq($before, grok_cli_scratch_entries($scratchRoot));
    });
});

test('W7 Grok removes scratch files after non-zero exit', function (): void {
    with_grok_cli_fixture('grok-nonzero.sh', function (string $binary, string $scratchRoot): void {
        $before = grok_cli_scratch_entries($scratchRoot);
        $error = assert_throws(fn () => (new GrokCliLlm('grok-4.6', $binary))->complete('nonzero'));
        assert_true($error instanceof HarnessCallFailed);
        assert_contains('exit 17', $error->getMessage());
        assert_contains('fake Grok non-zero diagnostic', $error->getMessage());
        assert_eq($before, grok_cli_scratch_entries($scratchRoot));
    });
});

test('W7 Grok removes scratch files after parse HarnessCallFailed', function (): void {
    with_grok_cli_fixture('grok-malformed.sh', function (string $binary, string $scratchRoot): void {
        $before = grok_cli_scratch_entries($scratchRoot);
        $error = assert_throws(fn () => (new GrokCliLlm('grok-4.6', $binary))->complete('malformed'));
        assert_true($error instanceof HarnessCallFailed);
        assert_contains('JSON', $error->getMessage());
        assert_eq($before, grok_cli_scratch_entries($scratchRoot));
    });
});

test('W8 Grok uses eight unique prompt paths without concurrent cross-talk', function (): void {
    with_grok_cli_fixture('grok-envelope.sh', function (string $binary, string $scratchRoot): void {
        $requests = [];
        for ($i = 0; $i < 8; $i++) {
            $requests['job-' . $i] = ['prompt' => 'concurrent-prompt-' . $i];
        }

        $result = (new GrokCliLlm('grok-4.6', $binary, 8))->completeBatch($requests);
        foreach ($requests as $key => $request) {
            assert_eq($request['prompt'], $result->texts[$key], "{$key} crossed into another response");
        }

        $records = grok_cli_records($binary);
        assert_eq(8, count($records));
        $paths = [];
        foreach ($records as $record) {
            $path = $record['prompt_path'];
            assert_true(is_string($path) && $path !== '');
            $promptFileIndex = array_search('--prompt-file', $record['argv'], true);
            assert_true($promptFileIndex !== false, 'concurrent Grok argv omitted --prompt-file');
            $paths[] = $path;
            assert_eq($path, $record['argv'][$promptFileIndex + 1]);
            assert_true(!file_exists($path), 'per-call prompt file leaked after batch');
        }
        assert_eq(8, count(array_unique($paths)), 'concurrent calls reused a prompt path');
        assert_eq([], grok_cli_scratch_entries($scratchRoot));
    });
});

test('W9 Grok usage reads top-level usage and ignores conflicting modelUsage', function (): void {
    with_grok_cli_fixture('grok-envelope.sh', function (string $binary): void {
        $llm = new GrokCliLlm('grok-4.6', $binary);
        $llm->complete('usage');
        $usage = $llm->usageTotals();

        // Grok reports uncached input separately, so billed input is 24066 + 5760.
        assert_eq(29826, $usage['input_tokens']);
        assert_eq(31, $usage['output_tokens']);
        assert_eq(29857, $usage['total_tokens']);
        assert_eq(5760, $usage['cache_read_input_tokens']);
        assert_eq(0, $usage['cache_creation_input_tokens']);
        assert_eq(1, $usage['requests']);
    });
});

test('W12 Grok structural conformance passes 2 of 2 with zero spawn and scratch', function (): void {
    with_grok_cli_fixture('grok-envelope.sh', function (string $binary, string $scratchRoot): void {
        $llm = new GrokCliLlm('grok-4.6', $binary);
        $findings = LlmConformance::structural($llm);
        assert_eq(2, count($findings));
        assert_true(LlmConformance::passed($findings));
        assert_eq(0, $llm->usageTotals()['requests']);
        assert_eq([], grok_cli_records($binary));
        assert_eq([], grok_cli_scratch_entries($scratchRoot));
    });
});

test('Grok malformed stdout raises HarnessCallFailed with useful context', function (): void {
    with_grok_cli_fixture('grok-malformed.sh', function (string $binary): void {
        $error = assert_throws(fn () => (new GrokCliLlm('grok-4.6', $binary))->complete('bad-json'));
        assert_true($error instanceof HarnessCallFailed);
        assert_contains($binary, $error->getMessage());
        assert_contains('JSON', $error->getMessage());
    });
});

test('Grok response missing text raises HarnessCallFailed with useful context', function (): void {
    with_grok_cli_fixture('grok-missing-text.sh', function (string $binary): void {
        $error = assert_throws(fn () => (new GrokCliLlm('grok-4.6', $binary))->complete('missing-text'));
        assert_true($error instanceof HarnessCallFailed);
        assert_contains($binary, $error->getMessage());
        assert_contains('text', strtolower($error->getMessage()));
    });
});

test('Grok stopReason feeds raw-text truncation degradation', function (): void {
    with_grok_cli_fixture('grok-truncated.sh', function (string $binary): void {
        $llm = new GrokCliLlm('grok-4.6', $binary);
        $result = $llm->completeBatch(['cut' => ['prompt' => 'partial', 'max_tokens' => 5]]);
        assert_eq('partial', $result->texts['cut']);
        assert_eq(1, $llm->usageTotals()['requests'], 'raw-text recovery must not respawn Grok');
        assert_contains('truncated', implode("\n", $result->notesFor('cut')));
    });
});
