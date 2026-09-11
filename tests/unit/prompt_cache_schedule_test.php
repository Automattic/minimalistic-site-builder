<?php
declare(strict_types=1);

use Automattic\SiteBuild\AnthropicClient;
use Automattic\SiteBuild\PromptCacheSchedule;
use Automattic\SiteBuild\RollingPool;

function cache_schedule_body(array $prefixes, string $model = 'opus'): array
{
    return AnthropicClient::bodyFor(['prompt' => 'Write the section.', 'cached_prefixes' => $prefixes], $model, 500);
}

function cache_schedule_start_event(): string
{
    return "data: {\"type\":\"message_start\",\"message\":{\"id\":\"x\"}}\n\n";
}

test('cache schedule creates one writer per page and model without waiting for complete output', function () {
    $bodies = [
        'header' => cache_schedule_body(['site']),
        'home-1' => cache_schedule_body(['site', 'build', 'homepage']),
        'home-2' => cache_schedule_body(['site', 'build', 'homepage']),
        'visit-1' => cache_schedule_body(['site', 'build', 'visit']),
        'visit-2' => cache_schedule_body(['site', 'build', 'visit']),
        'visit-3' => cache_schedule_body(['site', 'build', 'visit']),
        'menu' => cache_schedule_body(['site', 'build', 'menu']),
        'other-model' => cache_schedule_body(['site', 'build', 'homepage'], 'haiku'),
        'uncached' => cache_schedule_body([]),
    ];
    $schedule = new PromptCacheSchedule($bodies);
    $started = [];
    $round = 0;
    $out = RollingPool::run($bodies,
        function ($key) use ($schedule, &$started, &$round): void {
            $schedule->start($key);
            $started[$key] = $round;
        },
        function () use ($schedule, &$round): array {
            $round++;
            if ($round === 1) {
                $schedule->observe('home-1', cache_schedule_start_event());
                return [];
            }
            if ($round === 2) {
                $schedule->observe('visit-1', cache_schedule_start_event());
                return [];
            }
            return array_fill_keys(['header', 'home-1', 'home-2', 'visit-1', 'visit-2', 'visit-3', 'menu', 'other-model', 'uncached'], true);
        }, 10,
        fn ($key) => $schedule->canStart($key),
        fn ($key) => $schedule->release($key),
    );
    assert_eq(0, $started['home-1']);
    assert_eq(0, $started['other-model']);
    assert_eq(0, $started['uncached']);
    assert_eq(1, $started['header']);
    assert_eq(1, $started['home-2']);
    assert_eq(1, $started['visit-1']);
    assert_eq(1, $started['menu']);
    assert_eq(2, $started['visit-2']);
    assert_eq(2, $started['visit-3']);
    assert_eq(array_keys($bodies), array_keys($out));
});

test('each cache deadline starts when its writer starts', function () {
    $now = 0.0;
    $schedule = new PromptCacheSchedule([
        'home' => cache_schedule_body(['site', 'homepage']),
        'visit-1' => cache_schedule_body(['site', 'visit']),
        'visit-2' => cache_schedule_body(['site', 'visit']),
    ], static function () use (&$now): float { return $now; });
    $schedule->start('home');
    $now = 10.0;
    assert_eq(true, $schedule->canStart('visit-1'));
    $now = 20.0;
    $schedule->start('visit-1');
    assert_eq(false, $schedule->canStart('visit-2'));
    $now = 29.9;
    assert_eq(false, $schedule->canStart('visit-2'));
    $now = 30.0;
    assert_eq(true, $schedule->canStart('visit-2'));
});

test('cache schedule keeps different systems and schemas independent', function () {
    $first = cache_schedule_body(['site', 'page']);
    $second = $first;
    $second['system'] .= ' Other rules.';
    $third = $first;
    $third['output_config'] = ['format' => ['type' => 'json_schema', 'schema' => ['type' => 'object']]];
    $schedule = new PromptCacheSchedule(['a' => $first, 'b' => $second, 'c' => $third]);
    foreach (['a', 'b', 'c'] as $key) {
        assert_eq(true, $schedule->canStart($key));
    }
});

test('cache schedule releases dependencies after a refused curl add', function () {
    $bodies = ['a' => cache_schedule_body(['site']), 'b' => cache_schedule_body(['site'])];
    $schedule = new PromptCacheSchedule($bodies);
    $pool = new FakeCurlMultiPool([['b']]);
    $pool->refuseAdds = ['a'];
    $started = [];
    $result = $pool->run($bodies,
        function ($key) use ($schedule, $pool, &$started): CurlHandle {
            $schedule->start($key);
            $started[] = $key;
            return $pool->register($key, curl_init('http://localhost/unused'));
        },
        fn () => ['ok' => true], 3,
        fn ($key) => $schedule->canStart($key),
        fn ($key) => $schedule->release($key),
    );
    assert_eq(['a', 'b'], $started);
    assert_eq(false, $result['a']['ok']);
    assert_eq(true, $result['b']['ok']);
});

test('cache schedule returns held siblings after a rate limit without starting their gates', function () {
    $bodies = [
        'a' => cache_schedule_body(['site', 'homepage']),
        'b' => cache_schedule_body(['site', 'visit']),
        'c' => cache_schedule_body(['site', 'visit']),
    ];
    $schedule = new PromptCacheSchedule($bodies);
    $pool = new FakeCurlMultiPool([['a']], ['a' => 429]);
    $started = [];
    $result = $pool->run($bodies,
        function ($key) use ($schedule, $pool, &$started): CurlHandle {
            $schedule->start($key);
            $started[] = $key;
            return $pool->register($key, curl_init('http://localhost/unused'));
        },
        fn () => ['ok' => false, 'transient' => true], 3,
        fn ($key) => $schedule->canStart($key),
        fn ($key) => $schedule->release($key),
    );
    assert_eq(['a'], $started);
    assert_eq(true, $result['b']['held']);
    assert_eq(true, $result['c']['held']);
});
