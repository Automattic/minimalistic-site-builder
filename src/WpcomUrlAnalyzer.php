<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Reference-site analysis via the WPCOM analyze-url endpoint.
 *
 * mShots refuses requests from outside a8c, so screenshotting locally is not
 * an option — this endpoint is the only path. It screenshots and runs a vision
 * model server-side and hands back structured JSON.
 *
 * One retry per retryable URL: positive-evidence gate rejections retry because
 * the endpoint may still be rendering a screenshot; transient transport
 * failures and explicit rate limits retry after backoff. Other HTTP errors do
 * not retry.
 */
final class WpcomUrlAnalyzer implements UrlAnalyzer
{
    private const ENDPOINT = 'https://public-api.wordpress.com/wpcom/v2/analyze-url/describe';
    private const MAX_RESPONSE_BYTES = 1_048_576;

    private CurlMultiPool $pool;
    private \Closure $clock;
    private \Closure $sleeper;

    /**
     * A retry launches only when round one and backoff leave one full timeout window.
     * A budget below the timeout disables retry; equality permits only the
     * zero-elapsed, no-backoff edge.
     *
     * @param string                  $apiToken WordPress.com OAuth bearer. NOT the Vertex token —
     *        that one is scoped to the ai-api-proxy route, which this is not behind.
     * @param int                     $timeout  per-call seconds; the endpoint polls mShots internally
     * @param int                     $budget   total seconds before outstanding references are abandoned
     * @param int                     $cap      most concurrent transfers; must cover every supported URL
     * @param CurlMultiPool|null      $pool     injected transport for deterministic, network-free tests
     * @param callable():int|null     $clock    injected seconds clock for deadline tests
     * @param list<int>               $retryDelays retry backoff schedule in seconds
     * @param callable(int):void|null $sleeper  injected sleeper for deterministic backoff tests
     */
    public function __construct(
        private string $apiToken,
        private int $timeout = 120,
        private int $budget = 150,
        private int $cap = 3,
        ?CurlMultiPool $pool = null,
        ?callable $clock = null,
        private array $retryDelays = [1],
        ?callable $sleeper = null,
    ) {
        if ($cap < InspirationUrls::MAX) {
            throw new \InvalidArgumentException(sprintf(
                'URL analyzer concurrency cap must be at least InspirationUrls::MAX (%d)',
                InspirationUrls::MAX,
            ));
        }
        $this->pool = $pool ?? new CurlMultiPool();
        $this->clock = $clock === null
            ? static fn (): int => intdiv(hrtime(true), 1_000_000_000)
            : \Closure::fromCallable($clock);
        $this->sleeper = $sleeper === null
            ? static function (int $seconds): void { sleep($seconds); }
            : \Closure::fromCallable($sleeper);
    }

    public function analyze(array $urls): array
    {
        $urls = array_values(array_unique($urls));
        $selected = array_slice($urls, 0, InspirationUrls::MAX);
        $failures = [];
        foreach (array_slice($urls, InspirationUrls::MAX) as $url) {
            $failures[$url] = $this->failure(
                $url,
                'abandoned',
                sprintf('URL was not analyzed because the maximum is %d', InspirationUrls::MAX),
            );
        }

        if ($selected === []) {
            return ['references' => [], 'failures' => $failures];
        }

        $references = [];
        try {
            $deadline = $this->now() + $this->budget;
            $first = $this->round($selected, $deadline);
            $retryUrls = [];
            $retryWaits = [];
            $held = false;

            foreach ($selected as $url) {
                $outcome = $first[$url];
                if ($outcome['reference'] !== null) {
                    $references[$url] = $outcome['reference'];
                    continue;
                }
                if (!$outcome['retryable']) {
                    $failures[$url] = $outcome['failure'];
                    continue;
                }
                $retryUrls[] = $url;
                $held = $held || $outcome['held'];
                if ($outcome['wait'] !== null) {
                    $retryWaits[] = $outcome['wait'];
                }
            }

            if ($retryUrls === []) {
                return $this->result($urls, $references, $failures);
            }

            $wait = $held || $retryWaits !== []
                ? CurlMultiPool::heldWaveWait($retryWaits, $this->retryDelays)
                : 0;
            $remainingAfterBackoff = $deadline - $this->now() - $wait;
            if ($remainingAfterBackoff < $this->timeout) {
                return $this->abandon($urls, $references, $failures, $retryUrls, 'analysis deadline leaves less than one full retry window');
            }
            if ($wait > 0) {
                ($this->sleeper)($wait);
            }
            if ($deadline - $this->now() < $this->timeout) {
                return $this->abandon($urls, $references, $failures, $retryUrls, 'analysis deadline lost the full retry window during backoff');
            }

            $second = $this->round($retryUrls, $deadline);
            foreach ($retryUrls as $url) {
                $outcome = $second[$url];
                if ($outcome['reference'] !== null) {
                    $references[$url] = $outcome['reference'];
                } elseif ($this->now() >= $deadline) {
                    $failures[$url] = $this->failure($url, 'abandoned', 'analysis deadline expired');
                } else {
                    $failures[$url] = $outcome['failure'];
                }
            }

            return $this->result($urls, $references, $failures);
        } catch (\Throwable $e) {
            foreach ($selected as $url) {
                if (!isset($references[$url]) && !isset($failures[$url])) {
                    $failures[$url] = $this->failure(
                        $url,
                        'transport_error',
                        'URL analysis transport failed: ' . $e->getMessage(),
                    );
                    $this->logFailure($url, $e->getMessage());
                }
            }
            return $this->result($urls, $references, $failures);
        }
    }

    /**
     * @param  list<string> $urls
     * @return array<string,array{
     *     reference:array<string,mixed>|null,
     *     failure:array{url:string,kind:string,message:string},
     *     retryable:bool,
     *     held:bool,
     *     wait:int|null
     * }>
     */
    private function round(array $urls, int $deadline): array
    {
        /** @var array<string,array{endpoint:string,options:array<int,mixed>,response:\stdClass}> $requests */
        $requests = [];
        foreach ($urls as $url) {
            $remaining = max(1, min($this->timeout, $deadline - $this->now()));
            $response = (object) ['body' => '', 'oversized' => false];
            $requests[$url] = [
                'endpoint' => self::ENDPOINT,
                'options' => $this->optionsFor($url, $remaining, $response),
                'response' => $response,
            ];
        }

        $rawOutcomes = $this->pool->run(
            $requests,
            fn (string|int $key, array $request): \CurlHandle => $this->buildHandle($request),
            static function (string|int $key, \CurlHandle $ch, int $status) use ($requests): array {
                $response = $requests[$key]['response'];
                return [
                    'status' => $status,
                    'body' => $response->body,
                    'oversized' => $response->oversized,
                    'errno' => curl_errno($ch),
                    'error' => curl_error($ch),
                ];
            },
            $this->cap,
        );

        $outcomes = [];
        foreach ($urls as $url) {
            $raw = $rawOutcomes[$url] ?? [
                'ok' => false,
                'transient' => true,
                'error' => 'pool returned no outcome',
            ];
            if (!is_array($raw)) {
                throw new \RuntimeException('rolling pool returned a non-array outcome');
            }
            $outcomes[$url] = $this->classify($url, $requests[$url], $raw);
        }
        return $outcomes;
    }

    /**
     * Pure request-option builder. Keeping configuration as data lets tests
     * assert timeout, budget, auth, and payload values without executing cURL.
     *
     * @return array<int,mixed>
     */
    private function optionsFor(string $url, int $timeout, \stdClass $response): array
    {
        $headers = [
            'authorization: Bearer ' . $this->apiToken,
            'content-type: application/json',
        ];

        return [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => static function (\CurlHandle $handle, string $chunk) use ($response): int {
                $length = strlen($chunk);
                if ($response->oversized || strlen($response->body) + $length > self::MAX_RESPONSE_BYTES) {
                    $response->oversized = true;
                    return 0;
                }
                $response->body .= $chunk;
                return $length;
            },
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => (string) json_encode(['url' => $url], JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
        ];
    }

    /** @param array{endpoint:string,options:array<int,mixed>} $request */
    private function buildHandle(array $request): \CurlHandle
    {
        $ch = curl_init($request['endpoint']);
        if (!$ch instanceof \CurlHandle) {
            throw new \RuntimeException('Could not initialize analyze-url cURL handle');
        }
        if (!curl_setopt_array($ch, $request['options'])) {
            curl_close($ch);
            throw new \RuntimeException('Could not configure analyze-url cURL handle');
        }
        return $ch;
    }

    /**
     * @param array{endpoint:string,options:array<int,mixed>} $request
     * @param array<string,mixed>                             $outcome
     * @return array{
     *     reference:array<string,mixed>|null,
     *     failure:array{url:string,kind:string,message:string},
     *     retryable:bool,
     *     held:bool,
     *     wait:int|null
     * }
     */
    private function classify(string $url, array $request, array $outcome): array
    {
        $status = is_int($outcome['status'] ?? null) ? $outcome['status'] : 0;
        $body = is_string($outcome['body'] ?? null) ? $outcome['body'] : '';
        $requestLog = [
            'endpoint' => $request['endpoint'],
            'url' => $url,
            'status' => $status,
            // Same header list used to configure cURL. InspirationLogger owns redaction.
            'headers' => $request['options'][CURLOPT_HTTPHEADER],
        ];

        if (($outcome['oversized'] ?? false) === true) {
            $message = sprintf('response exceeded %d-byte limit', self::MAX_RESPONSE_BYTES);
            $this->safeLog($url, $requestLog, [], $message);
            return $this->failed($url, 'malformed_response', $message, false);
        }

        if (($outcome['held'] ?? false) === true) {
            $message = is_string($outcome['error'] ?? null)
                ? $outcome['error']
                : 'launch held after a sibling was rate-limited';
            $this->safeLog($url, $requestLog, [], $message);
            return $this->failed($url, 'transport_error', $message, true, true, null);
        }

        $errno = is_int($outcome['errno'] ?? null) ? $outcome['errno'] : 0;
        if ($errno !== 0) {
            $message = is_string($outcome['error'] ?? null) && $outcome['error'] !== ''
                ? $outcome['error']
                : "cURL error {$errno}";
            $retryable = in_array($errno, $this->transientCurlErrors(), true);
            $this->safeLog($url, $requestLog, [], $message);
            return $this->failed(
                $url,
                'transport_error',
                $message,
                $retryable,
                false,
                $retryable ? $this->firstRetryDelay() : null,
            );
        }

        if (($outcome['transient'] ?? false) === true && $status === 0) {
            $message = is_string($outcome['error'] ?? null)
                ? $outcome['error']
                : 'transient transport failure';
            $this->safeLog($url, $requestLog, [], $message);
            return $this->failed($url, 'transport_error', $message, true, false, $this->firstRetryDelay());
        }

        if ($status >= 400) {
            $message = "HTTP {$status}";
            $decoded = $this->decodeForLog($body);
            $retryable = $status === 429;
            $this->safeLog($url, $requestLog, $decoded, $message);
            return $this->failed(
                $url,
                'http_error',
                $message,
                $retryable,
                false,
                $retryable ? $this->firstRetryDelay() : null,
            );
        }

        if ($status === 0 || $body === '') {
            $message = is_string($outcome['error'] ?? null) && $outcome['error'] !== ''
                ? $outcome['error']
                : "empty response (status {$status})";
            $this->safeLog($url, $requestLog, [], $message);
            return $this->failed($url, 'transport_error', $message, true, false, $this->firstRetryDelay());
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $message = 'malformed JSON: ' . $e->getMessage();
            $this->safeLog($url, $requestLog, [], $message);
            return $this->failed($url, 'malformed_response', $message, false);
        }
        if (!is_array($decoded)) {
            $message = 'response was not a JSON object';
            $this->safeLog($url, $requestLog, [], $message);
            return $this->failed($url, 'malformed_response', $message, false);
        }

        $reference = InspirationBrief::fromResponse($url, $decoded);
        if ($reference === null) {
            $message = InspirationBrief::rejectionReason($decoded);
            $this->safeLog($url, $requestLog, $decoded, $message);
            return $this->failed($url, 'gate_rejected', $message, true);
        }

        $this->safeLog($url, $requestLog, $decoded);
        return [
            'reference' => $reference,
            'failure' => $this->failure($url, 'gate_rejected', ''),
            'retryable' => false,
            'held' => false,
            'wait' => null,
        ];
    }

    /** @return list<int> */
    private function transientCurlErrors(): array
    {
        return [
            CURLE_COULDNT_RESOLVE_PROXY,
            CURLE_COULDNT_RESOLVE_HOST,
            CURLE_COULDNT_CONNECT,
            CURLE_OPERATION_TIMEDOUT,
            CURLE_SEND_ERROR,
            CURLE_RECV_ERROR,
            CURLE_GOT_NOTHING,
        ];
    }

    private function firstRetryDelay(): int
    {
        return max(0, $this->retryDelays[0] ?? 0);
    }

    /**
     * @return array{
     *     reference:null,
     *     failure:array{url:string,kind:string,message:string},
     *     retryable:bool,
     *     held:bool,
     *     wait:int|null
     * }
     */
    private function failed(
        string $url,
        string $kind,
        string $message,
        bool $retryable,
        bool $held = false,
        ?int $wait = null,
    ): array {
        return [
            'reference' => null,
            'failure' => $this->failure($url, $kind, $message),
            'retryable' => $retryable,
            'held' => $held,
            'wait' => $wait,
        ];
    }

    /** @return array{url:string,kind:string,message:string} */
    private function failure(string $url, string $kind, string $message): array
    {
        return ['url' => $url, 'kind' => $kind, 'message' => $message];
    }

    /**
     * @param list<string> $urls
     * @param array<string,array<string,mixed>> $references
     * @param array<string,array{url:string,kind:string,message:string}> $failures
     * @return array{references:array<string,array<string,mixed>>,failures:array<string,array{url:string,kind:string,message:string}>}
     */
    private function result(array $urls, array $references, array $failures): array
    {
        $orderedReferences = [];
        $orderedFailures = [];
        foreach ($urls as $url) {
            if (isset($references[$url])) {
                $orderedReferences[$url] = $references[$url];
            } elseif (isset($failures[$url])) {
                $orderedFailures[$url] = $failures[$url];
            }
        }
        return ['references' => $orderedReferences, 'failures' => $orderedFailures];
    }

    /**
     * @param list<string> $allUrls
     * @param array<string,array<string,mixed>> $references
     * @param array<string,array{url:string,kind:string,message:string}> $failures
     * @param list<string> $urls
     * @return array{references:array<string,array<string,mixed>>,failures:array<string,array{url:string,kind:string,message:string}>}
     */
    private function abandon(
        array $allUrls,
        array $references,
        array $failures,
        array $urls,
        string $message,
    ): array {
        foreach ($urls as $url) {
            $failures[$url] = $this->failure($url, 'abandoned', $message);
        }
        return $this->result($allUrls, $references, $failures);
    }

    /** @return array<string,mixed> */
    private function decodeForLog(string $body): array
    {
        if ($body === '') {
            return [];
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $response */
    private function safeLog(string $url, array $request, array $response, ?string $error = null): void
    {
        try {
            InspirationLogger::log($url, $request, $response, $error);
        } catch (\Throwable) {
            // Logging is observability only; it must not violate analyze()'s no-throw contract.
        }
    }

    private function logFailure(string $url, string $message): void
    {
        $this->safeLog($url, ['endpoint' => self::ENDPOINT, 'url' => $url], [], $message);
    }

    private function now(): int
    {
        return ($this->clock)();
    }
}
