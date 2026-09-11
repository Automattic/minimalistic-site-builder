<?php
declare(strict_types=1);
namespace Automattic\SiteBuild;

/** Run image and vision tasks on one curl stack with separate provider limits. */
final class ImageTransportScheduler
{
    private static ?self $active = null;
    private array $tasks = [];
    private array $ownedFibers = [];
    private array $jobs = [];
    private array $handles = [];
    private array $laneCounts = [];
    private array $laneHold = [];
    private int $nextJob = 0;
    private int $imageBytes = 0;
    private bool $inPoll = false;
    private ?\CurlMultiHandle $multi = null;

    public static function current(): ?self { return self::$active; }

    public function spawn(callable $task): void
    {
        $fiber = new \Fiber($task);
        $this->ownedFibers[spl_object_id($fiber)] = true;
        $this->tasks[] = ['fiber' => $fiber, 'wake' => 0.0];
    }

    public static function pause(float $seconds = 0): void
    {
        $fiber = \Fiber::getCurrent();
        if (self::$active !== null && $fiber !== null && isset(self::$active->ownedFibers[spl_object_id($fiber)])) {
            \Fiber::suspend(microtime(true) + max(0, $seconds));
        } elseif (self::$active !== null) {
            $until = microtime(true) + max(0, $seconds);
            do {
                self::$active->poll();
                self::$active->waitForIo();
            } while (microtime(true) < $until);
        } elseif ($seconds > 0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }

    public function reserveImageBytes(int $bytes): void
    {
        if ($bytes < 0 || $bytes > Steps\GenerateImagesStep::MAX_QA_BYTES) {
            throw new \InvalidArgumentException('Image payload exceeds the scheduler byte budget');
        }
        while ($this->imageBytes + $bytes > Steps\GenerateImagesStep::MAX_QA_BYTES) {
            self::pause();
        }
        $this->imageBytes += $bytes;
    }

    public function releaseImageBytes(int $bytes): void { $this->imageBytes -= $bytes; }

    public function start(callable $task): void
    {
        if (self::$active !== null) {
            throw new \LogicException('An image transport scheduler is already active');
        }
        self::$active = $this;
        $this->multi = curl_multi_init();
        $this->spawn($task);
        try {
            $this->poll();
        } catch (\Throwable $error) {
            $this->cancel();
            throw $error;
        }
    }

    /** Advance tasks and dispatch their ready handles before returning. */
    public function poll(): void
    {
        if ($this->multi === null) {
            return;
        }
        if ($this->inPoll) {
            throw new \LogicException('A transport callback cannot start a nested scheduler poll');
        }
        $this->inPoll = true;
        try {
        foreach ($this->tasks as $index => &$state) {
            if ($state['wake'] > microtime(true)) {
                continue;
            }
            $fiber = $state['fiber'];
            $state['wake'] = (float) ($fiber->isStarted() ? $fiber->resume() : $fiber->start());
            if ($fiber->isTerminated()) {
                unset($this->tasks[$index], $this->ownedFibers[spl_object_id($fiber)]);
            }
        }
        unset($state);
        $this->pump();
        } finally {
            $this->inPoll = false;
        }
    }

    public function join(): void
    {
        try {
            while ($this->tasks !== []) {
                $this->poll();
                $this->waitForIo();
            }
        } finally {
            $this->cancel();
        }
    }

    public function run(callable $task): void
    {
        $this->start($task);
        $this->join();
    }

    /** Release in-flight handles when the host stops the graph. */
    public function cancel(): void
    {
        if ($this->multi === null) {
            return;
        }
        foreach ($this->handles as $entry) {
            curl_multi_remove_handle($this->multi, $entry['handle']);
        }
        $this->handles = [];
        $this->jobs = [];
        $this->tasks = [];
        $this->ownedFibers = [];
        $this->laneCounts = [];
        $this->laneHold = [];
        $this->imageBytes = 0;
        $this->nextJob = 0;
        curl_multi_close($this->multi);
        $this->multi = null;
        self::$active = null;
    }

    private function waitForIo(): void
    {
        if ($this->multi === null || $this->handles === [] || curl_multi_select($this->multi, 0.001) === -1) {
            usleep(1000);
        }
    }

    public function batch(array $items, callable $build, callable $classify, int $cap, ?callable $canStart, ?callable $onComplete, string $lane): array
    {
        $id = $this->nextJob++;
        $this->jobs[$id] = [
            'items' => $items, 'pending' => $items, 'build' => $build, 'classify' => $classify,
            'cap' => max(1, $cap), 'canStart' => $canStart, 'onComplete' => $onComplete,
            'lane' => $lane, 'active' => 0, 'results' => [], 'held' => false,
        ];
        while (count($this->jobs[$id]['results']) < count($items)) {
            self::pause();
        }
        $results = $this->jobs[$id]['results'];
        unset($this->jobs[$id]);
        return array_replace(array_fill_keys(array_keys($items), null), $results);
    }

    private function complete(int $id, string|int $key, array $result): void
    {
        $this->jobs[$id]['results'][$key] = $result;
        if ($this->jobs[$id]['onComplete'] !== null) {
            ($this->jobs[$id]['onComplete'])($key);
        }
    }

    private function pump(): void
    {
        foreach ($this->jobs as $id => &$job) {
            $lane = $job['lane'];
            foreach ($job['pending'] as $key => $item) {
                if ($job['held'] || ($this->laneHold[$lane] ?? 0) > microtime(true)) {
                    unset($job['pending'][$key]);
                    $this->complete($id, $key, ['ok' => false, 'transient' => true, 'held' => true,
                        'error' => 'launch held: a sibling request was rate-limited (HTTP 429)']);
                    continue;
                }
                if ($job['active'] >= $job['cap'] || ($this->laneCounts[$lane] ?? 0) >= $job['cap']) {
                    break;
                }
                if ($job['canStart'] !== null && !($job['canStart'])($key, $item)) {
                    continue;
                }
                unset($job['pending'][$key]);
                $handle = ($job['build'])($key, $item);
                if (curl_multi_add_handle($this->multi, $handle) !== CURLM_OK) {
                    $this->complete($id, $key, ['ok' => false, 'transient' => true,
                        'error' => 'curl_multi_add_handle refused the transfer']);
                    continue;
                }
                $this->handles[spl_object_id($handle)] = ['job' => $id, 'key' => $key, 'handle' => $handle];
                $job['active']++;
                $this->laneCounts[$lane] = ($this->laneCounts[$lane] ?? 0) + 1;
            }
        }
        unset($job);
        $status = curl_multi_exec($this->multi, $running);
        while (($message = curl_multi_info_read($this->multi)) !== false) {
            if ($message['msg'] === CURLMSG_DONE) {
                $this->finish($message['handle']);
            }
        }
        if ($status !== CURLM_OK || (!$running && $this->handles !== [])) {
            foreach ($this->handles as $entry) {
                $this->finish($entry['handle']);
            }
        }
    }

    private function finish(\CurlHandle $handle): void
    {
        $entry = $this->handles[spl_object_id($handle)];
        $id = $entry['job'];
        $job = $this->jobs[$id];
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        if ($status === 429) {
            $this->jobs[$id]['held'] = true;
            // Both API clients start their retry schedule at two seconds.
            $this->laneHold[$job['lane']] = max($this->laneHold[$job['lane']] ?? 0, microtime(true) + 2);
        }
        try {
            $this->complete($id, $entry['key'], ($job['classify'])($entry['key'], $handle, $status));
        } finally {
            unset($this->handles[spl_object_id($handle)]);
            $this->jobs[$id]['active']--;
            $this->laneCounts[$job['lane']]--;
            curl_multi_remove_handle($this->multi, $handle);
        }
    }
}
