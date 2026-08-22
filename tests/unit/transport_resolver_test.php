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

test('build() refuses a harness transport when proc_open is disabled', function (): void {
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if (!in_array('proc_open', $disabled, true) && function_exists('proc_open')) {
        // Can't disable it at runtime; assert the guard exists and is reachable instead.
        TransportResolver::assertSubprocessesAvailable();
        assert_true(true, 'proc_open available; guard is a no-op here');
        return;
    }
    $e = assert_throws(fn () => TransportResolver::assertSubprocessesAvailable());
    assert_contains('proc_open', $e->getMessage());
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

test('ancestry returns process names without throwing', function (): void {
    $names = TransportResolver::ancestry();
    assert_true(is_array($names), 'ancestry must return a list even when ps is unavailable');
});
