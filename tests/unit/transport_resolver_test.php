<?php
declare(strict_types=1);

use Automattic\SiteBuild\TransportChoice;
use Automattic\SiteBuild\TransportResolver;
use Automattic\SiteBuild\TransportUnavailable;

/** No binaries on PATH, no ancestors — the base case for ladder tests. */
function tr_nothing(): array
{
    return [static fn (string $n): ?string => null, static fn (): array => []];
}

/** Only these binaries exist, at a synthetic path. */
function tr_on_path(string ...$names): callable
{
    return static fn (string $n): ?string => in_array($n, $names, true) ? "/fake/bin/{$n}" : null;
}

/** Run isolated PHP code and return [output, exit status]. */
function tr_php(string $code, array $ini = []): array
{
    $command = escapeshellarg(PHP_BINARY);
    foreach ($ini as $setting) {
        $command .= ' -d ' . escapeshellarg($setting);
    }
    $output = [];
    $status = 0;
    exec($command . ' -r ' . escapeshellarg($code) . ' 2>&1', $output, $status);
    return [implode("\n", $output), $status];
}

test('rung 1: an explicit override wins over everything below it', function (): void {
    [$path, $anc] = tr_nothing();
    $c = TransportResolver::decide(
        ['SITE_BUILD_LLM' => 'claude-cli', 'ANTHROPIC_API_KEY' => 'sk-live', 'CLAUDECODE' => '1'],
        tr_on_path('claude'),
        $anc,
    );
    assert_eq(TransportChoice::KIND_CLAUDE_CLI, $c->kind);
    assert_contains('SITE_BUILD_LLM', $c->reason);
});

test('rung 1: an unknown override names the valid values', function (): void {
    [$path, $anc] = tr_nothing();
    $e = assert_throws(fn () => TransportResolver::decide(['SITE_BUILD_LLM' => 'telepathy'], $path, $anc));
    assert_true($e instanceof TransportUnavailable);
    assert_contains('claude-cli', $e->getMessage());
});

test('rung 2: the configured provider key beats a harness fingerprint (metered is the default)', function (): void {
    [$path, $anc] = tr_nothing();
    $c = TransportResolver::decide(['ANTHROPIC_API_KEY' => 'sk-live', 'CLAUDECODE' => '1'], tr_on_path('claude'), $anc);
    assert_eq(TransportChoice::KIND_API, $c->kind);
});

test('rung 2 follows LLM_PROVIDER rather than assuming Anthropic', function (): void {
    [$path, $anc] = tr_nothing();
    $c = TransportResolver::decide(['LLM_PROVIDER' => 'openai', 'OPENAI_API_KEY' => 'sk-x'], $path, $anc);
    assert_eq(TransportChoice::KIND_API, $c->kind);

    // The Anthropic key is irrelevant when the configured provider is openai.
    $e = assert_throws(fn () => TransportResolver::decide(
        ['LLM_PROVIDER' => 'openai', 'ANTHROPIC_API_KEY' => 'sk-live'],
        $path,
        $anc,
    ));
    assert_true($e instanceof TransportUnavailable);
});

test('billing C1: an unknown provider refuses instead of falling through to claude', function (): void {
    [, $anc] = tr_nothing();
    $e = assert_throws(fn () => TransportResolver::decide(
        ['LLM_PROVIDER' => 'anthropc', 'ANTHROPIC_API_KEY' => 'credential-must-not-leak'],
        tr_on_path('claude'),
        $anc,
    ));
    assert_true($e instanceof TransportUnavailable);
    assert_contains('Unknown LLM_PROVIDER', $e->getMessage());
    assert_contains('anthropic, openai, xai, openrouter, grok', $e->getMessage());
    assert_true(!str_contains($e->getMessage(), 'credential-must-not-leak'));
});

test('billing C1: broken openai-compatible alias is rejected by name', function (): void {
    [, $anc] = tr_nothing();
    $e = assert_throws(fn () => TransportResolver::decide(
        ['LLM_PROVIDER' => 'openai-compatible', 'OPENAI_API_KEY' => 'sk-x'],
        tr_on_path('claude'),
        $anc,
    ));
    assert_true($e instanceof TransportUnavailable);
    assert_contains('openai-compatible', $e->getMessage());
    assert_contains('Valid values', $e->getMessage());
});

test('billing C1: claude provider typo is rejected before PATH fallback', function (): void {
    [, $anc] = tr_nothing();
    $e = assert_throws(fn () => TransportResolver::decide(
        ['LLM_PROVIDER' => 'claude'],
        tr_on_path('claude'),
        $anc,
    ));
    assert_true($e instanceof TransportUnavailable);
    assert_contains("Unknown LLM_PROVIDER 'claude'", $e->getMessage());
});

test('billing C2: provider value zero is not coerced to anthropic', function (): void {
    [, $anc] = tr_nothing();
    $e = assert_throws(fn () => TransportResolver::decide(
        ['LLM_PROVIDER' => '0'],
        tr_on_path('claude'),
        $anc,
    ));
    assert_true($e instanceof TransportUnavailable);
    assert_contains("Unknown LLM_PROVIDER '0'", $e->getMessage());
});

test('billing C1: openrouter accepts both existing key names', function (): void {
    [$path, $anc] = tr_nothing();
    foreach (['OPENROUTER_API_KEY', 'OPEN_ROUTER_API_KEY'] as $key) {
        $c = TransportResolver::decide(['LLM_PROVIDER' => 'openrouter', $key => 'sk-x'], $path, $anc);
        assert_eq(TransportChoice::KIND_API, $c->kind);
        assert_contains("{$key} present", $c->reason);
    }
});

test('billing C10: explicit provider without its key refuses before harness PATH fallback', function (): void {
    [, $anc] = tr_nothing();
    $e = assert_throws(fn () => TransportResolver::decide(
        ['LLM_PROVIDER' => 'openai', 'ANTHROPIC_API_KEY' => 'sk-wrong-provider'],
        tr_on_path('claude'),
        $anc,
    ));
    assert_true($e instanceof TransportUnavailable);
    assert_contains('OPENAI_API_KEY', $e->getMessage());
    assert_true(!str_contains($e->getMessage(), 'sk-wrong-provider'));
});

test('billing C1: caller-supplied default provider controls key selection and audit reason', function (): void {
    [$path, $anc] = tr_nothing();
    $c = TransportResolver::decide(['OPEN_ROUTER_API_KEY' => 'sk-x'], $path, $anc, 'openrouter');
    assert_eq(TransportChoice::KIND_API, $c->kind);
    assert_contains('OPEN_ROUTER_API_KEY present', $c->reason);
    assert_contains('provider: openrouter', $c->reason);
});

test('rung 2 keeps the compiled anthropic default for existing three-argument callers', function (): void {
    [$path, $anc] = tr_nothing();
    $c = TransportResolver::decide(['ANTHROPIC_API_KEY' => 'sk-x'], $path, $anc);
    assert_eq(TransportChoice::KIND_API, $c->kind);
    assert_contains('provider: anthropic', $c->reason);
});

test('rung 3: env fingerprints match on exact value', function (): void {
    [$path, $anc] = tr_nothing();

    $c = TransportResolver::decide(['CLAUDECODE' => '1'], tr_on_path('claude'), $anc);
    assert_eq(TransportChoice::KIND_CLAUDE_CLI, $c->kind);
    assert_contains('CLAUDECODE', $c->reason);
});

test('rung 3: a wrong fingerprint value does not match, and the ladder continues', function (): void {
    [, $anc] = tr_nothing();
    $c = TransportResolver::decide(['CLAUDECODE' => 'true'], tr_on_path('claude'), $anc);
    assert_eq(TransportChoice::KIND_CLAUDE_CLI, $c->kind);
    assert_contains('only harness on PATH', $c->reason);
    assert_true(!str_contains($c->reason, 'fingerprint'));
});

test('rung 3: a wrong fingerprint value with no binaries reaches the terminal error', function (): void {
    [$noPath, $anc] = tr_nothing();
    $e = assert_throws(fn () => TransportResolver::decide(['CLAUDECODE' => 'true'], $noPath, $anc));
    assert_true($e instanceof TransportUnavailable);
});

test('rung 3: codex sandbox markers select the codex transport', function (): void {
    [$path, $anc] = tr_nothing();
    $c = TransportResolver::decide(['CODEX_THREAD_ID' => 'abc'], tr_on_path('codex'), $anc);
    assert_eq(TransportChoice::KIND_CODEX_CLI, $c->kind);
});

test('billing C3: mixed Claude and Codex fingerprints refuse as ambiguous', function (): void {
    [, $anc] = tr_nothing();
    $e = assert_throws(fn () => TransportResolver::decide(
        ['CLAUDECODE' => '1', 'CODEX_THREAD_ID' => 'abc'],
        tr_on_path('claude'),
        $anc,
    ));
    assert_true($e instanceof TransportUnavailable);
    assert_contains('Ambiguous transport', $e->getMessage());
    assert_contains('claude', $e->getMessage());
    assert_contains('codex', $e->getMessage());
    assert_contains('SITE_BUILD_LLM', $e->getMessage());
});

test('billing review: Claude fingerprint refuses conflicting Codex ancestry', function (): void {
    $e = assert_throws(fn () => TransportResolver::decide(
        ['CLAUDECODE' => '1'],
        tr_on_path('claude', 'codex'),
        static fn (): array => ['php', 'codex', 'zsh'],
    ));
    assert_true($e instanceof TransportUnavailable);
    assert_contains('Ambiguous transport', $e->getMessage());
    assert_contains('claude', $e->getMessage());
    assert_contains('codex', $e->getMessage());
    assert_contains('process ancestry', $e->getMessage());
});

test('billing C4: OpenCode fingerprint refuses instead of spending the Claude subscription', function (): void {
    [, $anc] = tr_nothing();
    $e = assert_throws(fn () => TransportResolver::decide(
        ['OPENCODE' => '1'],
        tr_on_path('claude'),
        $anc,
    ));
    assert_true($e instanceof TransportUnavailable);
    assert_contains('OpenCode', $e->getMessage());
    assert_contains('SITE_BUILD_LLM', $e->getMessage());
});

test('billing C4: pi.dev exact fingerprint refuses instead of spending the Claude subscription', function (): void {
    [, $anc] = tr_nothing();
    $e = assert_throws(fn () => TransportResolver::decide(
        ['PI_CODING_AGENT' => 'true'],
        tr_on_path('claude'),
        $anc,
    ));
    assert_true($e instanceof TransportUnavailable);
    assert_contains('pi.dev', $e->getMessage());
    assert_contains('SITE_BUILD_LLM', $e->getMessage());
});

test('rung 3: unsupported harness refusal guards use exact values', function (): void {
    [, $anc] = tr_nothing();
    $c = TransportResolver::decide(
        ['OPENCODE' => 'true', 'PI_CODING_AGENT' => '1'],
        tr_on_path('claude'),
        $anc,
    );
    assert_eq(TransportChoice::KIND_CLAUDE_CLI, $c->kind);
    assert_contains('only harness on PATH', $c->reason);
});

test('C7: a present null Codex marker continues normally without TypeError', function (): void {
    [, $anc] = tr_nothing();
    $c = TransportResolver::decide(
        ['CODEX_THREAD_ID' => null],
        tr_on_path('claude'),
        $anc,
    );
    assert_eq(TransportChoice::KIND_CLAUDE_CLI, $c->kind);
    assert_contains('only harness on PATH', $c->reason);
});

test('rung 4: process ancestry is codex\'s real signal under danger-full-access', function (): void {
    $c = TransportResolver::decide([], tr_on_path('codex'), static fn (): array => ['php', 'codex', 'zsh']);
    assert_eq(TransportChoice::KIND_CODEX_CLI, $c->kind);
    assert_contains('ancestry', $c->reason);
});

test('rung 5: exactly one harness on PATH is used, and two refuse', function (): void {
    [, $anc] = tr_nothing();

    $c = TransportResolver::decide([], tr_on_path('grok'), $anc);
    assert_eq(TransportChoice::KIND_GROK_CLI, $c->kind);
    assert_contains('only harness on PATH', $c->reason);

    $e = assert_throws(fn () => TransportResolver::decide([], tr_on_path('claude', 'codex'), $anc));
    assert_true($e instanceof TransportUnavailable);
    assert_contains('claude', $e->getMessage());
    assert_contains('codex', $e->getMessage());
});

test('rung 6: nothing usable names the fix', function (): void {
    [$path, $anc] = tr_nothing();
    $e = assert_throws(fn () => TransportResolver::decide([], $path, $anc));
    assert_true($e instanceof TransportUnavailable);
    assert_contains('SITE_BUILD_LLM', $e->getMessage());
    assert_contains('ANTHROPIC_API_KEY', $e->getMessage());
});

test('an override naming a harness with no binary fails loudly rather than falling through', function (): void {
    [$path, $anc] = tr_nothing();
    $e = assert_throws(fn () => TransportResolver::decide(
        ['SITE_BUILD_LLM' => 'claude-cli', 'ANTHROPIC_API_KEY' => 'sk-live'],
        $path,
        $anc,
    ));
    assert_true($e instanceof TransportUnavailable);
    assert_contains('claude', $e->getMessage());
});

test('C9: subprocess guard throws when proc_open is disabled in a real child runtime', function (): void {
    $autoload = dirname(__DIR__, 2) . '/autoload.php';
    $code = 'require ' . var_export($autoload, true) . '; '
        . 'try { \\Automattic\\SiteBuild\\TransportResolver::assertSubprocessesAvailable(); echo "NO_ERROR"; } '
        . 'catch (Throwable $e) { echo get_class($e) . " | " . $e->getMessage(); }';
    [$output, $status] = tr_php($code, ['disable_functions=proc_open']);
    assert_eq(0, $status);
    assert_contains(TransportUnavailable::class, $output);
    assert_contains('proc_open', $output);
    assert_true(!str_contains($output, 'NO_ERROR'));
});

test('billing C5a: build has no ignored model parameter', function (): void {
    $method = new ReflectionMethod(TransportResolver::class, 'build');
    assert_eq(1, $method->getNumberOfParameters());
    assert_eq('choice', $method->getParameters()[0]->getName());
});

test('billing C5b: API build without bootstrap throws a named TransportUnavailable', function (): void {
    $autoload = dirname(__DIR__, 2) . '/autoload.php';
    $code = 'require ' . var_export($autoload, true) . '; '
        . '$c = new \\Automattic\\SiteBuild\\TransportChoice('
        . '\\Automattic\\SiteBuild\\TransportChoice::KIND_API, "explicit"); '
        . 'try { \\Automattic\\SiteBuild\\TransportResolver::build($c); echo "NO_ERROR"; } '
        . 'catch (Throwable $e) { echo get_class($e) . " | " . $e->getMessage(); }';
    [$output, $status] = tr_php($code);
    assert_eq(0, $status);
    assert_contains(TransportUnavailable::class, $output);
    assert_contains('src/bootstrap.php', $output);
    assert_true(!str_contains($output, 'NO_ERROR'));
});

test('billing C5c: API build without provider key throws instead of exiting', function (): void {
    $bootstrap = dirname(__DIR__, 2) . '/src/bootstrap.php';
    $code = 'require ' . var_export($bootstrap, true) . '; '
        . 'foreach (["LLM_PROVIDER", "ANTHROPIC_API_KEY", "XAI_API_KEY", "OPENAI_API_KEY", '
        . '"OPENROUTER_API_KEY", "OPEN_ROUTER_API_KEY"] as $key) { putenv($key); } '
        . '$r = new ReflectionClass(\\Automattic\\SiteBuild\\Env::class); '
        . '$p = $r->getProperty("vars"); $p->setValue(null, []); '
        . '$c = new \\Automattic\\SiteBuild\\TransportChoice('
        . '\\Automattic\\SiteBuild\\TransportChoice::KIND_API, "explicit"); '
        . 'try { \\Automattic\\SiteBuild\\TransportResolver::build($c); echo "NO_ERROR"; } '
        . 'catch (Throwable $e) { echo get_class($e) . " | " . $e->getMessage(); }';
    [$output, $status] = tr_php($code);
    assert_eq(0, $status);
    assert_contains(TransportUnavailable::class, $output);
    assert_contains('ANTHROPIC_API_KEY', $output);
    assert_true(!str_contains($output, 'Missing required env var'));
    assert_true(!str_contains($output, 'NO_ERROR'));
});

test('describe() names the transport, the billing boundary and the rung', function (): void {
    $line = TransportResolver::describe(
        new \Automattic\SiteBuild\TransportChoice(
            \Automattic\SiteBuild\TransportChoice::KIND_CLAUDE_CLI,
            'env fingerprint CLAUDECODE=1',
            '/fake/bin/claude',
        )
    );
    assert_contains('claude-cli', $line);
    assert_contains('subscription', $line);
    assert_contains('CLAUDECODE=1', $line);
    assert_contains('/fake/bin/claude', $line);
});

test('describe() marks the API transport as metered', function (): void {
    $line = TransportResolver::describe(
        new \Automattic\SiteBuild\TransportChoice(\Automattic\SiteBuild\TransportChoice::KIND_API, 'ANTHROPIC_API_KEY present')
    );
    assert_contains('metered', $line);
});

test('binaryPath finds a real executable and misses a fictional one', function (): void {
    assert_true(TransportResolver::binaryPath('php') !== null, 'php should be on PATH');
    assert_eq(null, TransportResolver::binaryPath('definitely-not-a-real-binary-xyz'));
});

test('billing C6: relative PATH entry cannot select a cwd harness', function (): void {
    with_temp_dir('relative-harness-', function (string $dir): void {
        $binary = $dir . '/claude';
        file_put_contents($binary, "#!/bin/sh\nexit 0\n");
        chmod($binary, 0755);
        $oldPath = getenv('PATH');
        $oldCwd = getcwd();
        try {
            chdir($dir);
            putenv('PATH=.' . PATH_SEPARATOR . '/usr/bin');
            assert_eq(null, TransportResolver::binaryPath('claude'));
            $e = assert_throws(fn () => TransportResolver::decide(
                [],
                static fn (string $name): ?string => TransportResolver::binaryPath($name),
                static fn (): array => [],
            ));
            assert_true($e instanceof TransportUnavailable);
            assert_contains('No LLM transport', $e->getMessage());
        } finally {
            chdir($oldCwd);
            $oldPath === false ? putenv('PATH') : putenv("PATH={$oldPath}");
        }
    });
});

test('billing review: POSIX backslash-leading PATH entry stays relative', function (): void {
    if (DIRECTORY_SEPARATOR !== '/') {
        return;
    }
    with_temp_dir('backslash-harness-', function (string $dir): void {
        $relativeDir = '\\evil';
        mkdir($dir . '/' . $relativeDir);
        $binary = $dir . '/' . $relativeDir . '/claude';
        file_put_contents($binary, "#!/bin/sh\nexit 0\n");
        chmod($binary, 0755);
        $oldPath = getenv('PATH');
        $oldCwd = getcwd();
        try {
            chdir($dir);
            putenv("PATH={$relativeDir}");
            assert_eq(null, TransportResolver::binaryPath('claude'));
            $e = assert_throws(fn () => TransportResolver::decide(
                [],
                static fn (string $name): ?string => TransportResolver::binaryPath($name),
                static fn (): array => [],
            ));
            assert_true($e instanceof TransportUnavailable);
            assert_contains('No LLM transport', $e->getMessage());
        } finally {
            chdir($oldCwd);
            $oldPath === false ? putenv('PATH') : putenv("PATH={$oldPath}");
        }
    });
});

test('billing C13: unknown override error strips controls and clamps raw input', function (): void {
    [$path, $anc] = tr_nothing();
    $raw = "tele\npathy\0" . str_repeat('x', 200);
    $e = assert_throws(fn () => TransportResolver::decide(['SITE_BUILD_LLM' => $raw], $path, $anc));
    assert_true($e instanceof TransportUnavailable);
    assert_true(!str_contains($e->getMessage(), "\n"));
    assert_true(!str_contains($e->getMessage(), "\0"));
    assert_contains('...', $e->getMessage());
    assert_true(strlen($e->getMessage()) < 220, 'sanitized error must stay bounded');
});

test('ancestry returns process names without throwing', function (): void {
    $names = TransportResolver::ancestry();
    assert_true(is_array($names), 'ancestry must return a list even when ps is unavailable');
});
