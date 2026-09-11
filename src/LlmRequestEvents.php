<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Record local request events without changing transport or usage totals. */
final class LlmRequestEvents
{
    private string $batchId;
    private float $origin;
    private float $wallOrigin;
    private \Closure $clock;
    private \Closure $emit;
    private array $requests = [];
    private array $attempts = [];
    private bool $finished = false;

    /** The optional clock returns seconds from a monotonic clock. */
    public function __construct(array $bodies, array $labels = [], ?callable $clock = null, ?callable $emit = null)
    {
        $this->clock = $clock === null ? static fn (): float => hrtime(true) / 1e9 : \Closure::fromCallable($clock);
        $directory = LlmLogger::dir();
        $this->emit = $emit === null
            ? static fn (array $record) => LlmLogger::eventIn($directory, $record)
            : \Closure::fromCallable($emit);
        $this->batchId = getmypid() . '-' . uniqid('', true);
        $this->origin = ($this->clock)();
        $this->wallOrigin = microtime(true);
        $this->write('batch_admitted', null, ['request_count' => count($bodies)]);
        foreach ($bodies as $key => $body) {
            $this->requests[$key] = [
                'request_id' => $this->batchId . '/' . count($this->requests),
                'label' => (string) ($labels[$key] ?? $key),
                'model' => (string) ($body['model'] ?? 'unknown'),
                'attempt' => 0,
            ];
            $this->write('request_admitted', $key);
        }
    }

    public function admitAttempts(array $keys, ?PromptCacheSchedule $schedule = null): void
    {
        foreach ($keys as $key) {
            $this->requests[$key]['attempt']++;
            $this->attempts[$key] = [
                'admitted' => ($this->clock)(), 'started' => null, 'first_body' => false,
                'message_start' => false, 'gate_blocked' => null, 'gate_ready' => null, 'finished' => false,
            ];
            $this->write('attempt_admitted', $key, [
                'cache_dependencies' => array_map(
                    fn ($owner): string => $this->requests[$owner]['request_id'],
                    $schedule?->dependenciesFor($key) ?? [],
                ),
            ]);
        }
    }

    /** This interval measures predicate observations, not provider queue time. */
    public function gateChecked(string|int $key, bool $ready): bool
    {
        $attempt = &$this->attempts[$key];
        if (!$ready && $attempt['gate_blocked'] === null) {
            $attempt['gate_blocked'] = ($this->clock)();
            $this->write('cache_gate_blocked', $key);
        } elseif ($ready && $attempt['gate_ready'] === null) {
            $attempt['gate_ready'] = ($this->clock)();
            $this->write('cache_gate_ready', $key, ['gate_wait_observed_seconds' => $this->gateWait($attempt)]);
        }
        return $ready;
    }

    /** The boundary is cURL admission, before DNS, connection, or response work. */
    public function start(string|int $key, string $boundary = 'curl_multi_add_handle'): void
    {
        $attempt = &$this->attempts[$key];
        $attempt['started'] = ($this->clock)();
        $this->write('transport_started', $key, [
            'start_boundary' => $boundary,
            'local_wait_seconds' => $attempt['started'] - $attempt['admitted'],
            'gate_wait_observed_seconds' => $this->gateWait($attempt),
        ]);
    }

    /** The body can contain pings before the first message_start event. */
    public function observe(string|int $key, string $raw): void
    {
        $attempt = &$this->attempts[$key];
        if (!$attempt['first_body'] && $raw !== '') {
            $attempt['first_body'] = true;
            $this->write('first_response_body', $key);
        }
        if (!$attempt['message_start'] && PromptCacheGate::hasMessageStart($raw)) {
            $attempt['message_start'] = true;
            $this->write('message_start', $key);
        }
    }

    public function complete(string|int $key, array $outcome): void
    {
        if (!isset($this->attempts[$key]) || $this->attempts[$key]['finished']) {
            return;
        }
        $attempt = &$this->attempts[$key];
        $attempt['finished'] = true;
        $this->write('attempt_finished', $key, [
            'outcome' => $outcome['outcome'] ?? (!empty($outcome['held']) ? 'held' : (!empty($outcome['ok']) ? 'ok' : 'failed')),
            'transport_started' => $attempt['started'] !== null,
            'completion_boundary' => $outcome['completion_boundary'] ?? 'request_scope_exit',
            'http_status' => $outcome['http_status'] ?? null,
            'curl_errno' => $outcome['curl_errno'] ?? null,
            'transport_seconds' => $outcome['time'] ?? null,
            'attempt_seconds' => ($this->clock)() - $attempt['admitted'],
            'gate_wait_observed_seconds' => $this->gateWait($attempt),
            'gate_ready_observed' => $attempt['gate_ready'] !== null,
            'stop_reason' => $outcome['stop_reason'] ?? null,
        ]);
    }

    /** The shared scheduler cancels the whole batch, including queued requests. */
    public function cancel(string|int $key, array $outcome = []): void
    {
        $this->complete($key, ['outcome' => 'cancelled', 'completion_boundary' => 'scheduler_cancel'] + $outcome);
        foreach ($this->attempts as $pendingKey => $attempt) {
            if (!$attempt['finished'] && $attempt['started'] === null) {
                $this->complete($pendingKey, ['outcome' => 'cancelled_before_start', 'completion_boundary' => 'scheduler_cancel']);
            }
        }
        foreach ($this->attempts as $attempt) {
            if (!$attempt['finished']) {
                return;
            }
        }
        $this->finish('cancelled');
    }

    public function finish(string $outcome): void
    {
        if ($this->finished) {
            return;
        }
        foreach ($this->attempts as $key => $attempt) {
            if (!$attempt['finished']) {
                $this->complete($key, ['outcome' => 'aborted']);
            }
        }
        $this->finished = true;
        $this->write('batch_finished', null, ['outcome' => $outcome]);
    }

    private function gateWait(array $attempt): ?float
    {
        if ($attempt['gate_ready'] === null) {
            return null;
        }
        return $attempt['gate_blocked'] === null ? 0.0 : $attempt['gate_ready'] - $attempt['gate_blocked'];
    }

    private function write(string $event, string|int|null $key, array $data = []): void
    {
        try {
            $elapsed = ($this->clock)() - $this->origin;
            ($this->emit)([
                'event' => $event, 'batch_id' => $this->batchId,
                'at' => $this->wallOrigin + $elapsed, 'elapsed_seconds' => $elapsed,
            ] + ($key === null ? [] : $this->requests[$key]) + $data);
        } catch (\Throwable) {
            // An event log failure must not abort a request.
        }
    }
}
