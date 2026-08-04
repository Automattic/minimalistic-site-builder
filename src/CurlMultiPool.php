<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Shared curl_multi glue for {@see RollingPool}: run a keyed batch of cURL
 * transfers with at most $cap in flight, refilling a freed slot the moment any
 * transfer completes, and classify each finished transfer into an outcome.
 * The client owns the two protocol-specific seams — building a configured
 * handle for a key and classifying a completed transfer — while everything
 * transport-generic lives here once:
 *
 *  - Once any member completes with HTTP 429, further launches this pool run
 *    are HELD: firing the rest of the batch into a rate-limit event as fast
 *    as the 429s bounce back only extends the penalty. Held members return a
 *    synthetic transient outcome with `held: true`, so the batch retry layers
 *    (AnthropicClient::retryTextBatch, GeminiImage::retryBatch) re-send them after
 *    their backoff without charging their finite transient budgets.
 *  - A refused curl_multi_add_handle would otherwise be a phantom in-flight
 *    transfer that never produces a CURLMSG_DONE; it becomes a synthetic
 *    transient outcome instead, retried on a fresh stack.
 *  - When the multi stack stops without reporting a completion (a CURLM
 *    failure), every remaining transfer is classified with what it holds so
 *    far — the clients' classifiers mark never-responded transfers (status 0)
 *    transient — rather than hanging the pool or discarding sibling responses.
 *
 * The ext-curl multi calls sit behind overridable methods so the orchestration
 * is unit-testable with a scripted fake driver; production callers use the
 * default instance, which delegates to the real curl_multi_* functions.
 */
class CurlMultiPool
{
    /**
     * @param array<array-key,mixed> $items transfer input keyed by id
     * @param callable(string|int,mixed):\CurlHandle $buildHandle build one
     *        configured (not yet executed) handle for a key
     * @param callable(string|int,\CurlHandle,int):array<string,mixed> $classify
     *        classify one completed transfer into an outcome, given the HTTP
     *        status the pool already read for its 429 hold (the one reader of
     *        that status); called while the handle is still open, before the
     *        pool removes and closes it
     * @return array<array-key,array<string,mixed>> outcomes keyed and ordered
     *         as $items
     */
    public function run(array $items, callable $buildHandle, callable $classify, int $cap): array
    {
        if ($items === []) {
            return [];
        }
        $multi = $this->multiInit();
        /** @var array<int,array{0:string|int,1:\CurlHandle}> $inFlight key + handle by spl_object_id(handle) */
        $inFlight = [];
        /** @var array<array-key,array<string,mixed>> $queuedOutcomes synthetic transient outcomes (refused adds, held launches) drained by $await before any I/O */
        $queuedOutcomes = [];
        $holding = false;

        $start = function (string|int $key, mixed $item) use ($multi, $buildHandle, &$inFlight, &$queuedOutcomes, &$holding): void {
            if ($holding) {
                $queuedOutcomes[$key] = [
                    'ok' => false,
                    'transient' => true,
                    // Never sent: the batch retry layers re-send held keys
                    // without charging their finite transient budgets.
                    'held' => true,
                    'error' => 'launch held: a sibling request was rate-limited (HTTP 429)',
                ];
                return;
            }
            $ch = $buildHandle($key, $item);
            if ($this->addHandle($multi, $ch) !== CURLM_OK) {
                $this->closeHandle($ch);
                $queuedOutcomes[$key] = [
                    'ok' => false,
                    'transient' => true,
                    'error' => 'curl_multi_add_handle refused the transfer',
                ];
                return;
            }
            $inFlight[spl_object_id($ch)] = [$key, $ch];
        };

        // Classify one finished transfer and release its handle (and slot).
        // The release is in a finally: a classify callback may throw (e.g.
        // WpcomImageClient's disk write fails mid-batch, which is by design
        // allowed to propagate), and the finished handle must not leak.
        $finish = function (string|int $key, \CurlHandle $ch) use ($multi, $classify, &$inFlight, &$holding): array {
            $httpStatus = $this->httpStatus($ch);
            if ($httpStatus === 429) {
                $holding = true;
            }
            try {
                return $classify($key, $ch, $httpStatus);
            } finally {
                unset($inFlight[spl_object_id($ch)]);
                $this->removeHandle($multi, $ch);
                $this->closeHandle($ch);
            }
        };

        $await = function () use ($multi, &$inFlight, &$queuedOutcomes, $finish): array {
            if ($queuedOutcomes !== []) {
                $done = $queuedOutcomes;
                $queuedOutcomes = [];
                return $done;
            }
            // Drive the stack until at least one transfer finishes. The -1
            // guard prevents a busy-spin while there is no socket yet (DNS).
            do {
                $status = $this->multiExec($multi, $running);
                $done = [];
                while (($msg = $this->infoRead($multi)) !== false) {
                    if ($msg['msg'] !== CURLMSG_DONE) {
                        continue;
                    }
                    [$key] = $inFlight[spl_object_id($msg['handle'])]
                        ?? throw new \RuntimeException('rolling pool got a completion for an unregistered curl handle');
                    $done[$key] = $finish($key, $msg['handle']);
                }
                if ($done !== []) {
                    return $done;
                }
                if ($running && $status === CURLM_OK && $this->select($multi) === -1) {
                    usleep(1000);
                }
            } while ($running && $status === CURLM_OK);

            // The multi stack stopped without reporting a completion (a CURLM
            // failure). Classify what every remaining transfer holds so far
            // rather than hanging the pool or discarding sibling responses.
            $done = [];
            foreach ($inFlight as [$key, $ch]) {
                $done[$key] = $finish($key, $ch);
            }
            return $done;
        };

        try {
            return RollingPool::run($items, $start, $await, $cap);
        } finally {
            // Aborting mid-batch (a throwing classify) leaves siblings in
            // flight; drain and close them before closing the multi handle.
            foreach ($inFlight as [, $ch]) {
                $this->removeHandle($multi, $ch);
                $this->closeHandle($ch);
            }
            $this->multiClose($multi);
        }
    }

    /**
     * Backoff for a retry wave that may consist only of held launches.
     *
     * A held-only wave (the rate-limited sibling itself resolved another way,
     * so no request owns a backoff slot) still waits the first backoff: the
     * hold only exists because a sibling really hit a 429, and re-sending
     * immediately would fire straight into the still-active rate limit.
     *
     * @param list<int> $retryWaits backoff seconds owned by really-attempted transients in the wave
     * @param array<int,int> $delays the retry schedule
     */
    public static function heldWaveWait(array $retryWaits, array $delays): int
    {
        return $retryWaits === [] ? ($delays[0] ?? 0) : max($retryWaits);
    }

    protected function multiInit(): \CurlMultiHandle
    {
        return curl_multi_init();
    }

    protected function addHandle(\CurlMultiHandle $multi, \CurlHandle $ch): int
    {
        return curl_multi_add_handle($multi, $ch);
    }

    protected function multiExec(\CurlMultiHandle $multi, ?int &$running): int
    {
        return curl_multi_exec($multi, $running);
    }

    /** @return array{msg:int,handle:\CurlHandle,result:int}|false */
    protected function infoRead(\CurlMultiHandle $multi): array|false
    {
        return curl_multi_info_read($multi);
    }

    protected function select(\CurlMultiHandle $multi): int
    {
        return curl_multi_select($multi, 1.0);
    }

    protected function removeHandle(\CurlMultiHandle $multi, \CurlHandle $ch): void
    {
        curl_multi_remove_handle($multi, $ch);
    }

    protected function closeHandle(\CurlHandle $ch): void
    {
        curl_close($ch);
    }

    protected function multiClose(\CurlMultiHandle $multi): void
    {
        curl_multi_close($multi);
    }

    protected function httpStatus(\CurlHandle $ch): int
    {
        return (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    }
}
