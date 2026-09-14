<?php
declare(strict_types=1);

use Automattic\SiteBuild\AnthropicClient;
use Automattic\SiteBuild\CurlMultiPool;
use Automattic\SiteBuild\ImageTransportScheduler;
use Automattic\SiteBuild\LlmLogger;
use Automattic\SiteBuild\LlmRequestEvents;
use Automattic\SiteBuild\PromptCacheSchedule;

function llm_event_rows(array $records, string $event, ?string $label = null): array
{
    return array_values(array_filter($records, static fn ($row): bool => $row['event'] === $event
        && ($label === null || ($row['label'] ?? null) === $label)));
}

test('request events separate a cache observation from local queue time and batch completion', function () {
    $now = 0.0;
    $clock = static function () use (&$now): float { return $now; };
    $body = AnthropicClient::bodyFor(['prompt' => 'Write.', 'cached_prefixes' => ['site']], 'opus', 500);
    $bodies = ['owner' => $body, 'reader' => $body];
    $records = [];
    $events = new LlmRequestEvents($bodies, [], $clock, static function ($row) use (&$records): void { $records[] = $row; });
    $schedule = new PromptCacheSchedule($bodies, $clock);
    $events->admitAttempts(array_keys($bodies), $schedule);
    $now = 1.0;
    $schedule->start('owner');
    $events->start('owner');
    $now = 2.0;
    assert_eq(false, $events->gateChecked('reader', $schedule->canStart('reader')));
    $now = 3.0;
    $raw = "data: {\"type\":\"ping\"}\n\n";
    $events->observe('owner', $raw);
    $schedule->observe('owner', $raw);
    assert_eq(false, $schedule->canStart('reader'));
    $now = 4.0;
    $raw .= "data: {\"type\":\"message_start\",\"message\":{}}\n";
    $events->observe('owner', $raw);
    $schedule->observe('owner', $raw);
    assert_eq(false, $schedule->canStart('reader'));
    $now = 5.0;
    $raw .= "\n";
    $events->observe('owner', $raw);
    $schedule->observe('owner', $raw);
    assert_eq(true, $events->gateChecked('reader', $schedule->canStart('reader')));
    $now = 8.0;
    $schedule->start('reader');
    $events->start('reader');
    $now = 12.0;
    $events->complete('reader', ['ok' => true, 'time' => 4.0, 'completion_boundary' => 'curl_completion']);
    $now = 20.0;
    $events->complete('owner', ['ok' => true, 'time' => 19.0, 'completion_boundary' => 'curl_completion']);
    $now = 21.0;
    $events->finish('returned');

    $started = llm_event_rows($records, 'transport_started', 'reader')[0];
    assert_eq(8.0, $started['local_wait_seconds']);
    assert_eq(3.0, $started['gate_wait_observed_seconds']);
    assert_eq(3.0, llm_event_rows($records, 'first_response_body', 'owner')[0]['elapsed_seconds']);
    assert_eq(5.0, llm_event_rows($records, 'message_start', 'owner')[0]['elapsed_seconds']);
    assert_eq(12.0, llm_event_rows($records, 'attempt_finished', 'reader')[0]['elapsed_seconds']);
    assert_eq(21.0, llm_event_rows($records, 'batch_finished')[0]['elapsed_seconds']);
    $ownerId = llm_event_rows($records, 'request_admitted', 'owner')[0]['request_id'];
    assert_eq([$ownerId], llm_event_rows($records, 'attempt_admitted', 'reader')[0]['cache_dependencies']);
});

test('request events measure failed-owner release and cache deadline without a full response', function () {
    foreach (['failure', 'deadline'] as $mode) {
        $now = 0.0;
        $clock = static function () use (&$now): float { return $now; };
        $body = AnthropicClient::bodyFor(['prompt' => 'Write.', 'cached_prefixes' => ['site']], 'opus', 500);
        $bodies = ['owner' => $body, 'reader' => $body];
        $records = [];
        $events = new LlmRequestEvents($bodies, [], $clock, static function ($row) use (&$records): void { $records[] = $row; });
        $schedule = new PromptCacheSchedule($bodies, $clock);
        $events->admitAttempts(array_keys($bodies), $schedule);
        $schedule->start('owner');
        $events->start('owner');
        $now = 1.0;
        assert_eq(false, $events->gateChecked('reader', $schedule->canStart('reader')));
        $now = $mode === 'failure' ? 3.0 : 10.0;
        if ($mode === 'failure') {
            $events->complete('owner', ['ok' => false, 'curl_errno' => CURLE_OPERATION_TIMEDOUT, 'time' => 3.0]);
            $schedule->release('owner');
        }
        assert_eq(true, $events->gateChecked('reader', $schedule->canStart('reader')));
        $events->start('reader');
        $events->finish('aborted');
        assert_eq($mode === 'failure' ? 2.0 : 9.0,
            llm_event_rows($records, 'transport_started', 'reader')[0]['gate_wait_observed_seconds']);
        assert_eq([], llm_event_rows($records, 'message_start'));
    }
});

test('request events retain retry identity and leave unmeasured durations null', function () {
    $records = [];
    $events = new LlmRequestEvents(['a' => ['model' => 'opus']], ['a' => 'page-home--hero'],
        emit: static function ($row) use (&$records): void { $records[] = $row; });
    $events->admitAttempts(['a']);
    $events->complete('a', ['held' => true]);
    $events->admitAttempts(['a']);
    $events->start('a');
    $events->complete('a', ['outcome' => 'cancelled', 'completion_boundary' => 'scheduler_cancel']);
    $events->complete('a', ['ok' => false]);
    $events->finish('aborted');
    $finished = llm_event_rows($records, 'attempt_finished');
    assert_eq(2, count($finished));
    assert_eq([1, 2], array_column($finished, 'attempt'));
    assert_eq($finished[0]['request_id'], $finished[1]['request_id']);
    assert_eq(false, $finished[0]['transport_started']);
    assert_eq(null, $finished[0]['transport_seconds']);
    assert_eq(null, $finished[0]['gate_wait_observed_seconds']);
    assert_eq('cancelled', $finished[1]['outcome']);
    assert_eq('page-home--hero', $finished[1]['label']);
    assert_eq(1, count(llm_event_rows($records, 'request_admitted')));
});

test('event records retain the original directory and preserve transcript format', function () {
    with_temp_dir('llm_events_', function ($directory): void {
        try {
            LlmLogger::setDir($directory . '/first');
            $events = new LlmRequestEvents(['a' => ['model' => 'opus', 'prompt' => 'private prompt']]);
            LlmLogger::setDir($directory . '/second');
            $events->admitAttempts(['a']);
            $events->start('a');
            $events->complete('a', ['ok' => true]);
            $events->finish('returned');
            $text = file_get_contents($directory . '/first/events.jsonl');
            assert_true(!str_contains($text, 'private prompt'));
            assert_true(!file_exists($directory . '/second/events.jsonl'));
            $rows = array_map(static fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), explode("\n", trim($text)));
            assert_eq(1, count(llm_event_rows($rows, 'batch_finished')));
            assert_eq([], glob($directory . '/first/*.log'));
            LlmLogger::log('test', ['model' => 'opus'], ['text' => 'OK', 'input' => 2, 'output' => 3], 1.0);
            $transcripts = glob($directory . '/second/*.log');
            assert_eq(1, count($transcripts));
            assert_contains('Tokens       : 2 in + 3 out = 5 total', file_get_contents($transcripts[0]));
        } finally {
            LlmLogger::setDir(null);
        }
    });
});

test('an event writer failure cannot interrupt request events', function () {
    $events = new LlmRequestEvents(['a' => []], emit: static function (): void { throw new RuntimeException('disk unavailable'); });
    $events->admitAttempts(['a']);
    $events->start('a');
    $events->observe('a', "data: {\"type\":\"message_start\",\"message\":{}}\n\n");
    $events->complete('a', ['ok' => true]);
    $events->finish('returned');
});

test('Anthropic transport records actual local callback events without an API request', function () {
    with_temp_dir('llm_local_stream_', function ($directory): void {
        $fixture = $directory . '/response.sse';
        file_put_contents($fixture, "data: {\"type\":\"message_start\",\"message\":{}}\n\n");
        $pool = new class($fixture) extends CurlMultiPool {
            public function __construct(private string $fixture) {}
            protected function addHandle(CurlMultiHandle $multi, CurlHandle $handle): int
            {
                curl_setopt($handle, CURLOPT_URL, 'file://' . $this->fixture);
                curl_setopt($handle, CURLOPT_POST, false);
                return parent::addHandle($multi, $handle);
            }
        };
        $body = AnthropicClient::bodyFor(['prompt' => 'Write.', 'cached_prefixes' => ['site']], 'opus', 500);
        $bodies = ['owner' => $body, 'reader' => $body];
        $records = [];
        $events = new LlmRequestEvents($bodies, emit: static function ($row) use (&$records): void { $records[] = $row; });
        $client = new AnthropicClient('unused', 'opus');
        $method = new ReflectionMethod($client, 'streamMulti');
        $method->setAccessible(true);
        $result = $method->invoke($client, $bodies, $events, $pool);
        $events->finish('returned');
        foreach (['owner', 'reader'] as $label) {
            assert_eq(false, $result[$label]['ok'], 'a file response has no HTTP status');
            $sequence = array_column(array_filter($records, static fn ($row) => ($row['label'] ?? null) === $label), 'event');
            foreach (['transport_started', 'first_response_body', 'message_start', 'attempt_finished'] as $event) {
                assert_true(in_array($event, $sequence, true));
            }
            assert_true(array_search('transport_started', $sequence, true) < array_search('first_response_body', $sequence, true));
            assert_true(array_search('message_start', $sequence, true) < array_search('attempt_finished', $sequence, true));
            assert_eq('curl_completion', llm_event_rows($records, 'attempt_finished', $label)[0]['completion_boundary']);
        }
        assert_eq(0, $client->usageTotals()['requests'], 'transport events do not change usage totals');
    });
});

test('shared scheduler start and cancellation records match accepted handles', function () {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);
    assert_true(is_resource($socket));
    $address = stream_socket_get_name($socket, false);
    $scheduler = new ImageTransportScheduler();
    $records = [];
    $events = new LlmRequestEvents(['active' => [], 'queued' => []], emit: static function ($row) use (&$records): void { $records[] = $row; });
    $events->admitAttempts(['active', 'queued']);
    try {
        $scheduler->start(function () use ($address, $events): void {
            try {
                (new CurlMultiPool())->run(['active' => [], 'queued' => []], function () use ($address) {
                    $handle = curl_init('http://' . $address . '/');
                    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
                    return $handle;
                }, fn () => ['ok' => true], 1, lane: 'anthropic',
                    onStart: fn ($key) => $events->start($key),
                    onCancel: fn ($key) => $events->cancel($key));
            } finally {
                $events->finish('aborted');
            }
        });
        $scheduler->cancel();
        assert_eq(['active'], array_column(llm_event_rows($records, 'transport_started'), 'label'));
        assert_eq('cancelled', llm_event_rows($records, 'attempt_finished', 'active')[0]['outcome']);
        assert_eq(false, llm_event_rows($records, 'attempt_finished', 'queued')[0]['transport_started']);
        assert_eq('cancelled_before_start', llm_event_rows($records, 'attempt_finished', 'queued')[0]['outcome']);
        assert_eq(1, count(llm_event_rows($records, 'batch_finished')));
    } finally {
        $scheduler->cancel();
        fclose($socket);
    }
});
