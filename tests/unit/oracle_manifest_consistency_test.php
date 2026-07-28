<?php
declare(strict_types=1);

/**
 * The oracle manifest is the provenance record for the frozen registry
 * artifacts. These tests keep it a guarantee instead of documentation: any
 * edit to a hashed artifact fails here until the manifest is deliberately
 * re-frozen with the edit recorded as an amendment (with its certification
 * evidence and an end-to-end golden case; see docs/block-fixer-oracle.md).
 */

/** @return array<string,mixed> */
function oracle_manifest(): array
{
    $path = dirname(__DIR__) . '/fixtures/block-fixer/oracle-manifest.json';
    $contents = file_get_contents($path);
    assert_true($contents !== false, 'oracle-manifest.json must be readable');
    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    assert_true(is_array($decoded), 'oracle-manifest.json must decode to an object');
    return $decoded;
}

test('oracle manifest hashes match the committed registry artifacts', function (): void {
    $manifest = oracle_manifest();
    $root = dirname(__DIR__, 2);
    $artifacts = [
        'generatedPhpSha256' => $root . '/src/BlockSerializer/Registry/generated-registry.php',
        'runtimeJsonSha256' => $root . '/tests/fixtures/block-fixer/registered-runtime.json',
    ];
    foreach ($artifacts as $key => $path) {
        $expected = $manifest['registry'][$key] ?? null;
        assert_true(is_string($expected), "manifest registry.{$key} must be present");
        assert_eq(
            $expected,
            hash_file('sha256', $path),
            "{$key} is stale: " . basename($path) . ' changed without a manifest re-freeze; '
            . 'record the edit as a manifest amendment (with a golden case) and update the hash'
        );
    }
});

test('oracle manifest records post-oracle amendments with a policy', function (): void {
    $manifest = oracle_manifest();
    // An EMPTY ledger is the healthy state: it means every committed artifact
    // was derived by the oracle rather than hand-certified. What must always
    // hold is that the ledger exists and that anything in it carries its
    // evidence, so a hand-edit can never be recorded as a bare assertion.
    assert_true(
        is_array($manifest['amendments'] ?? null),
        'manifest must carry the post-oracle amendment ledger'
    );
    foreach ($manifest['amendments'] as $amendment) {
        assert_true(is_string($amendment['commit'] ?? null) && $amendment['commit'] !== '');
        assert_true(is_string($amendment['summary'] ?? null) && $amendment['summary'] !== '');
    }
    assert_true(is_string($manifest['amendmentPolicy'] ?? null), 'manifest must state the amendment policy');
});

test('oracle manifest package pins agree with the generated registry metadata', function (): void {
    $manifest = oracle_manifest();
    $registry = require dirname(__DIR__, 2) . '/src/BlockSerializer/Registry/generated-registry.php';
    assert_true(is_array($registry) && is_array($registry['metadata'] ?? null));
    assert_eq(
        $manifest['fingerprint']['packageLockSha256'] ?? null,
        $registry['metadata']['packageLockSha256'] ?? null,
        'registry metadata and manifest disagree on the oracle package-lock pin'
    );
});
