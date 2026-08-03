<?php
declare(strict_types=1);

use Automattic\SiteBuild\CurlMultiPool;

/**
 * Unit tests for the shared curl_multi rolling-pool glue (CurlMultiPool).
 * The ext-curl multi calls sit behind overridable methods, so a scripted fake
 * driver exercises the orchestration — held launches after a 429, refused
 * adds, the CURLM-failure fallback — without any network I/O. Handles are
 * real \CurlHandle objects (curl_init) that are never executed.
 */

/**
 * Fake driver: completions are scripted per await round as lists of keys, and
 * HTTP statuses per key. No transfer is ever executed.
 */
class FakeCurlMultiPool extends CurlMultiPool
{
    /** @var array<int,list<string|int>> keys completing per exec round */
    private array $script;
    /** @var array<array-key,int> HTTP status reported for each key */
    private array $statuses;
    /** @var array<int,string|int> spl_object_id(handle) => key */
    private array $keysById = [];
    /** @var array<int,\CurlHandle> attached handles by spl_object_id */
    private array $attached = [];
    /** @var list<array{msg:int,handle:\CurlHandle,result:int}> */
    private array $pendingMessages = [];
    public bool $multiClosed = false;
    /** When true, exec reports CURLM failure while transfers remain attached. */
    public bool $failMulti = false;
    /** @var list<string|int> keys whose add is refused */
    public array $refuseAdds = [];
    /** @var list<string|int> keys whose handle was closed */
    public array $closed = [];

    /**
     * @param array<int,list<string|int>> $script
     * @param array<array-key,int> $statuses
     */
    public function __construct(array $script, array $statuses = [])
    {
        $this->script = $script;
        $this->statuses = $statuses;
    }

    /** Track which key a handle belongs to (the test's buildHandle calls this). */
    public function register(string|int $key, \CurlHandle $ch): \CurlHandle
    {
        $this->keysById[spl_object_id($ch)] = $key;
        return $ch;
    }

    protected function addHandle(\CurlMultiHandle $multi, \CurlHandle $ch): int
    {
        $key = $this->keysById[spl_object_id($ch)];
        if (in_array($key, $this->refuseAdds, true)) {
            return CURLM_INTERNAL_ERROR;
        }
        $this->attached[spl_object_id($ch)] = $ch;
        return CURLM_OK;
    }

    protected function multiExec(\CurlMultiHandle $multi, ?int &$running): int
    {
        if ($this->failMulti) {
            $running = 0;
            return CURLM_INTERNAL_ERROR;
        }
        $completed = array_shift($this->script) ?? [];
        foreach ($this->attached as $ch) {
            if (in_array($this->keysById[spl_object_id($ch)], $completed, true)) {
                $this->pendingMessages[] = ['msg' => CURLMSG_DONE, 'handle' => $ch, 'result' => CURLE_OK];
            }
        }
        $running = count($this->attached) - count($this->pendingMessages);
        return CURLM_OK;
    }

    protected function infoRead(\CurlMultiHandle $multi): array|false
    {
        return array_shift($this->pendingMessages) ?? false;
    }

    protected function select(\CurlMultiHandle $multi): int
    {
        return 0;
    }

    protected function removeHandle(\CurlMultiHandle $multi, \CurlHandle $ch): void
    {
        unset($this->attached[spl_object_id($ch)]);
    }

    protected function closeHandle(\CurlHandle $ch): void
    {
        $this->closed[] = $this->keysById[spl_object_id($ch)];
    }

    protected function multiClose(\CurlMultiHandle $multi): void
    {
        $this->multiClosed = true;
    }

    protected function httpStatus(\CurlHandle $ch): int
    {
        return $this->statuses[$this->keysById[spl_object_id($ch)]] ?? 200;
    }
}

/**
 * buildHandle registering with the fake (appending each built key to $built
 * when given), and a classify echoing the key.
 *
 * @return array{0:callable,1:callable}
 */
function cmp_seams(FakeCurlMultiPool $pool, ?array &$built = null): array
{
    $buildHandle = function (string|int $key, mixed $item) use ($pool, &$built): \CurlHandle {
        if ($built !== null) {
            $built[] = $key;
        }
        return $pool->register($key, curl_init('http://localhost/never-executed'));
    };
    $classify = fn (string|int $key, \CurlHandle $ch): array => ['ok' => true, 'key' => $key];
    return [$buildHandle, $classify];
}

test('CurlMultiPool classifies scripted completions and refills freed slots', function () {
    // Cap 2 over 4 items: c must start on a's completion, d on b's — the pool
    // rolls slots instead of waiting for a window barrier.
    $pool = new FakeCurlMultiPool([['a'], ['b'], ['c', 'd']]);
    [$buildHandle, $classify] = cmp_seams($pool);

    $out = $pool->run(['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4], $buildHandle, $classify, 2);

    assert_eq(['a', 'b', 'c', 'd'], array_keys($out), 'outcomes are keyed and ordered as the input');
    assert_eq(['ok' => true, 'key' => 'c'], $out['c'], 'each completion reaches its own key');
    assert_true($pool->multiClosed, 'the multi handle is closed when the pool drains');
});

test('CurlMultiPool holds launches after a sibling 429 with the exact held outcome', function () {
    // a completes with HTTP 429 while b is in flight and c has not started.
    // c must never be sent: it gets the synthetic held outcome retryTextBatch
    // and GeminiImage::retryBatch recognize (transient, held, the pinned string).
    $pool = new FakeCurlMultiPool([['a'], ['b']], ['a' => 429]);
    $built = [];
    [$buildHandle] = cmp_seams($pool, $built);
    $classify = fn (string|int $key, \CurlHandle $ch, int $status): array
        => ['ok' => false, 'transient' => true, 'error' => "E:{$key}:{$status}"];

    $out = $pool->run(['a' => 1, 'b' => 2, 'c' => 3], $buildHandle, $classify, 2);

    assert_eq(['a', 'b'], $built, 'the held key is never built or sent');
    assert_eq([
        'ok' => false,
        'transient' => true,
        'held' => true,
        'error' => 'launch held: a sibling request was rate-limited (HTTP 429)',
    ], $out['c'], 'the held outcome carries the exact synthetic shape');
    assert_eq('E:a:429', $out['a']['error'], 'really-attempted keys are classified with the status the pool read');
});

test('CurlMultiPool resolves an empty batch without touching curl_multi', function () {
    $pool = new class([]) extends FakeCurlMultiPool {
        protected function multiInit(): \CurlMultiHandle
        {
            throw new RuntimeException('multiInit must not be called for an empty batch');
        }
    };
    [$buildHandle, $classify] = cmp_seams($pool);

    assert_eq([], $pool->run([], $buildHandle, $classify, 2));
});

test('CurlMultiPool turns a refused add into a synthetic transient outcome', function () {
    // A refused curl_multi_add_handle must not become a phantom in-flight
    // transfer that never produces a CURLMSG_DONE.
    $pool = new FakeCurlMultiPool([['a']]);
    $pool->refuseAdds = ['b'];
    [$buildHandle, $classify] = cmp_seams($pool);

    $out = $pool->run(['a' => 1, 'b' => 2], $buildHandle, $classify, 2);

    assert_eq(['ok' => true, 'key' => 'a'], $out['a']);
    assert_eq([
        'ok' => false,
        'transient' => true,
        'error' => 'curl_multi_add_handle refused the transfer',
    ], $out['b'], 'the refused add is classified transient so the retry layer re-sends it');
});

test('CurlMultiPool classifies every remaining transfer when the multi stack fails', function () {
    // A CURLM-level failure stops the stack without CURLMSG_DONE messages.
    // The pool must classify what each in-flight transfer holds so far (the
    // clients mark status-0 transfers transient) instead of hanging.
    $pool = new FakeCurlMultiPool([]);
    $pool->failMulti = true;
    $classified = [];
    [$buildHandle] = cmp_seams($pool);
    $classify = function (string|int $key, \CurlHandle $ch) use (&$classified): array {
        $classified[] = $key;
        return ['ok' => false, 'transient' => true, 'error' => 'no response received before the transfer stopped'];
    };

    $out = $pool->run(['a' => 1, 'b' => 2], $buildHandle, $classify, 2);

    assert_eq(['a', 'b'], $classified, 'every in-flight transfer is classified by the fallback');
    assert_eq(true, $out['b']['transient'], 'fallback outcomes flow back keyed to their requests');
    assert_true($pool->multiClosed, 'the multi handle is closed even after a CURLM failure');
});

test('CurlMultiPool closes the finished handle and in-flight siblings when classify throws', function () {
    // A classify callback is allowed to throw (WpcomImageClient's disk write
    // failing mid-batch aborts the build by design). The pool must still
    // remove+close the finished handle and drain every in-flight sibling
    // before the exception propagates — not leak open connections.
    $pool = new FakeCurlMultiPool([['a']]);
    [$buildHandle] = cmp_seams($pool);
    $classify = function (string|int $key, \CurlHandle $ch): array {
        throw new RuntimeException("disk full while saving '{$key}'");
    };

    $err = null;
    try {
        $pool->run(['a' => 1, 'b' => 2], $buildHandle, $classify, 2);
    } catch (RuntimeException $e) {
        $err = $e->getMessage();
    }

    assert_eq("disk full while saving 'a'", $err, 'the classify failure propagates to the caller');
    $closed = $pool->closed;
    sort($closed);
    assert_eq(['a', 'b'], $closed, 'the finished handle and the in-flight sibling are both closed');
    assert_true($pool->multiClosed, 'the multi handle is closed after the classify failure');
});

test('CurlMultiPool rejects a completion for an unregistered curl handle', function () {
    // A DONE message for a handle the pool never added is a transport bug —
    // fail loud instead of mis-keying a sibling's response.
    $pool = new class([]) extends FakeCurlMultiPool {
        protected function multiExec(\CurlMultiHandle $multi, ?int &$running): int
        {
            $running = 1;
            return CURLM_OK;
        }

        protected function infoRead(\CurlMultiHandle $multi): array|false
        {
            static $sent = false;
            if ($sent) {
                return false;
            }
            $sent = true;
            return ['msg' => CURLMSG_DONE, 'handle' => curl_init('http://localhost/ghost'), 'result' => CURLE_OK];
        }
    };
    [$buildHandle, $classify] = cmp_seams($pool);

    $err = null;
    try {
        $pool->run(['a' => 1], $buildHandle, $classify, 1);
    } catch (RuntimeException $e) {
        $err = $e->getMessage();
    }
    assert_true(is_string($err) && str_contains($err, 'unregistered curl handle'), 'an unknown handle is a loud failure');
});
