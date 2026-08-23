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

/** Resolve one explicit harness in a child and return [output, status, decoded details]. */
function tr_harness_probe(
    string $dir,
    string $kind,
    ?string $provider,
    ?string $envMapProvider = null,
): array
{
    $bootstrap = dirname(__DIR__, 2) . '/src/bootstrap.php';
    $providerSetup = $provider === null
        ? 'putenv("LLM_PROVIDER"); '
        : 'putenv("LLM_PROVIDER=' . addslashes($provider) . '"); ';
    $envMapSetup = $envMapProvider === null
        ? ''
        : '$envReflection = new ReflectionClass(\\Automattic\\SiteBuild\\Env::class); '
            . '$envProperty = $envReflection->getProperty("vars"); '
            . '$envVars = $envProperty->getValue(); '
            . '$envVars["LLM_PROVIDER"] = ' . var_export($envMapProvider, true) . '; '
            . '$envProperty->setValue(null, $envVars); ';
    $code = 'putenv("PATH=' . addslashes($dir) . '"); '
        . 'putenv("SITE_BUILD_LLM=' . addslashes($kind) . '"); '
        . $providerSetup
        . 'putenv("LLM_MODEL"); putenv("LLM_MODEL_SMALL"); '
        . 'require ' . var_export($bootstrap, true) . '; '
        . $envMapSetup
        . '$stream = fopen("php://memory", "w+"); '
        . '\\Automattic\\SiteBuild\\Narrator::setStream($stream); '
        . 'try { $llm = resolve_llm(); '
        . '$r = new ReflectionClass(\\Automattic\\SiteBuild\\HarnessCliLlm::class); '
        . '$model = $r->getProperty("model")->getValue($llm); '
        . 'echo json_encode(["class" => get_class($llm), "model" => $model, '
        . '"provider" => getenv("LLM_PROVIDER"), "default" => default_llm_model(), '
        . '"steps" => step_models()], JSON_THROW_ON_ERROR); } '
        . 'catch (Throwable $e) { echo "ERROR|" . get_class($e) . "|" . $e->getMessage(); }';
    [$output, $status] = tr_php($code);
    $decoded = json_decode($output, true);
    return [$output, $status, is_array($decoded) ? $decoded : null];
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
    assert_contains('anthropic, openai, xai, openrouter', $e->getMessage());
    assert_contains('grok is an alias for xai', $e->getMessage());
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
    assert_contains('Valid providers', $e->getMessage());
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

test('billing C10: explicit provider refusal is covered without any other provider key', function (): void {
    [, $anc] = tr_nothing();
    $e = assert_throws(fn () => TransportResolver::decide(
        ['LLM_PROVIDER' => 'openai'],
        tr_on_path('claude'),
        $anc,
    ));
    assert_true($e instanceof TransportUnavailable);
    assert_eq(
        'LLM_PROVIDER=openai requires OPENAI_API_KEY. Set its provider key, '
        . 'or set SITE_BUILD_LLM to an available harness transport.',
        $e->getMessage(),
    );
});

test('billing M1: non-default live keys refuse both exact wrong-budget reproductions', function (): void {
    [, $anc] = tr_nothing();
    $cases = [
        [
            ['OPENAI_API_KEY' => 'sk-live', 'CLAUDECODE' => '1'],
            'OPENAI_API_KEY',
        ],
        [
            ['XAI_API_KEY' => 'sk-live'],
            'XAI_API_KEY',
        ],
    ];
    foreach ($cases as [$env, $key]) {
        $e = assert_throws(fn () => TransportResolver::decide($env, tr_on_path('claude'), $anc));
        assert_true($e instanceof TransportUnavailable);
        assert_contains($key, $e->getMessage());
        assert_contains('SITE_BUILD_LLM=api|claude-cli|codex-cli|grok-cli', $e->getMessage());
        assert_contains('Set LLM_PROVIDER', $e->getMessage());
        assert_contains('unset', $e->getMessage());
        assert_true(!str_contains($e->getMessage(), $env[$key]));
    }
});

test('billing M1: refusal names every conflicting key name and no credential value', function (): void {
    [$path, $anc] = tr_nothing();
    $env = [
        'OPENAI_API_KEY' => 'openai-secret-value',
        'XAI_API_KEY' => 'xai-secret-value',
        'OPEN_ROUTER_API_KEY' => 'openrouter-secret-value',
    ];
    $e = assert_throws(fn () => TransportResolver::decide($env, $path, $anc));
    assert_true($e instanceof TransportUnavailable);
    foreach (array_keys($env) as $key) {
        assert_contains($key, $e->getMessage());
        assert_true(!str_contains($e->getMessage(), $env[$key]));
    }
});

test('billing M1: no known provider key still falls through to subscription rungs', function (): void {
    [, $anc] = tr_nothing();
    $c = TransportResolver::decide([], tr_on_path('claude'), $anc);
    assert_eq(TransportChoice::KIND_CLAUDE_CLI, $c->kind);
    assert_contains('only harness on PATH', $c->reason);
});

test('billing C1: caller-supplied default provider controls key selection and audit reason', function (): void {
    [$path, $anc] = tr_nothing();
    $c = TransportResolver::decide(['OPEN_ROUTER_API_KEY' => 'sk-x'], $path, $anc, 'openrouter');
    assert_eq(TransportChoice::KIND_API, $c->kind);
    assert_eq('openrouter', $c->provider);
    assert_contains('OPEN_ROUTER_API_KEY present', $c->reason);
    assert_contains('provider: openrouter', $c->reason);
});

test('billing N1: explicit API override carries a canonical provider', function (): void {
    [$path, $anc] = tr_nothing();
    $c = TransportResolver::decide(
        ['SITE_BUILD_LLM' => 'api', 'LLM_PROVIDER' => 'grok'],
        $path,
        $anc,
    );
    assert_eq(TransportChoice::KIND_API, $c->kind);
    assert_eq('xai', $c->provider);
    assert_contains('provider: xai', TransportResolver::describe($c));
});

test('rung 2 keeps the compiled anthropic default for existing three-argument callers', function (): void {
    [$path, $anc] = tr_nothing();
    $c = TransportResolver::decide(['ANTHROPIC_API_KEY' => 'sk-x'], $path, $anc);
    assert_eq(TransportChoice::KIND_API, $c->kind);
    assert_contains('provider: anthropic', $c->reason);
});

test('billing M4: grok is normalized to xai before key lookup and audit', function (): void {
    [$path, $anc] = tr_nothing();
    $c = TransportResolver::decide(
        ['LLM_PROVIDER' => 'grok', 'XAI_API_KEY' => 'xai-secret-value'],
        $path,
        $anc,
    );
    assert_eq(TransportChoice::KIND_API, $c->kind);
    assert_contains('XAI_API_KEY present', $c->reason);
    assert_contains('provider: xai', $c->reason);
    assert_true(!str_contains($c->reason, 'provider: grok'));

    $providers = (new ReflectionClass(TransportResolver::class))->getConstant('PROVIDER_KEYS');
    assert_eq(['anthropic', 'openai', 'xai', 'openrouter'], array_keys($providers));
});

test('billing M5: empty caller default is unset and uses the compiled default', function (): void {
    [$path, $anc] = tr_nothing();
    foreach (['', '   '] as $defaultProvider) {
        $c = TransportResolver::decide(['ANTHROPIC_API_KEY' => 'sk-x'], $path, $anc, $defaultProvider);
        assert_eq(TransportChoice::KIND_API, $c->kind);
        assert_contains('provider: anthropic', $c->reason);
    }
});

test('billing M5: invalid caller default names the default-provider input', function (): void {
    [$path, $anc] = tr_nothing();
    $e = assert_throws(fn () => TransportResolver::decide([], $path, $anc, 'nope'));
    assert_true($e instanceof TransportUnavailable);
    assert_contains("Unknown default provider 'nope'", $e->getMessage());
    assert_true(!str_contains($e->getMessage(), "Unknown LLM_PROVIDER 'nope'"));
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

test('billing R1g: CODEX_SANDBOX refuses a cross-subscription PATH fallback', function (): void {
    [, $anc] = tr_nothing();
    $e = assert_throws(fn () => TransportResolver::decide(
        ['CODEX_SANDBOX' => 'seatbelt'],
        tr_on_path('claude'),
        $anc,
    ));
    assert_true($e instanceof TransportUnavailable);
    assert_contains("Resolved codex-cli", $e->getMessage());
    assert_contains("'codex' is not on PATH", $e->getMessage());
});

test('billing R1g: CODEX_SANDBOX_NETWORK_DISABLED refuses a cross-subscription PATH fallback', function (): void {
    [, $anc] = tr_nothing();
    $e = assert_throws(fn () => TransportResolver::decide(
        ['CODEX_SANDBOX_NETWORK_DISABLED' => '1'],
        tr_on_path('claude'),
        $anc,
    ));
    assert_true($e instanceof TransportUnavailable);
    assert_contains("Resolved codex-cli", $e->getMessage());
    assert_contains("'codex' is not on PATH", $e->getMessage());
});

test('billing R1g: a blank configured-provider credential falls through to the subscription ladder', function (): void {
    [, $anc] = tr_nothing();
    $c = TransportResolver::decide(
        ['ANTHROPIC_API_KEY' => ''],
        tr_on_path('claude'),
        $anc,
    );
    assert_eq(TransportChoice::KIND_CLAUDE_CLI, $c->kind);
    assert_contains('only harness on PATH', $c->reason);
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

test('billing N7: Codex ancestry overrides an inherited Claude fingerprint', function (): void {
    $c = TransportResolver::decide(
        ['CLAUDECODE' => '1'],
        tr_on_path('codex'),
        static fn (): array => ['php', 'codex', 'zsh'],
    );
    assert_eq(TransportChoice::KIND_CODEX_CLI, $c->kind);
    assert_eq('process ancestry found codex (inherited CLAUDECODE=1 ignored)', $c->reason);
});

test('billing N7: mixed-case Claude ancestry overrides an inherited Codex marker', function (): void {
    $c = TransportResolver::decide(
        ['CODEX_THREAD_ID' => 'inherited'],
        tr_on_path('claude', 'codex'),
        static fn (): array => ['php', 'Claude', 'zsh'],
    );
    assert_eq(TransportChoice::KIND_CLAUDE_CLI, $c->kind);
    assert_eq('process ancestry found Claude (inherited CODEX_THREAD_ID present ignored)', $c->reason);
});

test('billing N7: one ancestry kind breaks a multiple-fingerprint tie', function (): void {
    $c = TransportResolver::decide(
        ['CLAUDECODE' => '1', 'CODEX_THREAD_ID' => 'inherited'],
        tr_on_path('claude', 'codex', 'grok'),
        static fn (): array => ['php', 'grok', 'zsh'],
    );
    assert_eq(TransportChoice::KIND_GROK_CLI, $c->kind);
    assert_eq(
        'process ancestry found grok (inherited CLAUDECODE=1 ignored; '
        . 'inherited CODEX_THREAD_ID present ignored)',
        $c->reason,
    );
});

test('billing N7: two ancestry kinds remain ambiguous despite a fingerprint', function (): void {
    $e = assert_throws(fn () => TransportResolver::decide(
        ['CLAUDECODE' => '1'],
        tr_on_path('claude', 'codex', 'grok'),
        static fn (): array => ['php', 'codex', 'grok', 'zsh'],
    ));
    assert_true($e instanceof TransportUnavailable);
    assert_contains('Ambiguous transport', $e->getMessage());
    assert_contains('codex', $e->getMessage());
    assert_contains('grok', $e->getMessage());
    assert_contains('process ancestry identifies multiple harnesses', $e->getMessage());
});

test('billing N7: same-kind ancestry remains the winning reason', function (): void {
    $c = TransportResolver::decide(
        ['CLAUDECODE' => '1'],
        tr_on_path('claude'),
        static fn (): array => ['php', 'Claude', 'zsh'],
    );
    assert_eq(TransportChoice::KIND_CLAUDE_CLI, $c->kind);
    assert_eq('process ancestry found Claude', $c->reason);
    assert_true(!str_contains($c->reason, 'ignored'));
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

test('billing exact-value discipline: every near-miss fingerprint falls through', function (): void {
    [, $anc] = tr_nothing();
    $nearMisses = [
        ['OPENCODE' => '0'],
        ['OPENCODE' => 'true'],
        ['PI_CODING_AGENT' => '1'],
        ['PI_CODING_AGENT' => 'TRUE'],
        ['CLAUDECODE' => '01'],
        ['CLAUDECODE' => ' 1 '],
    ];
    foreach ($nearMisses as $env) {
        $c = TransportResolver::decide($env, tr_on_path('claude'), $anc);
        assert_eq(TransportChoice::KIND_CLAUDE_CLI, $c->kind);
        assert_contains('only harness on PATH', $c->reason);
        assert_true(!str_contains($c->reason, 'fingerprint'));
    }
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

test('billing R1g: a blank Codex marker is not a harness fingerprint', function (): void {
    [, $anc] = tr_nothing();
    $c = TransportResolver::decide(
        ['CODEX_THREAD_ID' => ''],
        tr_on_path('claude'),
        $anc,
    );
    assert_eq(TransportChoice::KIND_CLAUDE_CLI, $c->kind);
    assert_contains('only harness on PATH', $c->reason);
});

test('billing R1g: rung 3 refuses a fingerprint whose harness binary is missing', function (): void {
    [, $anc] = tr_nothing();
    $e = assert_throws(fn () => TransportResolver::decide(
        ['CODEX_THREAD_ID' => 'current'],
        tr_on_path('claude'),
        $anc,
    ));
    assert_true($e instanceof TransportUnavailable);
    assert_contains("Resolved codex-cli", $e->getMessage());
    assert_contains("'codex' is not on PATH", $e->getMessage());
});

test('rung 4: process ancestry is codex\'s real signal under danger-full-access', function (): void {
    $c = TransportResolver::decide([], tr_on_path('codex'), static fn (): array => ['php', 'codex', 'zsh']);
    assert_eq(TransportChoice::KIND_CODEX_CLI, $c->kind);
    assert_contains('ancestry', $c->reason);
});

test('billing R1g: rung 4 refuses ancestry whose harness binary is missing', function (): void {
    $e = assert_throws(fn () => TransportResolver::decide(
        [],
        tr_on_path('claude'),
        static fn (): array => ['php', 'codex', 'zsh'],
    ));
    assert_true($e instanceof TransportUnavailable);
    assert_contains("Resolved codex-cli", $e->getMessage());
    assert_contains("'codex' is not on PATH", $e->getMessage());
});

test('billing M2: rung 4 refuses both exact ancestry-order ambiguity reproductions', function (): void {
    foreach ([
        ['php', 'claude', 'codex'],
        ['php', 'codex', 'claude'],
    ] as $ancestry) {
        $e = assert_throws(fn () => TransportResolver::decide(
            [],
            tr_on_path('claude', 'codex'),
            static fn (): array => $ancestry,
        ));
        assert_true($e instanceof TransportUnavailable);
        assert_contains('Ambiguous transport', $e->getMessage());
        assert_contains('claude', $e->getMessage());
        assert_contains('codex', $e->getMessage());
        assert_contains('process ancestry', $e->getMessage());
        assert_contains('SITE_BUILD_LLM', $e->getMessage());
    }
});

test('billing M2: repeated ancestry names for one harness remain unambiguous', function (): void {
    $c = TransportResolver::decide(
        [],
        tr_on_path('claude'),
        static fn (): array => ['php', 'claude', 'claude'],
    );
    assert_eq(TransportChoice::KIND_CLAUDE_CLI, $c->kind);
    assert_contains("process ancestry found 'claude'", $c->reason);
});

test('billing N6: mixed-case ancestry names still identify their harness', function (): void {
    $c = TransportResolver::decide(
        [],
        tr_on_path('claude'),
        static fn (): array => ['php', 'Claude', 'zsh'],
    );
    assert_eq(TransportChoice::KIND_CLAUDE_CLI, $c->kind);
    assert_contains("process ancestry found 'Claude'", $c->reason);
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

test('billing R1g: truncated ancestry is disclosed on a sole-PATH fallback', function (): void {
    $ancestry = array_fill(0, 12, 'php');
    $ancestry[] = 'ancestry walk truncated at depth 12';

    $c = TransportResolver::decide(
        [],
        tr_on_path('claude'),
        static fn (): array => $ancestry,
    );

    assert_eq(TransportChoice::KIND_CLAUDE_CLI, $c->kind);
    assert_eq(
        "'claude' is the only harness on PATH (ancestry walk truncated at depth 12)",
        $c->reason,
    );
});

test('rung 6: nothing usable names the fix', function (): void {
    [$path, $anc] = tr_nothing();
    $e = assert_throws(fn () => TransportResolver::decide([], $path, $anc));
    assert_true($e instanceof TransportUnavailable);
    assert_contains('SITE_BUILD_LLM', $e->getMessage());
    assert_contains('ANTHROPIC_API_KEY', $e->getMessage());
});

test('billing purity: decide exercises every rung with ambient I/O functions disabled', function (): void {
    $root = dirname(__DIR__, 2);
    $code = 'require ' . var_export($root . '/src/TransportChoice.php', true) . '; '
        . 'require ' . var_export($root . '/src/TransportUnavailable.php', true) . '; '
        . 'require ' . var_export($root . '/src/TransportResolver.php', true) . '; '
        . '$none = static fn (string $name): ?string => null; '
        . '$emptyAnc = static fn (): array => []; '
        . '$path = static fn (string ...$names): callable => '
        . 'static fn (string $name): ?string => in_array($name, $names, true) ? "/fake/bin/{$name}" : null; '
        . '$assertKind = static function ($actual, string $expected): void { '
        . 'if ($actual->kind !== $expected) { throw new RuntimeException("wrong kind"); } }; '
        . '$assertKind(\\Automattic\\SiteBuild\\TransportResolver::decide('
        . '["SITE_BUILD_LLM" => "claude-cli"], $path("claude"), $emptyAnc), '
        . '\\Automattic\\SiteBuild\\TransportChoice::KIND_CLAUDE_CLI); '
        . '$assertKind(\\Automattic\\SiteBuild\\TransportResolver::decide('
        . '["ANTHROPIC_API_KEY" => "secret"], $none, $emptyAnc), '
        . '\\Automattic\\SiteBuild\\TransportChoice::KIND_API); '
        . '$assertKind(\\Automattic\\SiteBuild\\TransportResolver::decide('
        . '["CLAUDECODE" => "1"], $path("claude"), $emptyAnc), '
        . '\\Automattic\\SiteBuild\\TransportChoice::KIND_CLAUDE_CLI); '
        . '$assertKind(\\Automattic\\SiteBuild\\TransportResolver::decide('
        . '[], $path("codex"), static fn (): array => ["php", "codex"]), '
        . '\\Automattic\\SiteBuild\\TransportChoice::KIND_CODEX_CLI); '
        . '$assertKind(\\Automattic\\SiteBuild\\TransportResolver::decide([], $path("grok"), $emptyAnc), '
        . '\\Automattic\\SiteBuild\\TransportChoice::KIND_GROK_CLI); '
        . 'try { \\Automattic\\SiteBuild\\TransportResolver::decide([], $none, $emptyAnc); '
        . 'throw new RuntimeException("rung 6 did not throw"); } '
        . 'catch (\\Automattic\\SiteBuild\\TransportUnavailable $e) {} '
        . 'echo "PURE";';
    [$output, $status] = tr_php($code, [
        'disable_functions=getenv,shell_exec,exec,proc_open,ini_get,is_file,is_executable,posix_getppid',
    ]);
    assert_eq(0, $status, $output);
    assert_eq('PURE', $output);
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

test('billing R1g: build invokes the subprocess availability guard for harness choices', function (): void {
    $autoload = dirname(__DIR__, 2) . '/autoload.php';
    $code = 'require ' . var_export($autoload, true) . '; '
        . '$c = new \\Automattic\\SiteBuild\\TransportChoice('
        . '\\Automattic\\SiteBuild\\TransportChoice::KIND_CLAUDE_CLI, "manual", PHP_BINARY); '
        . 'try { \\Automattic\\SiteBuild\\TransportResolver::build($c); echo "NO_ERROR"; } '
        . 'catch (Throwable $e) { echo get_class($e) . " | " . $e->getMessage(); }';
    [$output, $status] = tr_php($code, ['disable_functions=proc_open']);
    assert_eq(0, $status, $output);
    assert_contains(TransportUnavailable::class, $output);
    assert_contains('proc_open', $output);
    assert_true(!str_contains($output, 'not yet implemented'));
    assert_true(!str_contains($output, 'NO_ERROR'));
});

test('billing C5a: build has no ignored model parameter', function (): void {
    $method = new ReflectionMethod(TransportResolver::class, 'build');
    assert_eq(3, $method->getNumberOfParameters());
    assert_eq('choice', $method->getParameters()[0]->getName());
    assert_eq('apiFactory', $method->getParameters()[1]->getName());
    assert_true($method->getParameters()[1]->isOptional());
    assert_eq('harnessModel', $method->getParameters()[2]->getName());
    assert_true($method->getParameters()[2]->isOptional());
});

test('billing C5b: API build without a factory tells the host to supply one', function (): void {
    $autoload = dirname(__DIR__, 2) . '/autoload.php';
    $code = 'require ' . var_export($autoload, true) . '; '
        . '$c = new \\Automattic\\SiteBuild\\TransportChoice('
        . '\\Automattic\\SiteBuild\\TransportChoice::KIND_API, "explicit", null, "anthropic"); '
        . 'try { \\Automattic\\SiteBuild\\TransportResolver::build($c); echo "NO_ERROR"; } '
        . 'catch (Throwable $e) { echo get_class($e) . " | " . $e->getMessage(); }';
    [$output, $status] = tr_php($code);
    assert_eq(0, $status);
    assert_contains(TransportUnavailable::class, $output);
    assert_contains('host must supply an API factory', $output);
    assert_true(!str_contains($output, 'src/bootstrap.php'));
    assert_true(!str_contains($output, 'NO_ERROR'));
});

test('billing N1: API build with an injected factory refuses a providerless manual choice', function (): void {
    $bootstrap = dirname(__DIR__, 2) . '/src/bootstrap.php';
    $code = 'require ' . var_export($bootstrap, true) . '; '
        . '$c = new \\Automattic\\SiteBuild\\TransportChoice('
        . '\\Automattic\\SiteBuild\\TransportChoice::KIND_API, "manual"); '
        . 'try { \\Automattic\\SiteBuild\\TransportResolver::build($c, static fn (string $provider): '
        . '\\Automattic\\SiteBuild\\Llm => \\make_llm()); echo "NO_ERROR"; } '
        . 'catch (Throwable $e) { echo get_class($e) . " | " . $e->getMessage(); }';
    [$output, $status] = tr_php($code);
    assert_eq(0, $status, $output);
    assert_contains(TransportUnavailable::class, $output);
    assert_contains('no resolved provider', $output);
    assert_contains('TransportResolver::decide()', $output);
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
        . '\\Automattic\\SiteBuild\\TransportChoice::KIND_API, "explicit", null, "anthropic"); '
        . 'try { \\Automattic\\SiteBuild\\TransportResolver::build($c, static function (string $provider): '
        . '\\Automattic\\SiteBuild\\Llm { throw new \\Automattic\\SiteBuild\\TransportUnavailable('
        . '"{$provider} credentials are unavailable to the injected API factory."); }); echo "NO_ERROR"; } '
        . 'catch (Throwable $e) { echo get_class($e) . " | " . $e->getMessage(); }';
    [$output, $status] = tr_php($code);
    assert_eq(0, $status);
    assert_contains(TransportUnavailable::class, $output);
    assert_contains('anthropic credentials are unavailable to the injected API factory', $output);
    assert_true(!str_contains($output, 'Missing required env var'));
    assert_true(!str_contains($output, 'NO_ERROR'));
});

test('billing M4: API build passes the canonical grok provider to its factory', function (): void {
    $root = dirname(__DIR__, 2);
    $code = 'require ' . var_export($root . '/autoload.php', true) . '; '
        . 'require ' . var_export($root . '/tests/FakeLlm.php', true) . '; '
        . '$c = \\Automattic\\SiteBuild\\TransportResolver::decide('
        . '["LLM_PROVIDER" => "grok", "XAI_API_KEY" => "test-only-key"], '
        . 'static fn (string $name): ?string => null, static fn (): array => []); '
        . '$fake = new \\Automattic\\SiteBuild\\Tests\\FakeLlm(); $factoryProvider = null; '
        . 'try { $llm = \\Automattic\\SiteBuild\\TransportResolver::build($c, '
        . 'static function (string $provider) use ($fake, &$factoryProvider): '
        . '\\Automattic\\SiteBuild\\Llm { $factoryProvider = $provider; return $fake; }); '
        . 'echo get_class($llm) . " | " . $factoryProvider; } '
        . 'catch (Throwable $e) { echo "ERROR | " . get_class($e) . " | " . $e->getMessage(); }';
    [$output, $status] = tr_php($code);
    assert_eq(0, $status, $output);
    assert_contains('FakeLlm | xai', $output);
    assert_true(!str_contains($output, 'ERROR'));
});

test('billing R1g: API build canonicalizes every host-supplied provider spelling', function (): void {
    $root = dirname(__DIR__, 2);
    $code = 'require ' . var_export($root . '/autoload.php', true) . '; '
        . 'require ' . var_export($root . '/tests/FakeLlm.php', true) . '; '
        . '$fake = new \\Automattic\\SiteBuild\\Tests\\FakeLlm(); $seen = []; '
        . 'try { foreach (["grok", "ANTHROPIC", " xai "] as $authored) { '
        . '$choice = new \\Automattic\\SiteBuild\\TransportChoice('
        . '\\Automattic\\SiteBuild\\TransportChoice::KIND_API, "host choice", null, $authored); '
        . '$llm = \\Automattic\\SiteBuild\\TransportResolver::build($choice, '
        . 'static function (string $provider) use ($fake, &$seen): \\Automattic\\SiteBuild\\Llm { '
        . '$seen[] = $provider; return $fake; }); '
        . 'if ($llm !== $fake) { throw new \\RuntimeException("factory double replaced"); } '
        . '} echo implode(" | ", $seen); } '
        . 'catch (Throwable $e) { echo "ERROR | " . get_class($e) . " | " . $e->getMessage(); }';

    [$output, $status] = tr_php($code);
    assert_eq(0, $status, $output);
    assert_eq('xai | anthropic | xai', $output);
});

test('billing R1g: API build refuses an unknown host-supplied provider', function (): void {
    $factoryCalled = false;
    $choice = new TransportChoice(TransportChoice::KIND_API, 'host choice', null, 'bogus');
    $e = assert_throws(fn () => TransportResolver::build(
        $choice,
        static function (string $provider) use (&$factoryCalled): \Automattic\SiteBuild\Llm {
            $factoryCalled = true;
            return new \Automattic\SiteBuild\Tests\FakeLlm();
        },
    ));
    assert_true($e instanceof TransportUnavailable);
    assert_contains("Unknown resolved API provider 'bogus'", $e->getMessage());
    assert_true(!$factoryCalled);
});

test('billing N1: audit provider matches the provider passed to the factory', function (): void {
    $root = dirname(__DIR__, 2);
    $code = 'require ' . var_export($root . '/autoload.php', true) . '; '
        . 'require ' . var_export($root . '/tests/FakeLlm.php', true) . '; '
        . '$c = \\Automattic\\SiteBuild\\TransportResolver::decide('
        . '["OPEN_ROUTER_API_KEY" => "test-only-key"], '
        . 'static fn (string $name): ?string => null, static fn (): array => [], "openrouter"); '
        . '$line = \\Automattic\\SiteBuild\\TransportResolver::describe($c); '
        . '$fake = new \\Automattic\\SiteBuild\\Tests\\FakeLlm(); $factoryProvider = null; '
        . '$llm = \\Automattic\\SiteBuild\\TransportResolver::build($c, '
        . 'static function (string $provider) use ($fake, &$factoryProvider): '
        . '\\Automattic\\SiteBuild\\Llm { $factoryProvider = $provider; return $fake; }); '
        . 'echo $c->provider . " | " . $factoryProvider . " | " . $line;';
    [$output, $status] = tr_php($code);
    assert_eq(0, $status, $output);
    assert_contains('openrouter | openrouter | Transport: api', $output);
    assert_contains('provider: openrouter', $output);
});

test('billing N1: API build leaves the ambient provider unchanged', function (): void {
    $root = dirname(__DIR__, 2);
    $code = 'require ' . var_export($root . '/autoload.php', true) . '; '
        . 'require ' . var_export($root . '/tests/FakeLlm.php', true) . '; '
        . 'putenv("LLM_PROVIDER=anthropic"); '
        . '$c = new \\Automattic\\SiteBuild\\TransportChoice('
        . '\\Automattic\\SiteBuild\\TransportChoice::KIND_API, '
        . '"OPEN_ROUTER_API_KEY present (provider: openrouter)", null, "openrouter"); '
        . '$fake = new \\Automattic\\SiteBuild\\Tests\\FakeLlm(); $factoryProvider = null; '
        . '$llm = \\Automattic\\SiteBuild\\TransportResolver::build($c, '
        . 'static function (string $provider) use ($fake, &$factoryProvider): '
        . '\\Automattic\\SiteBuild\\Llm { $factoryProvider = $provider; return $fake; }); '
        . 'echo get_class($llm) . " | " . $factoryProvider . " | " . getenv("LLM_PROVIDER");';
    [$output, $status] = tr_php($code);
    assert_eq(0, $status, $output);
    assert_contains('FakeLlm | openrouter | anthropic', $output);
});

test('billing host layering: src classes do not reference bootstrap-only globals', function (): void {
    $src = dirname(__DIR__, 2) . '/src';
    $forbidden = [
        'make_llm',
        'make_api_llm',
        'default_llm_model',
        'step_models',
        'repo_path',
        'make_site_builder',
        'resolve_llm',
    ];
    $hits = [];

    foreach (glob($src . '/*.php') ?: [] as $file) {
        if (basename($file) === 'bootstrap.php') {
            continue;
        }
        $tokens = token_get_all((string) file_get_contents($file));
        $code = '';
        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        foreach ($forbidden as $name) {
            if (preg_match('/\\b' . preg_quote($name, '/') . '\\b/', $code) === 1) {
                $hits[] = basename($file) . ':' . $name;
            }
        }
    }

    assert_eq([], $hits, 'src classes must not depend on CLI bootstrap globals');
});

test('billing host layering: build returns an injected Llm without loading bootstrap', function (): void {
    $root = dirname(__DIR__, 2);
    $code = 'require ' . var_export($root . '/autoload.php', true) . '; '
        . 'require ' . var_export($root . '/tests/FakeLlm.php', true) . '; '
        . '$c = new \\Automattic\\SiteBuild\\TransportChoice('
        . '\\Automattic\\SiteBuild\\TransportChoice::KIND_API, "host injection", null, "anthropic"); '
        . '$fake = new \\Automattic\\SiteBuild\\Tests\\FakeLlm(); '
        . '$llm = \\Automattic\\SiteBuild\\TransportResolver::build($c, static fn (string $provider): '
        . '\\Automattic\\SiteBuild\\Llm => $fake); '
        . 'echo $llm === $fake ? "INJECTED" : "WRONG";';
    [$output, $status] = tr_php($code);
    assert_eq(0, $status, $output);
    assert_eq('INJECTED', $output);
});

test('billing Q9: host-2 factory receives the resolved provider and returns its double', function (): void {
    $root = dirname(__DIR__, 2);
    $code = 'require ' . var_export($root . '/autoload.php', true) . '; '
        . 'require ' . var_export($root . '/tests/FakeLlm.php', true) . '; '
        . 'foreach (["LLM_PROVIDER", "ANTHROPIC_API_KEY", "XAI_API_KEY", "OPENAI_API_KEY", '
        . '"OPENROUTER_API_KEY", "OPEN_ROUTER_API_KEY"] as $key) { putenv($key); } '
        . '$bootstrapFunction = "Automattic\\\\SiteBuild\\\\make_llm"; '
        . '$before = function_exists($bootstrapFunction) ? "BOOTSTRAP" : "NO_BOOTSTRAP"; '
        . '$c = new \\Automattic\\SiteBuild\\TransportChoice('
        . '\\Automattic\\SiteBuild\\TransportChoice::KIND_API, "host-2", null, "openrouter"); '
        . '$fake = new \\Automattic\\SiteBuild\\Tests\\FakeLlm(); '
        . '$factoryProvider = null; $factoryCalls = 0; '
        . '$llm = \\Automattic\\SiteBuild\\TransportResolver::build($c, '
        . 'static function (string $provider) use ($fake, &$factoryProvider, &$factoryCalls): '
        . '\\Automattic\\SiteBuild\\Llm { $factoryCalls++; $factoryProvider = $provider; return $fake; }); '
        . '$after = function_exists($bootstrapFunction) ? "BOOTSTRAP" : "NO_BOOTSTRAP"; '
        . 'echo $before . " | " . ($llm === $fake ? "INJECTED" : "WRONG") . " | " '
        . '. $factoryProvider . " | " . $factoryCalls . " | " . $after;';
    [$output, $status] = tr_php($code);
    assert_eq(0, $status, $output);
    assert_eq('NO_BOOTSTRAP | INJECTED | openrouter | 1 | NO_BOOTSTRAP', $output);
});

test('billing Q10: TransportResolver contains no putenv call', function (): void {
    $countPutenvCalls = static function (string $source): int {
        $tokens = token_get_all($source);
        $nameTokenIds = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];
        $calls = 0;

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || !in_array($token[0], $nameTokenIds, true)) {
                continue;
            }
            $parts = explode('\\', strtolower(ltrim($token[1], '\\')));
            if (end($parts) !== 'putenv') {
                continue;
            }

            for ($next = $index + 1; isset($tokens[$next]); $next++) {
                if (is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                    continue;
                }
                if ($tokens[$next] === '(') {
                    $calls++;
                }
                break;
            }
        }

        return $calls;
    };

    assert_eq(3, $countPutenvCalls('<?php putenv("A=1"); \\putenv("B=2"); namespace\\putenv("C=3");'));
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/TransportResolver.php');
    assert_eq(0, $countPutenvCalls($source), 'TransportResolver must not mutate process environment');
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

test('billing R1g: describe uses the structural provider instead of stale reason text', function (): void {
    $line = TransportResolver::describe(new TransportChoice(
        TransportChoice::KIND_API,
        'copied (provider: anthropic) via host',
        null,
        'openai',
    ));

    assert_contains('provider: openai', $line);
    assert_contains('resolved by copied via host', $line);
    assert_true(!str_contains($line, 'provider: anthropic'));
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

test('billing ancestry guard uses shell_exec availability, not proc_open availability', function (): void {
    $autoload = dirname(__DIR__, 2) . '/autoload.php';
    $code = 'require ' . var_export($autoload, true) . '; '
        . 'try { echo json_encode(\\Automattic\\SiteBuild\\TransportResolver::ancestry()); } '
        . 'catch (Throwable $e) { echo "ERROR | " . get_class($e) . " | " . $e->getMessage(); }';

    [$withoutShellExec, $shellExecStatus] = tr_php($code, ['disable_functions=shell_exec']);
    [$withoutProcOpen, $procOpenStatus] = tr_php($code, ['disable_functions=proc_open']);

    assert_eq(0, $shellExecStatus, $withoutShellExec);
    assert_eq('[]', $withoutShellExec);
    assert_eq(0, $procOpenStatus, $withoutProcOpen);
    $ancestry = json_decode($withoutProcOpen, true);
    assert_true(is_array($ancestry) && $ancestry !== [], $withoutProcOpen);
});

test('billing ancestry rejects a non-numeric parent pid from ps', function (): void {
    with_temp_dir('fake-ps-', function (string $dir): void {
        $ps = $dir . '/ps';
        file_put_contents($ps, "#!/bin/sh\nprintf '%s\\n' 'not-a-pid php'\n");
        chmod($ps, 0755);
        $oldPath = getenv('PATH');
        try {
            putenv("PATH={$dir}");
            assert_eq([], TransportResolver::ancestry());
        } finally {
            $oldPath === false ? putenv('PATH') : putenv("PATH={$oldPath}");
        }
    });
});

test('billing R1g: injected ancestry walker reports a bounded deep-tree truncation', function (): void {
    $autoload = dirname(__DIR__, 2) . '/autoload.php';
    $code = 'namespace Automattic\\SiteBuild { '
        . '$GLOBALS["resolver_walker_calls"] = 0; '
        . 'function posix_getppid(): int { return 100; } '
        . 'function getmypid(): int { return 100; } '
        . 'function shell_exec(string $command): string|false { '
        . '$GLOBALS["resolver_walker_calls"]++; '
        . 'if ($GLOBALS["resolver_walker_calls"] > 13) { return ""; } '
        . 'if (preg_match("/ -p ([0-9]+)/", $command, $matches) !== 1) { '
        . 'throw new \\RuntimeException("missing walker pid"); } '
        . '$pid = (int) $matches[1]; '
        . 'return ($pid + 1) . " process-" . $GLOBALS["resolver_walker_calls"]; '
        . '} } namespace { require ' . var_export($autoload, true) . '; '
        . '$names = \\Automattic\\SiteBuild\\TransportResolver::ancestry(); '
        . 'echo count($names) . " | " . $GLOBALS["resolver_walker_calls"] . " | " . end($names); }';

    [$output, $status] = tr_php($code);
    assert_eq(0, $status, $output);
    assert_eq('13 | 12 | ancestry walk truncated at depth 12', $output);
});

test('billing R1g: injected ancestry walker stops on a self-parent cycle', function (): void {
    $autoload = dirname(__DIR__, 2) . '/autoload.php';
    $code = 'namespace Automattic\\SiteBuild { '
        . '$GLOBALS["resolver_walker_calls"] = 0; '
        . 'function posix_getppid(): int { return 100; } '
        . 'function getmypid(): int { return 100; } '
        . 'function shell_exec(string $command): string|false { '
        . '$GLOBALS["resolver_walker_calls"]++; '
        . 'if ($GLOBALS["resolver_walker_calls"] > 3) { return ""; } '
        . 'if (preg_match("/ -p ([0-9]+)/", $command, $matches) !== 1) { '
        . 'throw new \\RuntimeException("missing walker pid"); } '
        . 'return $matches[1] . " cycle"; '
        . '} } namespace { require ' . var_export($autoload, true) . '; '
        . '$names = \\Automattic\\SiteBuild\\TransportResolver::ancestry(); '
        . 'echo implode(",", $names) . " | " . $GLOBALS["resolver_walker_calls"]; }';

    [$output, $status] = tr_php($code);
    assert_eq(0, $status, $output);
    assert_eq('cycle | 1', $output);
});

test('credentialVariableFor canonicalizes each API provider and the grok alias', function (): void {
    assert_eq('ANTHROPIC_API_KEY', TransportResolver::credentialVariableFor('anthropic'));
    assert_eq('OPENAI_API_KEY', TransportResolver::credentialVariableFor('openai'));
    assert_eq('XAI_API_KEY', TransportResolver::credentialVariableFor('xai'));
    assert_eq('XAI_API_KEY', TransportResolver::credentialVariableFor('grok'));
    assert_eq('OPENROUTER_API_KEY', TransportResolver::credentialVariableFor('openrouter'));
});

test('V5 make_llm explicit provider wins over ambient LLM_PROVIDER', function (): void {
    $bootstrap = dirname(__DIR__, 2) . '/src/bootstrap.php';
    $code = 'putenv("LLM_PROVIDER=anthropic"); '
        . 'putenv("ANTHROPIC_API_KEY=test-anthropic"); '
        . 'putenv("OPENROUTER_API_KEY=test-openrouter"); '
        . 'require ' . var_export($bootstrap, true) . '; '
        . '$llm = make_llm("openrouter"); '
        . '$r = new ReflectionClass($llm); $model = $r->getProperty("model")->getValue($llm); '
        . 'echo get_class($llm) . " | " . (method_exists($llm, "endpoint") ? $llm->endpoint() : "NO_ENDPOINT") '
        . '. " | " . $model;';

    [$output, $status] = tr_php($code);
    assert_eq(0, $status, $output);
    assert_eq(
        'Automattic\\SiteBuild\\OpenAiCompatibleClient | https://openrouter.ai/api/v1/chat/completions '
        . '| moonshotai/kimi-k3',
        $output,
    );
});

test('V5 make_llm without provider preserves ambient behavior', function (): void {
    $bootstrap = dirname(__DIR__, 2) . '/src/bootstrap.php';
    $code = 'putenv("LLM_PROVIDER=anthropic"); '
        . 'putenv("ANTHROPIC_API_KEY=test-anthropic"); '
        . 'require ' . var_export($bootstrap, true) . '; '
        . 'echo get_class(make_llm());';

    [$output, $status] = tr_php($code);
    assert_eq(0, $status, $output);
    assert_eq('Automattic\\SiteBuild\\AnthropicClient', $output);
});

test('V6 resolve_llm missing credential throws catchable TransportUnavailable', function (): void {
    $bootstrap = dirname(__DIR__, 2) . '/src/bootstrap.php';
    $code = 'require ' . var_export($bootstrap, true) . '; '
        . 'foreach (["ANTHROPIC_API_KEY", "OPENAI_API_KEY", "XAI_API_KEY", '
        . '"OPENROUTER_API_KEY", "OPEN_ROUTER_API_KEY"] as $key) { putenv($key); } '
        . 'putenv("SITE_BUILD_LLM=api"); putenv("LLM_PROVIDER=anthropic"); '
        . '$r = new ReflectionClass(\\Automattic\\SiteBuild\\Env::class); '
        . '$p = $r->getProperty("vars"); $p->setValue(null, []); '
        . 'try { resolve_llm(); echo "NO_ERROR"; } '
        . 'catch (Throwable $e) { echo get_class($e) . " | " . $e->getMessage(); }';

    [$output, $status] = tr_php($code);
    assert_eq(0, $status, $output);
    assert_contains(TransportUnavailable::class, $output);
    assert_contains('anthropic', $output);
    assert_contains('ANTHROPIC_API_KEY', $output);
    assert_true(!str_contains($output, 'Missing required env var'));
    assert_true(!str_contains($output, 'NO_ERROR'));
});

test('V7 claude-cli build without a harness model fails by name', function (): void {
    $choice = new TransportChoice(TransportChoice::KIND_CLAUDE_CLI, 'unit', PHP_BINARY);
    $error = assert_throws(fn () => TransportResolver::build($choice));
    assert_true($error instanceof TransportUnavailable);
    assert_contains('claude-cli', $error->getMessage());
    assert_contains('harness model', $error->getMessage());
});

test('V7 claude-cli build returns the real ClaudeCliLlm transport', function (): void {
    $choice = new TransportChoice(TransportChoice::KIND_CLAUDE_CLI, 'unit', PHP_BINARY);
    $llm = TransportResolver::build($choice, harnessModel: 'claude-haiku-4-5');
    assert_true($llm instanceof \Automattic\SiteBuild\ClaudeCliLlm);
});

test('V8 resolve_llm narrates exactly one audit line naming the built kind', function (): void {
    $bootstrap = dirname(__DIR__, 2) . '/src/bootstrap.php';
    $code = 'putenv("SITE_BUILD_LLM=api"); putenv("LLM_PROVIDER=anthropic"); '
        . 'putenv("ANTHROPIC_API_KEY=test-anthropic"); '
        . 'require ' . var_export($bootstrap, true) . '; '
        . '$stream = fopen("php://memory", "w+"); '
        . '\\Automattic\\SiteBuild\\Narrator::setStream($stream); '
        . '$llm = resolve_llm(); rewind($stream); $audit = stream_get_contents($stream); '
        . 'echo get_class($llm) . " | " . substr_count($audit, "Transport:") . " | " . trim($audit);';

    [$output, $status] = tr_php($code);
    assert_eq(0, $status, $output);
    assert_contains('Automattic\\SiteBuild\\AnthropicClient | 1 | Transport: api', $output);
});

test('V10 all four bin entry points call resolve_llm and none call make_llm', function (): void {
    $root = dirname(__DIR__, 2);
    $counts = ['make_llm' => 0, 'resolve_llm' => 0];
    foreach (['build.php', 'eval.php', 'images.php', 'llm-conformance.php'] as $name) {
        $tokens = token_get_all((string) file_get_contents($root . '/bin/' . $name));
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_STRING || !array_key_exists($token[1], $counts)) {
                continue;
            }
            for ($next = $index + 1; isset($tokens[$next]); $next++) {
                if (is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                    continue;
                }
                if ($tokens[$next] === '(') {
                    $counts[$token[1]]++;
                }
                break;
            }
        }
    }
    assert_eq(['make_llm' => 0, 'resolve_llm' => 4], $counts);
});

test('resolve_llm factory passes its resolved provider to make_llm', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/bootstrap.php');
    assert_true(
        preg_match('/\\bmake_llm\\(\\$provider\\)/', $source) === 1,
        'resolve_llm must not discard the provider passed to its API factory',
    );
});

test('V14 resolve_llm sees Env-loaded credentials before inherited Codex markers', function (): void {
    with_temp_dir('resolve-env-', function (string $dir): void {
        $codex = $dir . '/codex';
        file_put_contents($codex, "#!/bin/sh\nexit 0\n");
        chmod($codex, 0755);
        $bootstrap = dirname(__DIR__, 2) . '/src/bootstrap.php';
        $code = 'putenv("SITE_BUILD_LLM"); putenv("LLM_PROVIDER"); putenv("ANTHROPIC_API_KEY"); '
            . 'putenv("CODEX_THREAD_ID=unit-codex"); putenv("PATH=' . addslashes($dir) . '"); '
            . 'require ' . var_export($bootstrap, true) . '; '
            . '$r = new ReflectionClass(\\Automattic\\SiteBuild\\Env::class); '
            . '$p = $r->getProperty("vars"); $p->setValue(null, ["ANTHROPIC_API_KEY" => "from-dotenv"]); '
            . '$stream = fopen("php://memory", "w+"); '
            . '\\Automattic\\SiteBuild\\Narrator::setStream($stream); '
            . 'try { $llm = resolve_llm(); rewind($stream); '
            . 'echo get_class($llm) . " | " . trim((string) stream_get_contents($stream)); } '
            . 'catch (Throwable $e) { echo "ERROR | " . get_class($e) . " | " . $e->getMessage(); }';

        [$output, $status] = tr_php($code);
        assert_eq(0, $status, $output);
        assert_contains('Automattic\\SiteBuild\\AnthropicClient | Transport: api', $output);
        assert_true(!str_contains($output, 'codex-cli'), $output);
        assert_true(!str_contains($output, 'ERROR'), $output);
    });
});

test('V14 resolve_llm sees the Env-loaded OpenRouter credential alias', function (): void {
    $bootstrap = dirname(__DIR__, 2) . '/src/bootstrap.php';
    $code = 'foreach (["SITE_BUILD_LLM", "LLM_PROVIDER", "ANTHROPIC_API_KEY", "OPENAI_API_KEY", '
        . '"XAI_API_KEY", "OPENROUTER_API_KEY", "OPEN_ROUTER_API_KEY"] as $key) { putenv($key); } '
        . 'require ' . var_export($bootstrap, true) . '; '
        . '$r = new ReflectionClass(\\Automattic\\SiteBuild\\Env::class); '
        . '$p = $r->getProperty("vars"); $p->setValue(null, ['
        . '"LLM_PROVIDER" => "openrouter", "OPEN_ROUTER_API_KEY" => "from-dotenv-alias"]); '
        . '$stream = fopen("php://memory", "w+"); '
        . '\\Automattic\\SiteBuild\\Narrator::setStream($stream); '
        . 'try { $llm = resolve_llm(); rewind($stream); '
        . 'echo get_class($llm) . " | " . $llm->endpoint() . " | " . trim((string) stream_get_contents($stream)); } '
        . 'catch (Throwable $e) { echo "ERROR | " . get_class($e) . " | " . $e->getMessage(); }';

    [$output, $status] = tr_php($code);
    assert_eq(0, $status, $output);
    assert_contains('Automattic\\SiteBuild\\OpenAiCompatibleClient', $output);
    assert_contains('https://openrouter.ai/api/v1/chat/completions', $output);
    assert_contains('Transport: api', $output);
    assert_contains('provider: openrouter', $output);
    assert_true(!str_contains($output, 'ERROR'), $output);
});

test('W11 build wires codex-cli to CodexCliLlm without spawning', function (): void {
    with_temp_dir('built-codex-', function (string $dir): void {
        $binary = $dir . '/codex';
        assert_true(copy(dirname(__DIR__) . '/fixtures/fake-harness/spawn-counter.sh', $binary));
        assert_true(chmod($binary, 0755));
        $choice = new TransportChoice(TransportChoice::KIND_CODEX_CLI, 'unit', $binary);
        $llm = TransportResolver::build($choice, harnessModel: 'gpt-5.5');
        assert_true($llm instanceof \Automattic\SiteBuild\CodexCliLlm);
        assert_true(!file_exists($binary . '.count'));
    });
});

test('W11 build wires grok-cli to GrokCliLlm without spawning', function (): void {
    with_temp_dir('built-grok-', function (string $dir): void {
        $binary = $dir . '/grok';
        assert_true(copy(dirname(__DIR__) . '/fixtures/fake-harness/spawn-counter.sh', $binary));
        assert_true(chmod($binary, 0755));
        $choice = new TransportChoice(TransportChoice::KIND_GROK_CLI, 'unit', $binary);
        $llm = TransportResolver::build($choice, harnessModel: 'grok-4.5');
        assert_true($llm instanceof \Automattic\SiteBuild\GrokCliLlm);
        assert_true(!file_exists($binary . '.count'));
    });
});

test('W11 resolver source has no deferred harness stub left', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/TransportResolver.php');
    assert_true(!str_contains($source, 'not yet implemented'));
});

test('V15 explicit claude-cli override builds without spending', function (): void {
    with_temp_dir('resolved-claude-', function (string $dir): void {
        $claude = $dir . '/claude';
        file_put_contents($claude, "#!/bin/sh\nexit 0\n");
        chmod($claude, 0755);
        $bootstrap = dirname(__DIR__, 2) . '/src/bootstrap.php';
        $code = 'putenv("PATH=' . addslashes($dir) . '"); putenv("SITE_BUILD_LLM=claude-cli"); '
            . 'require ' . var_export($bootstrap, true) . '; '
            . 'try { echo get_class(resolve_llm()); } '
            . 'catch (Throwable $e) { echo "ERROR | " . get_class($e) . " | " . $e->getMessage(); }';

        [$output, $status] = tr_php($code);
        assert_eq(0, $status, $output);
        assert_contains('Automattic\\SiteBuild\\ClaudeCliLlm', $output);
        assert_true(!str_contains($output, 'not yet implemented'));
        assert_true(!str_contains($output, 'ERROR'));
    });
});

test('W17 claude-cli implies Anthropic models for transport and steps', function (): void {
    with_temp_dir('provider-claude-', function (string $dir): void {
        foreach (['claude', 'codex', 'grok'] as $name) {
            assert_true(copy(dirname(__DIR__) . '/fixtures/fake-harness/spawn-counter.sh', $dir . '/' . $name));
            assert_true(chmod($dir . '/' . $name, 0755));
        }
        [$output, $status, $details] = tr_harness_probe($dir, 'claude-cli', null);
        assert_eq(0, $status, $output);
        assert_true(is_array($details), $output);
        assert_eq('Automattic\\SiteBuild\\ClaudeCliLlm', $details['class']);
        assert_eq('anthropic', $details['provider']);
        assert_eq('claude-opus-5', $details['model']);
        assert_eq($details['model'], $details['default']);
        assert_true(str_starts_with($details['steps']['sections'], 'claude-'));
    });
});

test('W17 codex-cli implies OpenAI models for transport and steps', function (): void {
    with_temp_dir('provider-codex-', function (string $dir): void {
        foreach (['claude', 'codex', 'grok'] as $name) {
            assert_true(copy(dirname(__DIR__) . '/fixtures/fake-harness/spawn-counter.sh', $dir . '/' . $name));
            assert_true(chmod($dir . '/' . $name, 0755));
        }
        [$output, $status, $details] = tr_harness_probe($dir, 'codex-cli', null);
        assert_eq(0, $status, $output);
        assert_true(is_array($details), $output);
        assert_eq('Automattic\\SiteBuild\\CodexCliLlm', $details['class']);
        assert_eq('openai', $details['provider']);
        assert_eq('gpt-5.5', $details['model']);
        assert_eq($details['model'], $details['default']);
        assert_eq('gpt-5.4-mini', $details['steps']['refine-prompt']);
        assert_eq('gpt-5.5', $details['steps']['sections']);
        assert_true(!str_starts_with($details['steps']['sections'], 'claude-'));
    });
});

test('W17 grok-cli implies xAI models for transport and steps', function (): void {
    with_temp_dir('provider-grok-', function (string $dir): void {
        foreach (['claude', 'codex', 'grok'] as $name) {
            assert_true(copy(dirname(__DIR__) . '/fixtures/fake-harness/spawn-counter.sh', $dir . '/' . $name));
            assert_true(chmod($dir . '/' . $name, 0755));
        }
        [$output, $status, $details] = tr_harness_probe($dir, 'grok-cli', null);
        assert_eq(0, $status, $output);
        assert_true(is_array($details), $output);
        assert_eq('Automattic\\SiteBuild\\GrokCliLlm', $details['class']);
        assert_eq('xai', $details['provider']);
        assert_eq('grok-4.5', $details['model']);
        assert_eq($details['model'], $details['default']);
        assert_eq(['grok-4.5'], array_values(array_unique($details['steps'])));
    });
});

test('W18 incoherent explicit provider and harness pairing is refused', function (): void {
    with_temp_dir('provider-refusal-', function (string $dir): void {
        $binary = $dir . '/codex';
        assert_true(copy(dirname(__DIR__) . '/fixtures/fake-harness/spawn-counter.sh', $binary));
        assert_true(chmod($binary, 0755));
        [$output, $status, $details] = tr_harness_probe($dir, 'codex-cli', 'anthropic');
        assert_eq(0, $status, $output);
        assert_eq(null, $details);
        assert_contains(TransportUnavailable::class, $output);
        assert_contains('anthropic', $output);
        assert_contains('codex-cli', $output);
        assert_contains('openai', $output);
        assert_true(!file_exists($binary . '.count'));
    });
});

test('W18 incoherent provider from .env and harness pairing is refused', function (): void {
    with_temp_dir('provider-dotenv-refusal-', function (string $dir): void {
        $binary = $dir . '/codex';
        assert_true(copy(dirname(__DIR__) . '/fixtures/fake-harness/spawn-counter.sh', $binary));
        assert_true(chmod($binary, 0755));
        [$output, $status, $details] = tr_harness_probe($dir, 'codex-cli', null, 'anthropic');
        assert_eq(0, $status, $output);
        assert_eq(null, $details);
        assert_contains(TransportUnavailable::class, $output);
        assert_contains('anthropic', $output);
        assert_contains('codex-cli', $output);
        assert_contains('openai', $output);
        assert_true(!file_exists($binary . '.count'));
    });
});

test('W18 coherent explicit provider and harness pairing proceeds', function (): void {
    with_temp_dir('provider-coherent-', function (string $dir): void {
        $binary = $dir . '/codex';
        assert_true(copy(dirname(__DIR__) . '/fixtures/fake-harness/spawn-counter.sh', $binary));
        assert_true(chmod($binary, 0755));
        [$output, $status, $details] = tr_harness_probe($dir, 'codex-cli', 'openai');
        assert_eq(0, $status, $output);
        assert_true(is_array($details), $output);
        assert_eq('Automattic\\SiteBuild\\CodexCliLlm', $details['class']);
        assert_eq('openai', $details['provider']);
        assert_eq('gpt-5.5', $details['model']);
        assert_true(!file_exists($binary . '.count'));
    });
});

test('W18 coherent grok provider alias and grok harness pairing proceeds', function (): void {
    with_temp_dir('provider-grok-alias-', function (string $dir): void {
        $binary = $dir . '/grok';
        assert_true(copy(dirname(__DIR__) . '/fixtures/fake-harness/spawn-counter.sh', $binary));
        assert_true(chmod($binary, 0755));
        [$output, $status, $details] = tr_harness_probe($dir, 'grok-cli', 'grok');
        assert_eq(0, $status, $output);
        assert_true(is_array($details), $output);
        assert_eq('Automattic\\SiteBuild\\GrokCliLlm', $details['class']);
        assert_eq('xai', $details['provider']);
        assert_eq('grok-4.5', $details['model']);
        assert_true(!file_exists($binary . '.count'));
    });
});
