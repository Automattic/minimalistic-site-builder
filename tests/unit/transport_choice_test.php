<?php
declare(strict_types=1);

use Automattic\SiteBuild\HarnessCallFailed;
use Automattic\SiteBuild\TransportChoice;
use Automattic\SiteBuild\TransportUnavailable;

test('TransportChoice carries kind, reason and binary', function (): void {
    $c = new TransportChoice(TransportChoice::KIND_CLAUDE_CLI, 'env fingerprint CLAUDECODE=1', '/usr/bin/claude');
    assert_eq('claude-cli', $c->kind);
    assert_eq('env fingerprint CLAUDECODE=1', $c->reason);
    assert_eq('/usr/bin/claude', $c->binary);
});

test('TransportChoice rejects an unknown kind', function (): void {
    $e = assert_throws(fn () => new TransportChoice('telepathy', 'why'));
    assert_contains('unknown transport kind', $e->getMessage());
});

test('TransportChoice rejects a blank reason so the echo line always explains itself', function (): void {
    $e = assert_throws(fn () => new TransportChoice(TransportChoice::KIND_API, '   '));
    assert_contains('reason', $e->getMessage());
});

test('TransportChoice classifies the API transport as metered', function (): void {
    $choice = new TransportChoice(TransportChoice::KIND_API, 'explicit API transport');
    assert_true(!$choice->isSubscription());
});

test('TransportChoice classifies every existing CLI transport as subscription-backed', function (): void {
    $subscriptionKinds = [
        TransportChoice::KIND_CLAUDE_CLI,
        TransportChoice::KIND_CODEX_CLI,
        TransportChoice::KIND_GROK_CLI,
    ];

    foreach ($subscriptionKinds as $kind) {
        $choice = new TransportChoice($kind, 'explicit CLI transport');
        assert_true($choice->isSubscription(), "{$kind} should be subscription-backed");
    }
});

test('billing map refuses a transport kind without an explicit classification', function (): void {
    $reflection = new ReflectionClass(TransportChoice::class);
    /** @var TransportChoice $choice */
    $choice = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('kind')->setValue($choice, 'telepathy');

    $e = assert_throws(fn () => $choice->isSubscription());
    assert_true($e instanceof LogicException);
    assert_contains('telepathy', $e->getMessage());
    assert_contains('billing classification', $e->getMessage());
});

test('resolution and runtime failures are distinguishable types', function (): void {
    assert_true(new TransportUnavailable('x') instanceof \RuntimeException);
    assert_true(new HarnessCallFailed('x') instanceof \RuntimeException);
    assert_true(!(new HarnessCallFailed('x') instanceof TransportUnavailable));
});
