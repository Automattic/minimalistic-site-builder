<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProcessPool;

function pp_fixture(string $name): string
{
    return dirname(__DIR__) . '/fixtures/fake-harness/' . $name;
}

function pp_compact_php(string $path): string
{
    $source = @file_get_contents($path);
    assert_true($source !== false, "could not read {$path}");
    $out = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $out .= is_array($token) ? $token[1] : $token;
    }
    return $out;
}

test('ProcessPool key ordering follows input jobs', function (): void {
    $jobs = [
        'alpha' => ['argv' => [pp_fixture('echo-stdin.sh')], 'stdin' => 'alpha-payload'],
        'beta'  => ['argv' => [pp_fixture('echo-stdin.sh')], 'stdin' => 'beta-payload'],
    ];
    $out = ProcessPool::run($jobs, 2, 30);
    assert_eq(['alpha', 'beta'], array_keys($out));
    assert_eq('alpha-payload', trim($out['alpha']['stdout']));
    assert_eq('beta-payload', trim($out['beta']['stdout']));
    assert_eq(0, $out['alpha']['exit']);
    assert_eq(['exit', 'stdout', 'stderr', 'secs', 'timedOut', 'truncated'], array_keys($out['alpha']));
    assert_true(is_float($out['alpha']['secs']), 'secs must be a float');
    assert_true(!$out['alpha']['truncated'], 'ordinary output was marked truncated');
});

test('ProcessPool argv canary passes one literal argument', function (): void {
    $canary = '/tmp/pp-argv-canary';
    $payload = '; touch ' . $canary;
    @unlink($canary);
    $created = false;
    try {
        $out = ProcessPool::run(
            ['x' => ['argv' => [pp_fixture('echo-argv.sh'), $payload]]],
            1,
            30,
        );
        $created = file_exists($canary);
    } finally {
        @unlink($canary);
    }
    assert_eq("1\n{$payload}", $out['x']['stdout'], 'argv element must arrive verbatim as one argument');
    assert_true(!$created, 'shell injection executed');
});

test('ProcessPool stdin-non-blocking guard drains 512KB before child reads stdin', function (): void {
    $out = ProcessPool::run(
        ['guard' => ['argv' => [pp_fixture('output-first.sh')], 'stdin' => 'stdin-arrived']],
        1,
        3,
    );
    assert_true(!$out['guard']['timedOut'], 'blocking stdin deadlocked against child stdout');
    assert_eq(512 * 1024 + strlen('stdin-arrived'), strlen($out['guard']['stdout']));
    assert_true(str_ends_with($out['guard']['stdout'], 'stdin-arrived'), 'stdin did not reach child');
});

test('ProcessPool 512KB round-trip does not truncate or deadlock', function (): void {
    $big = str_repeat('abcdefgh', 64 * 1024);
    $out = ProcessPool::run(
        ['big' => ['argv' => [pp_fixture('echo-stdin.sh')], 'stdin' => $big]],
        1,
        60,
    );
    assert_eq(strlen($big), strlen(trim($out['big']['stdout'])), 'large payload was truncated or deadlocked');
});

test('ProcessPool stderr capture stays separate from stdout', function (): void {
    $out = ProcessPool::run(['f' => ['argv' => [pp_fixture('fail.sh')]]], 1, 30);
    assert_eq(7, $out['f']['exit']);
    assert_contains('partial output', $out['f']['stdout']);
    assert_contains('diagnostic detail', $out['f']['stderr']);
});

test('ProcessPool timeout kill flags and returns promptly', function (): void {
    $started = microtime(true);
    $out = ProcessPool::run(['s' => ['argv' => [pp_fixture('slow.sh')]]], 1, 1);
    $elapsed = microtime(true) - $started;
    assert_true($out['s']['timedOut'], 'timed-out job must be flagged');
    assert_true($elapsed < 10.0, "pool waited {$elapsed}s for a 1s timeout");
});

test('ProcessPool concurrency cap preserves all results', function (): void {
    $jobs = [];
    foreach (range(1, 6) as $i) {
        $jobs["j{$i}"] = ['argv' => [pp_fixture('echo-stdin.sh')], 'stdin' => "payload-{$i}"];
    }
    $out = ProcessPool::run($jobs, 2, 30);
    assert_eq(6, count($out));
    foreach (range(1, 6) as $i) {
        assert_eq("payload-{$i}", trim($out["j{$i}"]['stdout']));
    }
});

test('ProcessPool redirects no-stdin jobs from dev null', function (): void {
    $out = ProcessPool::run(['null' => ['argv' => [pp_fixture('stdin-kind.sh')]]], 1, 30);
    assert_eq('character-device', $out['null']['stdout']);
});

test('ProcessPool non-positive timeout means no deadline', function (): void {
    $out = ProcessPool::run(
        ['none' => ['argv' => [PHP_BINARY, '-r', 'usleep(300000); echo "finished";']]],
        1,
        0,
    );

    assert_eq(0, $out['none']['exit']);
    assert_true(!$out['none']['timedOut'], 'zero timeout created a deadline');
    assert_eq('finished', $out['none']['stdout']);
});

test('ProcessPool job env replaces the complete child environment', function (): void {
    $probe = <<<'PHP'
echo json_encode([
    'path' => getenv('PATH'),
    'home' => getenv('HOME'),
    'only' => getenv('PP_ONLY'),
]);
PHP;
    $out = ProcessPool::run(
        ['env' => ['argv' => [PHP_BINARY, '-r', $probe], 'env' => ['PP_ONLY' => 'kept']]],
        1,
        2,
    );

    assert_eq('{"path":false,"home":false,"only":"kept"}', $out['env']['stdout']);
});

test('ProcessPool resolves a bare executable from the replacement env PATH', function (): void {
    $out = ProcessPool::run(
        [
            'path' => [
                'argv' => [basename(PHP_BINARY), '-r', 'echo "resolved";'],
                'env' => ['PATH' => dirname(PHP_BINARY)],
            ],
        ],
        1,
        2,
    );

    assert_eq(0, $out['path']['exit'], $out['path']['stderr']);
    assert_eq('resolved', $out['path']['stdout']);
});

test('ProcessPool resolves an explicit relative executable from the child cwd', function (): void {
    $out = ProcessPool::run(
        [
            'relative' => [
                'argv' => ['./echo-stdin.sh'],
                'stdin' => 'relative-ok',
                'cwd' => dirname(pp_fixture('echo-stdin.sh')),
            ],
        ],
        1,
        2,
    );

    assert_eq(0, $out['relative']['exit'], $out['relative']['stderr']);
    assert_eq('relative-ok', trim($out['relative']['stdout']));
});

test('ProcessPool refuses a missing working directory rather than running elsewhere', function (): void {
    $missingCwd = sys_get_temp_dir() . '/process-pool-missing-cwd-' . bin2hex(random_bytes(8));
    $out = ProcessPool::run(
        ['cwd' => ['argv' => [PHP_BINARY, '-r', 'echo "unreachable";'], 'cwd' => $missingCwd]],
        1,
        2,
    );

    // The outcome must not depend on the PHP version: 8.1's proc_open ignores a
    // missing cwd and runs the child in the parent's directory, where 8.3+ fails
    // the spawn. Asserting the child produced nothing is what separates the two.
    assert_true($out['cwd']['exit'] !== 0, 'bad cwd must fail');
    assert_true(
        !str_contains($out['cwd']['stdout'], 'unreachable'),
        'the child ran despite a working directory that does not exist',
    );
    assert_contains(PHP_BINARY, $out['cwd']['stderr']);
    assert_contains($missingCwd, $out['cwd']['stderr']);
});

test('ProcessPool grandchild-held stdout preserves the direct child exit without timeout', function (): void {
    $started = microtime(true);
    $out = ProcessPool::run(
        ['direct' => ['argv' => ['/bin/sh', '-c', 'sleep 5 & exit 3']]],
        1,
        2,
    );
    $elapsed = microtime(true) - $started;

    assert_eq(3, $out['direct']['exit'], 'direct child exit code was lost');
    assert_true(!$out['direct']['timedOut'], 'exited direct child was falsely timed out');
    assert_true($elapsed < 1.5, "pool waited {$elapsed}s for grandchild-held stdout");
});

test('ProcessPool reaps from its cached first terminal status', function (): void {
    $pool = pp_compact_php(dirname(__DIR__, 2) . '/src/ProcessPool.php');

    assert_contains("\$slot['terminalStatus']=\$status", $pool, 'first terminal status is not cached');
    assert_contains("\$status=\$slot['terminalStatus']", $pool, 'reap path re-polls terminal status');
});

test('ProcessPool missing binary failure names the requested executable', function (): void {
    $binary = 'totally-not-on-path-xyz';
    $out = ProcessPool::run(['missing' => ['argv' => [$binary]]], 1, 2);

    assert_true($out['missing']['exit'] !== 0, 'missing binary must fail');
    assert_contains('executable not found or not executable', $out['missing']['stderr']);
    assert_contains($binary, $out['missing']['stderr']);
});

test('ProcessPool bounded capture truncates a flood under a constrained memory limit', function (): void {
    $root = dirname(__DIR__, 2);
    $driver = <<<'PHP'
require $argv[1] . '/autoload.php';

$producer = <<<'CHILD'
$chunk = str_repeat('x', 1024 * 1024);
for ($i = 0; $i < 80; $i++) {
    fwrite(STDOUT, $chunk);
}
CHILD;

$result = Automattic\SiteBuild\ProcessPool::run(
    [['argv' => [PHP_BINARY, '-r', $producer]]],
    1,
    10,
)[0];
$ok = $result['exit'] === 0
    && !$result['timedOut']
    && ($result['truncated'] ?? false) === true
    && strlen($result['stdout']) === 64 * 1024 * 1024;
printf(
    "exit=%d timedOut=%s bytes=%d truncated=%s\n",
    $result['exit'],
    $result['timedOut'] ? 'true' : 'false',
    strlen($result['stdout']),
    ($result['truncated'] ?? false) ? 'true' : 'false',
);
exit($ok ? 0 : 1);
PHP;

    $out = ProcessPool::run(
        ['flood' => ['argv' => [PHP_BINARY, '-d', 'memory_limit=192M', '-r', $driver, $root]]],
        1,
        15,
    );

    assert_eq(0, $out['flood']['exit'], $out['flood']['stderr'] . $out['flood']['stdout']);
    assert_contains('bytes=67108864 truncated=true', $out['flood']['stdout']);
});

test('ProcessPool and build-demos job spawn keep argv arrays', function (): void {
    $pool = pp_compact_php(dirname(__DIR__, 2) . '/src/ProcessPool.php');
    assert_contains("proc_open(\$job['argv'],", $pool, 'ProcessPool must pass the argv array directly');

    $demos = pp_compact_php(dirname(__DIR__, 2) . '/bin/build-demos.php');
    // Two job batches: build inline, screenshot via a named builder so its
    // option forwarding stays unit-testable. Both must carry an argv list.
    $argvJobs = substr_count($demos, "'argv'=>[")
        + substr_count($demos, "'argv'=>demo_screenshot_argv(");
    assert_true($argvJobs >= 2, 'build and screenshot jobs need argv arrays');
    assert_true(!str_contains($demos, "'cmd'=>"), 'build-demos job path still constructs shell strings');
    assert_contains('ProcessPool::run($jobs,', $demos);
    assert_contains('ProcessPool::run($shotJobs,', $demos);
});

test('build-demos replaces only run_jobs with ProcessPool', function (): void {
    $src = (string) @file_get_contents(dirname(__DIR__, 2) . '/bin/build-demos.php');
    assert_true(!str_contains($src, 'function run_jobs'), 'run_jobs must move to ProcessPool');
    assert_true(str_contains($src, 'function pump_children'), 'serve_all still needs its long-lived pump helper');
    assert_true(str_contains($src, 'pump_children($servers'), 'serve_all must keep using its pump helper');
    assert_true(str_contains($src, 'ProcessPool::run'), 'build-demos job batches must drive ProcessPool');
});

test('build-demos replays prefixed stdout and diagnoses both timeout paths', function (): void {
    $src = (string) @file_get_contents(dirname(__DIR__, 2) . '/bin/build-demos.php');
    $compact = pp_compact_php(dirname(__DIR__, 2) . '/bin/build-demos.php');

    assert_true(substr_count($compact, "print(prefix_child_lines(") === 2, 'child stdout must use stdout');
    assert_true(substr_count($compact, 'Narrator::write(prefix_child_lines(') === 2, 'child stderr must use narration');
    assert_contains("'/\\A|(?<=\\n)(?!\\z)/'", $src, 'every non-terminal output line needs a prefix');
    assert_contains("if(\$r['timedOut'])", $compact, 'build timeout result is ignored');
    assert_contains("elseif(\$shotResults[\$i]['timedOut'])", $compact, 'screenshot timeout result is ignored');
});
