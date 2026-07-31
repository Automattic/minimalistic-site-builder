<?php
declare(strict_types=1);

/**
 * @return array{
 *     root:string,
 *     repo:string,
 *     script:string,
 *     vendor:string,
 *     fake_bin:string,
 *     git_log:string,
 *     rsync_log:string,
 *     state:string
 * }
 */
function sync_blocks_engine_fixture(string $upstreamCommit): array
{
    $root = sys_get_temp_dir() . '/sync-blocks-engine-' . bin2hex(random_bytes(8));
    $repo = $root . '/repo';
    $fakeBin = $root . '/fake-bin';
    $vendor = $repo . '/lib/blocks-engine-php-transformer';
    $script = $repo . '/bin/sync-blocks-engine.sh';

    foreach ([$fakeBin, dirname($script), $vendor . '/src'] as $directory) {
        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Could not create {$directory}.");
        }
    }

    $sourceScript = dirname(__DIR__, 2) . '/bin/sync-blocks-engine.sh';
    if (!copy($sourceScript, $script) || !chmod($script, 0755)) {
        throw new RuntimeException('Could not copy sync script into fixture.');
    }

    file_put_contents($vendor . '/UPSTREAM_COMMIT', $upstreamCommit . "\n");
    file_put_contents($vendor . '/VERSION', "before\n");
    file_put_contents($vendor . '/src/sentinel.php', "<?php // before\n");

    $fakeGit = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

{
    printf 'git'
    printf ' <%s>' "$@"
    printf '\n'
} >> "${SYNC_TEST_GIT_LOG}"

repo=''
if [[ "${1:-}" == '-C' ]]; then
    repo="${2:?}"
    shift 2
fi

case "${1:-}" in
    init)
        mkdir -p -- "${2:?}"
        ;;
    remote)
        [[ "${2:-}" == 'add' && "${3:-}" == 'origin' ]]
        ;;
    fetch)
        printf '%s\n' "${@: -1}" > "${SYNC_TEST_STATE}"
        ;;
    checkout)
        [[ "${2:-}" == '--detach' && "${3:-}" == 'FETCH_HEAD' ]]
        mkdir -p -- "${repo:?}/php-transformer/src"
        printf '%s\n' '<?php // fetched' > "${repo}/php-transformer/src/fetched.php"
        printf '%s\n' 'fixture-version' > "${repo}/php-transformer/VERSION"
        ;;
    rev-parse)
        if [[ -n "${SYNC_TEST_RESOLVED_SHA:-}" ]]; then
            printf '%s\n' "${SYNC_TEST_RESOLVED_SHA}"
        else
            tr '[:upper:]' '[:lower:]' < "${SYNC_TEST_STATE}"
        fi
        ;;
    *)
        printf 'Unsupported fake git command: %s\n' "$*" >&2
        exit 2
        ;;
esac
BASH;
    $fakeRsync = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

{
    printf 'rsync'
    printf ' <%s>' "$@"
    printf '\n'
} >> "${SYNC_TEST_RSYNC_LOG}"

args=("$@")
count="${#args[@]}"
source_path="${args[$((count - 2))]}"
target_path="${args[$((count - 1))]}"
[[ "${target_path}" == "${SYNC_TEST_ALLOWED_RSYNC_TARGET}" ]]
command rm -rf -- "${target_path%/}"
mkdir -p -- "${target_path}"
command cp -R "${source_path%/}/." "${target_path%/}/"
BASH;

    file_put_contents($fakeBin . '/git', $fakeGit);
    file_put_contents($fakeBin . '/rsync', $fakeRsync);
    chmod($fakeBin . '/git', 0755);
    chmod($fakeBin . '/rsync', 0755);

    return [
        'root' => $root,
        'repo' => $repo,
        'script' => $script,
        'vendor' => $vendor,
        'fake_bin' => $fakeBin,
        'git_log' => $root . '/git.log',
        'rsync_log' => $root . '/rsync.log',
        'state' => $root . '/state',
    ];
}

/**
 * @param array{
 *     script:string,
 *     fake_bin:string,
 *     git_log:string,
 *     rsync_log:string,
 *     state:string,
 *     vendor:string
 * } $fixture
 * @param list<string> $arguments
 * @return array{exit:int,output:string}
 */
function run_sync_blocks_engine(array $fixture, array $arguments, ?string $resolvedSha = null): array
{
    $path = $fixture['fake_bin'] . ':' . (string) getenv('PATH');
    $command = 'env'
        . ' PATH=' . escapeshellarg($path)
        . ' SYNC_TEST_GIT_LOG=' . escapeshellarg($fixture['git_log'])
        . ' SYNC_TEST_RSYNC_LOG=' . escapeshellarg($fixture['rsync_log'])
        . ' SYNC_TEST_STATE=' . escapeshellarg($fixture['state'])
        . ' SYNC_TEST_ALLOWED_RSYNC_TARGET=' . escapeshellarg($fixture['vendor'] . '/src/');
    if ($resolvedSha !== null) {
        $command .= ' SYNC_TEST_RESOLVED_SHA=' . escapeshellarg($resolvedSha);
    }
    $command .= ' bash ' . escapeshellarg($fixture['script']);
    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }

    $output = [];
    $exit = 0;
    exec($command . ' 2>&1', $output, $exit);

    return ['exit' => $exit, 'output' => implode("\n", $output)];
}

function remove_sync_blocks_engine_fixture(string $root): void
{
    exec('rm -rf -- ' . escapeshellarg($root));
}

test('sync blocks-engine fetches and checks out the exact requested commit', function (): void {
    $requested = '1234567890abcdef1234567890abcdef12345678';
    $fixture = sync_blocks_engine_fixture(str_repeat('0', 40));

    try {
        $result = run_sync_blocks_engine($fixture, [$requested]);
        $gitLog = is_file($fixture['git_log']) ? (string) file_get_contents($fixture['git_log']) : '';

        assert_eq(0, $result['exit'], $result['output']);
        assert_true(
            preg_match(
                '/^git <-C> <[^>]+> <fetch> <--depth> <1> <origin> <' . preg_quote($requested, '/') . '>$/m',
                $gitLog,
            ) === 1,
            'must fetch requested commit from origin',
        );
        assert_true(
            preg_match('/^git <-C> <[^>]+> <checkout> <--detach> <FETCH_HEAD>$/m', $gitLog) === 1,
            'must check out FETCH_HEAD detached',
        );
        assert_true(!str_contains($gitLog, '<clone>'), 'must not clone a floating branch');
        assert_true(!str_contains($gitLog, '<trunk>'), 'must not resolve floating trunk');
        assert_eq($requested . "\n", file_get_contents($fixture['vendor'] . '/UPSTREAM_COMMIT'));
        assert_true(is_file($fixture['vendor'] . '/src/fetched.php'));
        assert_true(!is_file($fixture['vendor'] . '/src/sentinel.php'), 'rsync --delete replaces old source');
    } finally {
        remove_sync_blocks_engine_fixture($fixture['root']);
    }
});

test('sync blocks-engine defaults to the existing UPSTREAM_COMMIT', function (): void {
    $requested = 'abcdefabcdefabcdefabcdefabcdefabcdefabcd';
    $fixture = sync_blocks_engine_fixture($requested);

    try {
        $result = run_sync_blocks_engine($fixture, []);
        $gitLog = is_file($fixture['git_log']) ? (string) file_get_contents($fixture['git_log']) : '';

        assert_eq(0, $result['exit'], $result['output']);
        assert_true(
            preg_match(
                '/^git <-C> <[^>]+> <fetch> <--depth> <1> <origin> <' . preg_quote($requested, '/') . '>$/m',
                $gitLog,
            ) === 1,
            'must fetch existing UPSTREAM_COMMIT from origin',
        );
        assert_eq($requested . "\n", file_get_contents($fixture['vendor'] . '/UPSTREAM_COMMIT'));
    } finally {
        remove_sync_blocks_engine_fixture($fixture['root']);
    }
});

test('sync blocks-engine rejects an invalid commit before git or rsync', function (): void {
    $existing = 'abcdefabcdefabcdefabcdefabcdefabcdefabcd';
    $fixture = sync_blocks_engine_fixture($existing);

    try {
        $result = run_sync_blocks_engine($fixture, ['trunk']);

        assert_true($result['exit'] !== 0, 'invalid commit must fail');
        assert_eq('', is_file($fixture['git_log']) ? (string) file_get_contents($fixture['git_log']) : '');
        assert_eq('', is_file($fixture['rsync_log']) ? (string) file_get_contents($fixture['rsync_log']) : '');
        assert_eq($existing . "\n", file_get_contents($fixture['vendor'] . '/UPSTREAM_COMMIT'));
        assert_eq("<?php // before\n", file_get_contents($fixture['vendor'] . '/src/sentinel.php'));
    } finally {
        remove_sync_blocks_engine_fixture($fixture['root']);
    }
});

test('sync blocks-engine refuses a resolved commit mismatch before rsync', function (): void {
    $requested = '1234567890abcdef1234567890abcdef12345678';
    $resolved = 'abcdefabcdefabcdefabcdefabcdefabcdefabcd';
    $fixture = sync_blocks_engine_fixture(str_repeat('0', 40));

    try {
        $result = run_sync_blocks_engine($fixture, [$requested], $resolved);

        assert_true($result['exit'] !== 0, 'resolved commit mismatch must fail');
        assert_eq('', is_file($fixture['rsync_log']) ? (string) file_get_contents($fixture['rsync_log']) : '');
        assert_eq(str_repeat('0', 40) . "\n", file_get_contents($fixture['vendor'] . '/UPSTREAM_COMMIT'));
        assert_eq("<?php // before\n", file_get_contents($fixture['vendor'] . '/src/sentinel.php'));
    } finally {
        remove_sync_blocks_engine_fixture($fixture['root']);
    }
});
