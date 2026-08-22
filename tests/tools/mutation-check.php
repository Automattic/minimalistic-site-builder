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
                'environment fingerprints and process ancestry identify multiple harnesses',
            );
        }
PHP,
        '',
    ],
    [
        '6 unknown provider refusal',
        'src/TransportResolver.php',
        <<<'PHP'
        $provider = self::normalizeProvider(
            $explicit ? $configured : $defaultProvider,
            $explicit ? 'LLM_PROVIDER' : 'default provider',
        );
PHP,
        <<<'PHP'
        try {
            $provider = self::normalizeProvider(
                $explicit ? $configured : $defaultProvider,
                $explicit ? 'LLM_PROVIDER' : 'default provider',
            );
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
                . 'Set LLM_PROVIDER to choose their provider, or unset the listed key(s) before using a harness transport.'
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
];

$tempBase = sys_get_temp_dir() . '/msb-transport-mutations-' . getmypid() . '-' . bin2hex(random_bytes(6));
$failed = false;

try {
    $baseline = $tempBase . '/baseline';
    mutation_copy_tree($baseline);
    $baselineResult = mutation_run($baseline);
    if ($baselineResult['status'] !== 0) {
        throw new RuntimeException("Unmutated transport tests failed in copied tree:\n" . $baselineResult['output']);
    }

    foreach ($mutants as $index => [$label, $file, $search, $replace]) {
        $root = $tempBase . '/mutant-' . ($index + 1);
        mutation_copy_tree($root);
        mutation_apply($root, $file, $search, $replace);

        $lint = mutation_run($root, true, $file);
        if ($lint['status'] !== 0) {
            throw new RuntimeException("Mutant {$label} produced invalid PHP:\n" . $lint['output']);
        }

        $result = mutation_run($root);
        $verdict = $result['status'] === 0 ? 'SURVIVED' : 'KILLED';
        echo "{$verdict} {$label}\n";
        if ($verdict === 'SURVIVED') {
            $failed = true;
        }
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
