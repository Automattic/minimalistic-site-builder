<?php
declare(strict_types=1);

/**
 * Manual mutation-resistance gate for the transport billing boundary.
 *
 * Each mutant gets a fresh minimal copy of the source tree. The two transport
 * test files must pass in an unmodified copy and fail after every mutation.
 * Usage: php tests/tools/mutation-check.php
 */

const MUTATION_ROOT = __DIR__ . '/../..';

/** @return array{status:int,output:string} */
function mutation_run(string $root, bool $lintOnly = false, ?string $lintFile = null): array
{
    if ($lintOnly) {
        if ($lintFile === null) {
            throw new RuntimeException('Mutation lint needs a file path.');
        }
        $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/' . $lintFile) . ' 2>&1';
    } else {
        $runner = $root . '/mutation-runner.php';
        $runnerBytes = <<<'PHP'
<?php
declare(strict_types=1);

require_once __DIR__ . '/tests/lib.php';
require_once __DIR__ . '/tests/unit/transport_choice_test.php';
require_once __DIR__ . '/tests/unit/transport_resolver_test.php';

exit(run_tests());
PHP;
        if (file_put_contents($runner, $runnerBytes) !== strlen($runnerBytes)) {
            throw new RuntimeException("Could not write mutation runner: {$runner}");
        }
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1';
    }

    $output = [];
    $status = 0;
    exec($command, $output, $status);
    return ['status' => $status, 'output' => implode("\n", $output)];
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
        'config',
        'src',
        'tests/lib.php',
        'tests/FakeLlm.php',
        'tests/doubles.php',
        'tests/unit/transport_choice_test.php',
        'tests/unit/transport_resolver_test.php',
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
 * @param array{status:int,output:string} $result
 * @return array{verdict:string,detail:string,summary:array{passed:int,failed:int,skipped:int}|null}
 */
function mutation_classify(array $result): array
{
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
                return $apiFactory();
PHP,
        <<<'PHP'
                return \make_llm();
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
        $failed = $counts['KILLED'] < 13 || $counts['SURVIVED'] !== 0 || $counts['HARD ERROR'] !== 0;
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
