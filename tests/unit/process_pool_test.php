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
    assert_eq(['exit', 'stdout', 'stderr', 'secs', 'timedOut'], array_keys($out['alpha']));
    assert_true(is_float($out['alpha']['secs']), 'secs must be a float');
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

test('ProcessPool and build-demos job spawn keep argv arrays', function (): void {
    $pool = pp_compact_php(dirname(__DIR__, 2) . '/src/ProcessPool.php');
    assert_contains("proc_open(\$job['argv'],", $pool, 'ProcessPool must pass the argv array directly');

    $demos = pp_compact_php(dirname(__DIR__, 2) . '/bin/build-demos.php');
    assert_true(substr_count($demos, "'argv'=>[") >= 2, 'build and screenshot jobs need argv arrays');
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
