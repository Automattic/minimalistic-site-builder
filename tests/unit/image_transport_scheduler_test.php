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

test('scheduler cancellation clears byte reservations and permits reuse', function () {
    $scheduler = new ImageTransportScheduler();
    $scheduler->start(function () use ($scheduler): void {
        $scheduler->reserveImageBytes(1024);
        ImageTransportScheduler::pause(60);
    });
    $scheduler->cancel();
    $scheduler->run(function () use ($scheduler): void {
        $scheduler->reserveImageBytes(\Automattic\SiteBuild\Steps\GenerateImagesStep::MAX_QA_BYTES);
        $scheduler->releaseImageBytes(\Automattic\SiteBuild\Steps\GenerateImagesStep::MAX_QA_BYTES);
    });
    assert_throws(fn () => $scheduler->reserveImageBytes(\Automattic\SiteBuild\Steps\GenerateImagesStep::MAX_QA_BYTES + 1));
    assert_eq(null, ImageTransportScheduler::current());
});

test('a transport callback cannot start a nested scheduler poll', function () {
    $scheduler = new ImageTransportScheduler();
    $error = assert_throws(function () use ($scheduler): void {
        $scheduler->run(function () use ($scheduler): void {
            (new CurlMultiPool())->run([0], function () {
                $handle = curl_init('file://' . __FILE__);
                curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
                return $handle;
            }, function () use ($scheduler) {
                $scheduler->poll();
                return ['ok' => true];
            }, 1, lane: 'images');
        });
    });
    assert_contains('nested scheduler poll', $error->getMessage());
    assert_eq(null, ImageTransportScheduler::current());
});

test('a root graph inside a host Fiber keeps control of that Fiber', function () {
    $host = new Fiber(function (): void {
        $scheduler = new ImageTransportScheduler();
        $events = [];
        $scheduler->start(function () use (&$events): void { scheduler_file_batch([1], 'images', $events); });
        scheduler_file_batch([2], 'vision', $events);
        $scheduler->join();
        assert_eq(4, count($events));
    });
    $host->start();
    assert_true($host->isTerminated());
    assert_eq(null, ImageTransportScheduler::current());
});

test('cancellation with an active transfer resets the provider lane', function () {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);
    assert_true(is_resource($socket));
    $address = stream_socket_get_name($socket, false);
    $scheduler = new ImageTransportScheduler();
    try {
        $scheduler->start(function () use ($address): void {
            (new CurlMultiPool())->run([0], function () use ($address) {
                $handle = curl_init('http://' . $address . '/');
                curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
                return $handle;
            }, fn () => ['ok' => true], 1, lane: 'images');
        });
        $scheduler->cancel();
        $events = [];
        $scheduler->run(function () use (&$events): void { scheduler_file_batch([0], 'images', $events); });
        assert_eq(2, count($events));
    } finally {
        $scheduler->cancel();
        fclose($socket);
    }
});

test('an image retry wait permits a vision request to finish', function () {
    $scheduler = new ImageTransportScheduler();
    $events = [];
    $attempts = 0;
    $scheduler->run(function () use ($scheduler, &$events, &$attempts): void {
        $scheduler->spawn(function () use (&$events, &$attempts): void {
            $result = \Automattic\SiteBuild\GeminiImage::retryBatch([0 => []], function ($requests) use (&$events, &$attempts) {
                $events[] = 'image-' . ++$attempts;
                return $attempts === 1
                    ? [0 => ['ok' => false, 'transient' => true, 'error' => 'retry']]
                    : [0 => ['ok' => true]];
            }, [1]);
            assert_eq(1, $result['succeeded']);
        });
        $scheduler->spawn(function () use (&$events): void {
            scheduler_file_batch([0], 'vision', $events);
        });
    });
    assert_eq(2, $attempts);
    assert_true(array_search('done-vision-0', $events, true) < array_search('image-2', $events, true));
});

test('an HTTP 429 holds image siblings without stopping the vision lane', function () {
    $directory = sys_get_temp_dir() . '/image_scheduler_http_' . uniqid();
    mkdir($directory);
    $router = $directory . '/router.php';
    file_put_contents($router, '<?php http_response_code(str_contains($_SERVER["REQUEST_URI"], "limited") ? 429 : 200); echo "{}";');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);
    assert_true(is_resource($socket));
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $process = proc_open([PHP_BINARY, '-S', $address, $router], [
        0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory . '/server.log', 'w'],
        2 => ['file', $directory . '/server.log', 'a'],
    ], $pipes);
    assert_true(is_resource($process));
    try {
        $ready = false;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $probe = @stream_socket_client('tcp://' . $address, $errorCode, $error, 0.02);
            if (is_resource($probe)) {
                fclose($probe);
                $ready = true;
                break;
            }
            usleep(10000);
        }
        assert_true($ready);
        $scheduler = new ImageTransportScheduler();
        $results = [];
        $started = [];
        $completed = [];
        $scheduler->run(function () use ($scheduler, $address, &$results, &$started, &$completed): void {
            foreach (['images' => ['limited', 'sibling'], 'vision' => ['check']] as $lane => $paths) {
                $scheduler->spawn(function () use ($lane, $paths, $address, &$results, &$started, &$completed): void {
                    $results[$lane] = (new CurlMultiPool())->run($paths,
                        function ($key, $path) use ($address, &$started) {
                            $started[] = $path;
                            $handle = curl_init('http://' . $address . '/' . $path);
                            curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
                            return $handle;
                        }, fn ($key, $handle, $status) => ['ok' => $status === 200, 'transient' => $status === 429, 'status' => $status],
                        1, onComplete: function ($key) use ($lane, &$completed): void { $completed[$lane][] = $key; }, lane: $lane);
                });
            }
        });
        assert_eq(429, $results['images'][0]['status']);
        assert_eq(true, $results['images'][1]['held']);
        assert_eq(true, $results['vision'][0]['ok']);
        assert_true(!in_array('sibling', $started, true));
        assert_eq([0, 1], $completed['images']);
        assert_eq(null, ImageTransportScheduler::current());
    } finally {
        proc_terminate($process);
        proc_close($process);
        remove_tree($directory);
    }
});
