<?php
declare(strict_types=1);

use Automattic\SiteBuild\CurlMultiPool;
use Automattic\SiteBuild\ImageTransportScheduler;

function scheduler_file_batch(array $items, string $lane, array &$events, ?callable $canStart = null, ?callable $onComplete = null): array
{
    return (new CurlMultiPool())->run($items,
        function ($key, $item) use (&$events, $lane) {
            $events[] = 'start-' . $lane . '-' . $key;
            $handle = curl_init('file://' . __FILE__);
            curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
            return $handle;
        },
        function ($key, $handle, $status) use (&$events, $lane) {
            $events[] = 'done-' . $lane . '-' . $key;
            return ['ok' => true, 'body' => curl_multi_getcontent($handle)];
        }, 2, $canStart, $onComplete, lane: $lane);
}

test('the scheduler runs image and vision pools together and preserves gates and keys', function () {
    $scheduler = new ImageTransportScheduler();
    $events = [];
    $results = [];
    $released = false;
    $scheduler->run(function () use ($scheduler, &$events, &$results, &$released): void {
        $scheduler->spawn(function () use (&$events, &$results, &$released): void {
            $results['images'] = scheduler_file_batch(['first' => 1, 'second' => 2], 'images', $events,
                function ($key) use (&$released) { return $key === 'first' || $released; },
                function ($key) use (&$released): void { if ($key === 'first') { $released = true; } });
        });
        $scheduler->spawn(function () use (&$events, &$results): void {
            $results['vision'] = scheduler_file_batch(['check' => 1], 'vision', $events);
        });
    });
    assert_eq(['first', 'second'], array_keys($results['images']));
    assert_eq(['check'], array_keys($results['vision']));
    assert_true(array_search('start-vision-check', $events, true) < array_search('done-images-second', $events, true));
    assert_true(array_search('done-images-first', $events, true) < array_search('start-images-second', $events, true));
    assert_eq(null, ImageTransportScheduler::current());
});

test('separate image jobs share one concurrency limit', function () {
    $scheduler = new ImageTransportScheduler();
    $active = 0;
    $peak = 0;
    $completed = 0;
    $scheduler->run(function () use ($scheduler, &$active, &$peak, &$completed): void {
        for ($job = 0; $job < 3; $job++) {
            $scheduler->spawn(function () use (&$active, &$peak, &$completed): void {
                (new CurlMultiPool())->run(range(1, 12), function () use (&$active, &$peak) {
                    $peak = max($peak, ++$active);
                    $handle = curl_init('file://' . __FILE__);
                    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
                    return $handle;
                }, function () use (&$active, &$completed) {
                    $active--;
                    $completed++;
                    return ['ok' => true];
                }, 10, lane: 'images');
            });
        }
    });
    assert_eq(36, $completed);
    assert_eq(10, $peak);
    assert_eq(0, $active);
});

test('a root graph batch advances an earlier image stage before join', function () {
    $scheduler = new ImageTransportScheduler();
    $events = [];
    $stageDone = false;
    $scheduler->start(function () use (&$events, &$stageDone): void {
        scheduler_file_batch(['stage' => 1], 'images', $events);
        $stageDone = true;
    });
    assert_contains('start-images-stage', implode(' ', $events));
    scheduler_file_batch(['graph' => 1], 'vision', $events);
    $scheduler->join();
    assert_true($stageDone);
    assert_eq(null, ImageTransportScheduler::current());
});

test('scheduler cleanup releases its context after a task fails', function () {
    $scheduler = new ImageTransportScheduler();
    assert_throws(fn () => $scheduler->run(function (): void { throw new RuntimeException('task failed'); }));
    assert_eq(null, ImageTransportScheduler::current());
    $scheduler->run(fn () => null);
    assert_eq(null, ImageTransportScheduler::current());
});
