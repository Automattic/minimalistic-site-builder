<?php
declare(strict_types=1);

/**
 * Manual mutation-resistance gate for the transport billing boundary.
 *
 * Each mutant gets a fresh minimal copy of the source tree. The transport and
 * process-pool test files must pass unmodified and fail after every mutation.
 * Usage: php tests/tools/mutation-check.php
 */

const MUTATION_ROOT = __DIR__ . '/../..';
const MUTATION_RUN_TIMEOUT_SECONDS = 60;

/**
 * @param list<string> $command
 * @return array{status:int,output:string,timedOut:bool}
 */
function mutation_exec(array $command): array
{
    $proc = proc_open(
        $command,
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
    );
    if (!is_resource($proc)) {
        return ['status' => 2, 'output' => 'could not start mutation runner', 'timedOut' => false];
    }

    stream_set_blocking($pipes[1], false);
    $output = '';
    $deadline = microtime(true) + MUTATION_RUN_TIMEOUT_SECONDS;
    $timedOut = false;
    $exit = -1;
    while (true) {
        $chunk = stream_get_contents($pipes[1]);
        if ($chunk !== false && $chunk !== '') {
            $output .= $chunk;
        }

        $status = proc_get_status($proc);
        if (!$status['running']) {
            $exit = (int) $status['exitcode'];
            break;
        }
        if (microtime(true) >= $deadline) {
            $timedOut = true;
            proc_terminate($proc, 9);
            break;
        }

        $read = [$pipes[1]];
        $write = $except = null;
        @stream_select($read, $write, $except, 0, 100_000);
    }

    $chunk = stream_get_contents($pipes[1]);
    if ($chunk !== false && $chunk !== '') {
        $output .= $chunk;
    }
    fclose($pipes[1]);
    $closed = proc_close($proc);
    if (!$timedOut && $exit < 0 && $closed >= 0) {
        $exit = $closed;
    }

    return [
        'status' => $timedOut ? 124 : $exit,
        'output' => rtrim($output, "\r\n"),
        'timedOut' => $timedOut,
    ];
}

/** @return array{status:int,output:string,timedOut:bool} */
function mutation_run(string $root, bool $lintOnly = false, ?string $lintFile = null): array
{
    if ($lintOnly) {
        if ($lintFile === null) {
            throw new RuntimeException('Mutation lint needs a file path.');
        }
        $command = [PHP_BINARY, '-l', $root . '/' . $lintFile];
    } else {
        $runner = $root . '/mutation-runner.php';
        $runnerBytes = <<<'PHP'
<?php
declare(strict_types=1);

require_once __DIR__ . '/tests/lib.php';
require_once __DIR__ . '/tests/unit/transport_choice_test.php';
require_once __DIR__ . '/tests/unit/transport_resolver_test.php';
require_once __DIR__ . '/tests/unit/process_pool_test.php';
require_once __DIR__ . '/tests/unit/claude_cli_llm_test.php';
require_once __DIR__ . '/tests/unit/codex_cli_llm_test.php';
require_once __DIR__ . '/tests/unit/grok_cli_llm_test.php';
require_once __DIR__ . '/tests/unit/harness_cli_llm_test.php';

exit(run_tests());
PHP;
        if (file_put_contents($runner, $runnerBytes) !== strlen($runnerBytes)) {
            throw new RuntimeException("Could not write mutation runner: {$runner}");
        }
        $command = [PHP_BINARY, $runner];
    }
    return mutation_exec($command);
}

function mutation_copy_path(string $source, string $target): void
{
    if (is_link($source)) {
        throw new RuntimeException("Refusing to copy symlink into mutation tree: {$source}");
    }
    if (is_file($source)) {
        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
            throw new RuntimeException("Could not create mutation directory: {$parent}");
        }
        if (!copy($source, $target)) {
            throw new RuntimeException("Could not copy mutation file: {$source}");
        }
        $mode = fileperms($source);
        if ($mode !== false && !chmod($target, $mode & 0777)) {
            throw new RuntimeException("Could not preserve mutation file mode: {$source}");
        }
        return;
    }
    if (!is_dir($source)) {
        throw new RuntimeException("Mutation source path is missing: {$source}");
    }
    if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
        throw new RuntimeException("Could not create mutation directory: {$target}");
    }
    foreach (scandir($source) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            mutation_copy_path($source . '/' . $name, $target . '/' . $name);
        }
    }
}

function mutation_copy_tree(string $target): void
{
    if (!mkdir($target, 0775, true) && !is_dir($target)) {
        throw new RuntimeException("Could not create mutation root: {$target}");
    }
    foreach ([
        'autoload.php',
        'bin/build.php',
        'bin/build-demos.php',
        'bin/eval.php',
        'bin/images.php',
        'bin/llm-conformance.php',
        'config',
        'src',
        'tests/lib.php',
        'tests/FakeLlm.php',
        'tests/doubles.php',
        'tests/unit/transport_choice_test.php',
        'tests/unit/transport_resolver_test.php',
        'tests/unit/process_pool_test.php',
        'tests/unit/claude_cli_llm_test.php',
        'tests/unit/codex_cli_llm_test.php',
        'tests/unit/grok_cli_llm_test.php',
        'tests/unit/harness_cli_llm_test.php',
        'tests/fixtures/fake-harness',
    ] as $path) {
        mutation_copy_path(MUTATION_ROOT . '/' . $path, $target . '/' . $path);
    }
}

function mutation_remove_tree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        if (!unlink($path)) {
            throw new RuntimeException("Could not remove mutation file: {$path}");
        }
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            mutation_remove_tree($path . '/' . $name);
        }
    }
    if (!rmdir($path)) {
        throw new RuntimeException("Could not remove mutation directory: {$path}");
    }
}

function mutation_apply(string $root, string $file, string $search, string $replace): void
{
    $path = $root . '/' . $file;
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException("Could not read mutation target: {$path}");
    }
    $count = substr_count($source, $search);
    if ($count !== 1) {
        throw new RuntimeException("Mutation anchor for {$file} occurred {$count} times; expected exactly 1.");
    }
    $mutated = str_replace($search, $replace, $source);
    if ($mutated === $source || file_put_contents($path, $mutated) !== strlen($mutated)) {
        throw new RuntimeException("Could not apply mutation to {$file}.");
    }
}

function mutation_append(string $root, string $file, string $bytes): void
{
    $path = $root . '/' . $file;
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException("Could not read mutation target: {$path}");
    }
    $mutated = $source . $bytes;
    if ($mutated === $source || file_put_contents($path, $mutated) !== strlen($mutated)) {
        throw new RuntimeException("Could not append mutation to {$file}.");
    }
}

/** @return array{passed:int,failed:int,skipped:int}|null */
function mutation_parse_summary(string $output): ?array
{
    preg_match_all(
        '/^([0-9]+) passed, ([0-9]+) failed, ([0-9]+) skipped\r?$/m',
        $output,
        $matches,
        PREG_SET_ORDER,
    );
    if (count($matches) !== 1) {
        return null;
    }
    return [
        'passed' => (int) $matches[0][1],
        'failed' => (int) $matches[0][2],
        'skipped' => (int) $matches[0][3],
    ];
}

/**
 * @param array{status:int,output:string,timedOut:bool} $result
 * @return array{verdict:string,detail:string,summary:array{passed:int,failed:int,skipped:int}|null}
 */
function mutation_classify(array $result): array
{
    if ($result['timedOut']) {
        return [
            'verdict' => 'KILLED',
            'detail' => 'timeout=' . MUTATION_RUN_TIMEOUT_SECONDS . 's',
            'summary' => null,
        ];
    }
    $summary = mutation_parse_summary($result['output']);
    if ($summary === null) {
        return [
            'verdict' => 'HARD ERROR',
            'detail' => "exit={$result['status']} [NO COUNT LINE OR MALFORMED COUNT LINE]",
            'summary' => null,
        ];
    }

    $detail = "exit={$result['status']} passed={$summary['passed']} failed={$summary['failed']} skipped={$summary['skipped']}";
    if (array_sum($summary) === 0) {
        return ['verdict' => 'HARD ERROR', 'detail' => $detail . ' [ZERO TESTS REGISTERED]', 'summary' => $summary];
    }
    if ($result['status'] === 0 && $summary['passed'] >= 1 && $summary['failed'] === 0) {
        return ['verdict' => 'SURVIVED', 'detail' => $detail, 'summary' => $summary];
    }
    if ($result['status'] === 1 && $summary['failed'] >= 1) {
        return ['verdict' => 'KILLED', 'detail' => $detail, 'summary' => $summary];
    }
    return ['verdict' => 'HARD ERROR', 'detail' => $detail . ' [EXIT/COUNT MISMATCH]', 'summary' => $summary];
}

$mutants = [
    [
        '1 isSubscription billing map',
        'src/TransportChoice.php',
        <<<'PHP'
        return match ($this->kind) {
            self::KIND_API => false,
            self::KIND_CLAUDE_CLI,
            self::KIND_CODEX_CLI,
            self::KIND_GROK_CLI => true,
            default => throw new \LogicException(
                "transport kind '{$this->kind}' has no billing classification"
            ),
        };
PHP,
        <<<'PHP'
        return $this->kind !== self::KIND_API;
PHP,
    ],
    [
        '2 ancestry availability guard',
        'src/TransportResolver.php',
        <<<'PHP'
        if (!function_exists('shell_exec')) {
            return [];
        }
PHP,
        <<<'PHP'
        if (!function_exists('proc_open')) {
            return [];
        }
PHP,
    ],
    [
        '3 ancestry numeric ppid guard',
        'src/TransportResolver.php',
        <<<'PHP'
            if ($rawPpid === '' || !ctype_digit($rawPpid) || $name === '') {
PHP,
        <<<'PHP'
            if ($rawPpid === '' || $name === '') {
PHP,
    ],
    [
        '4 rung-4 ambiguity guard',
        'src/TransportResolver.php',
        <<<'PHP'
        if (count($matches) > 1) {
            $names = array_map(
                static fn (string $kind): string => self::BINARY_FOR[$kind],
                array_keys($matches),
            );
            throw self::ambiguousTransport($names, 'process ancestry identifies multiple harnesses');
        }
PHP,
        '',
    ],
    [
        '5 rung-3 ambiguity guard',
        'src/TransportResolver.php',
        <<<'PHP'
        if (count($matches) > 1) {
            $names = array_map(
                static fn (string $kind): string => self::BINARY_FOR[$kind],
                array_keys($matches),
            );
            throw self::ambiguousTransport(
                $names,
                'environment fingerprints identify multiple harnesses',
            );
        }
PHP,
        '',
    ],
    [
        '6 unknown provider refusal',
        'src/TransportResolver.php',
        <<<'PHP'
        $configured = strtolower(trim((string) ($env['LLM_PROVIDER'] ?? '')));
        $explicit = $configured !== '';
        $provider = self::apiProvider($env, $defaultProvider);
PHP,
        <<<'PHP'
        $configured = strtolower(trim((string) ($env['LLM_PROVIDER'] ?? '')));
        $explicit = $configured !== '';
        try {
            $provider = self::apiProvider($env, $defaultProvider);
        } catch (TransportUnavailable) {
            return null;
        }
PHP,
    ],
    [
        '7 other-provider-key refusal',
        'src/TransportResolver.php',
        <<<'PHP'
        $otherKeys = self::presentProviderKeys($env, $provider);
        if ($otherKeys !== []) {
            throw new TransportUnavailable(
                "Default provider {$provider} has no " . implode(' or ', self::PROVIDER_KEYS[$provider])
                . ', but other provider keys are present: ' . implode(', ', $otherKeys) . '. '
                . 'Set SITE_BUILD_LLM=api|claude-cli|codex-cli|grok-cli explicitly. '
                . 'Set LLM_PROVIDER only to use the present API key(s) and choose their provider; '
                . 'otherwise unset the listed key(s).'
            );
        }
PHP,
        '',
    ],
    [
        '8 absolute PATH guard',
        'src/TransportResolver.php',
        <<<'PHP'
    public static function binaryPath(string $name): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if (!self::isAbsolutePath($dir)) {
                continue;
            }
            $candidate = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name;
            if (self::isAbsolutePath($candidate) && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }
PHP,
        <<<'PHP'
    public static function binaryPath(string $name): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            $candidate = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }
PHP,
    ],
    [
        '9 rung-2 explicit-provider refusal',
        'src/TransportResolver.php',
        <<<'PHP'
        if ($explicit) {
            throw new TransportUnavailable(
                "LLM_PROVIDER={$provider} requires " . implode(' or ', self::PROVIDER_KEYS[$provider]) . '. '
                . 'Set its provider key, or set SITE_BUILD_LLM to an available harness transport.'
            );
        }
PHP,
        '',
    ],
    [
        '10 resolved provider source',
        'src/TransportResolver.php',
        <<<'PHP'
            $provider = self::normalizeProvider($choice->provider, 'resolved API provider');
PHP,
        <<<'PHP'
            $provider = self::normalizeProvider((string) getenv('LLM_PROVIDER'), 'LLM_PROVIDER');
PHP,
    ],
    [
        '11 case-insensitive ancestry matching',
        'src/TransportResolver.php',
        <<<'PHP'
    private static function rungAncestry(
        array $env,
        callable $onPath,
        callable $ancestry,
        string $defaultProvider,
    ): ?TransportChoice
    {
        $matches = [];
        foreach ($ancestry() as $name) {
            $kind = self::HARNESSES[strtolower(trim($name))] ?? null;
PHP,
        <<<'PHP'
    private static function rungAncestry(
        array $env,
        callable $onPath,
        callable $ancestry,
        string $defaultProvider,
    ): ?TransportChoice
    {
        $matches = [];
        foreach ($ancestry() as $name) {
            $kind = self::HARNESSES[trim($name)] ?? null;
PHP,
    ],
    [
        '12 live ancestry priority over inherited fingerprints',
        'src/TransportResolver.php',
        <<<'PHP'
        if ($matches !== []) {
            $ancestryMatches = [];
            foreach ($ancestry() as $name) {
                $kind = self::HARNESSES[strtolower(trim($name))] ?? null;
                if ($kind !== null && !isset($ancestryMatches[$kind])) {
                    $ancestryMatches[$kind] = $name;
                }
            }
            if (count($ancestryMatches) > 1) {
                $names = array_map(
                    static fn (string $kind): string => self::BINARY_FOR[$kind],
                    array_keys($ancestryMatches),
                );
                throw self::ambiguousTransport($names, 'process ancestry identifies multiple harnesses');
            }
            if ($ancestryMatches !== []) {
                $kind = array_key_first($ancestryMatches);
                $name = $ancestryMatches[$kind];
                $ignored = [];
                foreach ($matches as $fingerprintKind => $signals) {
                    if ($fingerprintKind === $kind) {
                        continue;
                    }
                    foreach ($signals as $signal) {
                        $ignored[] = "inherited {$signal} ignored";
                    }
                }
                $reason = "process ancestry found {$name}"
                    . ($ignored === [] ? '' : ' (' . implode('; ', $ignored) . ')');
                return self::harnessChoice($kind, $reason, $onPath);
            }
        }
PHP,
        <<<'PHP'
        if ($matches !== []) {
            foreach ($ancestry() as $name) {
                $kind = self::HARNESSES[strtolower(trim($name))] ?? null;
                if ($kind !== null && !isset($matches[$kind])) {
                    $matches[$kind][] = "process ancestry found '{$name}'";
                }
            }
        }
PHP,
    ],
    [
        '13 injected API factory boundary',
        'src/TransportResolver.php',
        <<<'PHP'
            return $apiFactory($provider);
PHP,
        <<<'PHP'
            return \make_llm();
PHP,
    ],
    [
        '14 injected API factory credential ownership',
        'src/TransportResolver.php',
        <<<'PHP'
            $provider = self::normalizeProvider($choice->provider, 'resolved API provider');

            return $apiFactory($provider);
PHP,
        <<<'PHP'
            $provider = self::normalizeProvider($choice->provider, 'resolved API provider');

            if (trim((string) Env::get(self::PROVIDER_KEYS[$provider][0], '')) === '') {
                throw new TransportUnavailable(
                    "Transport api with LLM_PROVIDER={$provider} requires "
                    . self::PROVIDER_KEYS[$provider][0] . '. Set the provider key before building.'
                );
            }
            return $apiFactory($provider);
PHP,
    ],
    [
        '15 CODEX_SANDBOX marker',
        'src/TransportResolver.php',
        <<<'PHP'
    private const CODEX_MARKERS = ['CODEX_SANDBOX_NETWORK_DISABLED', 'CODEX_THREAD_ID', 'CODEX_SANDBOX'];
PHP,
        <<<'PHP'
    private const CODEX_MARKERS = ['CODEX_SANDBOX_NETWORK_DISABLED', 'CODEX_THREAD_ID'];
PHP,
    ],
    [
        '16 CODEX_SANDBOX_NETWORK_DISABLED marker',
        'src/TransportResolver.php',
        <<<'PHP'
    private const CODEX_MARKERS = ['CODEX_SANDBOX_NETWORK_DISABLED', 'CODEX_THREAD_ID', 'CODEX_SANDBOX'];
PHP,
        <<<'PHP'
    private const CODEX_MARKERS = ['CODEX_THREAD_ID', 'CODEX_SANDBOX'];
PHP,
    ],
    [
        '17 blank credential rejection',
        'src/TransportResolver.php',
        <<<'PHP'
        foreach (self::PROVIDER_KEYS[$provider] as $var) {
            if (trim((string) ($env[$var] ?? '')) !== '') {
PHP,
        <<<'PHP'
        foreach (self::PROVIDER_KEYS[$provider] as $var) {
            if (array_key_exists($var, $env)) {
PHP,
    ],
    [
        '18 blank Codex marker rejection',
        'src/TransportResolver.php',
        <<<'PHP'
        foreach (self::CODEX_MARKERS as $var) {
            if (trim((string) ($env[$var] ?? '')) !== '') {
PHP,
        <<<'PHP'
        foreach (self::CODEX_MARKERS as $var) {
            if (isset($env[$var])) {
PHP,
    ],
    [
        '19 missing harness binary refusal',
        'src/TransportResolver.php',
        <<<'PHP'
        if ($path === null) {
            throw new TransportUnavailable(
                "Resolved {$kind} ({$reason}) but '{$binary}' is not on PATH. "
                . 'Install it, or set SITE_BUILD_LLM / a provider API key.'
            );
        }
PHP,
        '',
    ],
    [
        '20 ancestry self-parent cycle guard',
        'src/TransportResolver.php',
        <<<'PHP'
            if ($next === $pid) {
                break;
            }
PHP,
        '',
    ],
    [
        '21 build provider canonicalization',
        'src/TransportResolver.php',
        <<<'PHP'
            $provider = self::normalizeProvider($choice->provider, 'resolved API provider');
PHP,
        <<<'PHP'
            $provider = $choice->provider;
PHP,
    ],
    [
        '22 build subprocess availability guard',
        'src/TransportResolver.php',
        <<<'PHP'
        self::assertSubprocessesAvailable();
PHP,
        '',
    ],
    [
        '23 blocking stdin pipe',
        'src/ProcessPool.php',
        <<<'PHP'
                stream_set_blocking($pipes[0], false);
PHP,
        <<<'PHP'
                stream_set_blocking($pipes[0], true);
PHP,
    ],
    [
        '24 await returns before a child completes',
        'src/ProcessPool.php',
        <<<'PHP'
            while ($done === [] && $live !== []) {
PHP,
        <<<'PHP'
            if ($done === [] && $live !== []) {
PHP,
    ],
    [
        '25 argv element loses literal process boundary',
        'src/ProcessPool.php',
        <<<'PHP'
                $job['argv'],
PHP,
        <<<'PHP'
                implode(' ', $job['argv']),
PHP,
    ],
    [
        '26 no-stdin job gets a closed empty pipe',
        'src/ProcessPool.php',
        <<<'PHP'
            $hasStdin = array_key_exists('stdin', $job);
PHP,
        <<<'PHP'
            $hasStdin = true;
PHP,
    ],
    [
        '27 terminal status is re-polled instead of cached',
        'src/ProcessPool.php',
        <<<'PHP'
                    $status = $slot['terminalStatus'];
PHP,
        <<<'PHP'
                    $status = proc_get_status($slot['proc']);
PHP,
    ],
    [
        '28 pre-spawn executable check is dropped',
        'src/ProcessPool.php',
        <<<'PHP'
            $resolvedBinary = self::resolveExecutable(
                $requestedBinary,
                $job['env'] ?? null,
                $cwd,
            );
            if ($resolvedBinary === null) {
                $name = $requestedBinary === '' ? '(empty argv[0])' : $requestedBinary;
                $live[$key] = [
                    'failed' => "executable not found or not executable: {$name}",
                    'start' => $started,
                ];
                return;
            }
            $job['argv'][0] = $resolvedBinary;
PHP,
        '',
    ],
    [
        '28b missing working directory is left to the platform',
        'src/ProcessPool.php',
        <<<'PHP'
            if ($cwd !== null && !is_dir($cwd)) {
PHP,
        <<<'PHP'
            if (false) {
PHP,
    ],
    [
        '29 Claude argv omits the pinned model',
        'src/ClaudeCliLlm.php',
        <<<'PHP'
            '--model',
            $model,
PHP,
        '',
    ],
    [
        '30 prompt moves from stdin into argv',
        'src/ClaudeCliLlm.php',
        <<<'PHP'
        return ['argv' => $argv, 'stdin' => $prepared['prompt']];
PHP,
        <<<'PHP'
        return ['argv' => [...$argv, $prepared['prompt']]];
PHP,
    ],
    [
        '31 cached prefixes are dropped from stdin',
        'src/HarnessCliLlm.php',
        <<<'PHP'
            'prompt' => implode('', $layers) . $request['prompt'],
PHP,
        <<<'PHP'
            'prompt' => $request['prompt'],
PHP,
    ],
    [
        '32 max_tokens disclosure is swallowed',
        'src/HarnessCliLlm.php',
        <<<'PHP'
        $options = ['temperature', 'max_tokens'];
PHP,
        <<<'PHP'
        $options = ['temperature'];
PHP,
    ],
    [
        '33 JSON content repair retry is disabled',
        'src/HarnessCliLlm.php',
        <<<'PHP'
        return JsonBatchRecovery::run(
            $requests,
            fn (array $subset): array => $this->responseBatch($subset),
        );
PHP,
        <<<'PHP'
        return JsonBatchRecovery::run(
            $requests,
            fn (array $subset): array => $this->responseBatch($subset),
            maxRetries: 0,
        );
PHP,
    ],
    [
        '34 total transport failure floor is removed',
        'src/HarnessCliLlm.php',
        <<<'PHP'
                if ($responses !== [] && $usableResponses === 0 && $firstFailure !== null) {
                    throw $firstFailure;
                }
PHP,
        '',
    ],
    [
        '35 make_llm ignores its explicit provider',
        'src/bootstrap.php',
        <<<'PHP'
    $provider = strtolower((string) ($provider ?? Env::get('LLM_PROVIDER', ModelConfig::defaultProvider())));
PHP,
        <<<'PHP'
    $provider = strtolower((string) Env::get('LLM_PROVIDER', ModelConfig::defaultProvider()));
PHP,
    ],
    [
        '36 resolve_llm drops its catchable credential pre-check',
        'src/bootstrap.php',
        <<<'PHP'
            $variable = TransportResolver::credentialVariableFor($provider);
            $openrouterCredential = $provider === 'openrouter' ? openrouter_api_credential() : null;
            $credential = $openrouterCredential['value'] ?? ($variable === null ? null : Env::get($variable));
            if ($variable !== null && ($credential === null || trim($credential) === '')) {
                throw new TransportUnavailable(
                    "Transport api resolved provider {$provider}, but required credential {$variable} is missing. "
                    . "Set {$variable}, or choose an available harness with SITE_BUILD_LLM."
                );
            }

PHP,
        '',
    ],
    [
        '37 resolve_llm discards the resolved provider',
        'src/bootstrap.php',
        <<<'PHP'
            return make_llm($provider);
PHP,
        <<<'PHP'
            return make_llm();
PHP,
    ],
    [
        '38 resolve_llm misses Env-loaded credentials',
        'src/bootstrap.php',
        <<<'PHP'
        $value = Env::get($variable);
PHP,
        <<<'PHP'
        $value = getenv($variable);
PHP,
    ],
    [
        '38b harnesses inherit the developer reasoning effort',
        'src/HarnessCliLlm.php',
        <<<'PHP'
    public const REASONING_EFFORT = 'low';
PHP,
        <<<'PHP'
    public const REASONING_EFFORT = 'xhigh';
PHP,
    ],
    [
        '38c harness thinking is left on',
        'src/HarnessCliLlm.php',
        <<<'PHP'
    public const THINKING_OFF = true;
PHP,
        <<<'PHP'
    public const THINKING_OFF = false;
PHP,
    ],
    [
        '39 Codex argv omits the pinned model',
        'src/CodexCliLlm.php',
        <<<'PHP'
            '-m',
            $model,
PHP,
        '',
    ],
    [
        '40 Grok argv omits the pinned model',
        'src/GrokCliLlm.php',
        <<<'PHP'
            '-m',
            $model,
PHP,
        '',
    ],
    [
        '41 Grok puts the prompt in argv',
        'src/GrokCliLlm.php',
        <<<'PHP'
            '--prompt-file',
            $promptPath,
PHP,
        <<<'PHP'
            '-p',
            $prepared['prompt'],
PHP,
    ],
    [
        '42 scratch cleanup is skipped on failure',
        'src/HarnessCliLlm.php',
        <<<'PHP'
        } finally {
            $cleanupFailure = null;
            foreach (array_reverse($scratchDirs, true) as $scratchDir) {
                try {
                    $this->removeScratchPath($scratchDir);
                } catch (\Throwable $e) {
                    $cleanupFailure ??= $e;
                }
            }
            if ($cleanupFailure !== null) {
                throw $cleanupFailure;
            }
        }
PHP,
        <<<'PHP'
        } catch (\Throwable $e) {
            throw $e;
        }
PHP,
    ],
    [
        '43 Codex reads the event text instead of the output file',
        'src/CodexCliLlm.php',
        <<<'PHP'
        return [
            'text' => $answerFromFile,
PHP,
        <<<'PHP'
        $eventLines = preg_split('/\R/', trim($stdout)) ?: [];
        $eventMessage = isset($eventLines[2]) ? json_decode($eventLines[2], true) : null;
        return [
            'text' => (string) ($eventMessage['item']['text'] ?? ''),
PHP,
    ],
    [
        '44 resolve_llm does not align the harness provider',
        'src/bootstrap.php',
        <<<'PHP'
        putenv("LLM_PROVIDER={$harnessProvider}");
PHP,
        '',
    ],
    [
        '45 incoherent provider and harness pairing is accepted',
        'src/bootstrap.php',
        <<<'PHP'
        if ($explicitProvider !== null && trim($explicitProvider) !== '') {
            $explicitCredential = TransportResolver::credentialVariableFor($explicitProvider);
            $harnessCredential = TransportResolver::credentialVariableFor($harnessProvider);
            if ($explicitCredential !== $harnessCredential) {
                throw new TransportUnavailable(
                    "Transport {$choice->kind} requires provider {$harnessProvider}, "
                    . "but explicit LLM_PROVIDER={$explicitProvider} disagrees."
                );
            }
        }
PHP,
        '',
    ],
    [
        '46 Codex double counts cached input',
        'src/CodexCliLlm.php',
        <<<'PHP'
            'input' => (int) ($turnUsage['input_tokens'] ?? 0),
PHP,
        <<<'PHP'
            'input' => (int) ($turnUsage['input_tokens'] ?? 0)
                + (int) ($turnUsage['cached_input_tokens'] ?? 0),
PHP,
    ],
    [
        '47 Codex and Grok swallow system without disclosure',
        'src/HarnessCliLlm.php',
        <<<'PHP'
        if (!$this->honorsSystemOption()
            && is_string($request['system'] ?? null)
            && trim($request['system']) !== ''
        ) {
            $options[] = 'system';
        }
PHP,
        '',
    ],
    [
        '48 Codex puts system text in argv',
        'src/CodexCliLlm.php',
        <<<'PHP'
        return ['argv' => $argv, 'stdin' => $prepared['prompt']];
PHP,
        <<<'PHP'
        $argv[] = (string) ($prepared['request']['system'] ?? '');
        return ['argv' => $argv, 'stdin' => $prepared['prompt']];
PHP,
    ],
    [
        '49 coherence check ignores Env-loaded provider',
        'src/bootstrap.php',
        <<<'PHP'
        $explicitProvider = $configuredProvider;
PHP,
        <<<'PHP'
        $explicitProvider = getenv('LLM_PROVIDER');
PHP,
    ],
];

$args = array_slice($argv, 1);
if ($args !== [] && $args !== ['--self-test']) {
    echo 'ERROR: unknown arguments: ' . implode(' ', $args) . "\n";
    echo "Usage: php tests/tools/mutation-check.php [--self-test]\n";
    exit(2);
}

$tempBase = sys_get_temp_dir() . '/msb-transport-mutations-' . getmypid() . '-' . bin2hex(random_bytes(6));
$failed = false;

try {
    if ($args === ['--self-test']) {
        $expectations = [
            ['no-op', 'SURVIVED'],
            ['rung-2 explicit-provider guard deletion', 'KILLED'],
            ['bootstrap crash', 'HARD ERROR'],
        ];
        foreach ($expectations as $index => [$label, $expected]) {
            $root = $tempBase . '/self-test-' . ($index + 1);
            mutation_copy_tree($root);
            if ($index === 0) {
                mutation_apply(
                    $root,
                    'src/TransportResolver.php',
                    "final class TransportResolver\n{",
                    "/* mutation self-test: intentional semantic no-op */\nfinal class TransportResolver\n{",
                );
            } elseif ($index === 1) {
                [, $file, $search, $replace] = $mutants[8];
                mutation_apply($root, $file, $search, $replace);
            } else {
                mutation_append($root, 'src/bootstrap.php', "\nundefined_function_zzz();\n");
            }

            $result = mutation_run($root);
            $classification = mutation_classify($result);
            $extra = '';
            if ($index === 2) {
                preg_match_all('/^  (?:PASS|FAIL|SKIP)\b/m', $result['output'], $testLines);
                $registered = count($testLines[0]);
                $extra = " registered-test-lines={$registered}";
                if ($registered !== 0 || !str_contains($result['output'], 'undefined_function_zzz')) {
                    $failed = true;
                }
            }
            echo "{$classification['verdict']} self-test {$label} {$classification['detail']}{$extra}\n";
            if ($classification['verdict'] !== $expected) {
                echo "SELF-TEST ERROR: {$label} expected {$expected}, got {$classification['verdict']}\n";
                $failed = true;
            }
        }
        echo $failed
            ? "SELF-TEST FAIL\n"
            : "SELF-TEST PASS: 1 SURVIVED, 1 KILLED, 1 HARD ERROR\n";
    } else {
        $baseline = $tempBase . '/baseline';
        mutation_copy_tree($baseline);
        $baselineResult = mutation_run($baseline);
        $baselineClassification = mutation_classify($baselineResult);
        if ($baselineClassification['verdict'] !== 'SURVIVED') {
            throw new RuntimeException(
                "Unmutated transport tests were classified {$baselineClassification['verdict']} "
                . $baselineClassification['detail'] . ":\n" . $baselineResult['output'],
            );
        }
        echo "BASELINE PASS {$baselineClassification['detail']}\n";

        $counts = ['KILLED' => 0, 'SURVIVED' => 0, 'HARD ERROR' => 0];

        foreach ($mutants as $index => [$label, $file, $search, $replace]) {
            $root = $tempBase . '/mutant-' . ($index + 1);
            try {
                mutation_copy_tree($root);
                mutation_apply($root, $file, $search, $replace);

                $lint = mutation_run($root, true, $file);
                if ($lint['status'] !== 0) {
                    $classification = [
                        'verdict' => 'HARD ERROR',
                        'detail' => "lint-exit={$lint['status']} [INVALID PHP]",
                        'summary' => null,
                    ];
                } else {
                    $classification = mutation_classify(mutation_run($root));
                }
            } catch (Throwable $e) {
                $classification = [
                    'verdict' => 'HARD ERROR',
                    'detail' => '[MUTATION SETUP FAILED] ' . $e->getMessage(),
                    'summary' => null,
                ];
            }

            $counts[$classification['verdict']]++;
            echo "{$classification['verdict']} {$label} {$classification['detail']}\n";
        }
        echo "RESULT {$counts['KILLED']} KILLED, {$counts['SURVIVED']} SURVIVED, {$counts['HARD ERROR']} HARD ERROR\n";
        $failed = $counts['KILLED'] < 49 || $counts['SURVIVED'] !== 0 || $counts['HARD ERROR'] !== 0;
    }
} catch (Throwable $e) {
    echo 'MUTATION ERROR: ' . $e->getMessage() . "\n";
    $failed = true;
} finally {
    try {
        mutation_remove_tree($tempBase);
    } catch (Throwable $e) {
        echo 'MUTATION CLEANUP ERROR: ' . $e->getMessage() . "\n";
        $failed = true;
    }
}

exit($failed ? 1 : 0);
