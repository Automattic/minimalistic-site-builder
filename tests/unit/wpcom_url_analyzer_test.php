<?php
declare(strict_types=1);

namespace Automattic\SiteBuild {
    /** Test-only wall clock seam; ordinary calls delegate to PHP's real clock. */
    function time(): int
    {
        $override = $GLOBALS['wpcom_test_wall_clock'] ?? null;
        return is_callable($override) ? $override() : \time();
    }
}

namespace {

use Automattic\SiteBuild\InspirationBrief;
use Automattic\SiteBuild\InspirationLogger;
use Automattic\SiteBuild\CurlMultiPool;
use Automattic\SiteBuild\UrlAnalyzer;
use Automattic\SiteBuild\WpcomUrlAnalyzer;

/** @return array<string,mixed> */
function analyzer_valid_response(string $style = 'Bold'): array
{
    return [
        'page_type' => 'store',
        'owner_type' => 'business',
        'style' => $style,
        'colors' => [['hex' => '#FF90E8', 'name' => 'pink', 'role' => 'accent']],
        'sections' => [['category' => 'hero', 'description' => 'Full-bleed color field']],
    ];
}

/** @return array{status:int,body:string} */
function analyzer_outcome(array|string $body, int $status = 200): array
{
    return [
        'status' => $status,
        'body' => is_string($body)
            ? $body
            : (string) json_encode($body, JSON_UNESCAPED_SLASHES),
    ];
}

/**
 * Scripted transport at the injected CurlMultiPool boundary. It calls the real
 * handle builder so request configuration reaches that seam, but never executes
 * any handle and therefore cannot perform network I/O.
 */
final class ScriptedAnalyzerCurlMultiPool extends CurlMultiPool
{
    /** @var array<string,list<array<string,mixed>>> */
    private array $responses;
    private ?\Closure $afterRun;

    /** @var array<string,int> */
    public array $attempts = [];
    /** @var list<array{key:string|int,request:array<string,mixed>}> */
    public array $requests = [];
    /** @var list<int> */
    public array $caps = [];
    /** @var list<int> */
    public array $itemCounts = [];
    /** @var list<int> */
    public array $writeReturns = [];
    public int $runs = 0;

    /**
     * @param array<string,list<array<string,mixed>>> $responses
     * @param callable(int):void|null                  $afterRun
     */
    public function __construct(array $responses = [], ?callable $afterRun = null)
    {
        $this->responses = $responses;
        $this->afterRun = $afterRun === null ? null : \Closure::fromCallable($afterRun);
    }

    public function run(array $items, callable $buildHandle, callable $classify, int $cap): array
    {
        $this->runs++;
        $this->caps[] = $cap;
        $this->itemCounts[] = count($items);
        $outcomes = [];
        foreach ($items as $key => $request) {
            $url = (string) $key;
            $queue = $this->responses[$url] ?? [];
            $outcome = array_shift($queue) ?? ['status' => 500, 'body' => ''];
            $this->responses[$url] = $queue;
            if (($outcome['held'] ?? false) !== true) {
                $this->attempts[$url] = ($this->attempts[$url] ?? 0) + 1;
                $this->requests[] = ['key' => $key, 'request' => $request];
                $ch = $buildHandle($key, $request);
                $chunks = $outcome['callback_chunks'] ?? null;
                if (is_array($chunks)) {
                    $writer = $request['options'][CURLOPT_WRITEFUNCTION] ?? null;
                    if (!is_callable($writer)) {
                        throw new RuntimeException('scripted response requires CURLOPT_WRITEFUNCTION');
                    }
                    foreach ($chunks as $chunk) {
                        $written = $writer($ch, (string) $chunk);
                        $this->writeReturns[] = $written;
                        if ($written !== strlen((string) $chunk)) {
                            break;
                        }
                    }
                    $outcome = $classify($key, $ch, (int) ($outcome['status'] ?? 200));
                }
                curl_close($ch);
            }
            $outcomes[$key] = $outcome;
        }
        if ($this->afterRun !== null) {
            ($this->afterRun)($this->runs);
        }
        return $outcomes;
    }
}

/** Records what it was asked and returns scripted results. */
final class FakeUrlAnalyzer implements UrlAnalyzer
{
    /** @var list<string> */
    public array $seen = [];

    /** @param array<string,array<mixed>> $responses url => raw decoded response */
    public function __construct(private array $responses) {}

    public function analyze(array $urls): array
    {
        $references = [];
        $failures = [];
        foreach ($urls as $url) {
            $this->seen[] = $url;
            $raw = $this->responses[$url] ?? null;
            if ($raw === null) {
                $failures[$url] = ['url' => $url, 'kind' => 'transport_error', 'message' => 'no scripted response'];
                continue;
            }
            $ref = InspirationBrief::fromResponse($url, $raw);
            if ($ref !== null) {
                $references[$url] = $ref;
            } else {
                $failures[$url] = ['url' => $url, 'kind' => 'gate_rejected', 'message' => 'rejected by positive-evidence gate'];
            }
        }
        return ['references' => $references, 'failures' => $failures];
    }
}

test('a fake analyzer satisfies the interface and drops rejected urls', function () {
    $analyzer = new FakeUrlAnalyzer([
        'https://good.com' => [
            'style' => 'Bold',
            'colors' => [['hex' => '#000000', 'name' => 'black', 'role' => 'text']],
            'sections' => [],
        ],
        'https://bad.com' => ['error' => ['message' => 'Timeout fetching screenshot']],
    ]);

    $result = $analyzer->analyze(['https://good.com', 'https://bad.com', 'https://absent.com']);

    assert_eq(['https://good.com'], array_keys($result['references']));
    assert_eq(['https://bad.com', 'https://absent.com'], array_keys($result['failures']));
    assert_eq(3, count($analyzer->seen), 'every url should be attempted');
});

test('a fake analyzer returns an empty array for no urls', function () {
    assert_eq(['references' => [], 'failures' => []], (new FakeUrlAnalyzer([]))->analyze([]));
});

test('WpcomUrlAnalyzer returns an empty array without transport I/O for no urls', function () {
    $pool = new ScriptedAnalyzerCurlMultiPool();

    assert_eq(['references' => [], 'failures' => []], (new WpcomUrlAnalyzer('token', pool: $pool))->analyze([]));
    assert_eq(0, $pool->runs, 'empty input must not enter the transport');
});

test('WpcomUrlAnalyzer accepts one response that passes the positive-evidence gate', function () {
    $url = 'https://good.com';
    $pool = new ScriptedAnalyzerCurlMultiPool([
        $url => [analyzer_outcome(analyzer_valid_response())],
    ]);

    $result = (new WpcomUrlAnalyzer('token', pool: $pool))->analyze([$url]);

    assert_eq([$url], array_keys($result['references']));
    assert_eq([], $result['failures']);
    assert_eq($url, $result['references'][$url]['url']);
    assert_eq(1, $pool->attempts[$url]);
});

test('WpcomUrlAnalyzer drops a 200 error body after one bounded retry', function () {
    $url = 'https://error.com';
    $error = ['error' => ['message' => 'Timeout fetching screenshot']];
    $pool = new ScriptedAnalyzerCurlMultiPool([
        $url => [analyzer_outcome($error), analyzer_outcome($error)],
    ]);

    $result = (new WpcomUrlAnalyzer('token', pool: $pool))->analyze([$url]);
    assert_eq([], $result['references']);
    assert_eq('gate_rejected', $result['failures'][$url]['kind']);
    assert_eq(2, $pool->attempts[$url], 'a rejected response gets exactly one retry');
});

test('WpcomUrlAnalyzer retries a placeholder exactly once and then drops it', function () {
    $url = 'https://placeholder.com';
    $placeholder = analyzer_valid_response('Generating Preview while screenshot loads');
    $pool = new ScriptedAnalyzerCurlMultiPool([
        $url => [analyzer_outcome($placeholder), analyzer_outcome($placeholder)],
    ]);

    $result = (new WpcomUrlAnalyzer('token', pool: $pool))->analyze([$url]);
    assert_eq([], $result['references']);
    assert_eq('gate_rejected', $result['failures'][$url]['kind']);
    assert_eq(2, $pool->attempts[$url]);
});

test('WpcomUrlAnalyzer keeps a valid brief returned by the one retry', function () {
    $url = 'https://retry-success.com';
    $placeholder = analyzer_valid_response('Generating Preview while screenshot loads');
    $pool = new ScriptedAnalyzerCurlMultiPool([
        $url => [analyzer_outcome($placeholder), analyzer_outcome(analyzer_valid_response('Ready'))],
    ]);

    $result = (new WpcomUrlAnalyzer('token', pool: $pool))->analyze([$url]);

    assert_eq([$url], array_keys($result['references']));
    assert_eq([], $result['failures']);
    assert_eq('Ready', $result['references'][$url]['style']);
    assert_eq(2, $pool->attempts[$url]);
});

test('WpcomUrlAnalyzer reports malformed JSON as a non-retryable protocol failure', function () {
    $url = 'https://malformed.com';
    $pool = new ScriptedAnalyzerCurlMultiPool([
        $url => [analyzer_outcome('{not-json'), analyzer_outcome('{still-not-json')],
    ]);

    $result = (new WpcomUrlAnalyzer('token', pool: $pool))->analyze([$url]);
    assert_eq([], $result['references']);
    assert_eq('malformed_response', $result['failures'][$url]['kind']);
    assert_eq(1, $pool->attempts[$url]);
});

test('WpcomUrlAnalyzer reports non-object JSON as a non-retryable protocol failure', function () {
    $url = 'https://scalar.com';
    $pool = new ScriptedAnalyzerCurlMultiPool([
        $url => [analyzer_outcome('"not an object"'), analyzer_outcome('"still not an object"')],
    ]);

    $result = (new WpcomUrlAnalyzer('token', pool: $pool))->analyze([$url]);
    assert_eq([], $result['references']);
    assert_eq('malformed_response', $result['failures'][$url]['kind']);
    assert_eq(1, $pool->attempts[$url]);
});

test('WpcomUrlAnalyzer drops HTTP 500 without retry even when the body would otherwise pass', function () {
    $url = 'https://server-error.com';
    $valid = analyzer_valid_response();
    $pool = new ScriptedAnalyzerCurlMultiPool([
        $url => [analyzer_outcome($valid, 500), analyzer_outcome($valid, 500)],
    ]);

    $result = (new WpcomUrlAnalyzer('token', pool: $pool))->analyze([$url]);
    assert_eq([], $result['references']);
    assert_eq('http_error', $result['failures'][$url]['kind']);
    assert_eq(1, $pool->attempts[$url]);
});

test('WpcomUrlAnalyzer keeps successful siblings when the middle URL fails', function () {
    $urls = ['https://one.com', 'https://bad.com', 'https://three.com'];
    $error = ['error' => ['message' => 'capture failed']];
    $pool = new ScriptedAnalyzerCurlMultiPool([
        $urls[0] => [analyzer_outcome(analyzer_valid_response('One'))],
        $urls[1] => [analyzer_outcome($error), analyzer_outcome($error)],
        $urls[2] => [analyzer_outcome(analyzer_valid_response('Three'))],
    ]);

    $result = (new WpcomUrlAnalyzer('token', pool: $pool))->analyze($urls);

    assert_eq([$urls[0], $urls[2]], array_keys($result['references']));
    assert_eq([$urls[1]], array_keys($result['failures']));
    assert_eq(1, $pool->attempts[$urls[0]]);
    assert_eq(2, $pool->attempts[$urls[1]]);
    assert_eq(1, $pool->attempts[$urls[2]]);
});

test('WpcomUrlAnalyzer analyzes duplicate URLs once', function () {
    $url = 'https://duplicate.com';
    $pool = new ScriptedAnalyzerCurlMultiPool([
        $url => [analyzer_outcome(analyzer_valid_response())],
    ]);

    $result = (new WpcomUrlAnalyzer('token', pool: $pool))->analyze([$url, $url, $url]);

    assert_eq([$url], array_keys($result['references']));
    assert_eq([], $result['failures']);
    assert_eq(1, $pool->attempts[$url]);
});

test('WpcomUrlAnalyzer sends auth payload and 120-second timeout to the handle builder', function () {
    $url = 'https://options.com/path';
    $pool = new ScriptedAnalyzerCurlMultiPool([
        $url => [analyzer_outcome(analyzer_valid_response())],
    ]);

    (new WpcomUrlAnalyzer('sk-SECRET', pool: $pool))->analyze([$url]);

    $request = $pool->requests[0]['request'];
    $options = $request['options'];
    assert_eq('https://public-api.wordpress.com/wpcom/v2/analyze-url/describe', $request['endpoint']);
    assert_eq(120, $options[CURLOPT_TIMEOUT]);
    assert_eq(15, $options[CURLOPT_CONNECTTIMEOUT]);
    assert_eq(false, $options[CURLOPT_RETURNTRANSFER], 'write callback must own response buffering');
    assert_true(is_callable($options[CURLOPT_WRITEFUNCTION]), 'response bytes need a hard-cap callback');
    assert_eq(['authorization: Bearer sk-SECRET', 'content-type: application/json'], $options[CURLOPT_HTTPHEADER]);
    assert_eq('{"url":"https://options.com/path"}', $options[CURLOPT_POSTFIELDS]);
    assert_true(
        !str_contains(strtolower(implode("\n", $options[CURLOPT_HTTPHEADER])), 'x-wpcom-ai-feature:'),
        'analyze-url is not behind the ai-api-proxy route',
    );
    assert_eq([3], $pool->caps);
});

test('WpcomUrlAnalyzer accepts bounded callback bytes and rejects an oversized body normally', function () {
    $good = 'https://bounded.com';
    $large = 'https://oversized.com';
    $encoded = (string) json_encode(analyzer_valid_response('Bounded'), JSON_UNESCAPED_SLASHES);
    $split = intdiv(strlen($encoded), 2);
    $pool = new ScriptedAnalyzerCurlMultiPool([
        $good => [[
            'status' => 200,
            'callback_chunks' => [substr($encoded, 0, $split), substr($encoded, $split)],
        ]],
        $large => [[
            'status' => 200,
            'callback_chunks' => [str_repeat('x', 1_048_576), 'x'],
        ]],
    ]);

    $result = (new WpcomUrlAnalyzer('token', pool: $pool))->analyze([$good, $large]);

    assert_eq([$good], array_keys($result['references']), 'ordinary callback response still decodes');
    assert_eq('malformed_response', $result['failures'][$large]['kind']);
    assert_contains('1048576-byte limit', $result['failures'][$large]['message']);
    assert_eq(1, $pool->attempts[$large], 'oversized endpoint response is not retried');
    assert_eq(
        [strlen(substr($encoded, 0, $split)), strlen(substr($encoded, $split)), 1_048_576, 0],
        $pool->writeReturns,
        'the exact limit is accepted and the first excess byte aborts without buffering',
    );
});

test('WpcomUrlAnalyzer enforces the 150-second budget and skips retry after expiry', function () {
    $url = 'https://slow.com';
    $now = 1000;
    $pool = new ScriptedAnalyzerCurlMultiPool(
        [$url => [analyzer_outcome(['error' => ['message' => 'slow']])]],
        function (int $run) use (&$now): void {
            if ($run === 1) {
                $now = 1150;
            }
        },
    );
    $clock = static function () use (&$now): int {
        return $now;
    };

    $result = (new WpcomUrlAnalyzer('token', timeout: 200, budget: 150, pool: $pool, clock: $clock))
        ->analyze([$url]);

    assert_eq([], $result['references']);
    assert_eq('abandoned', $result['failures'][$url]['kind']);
    assert_eq(150, $pool->requests[0]['request']['options'][CURLOPT_TIMEOUT]);
    assert_eq(1, $pool->attempts[$url], 'deadline expiry prevents the retry wave');
});

test('WpcomUrlAnalyzer enforces the URL cap without over-truncating', function () {
    $urls = array_map(static fn (int $i): string => "https://site{$i}.com", range(1, 8));
    $responses = [];
    foreach ($urls as $url) {
        $responses[$url] = [analyzer_outcome(analyzer_valid_response($url))];
    }
    $pool = new ScriptedAnalyzerCurlMultiPool($responses);

    $result = (new WpcomUrlAnalyzer('token', pool: $pool))->analyze($urls);

    assert_eq(3, count($pool->requests), 'at most InspirationUrls::MAX requests are built');
    assert_eq([3], $pool->itemCounts, 'one full-cap wave leaves nothing queued');
    assert_eq(array_slice($urls, 0, 3), array_keys($result['references']));
    assert_eq(array_slice($urls, 3), array_keys($result['failures']));
    foreach (array_slice($urls, 3) as $url) {
        assert_eq('abandoned', $result['failures'][$url]['kind']);
        assert_eq($url, $result['failures'][$url]['url']);
    }

    $three = array_slice($urls, 0, 3);
    $threeResponses = array_intersect_key($responses, array_flip($three));
    $threePool = new ScriptedAnalyzerCurlMultiPool($threeResponses);
    $threeResult = (new WpcomUrlAnalyzer('token', pool: $threePool))->analyze($three);
    assert_eq($three, array_keys($threeResult['references']), 'exactly MAX URLs are all analyzed');
    assert_eq(3, count($threePool->requests));
});

test('WpcomUrlAnalyzer keeps a full retry wave inside the 150-second budget', function () {
    $urls = ['https://one.com', 'https://two.com', 'https://three.com'];
    $now = 1000;
    $responses = [];
    foreach ($urls as $url) {
        $responses[$url] = [
            analyzer_outcome(['error' => ['message' => 'capture pending']]),
            analyzer_outcome(analyzer_valid_response('Ready')),
        ];
    }
    $pool = new ScriptedAnalyzerCurlMultiPool($responses, function (int $run) use (&$now): void {
        $now = $run === 1 ? 1030 : 1150;
    });
    $clock = static function () use (&$now): int { return $now; };

    $result = (new WpcomUrlAnalyzer('token', timeout: 120, budget: 150, pool: $pool, clock: $clock))
        ->analyze($urls);

    assert_eq($urls, array_keys($result['references']));
    assert_eq([], $result['failures']);
    assert_eq([3, 3], $pool->itemCounts);
    $timeouts = array_map(static fn (array $entry): int => $entry['request']['options'][CURLOPT_TIMEOUT], $pool->requests);
    assert_eq([120, 120, 120, 120, 120, 120], $timeouts);
    assert_true($now - 1000 <= 150, 'full wave stays inside step budget');
});

test('WpcomUrlAnalyzer abandons a retry without one full per-call window', function () {
    $url = 'https://doomed-retry.com';
    $now = 1000;
    $rejected = ['error' => ['message' => 'capture pending']];
    $pool = new ScriptedAnalyzerCurlMultiPool(
        [
            $url => [
                analyzer_outcome($rejected),
                ['status' => 0, 'body' => '', 'transient' => true, 'error' => 'Operation timed out'],
            ],
        ],
        function (int $run) use (&$now): void {
            if ($run === 1) {
                $now = 1149;
            }
        },
    );
    $clock = static function () use (&$now): int { return $now; };

    $result = (new WpcomUrlAnalyzer('token', timeout: 120, budget: 150, pool: $pool, clock: $clock))
        ->analyze([$url]);

    assert_eq('abandoned', $result['failures'][$url]['kind']);
    assert_eq(1, $pool->runs, 'doomed retry wave must not enter transport');
    assert_eq(1, $pool->attempts[$url]);
});

test('WpcomUrlAnalyzer reports actionable per-URL failure reasons without logs', function () {
    $urls = ['https://good.com', 'https://gate.com', 'https://auth.com'];
    $gate = ['error' => ['message' => 'capture failed']];
    $pool = new ScriptedAnalyzerCurlMultiPool([
        $urls[0] => [analyzer_outcome(analyzer_valid_response())],
        $urls[1] => [analyzer_outcome($gate), analyzer_outcome($gate)],
        $urls[2] => [analyzer_outcome(['error' => 'unauthorized'], 401)],
    ]);

    $result = (new WpcomUrlAnalyzer('token', pool: $pool))->analyze($urls);

    assert_eq([$urls[0]], array_keys($result['references']));
    assert_eq([$urls[1], $urls[2]], array_keys($result['failures']));
    assert_eq(['gate_rejected', 'http_error'], array_column($result['failures'], 'kind'));
    foreach ($result['failures'] as $url => $failure) {
        assert_eq($url, $failure['url']);
        assert_true($failure['message'] !== '', 'failure message must be actionable');
    }
});

test('WpcomUrlAnalyzer surfaces each gate-rejection reason without logs', function () {
    $urls = ['https://error-body.com', 'https://placeholder.com', 'https://empty.com'];
    $error = ['error' => ['message' => 'Timeout fetching screenshot']];
    $placeholder = analyzer_valid_response('Generating Preview while screenshot loads');
    $empty = ['style' => 'A sparse page', 'colors' => [], 'sections' => []];
    $pool = new ScriptedAnalyzerCurlMultiPool([
        $urls[0] => [analyzer_outcome($error), analyzer_outcome($error)],
        $urls[1] => [analyzer_outcome($placeholder), analyzer_outcome($placeholder)],
        $urls[2] => [analyzer_outcome($empty), analyzer_outcome($empty)],
    ]);

    $result = (new WpcomUrlAnalyzer('token', pool: $pool))->analyze($urls);

    assert_eq([], $result['references']);
    assert_eq($urls, array_keys($result['failures']));
    assert_eq(['gate_rejected', 'gate_rejected', 'gate_rejected'], array_column($result['failures'], 'kind'));
    assert_eq([
        'endpoint error: Timeout fetching screenshot',
        'response described the mShots placeholder',
        'response contained neither usable colors nor sections',
    ], array_column($result['failures'], 'message'));
});

test('WpcomUrlAnalyzer backs off before retrying held 429 launches', function () {
    $urls = ['https://limited.com', 'https://held.com'];
    $held = ['ok' => false, 'transient' => true, 'held' => true, 'error' => 'launch held: sibling HTTP 429'];
    $events = [];
    $pool = new ScriptedAnalyzerCurlMultiPool(
        [
            $urls[0] => [analyzer_outcome(['error' => 'slow down'], 429), analyzer_outcome(analyzer_valid_response())],
            $urls[1] => [$held, analyzer_outcome(analyzer_valid_response())],
        ],
        function (int $run) use (&$events): void { $events[] = "run:{$run}"; },
    );
    $sleeper = function (int $seconds) use (&$events): void { $events[] = "sleep:{$seconds}"; };

    $result = (new WpcomUrlAnalyzer('token', pool: $pool, retryDelays: [5], sleeper: $sleeper))
        ->analyze($urls);

    assert_eq(['run:1', 'sleep:5', 'run:2'], $events, 'retry wave waits before firing');
    assert_eq($urls, array_keys($result['references']));
    assert_eq([], $result['failures']);
});

test('WpcomUrlAnalyzer never throws when handle building or pool protocol fails', function () {
    $url = 'https://broken.com';
    $buildFailurePool = new class extends CurlMultiPool {
        public function run(array $items, callable $buildHandle, callable $classify, int $cap): array
        {
            $request = reset($items);
            $request['endpoint'] = [];
            $buildHandle((string) array_key_first($items), $request);
            return [];
        }
    };
    $buildResult = (new WpcomUrlAnalyzer('token', pool: $buildFailurePool))->analyze([$url]);
    assert_eq('transport_error', $buildResult['failures'][$url]['kind']);

    $protocolFailurePool = new class extends CurlMultiPool {
        public function run(array $items, callable $buildHandle, callable $classify, int $cap): array
        {
            throw new RuntimeException('rolling pool protocol failed');
        }
    };
    $protocolResult = (new WpcomUrlAnalyzer('token', pool: $protocolFailurePool))->analyze([$url]);
    assert_eq('transport_error', $protocolResult['failures'][$url]['kind']);
    assert_contains('rolling pool protocol failed', $protocolResult['failures'][$url]['message']);
});

test('WpcomUrlAnalyzer does not retry 401 but still retries gate rejection', function () {
    $auth = 'https://auth.com';
    $gate = 'https://gate.com';
    $rejected = ['error' => ['message' => 'capture failed']];
    $pool = new ScriptedAnalyzerCurlMultiPool([
        $auth => [analyzer_outcome(['error' => 'unauthorized'], 401)],
        $gate => [analyzer_outcome($rejected), analyzer_outcome($rejected)],
    ]);

    $result = (new WpcomUrlAnalyzer('token', pool: $pool))->analyze([$auth, $gate]);

    assert_eq(1, $pool->attempts[$auth]);
    assert_eq(2, $pool->attempts[$gate]);
    assert_eq('http_error', $result['failures'][$auth]['kind']);
    assert_eq('gate_rejected', $result['failures'][$gate]['kind']);
});

test('WpcomUrlAnalyzer rejects a cap below the supported URL maximum', function () {
    $error = assert_throws(fn () => new WpcomUrlAnalyzer('token', cap: 2));

    assert_contains('must be at least InspirationUrls::MAX (3)', $error->getMessage());
});

test('WpcomUrlAnalyzer logs the same authorization header only in redacted form', function () {
    with_temp_dir('inspiration-analyzer-log-', function (string $dir): void {
        $url = 'https://logged.com';
        $token = 'sk-SECRET-ANALYZER';
        $pool = new ScriptedAnalyzerCurlMultiPool([
            $url => [analyzer_outcome(analyzer_valid_response())],
        ]);
        InspirationLogger::setDir($dir);
        try {
            (new WpcomUrlAnalyzer($token, pool: $pool))->analyze([$url]);
        } finally {
            InspirationLogger::setDir(null);
        }

        $files = glob($dir . '/*.txt') ?: [];
        assert_eq(1, count($files));
        $transcript = (string) file_get_contents($files[0]);
        $rawHeader = 'authorization: Bearer ' . $token;
        assert_true(in_array($rawHeader, $pool->requests[0]['request']['options'][CURLOPT_HTTPHEADER], true));
        assert_true(!str_contains($transcript, $token), 'transcript must not contain bearer token');
        assert_true(!str_contains($transcript, $rawHeader), 'transcript must not contain raw authorization header');
        assert_contains('[REDACTED]', $transcript);
    });
});

test('WpcomUrlAnalyzer default deadline ignores a namespaced wall-clock jump', function () {
    $wallClock = [1000, 5000, 5000, 5000];
    $GLOBALS['wpcom_test_wall_clock'] = static function () use (&$wallClock): int {
        return array_shift($wallClock) ?? 5000;
    };

    try {
        $url = 'https://monotonic.com';
        $rejected = ['error' => ['message' => 'capture pending']];
        $pool = new ScriptedAnalyzerCurlMultiPool([
            $url => [analyzer_outcome($rejected), analyzer_outcome(analyzer_valid_response('Recovered'))],
        ]);

        $result = (new WpcomUrlAnalyzer('token', pool: $pool))->analyze([$url]);

        assert_eq([$url], array_keys($result['references']));
        assert_eq(2, $pool->attempts[$url], 'wall-clock jump must not consume monotonic retry budget');
    } finally {
        unset($GLOBALS['wpcom_test_wall_clock']);
    }
});

}
