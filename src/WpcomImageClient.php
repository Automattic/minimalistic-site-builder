<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Image generation transport via the WPCOM AI proxy (Google Vertex Gemini).
 *
 * The wpcom-specific half of image generation: the proxy endpoint, the
 * feature-slugged auth header, and the cURL I/O. Everything protocol-shaped
 * (request bodies, response classification, prompt-spec math, batch-retry
 * orchestration) lives in {@see GeminiImage}, so a different host can ship its
 * own ImageClient over the same protocol without copying any of this.
 *
 * Zero dependencies: a plain cURL POST, matching AnthropicClient's style. This
 * is the one proxy route that works for the builder — the GOOGLE_VERTEX_API_TOKEN
 * is scoped to Google Vertex, and telex uses the same endpoint for theme images.
 * See PROGRESS.md (Phase 0) for why Claude cannot go through the proxy.
 */
final class WpcomImageClient implements ImageClient
{
    private const ENDPOINT_TPL =
        'https://public-api.wordpress.com/wpcom/v2/ai-api-proxy/v1/publishers/google/models/%s:generateContent';

    /**
     * Most concurrent in-flight generateContent requests. The rolling pool keeps up to
     * this many transfers running and refills a slot the moment its transfer
     * completes, so a wide batch is bounded without stalling behind a barrier.
     */
    private const MAX_CONCURRENCY = 10;

    private int $requests = 0;

    /**
     * @param array<int,int> $retryDelays seconds to wait before retries 1..N of
     *        a retryable failure — transient transport errors AND
     *        safety-filtered prompts, which the non-deterministic filter can
     *        pass on a later attempt (its length is the max retry count)
     */
    public function __construct(
        private string $apiToken,
        private string $model = 'gemini-3.1-flash-image',
        private string $feature = 'builder-theme-image',
        private array $retryDelays = [2, 5, 12],
    ) {}

    /** How many image requests this client has made. */
    public function requestCount(): int
    {
        return $this->requests;
    }

    /** The image model this client generates with (used for request logging). */
    public function model(): string
    {
        return $this->model;
    }

    public function generate(string $prompt, array $opts = []): string
    {
        $bytes = $this->requestWithRetry(GeminiImage::buildBody($prompt, $opts));
        $this->requests++;
        return $this->encodeForMime($bytes, ($opts['mime'] ?? null) ?: 'image/jpeg');
    }

    /**
     * Generate several images concurrently (one cURL transfer per spec, run
     * together via curl_multi). Transient failures within the batch are retried
     * — only the still-failing handles, with backoff — so a slow image never
     * blocks the others and a partial failure never aborts the rest.
     *
     * @param array<int,array{prompt:string,aspect_ratio?:string}> $specs
     * @return array<int,array{ok:bool,bytes?:string,error?:string,filtered?:bool}>
     *         keyed by the same index as $specs (order preserved); `filtered`
     *         marks a prompt the safety filter rejected on every attempt
     */
    public function generateBatch(array $specs, ?callable $onResult = null): array
    {
        if ($specs === []) {
            return [];
        }

        // Bodies keyed by the caller's index. The requested mime stays local:
        // Gemini has no output-format knob, so JPEG assets are re-encoded
        // client-side as each success is delivered.
        $bodies = [];
        $mimes = [];
        foreach ($specs as $i => $spec) {
            $mimes[$i] = ($spec['mime'] ?? null) ?: 'image/jpeg';
            $bodies[$i] = GeminiImage::buildBody((string) $spec['prompt'], [
                'aspect_ratio'      => $spec['aspect_ratio'] ?? '16:9',
                'sample_image_size' => $spec['sample_image_size'] ?? null,
            ]);
        }

        // With a caller callback, success bytes leave the pipeline the moment
        // a transfer is classified (success is always final): the transport
        // delivers them straight to $onResult and returns light outcomes, so
        // neither the pool nor retryBatch ever holds every generated image at
        // once (52 images ≈ 150MB — over PHP's default limit). retryBatch
        // then reports only the FAILURES, whose finality it alone knows.
        $onBytes = $onResult === null ? null
            : function (int $i, string $bytes) use ($onResult, $mimes): void {
                $onResult($i, ['ok' => true, 'bytes' => $this->encodeForMime($bytes, $mimes[$i])]);
            };
        $out = GeminiImage::retryBatch(
            $bodies,
            fn (array $subset): array => $this->multiRequest($subset, $onBytes),
            $this->retryDelays,
            static function (int $count, int $attempt, int $wait): void {
                Narrator::write("    (retryable image API failure on {$count} image(s); retry {$attempt} in {$wait}s)\n");
            },
            $onResult === null ? null
                : static function (int $i, array $result) use ($onResult): void {
                    if (!($result['ok'] ?? false)) {
                        $onResult($i, $result);
                    }
                },
        );
        $this->requests += $out['succeeded'];
        $results = $out['results'];
        if ($onResult === null) {
            // No callback delivery: the returned records still carry bytes,
            // which must honor the requested mime just like the callback path.
            foreach ($results as $i => $result) {
                if (($result['ok'] ?? false) && isset($result['bytes'])) {
                    $results[$i]['bytes'] = $this->encodeForMime($result['bytes'], $mimes[$i]);
                }
            }
        }
        return $results;
    }

    /**
     * Honor the asset's requested output format: Gemini emits PNG, so JPEG
     * assets are re-encoded here at the delivery boundary. PNG assets pass
     * through untouched (their transparency keying happens downstream).
     */
    private function encodeForMime(string $bytes, string $mime): string
    {
        return $mime === 'image/jpeg' ? GeminiImage::toJpeg($bytes) : $bytes;
    }

    /**
     * Run a set of generateContent requests through a rolling pool — at most
     * MAX_CONCURRENCY in flight, the freed slot refilled the moment any
     * transfer completes — and classify each transfer. Pure transport — no
     * retry, no request counting. A 429 on any member holds all further
     * launches for the round: held members come back transient with
     * `held: true`, so GeminiImage::retryBatch re-sends them after its backoff
     * without charging its budget, and the pool doesn't fire the whole batch
     * into a rate-limit event as fast as the 429s bounce back.
     *
     * With $onBytes, a successful transfer's decoded bytes are handed to the
     * callback immediately and the returned outcome is `['ok' => true]` with
     * no payload — the pool's result set stays light no matter how many
     * images the batch carries.
     *
     * @param array<int,array<string,mixed>> $bodies request body keyed by index
     * @param callable(int,string):void|null $onBytes immediate delivery for each success
     * @return array<int,array{ok:bool,bytes?:string,error?:string,transient?:bool,held?:bool,filtered?:bool}>
     */
    private function multiRequest(array $bodies, ?callable $onBytes = null): array
    {
        $multi = curl_multi_init();
        /** @var array<int,\CurlHandle> $handles */
        $handles = [];
        /** @var array<int,int> $keysById spl_object_id(handle) => request index */
        $keysById = [];
        /** @var array<int,array<string,mixed>> $queuedOutcomes synthetic transient outcomes (refused adds, held launches) drained by $await before any I/O */
        $queuedOutcomes = [];
        $holding = false;

        $start = function (string|int $i, array $body) use ($multi, &$handles, &$keysById, &$queuedOutcomes, &$holding): void {
            if ($holding) {
                $queuedOutcomes[$i] = [
                    'ok' => false,
                    'transient' => true,
                    'held' => true,
                    'error' => 'launch held: a sibling request was rate-limited (HTTP 429)',
                ];
                return;
            }
            $ch = $this->buildHandle($body);
            if (curl_multi_add_handle($multi, $ch) !== CURLM_OK) {
                curl_close($ch);
                $queuedOutcomes[$i] = [
                    'ok' => false,
                    'transient' => true,
                    'error' => 'curl_multi_add_handle refused the transfer',
                ];
                return;
            }
            $handles[$i] = $ch;
            $keysById[spl_object_id($ch)] = $i;
        };

        // Classify one finished transfer and release its handle (and slot).
        $finish = function (string|int $i, \CurlHandle $ch) use ($multi, &$handles, &$keysById, &$holding, $onBytes): array {
            $raw    = (string) curl_multi_getcontent($ch);
            $errno  = curl_errno($ch);
            $error  = curl_error($ch);
            $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpStatus === 429) {
                $holding = true;
            }
            unset($handles[$i], $keysById[spl_object_id($ch)]);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);

            try {
                self::throwOnTransportError($errno, $error);
                if ($errno === 0 && $httpStatus === 0) {
                    // No response headers at all (a CURLM-level failure):
                    // operational, not the prompt's fault — retry it.
                    throw new TransientApiException('no response received before the transfer stopped');
                }
                $bytes = GeminiImage::interpret($raw, $httpStatus);
            } catch (ImageFilteredException $e) {
                // The safety filter is non-deterministic: retry like a
                // transient failure, but keep the filtered flag so the caller
                // can repair the prompt once the retries run out.
                return ['ok' => false, 'transient' => true, 'filtered' => true, 'error' => $e->getMessage()];
            } catch (TransientApiException $e) {
                return ['ok' => false, 'transient' => true, 'error' => $e->getMessage()];
            } catch (\RuntimeException $e) {
                return ['ok' => false, 'transient' => false, 'error' => $e->getMessage()];
            }

            // Delivery belongs to the caller, not the transport classifier:
            // persistence/I/O failures and programming invariants must escape
            // instead of being mislabeled as a permanent image API failure.
            if ($onBytes !== null) {
                $onBytes((int) $i, $bytes);
                return ['ok' => true];
            }
            return ['ok' => true, 'bytes' => $bytes];
        };

        $await = function () use ($multi, &$handles, &$keysById, &$queuedOutcomes, $finish): array {
            if ($queuedOutcomes !== []) {
                $done = $queuedOutcomes;
                $queuedOutcomes = [];
                return $done;
            }
            // Drive the stack until at least one transfer finishes. The -1
            // guard prevents a busy-spin while there is no socket yet (DNS).
            do {
                $status = curl_multi_exec($multi, $running);
                $done = [];
                while (($msg = curl_multi_info_read($multi)) !== false) {
                    if ($msg['msg'] !== CURLMSG_DONE) {
                        continue;
                    }
                    $i = $keysById[spl_object_id($msg['handle'])]
                        ?? throw new \RuntimeException('rolling pool got a completion for an unregistered curl handle');
                    $done[$i] = $finish($i, $msg['handle']);
                }
                if ($done !== []) {
                    return $done;
                }
                if ($running && $status === CURLM_OK && curl_multi_select($multi, 1.0) === -1) {
                    usleep(1000);
                }
            } while ($running && $status === CURLM_OK);

            // The multi stack stopped without reporting a completion (a CURLM
            // failure). Classify what every remaining transfer holds so far —
            // $finish marks never-responded transfers transient — rather than
            // hanging the pool or discarding sibling responses.
            $done = [];
            foreach ($handles as $i => $ch) {
                $done[$i] = $finish($i, $ch);
            }
            return $done;
        };

        try {
            return RollingPool::run($bodies, $start, $await, self::MAX_CONCURRENCY);
        } finally {
            curl_multi_close($multi);
        }
    }

    /**
     * POST the request, retrying retryable failures — transient transport
     * errors (429/5xx, stalls) and safety-filtered prompts — with backoff. A
     * prompt still filtered after the retries rethrows ImageFilteredException
     * so the caller can repair the prompt.
     *
     * @param array<string,mixed> $body
     */
    private function requestWithRetry(array $body): string
    {
        $delays = $this->retryDelays; // seconds before retries
        $attempt = 0;
        while (true) {
            try {
                return $this->request($body);
            } catch (TransientApiException|ImageFilteredException $e) {
                if ($attempt >= count($delays)) {
                    if ($e instanceof ImageFilteredException) {
                        throw $e; // callers tell a filtered prompt from a transport failure
                    }
                    throw new \RuntimeException('Image proxy failed after retries: ' . $e->getMessage(), 0, $e);
                }
                $wait = $delays[$attempt];
                $attempt++;
                Narrator::write("    (transient image API error: {$e->getMessage()}; retry {$attempt} in {$wait}s)\n");
                sleep($wait);
            }
        }
    }

    /**
     * One generateContent call. Returns decoded image bytes.
     *
     * @param array<string,mixed> $body
     * @throws TransientApiException on a retryable failure (429, 5xx, stall)
     */
    private function request(array $body): string
    {
        $ch = $this->buildHandle($body);
        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        self::throwOnTransportError($errno, $error);
        return GeminiImage::interpret((string) $raw, (int) $status);
    }

    /**
     * Classify a completed cURL transfer's connection-level result — the
     * transport-specific half interpret() must not carry. Retryable connect/
     * timeout/stall errnos become a TransientApiException; any other non-zero
     * errno is a permanent transport failure.
     *
     * @throws TransientApiException on a retryable connection failure
     */
    private static function throwOnTransportError(int $errno, string $error): void
    {
        // Connection-level failures: DNS, timeout, stall, connect/recv — retryable.
        if (in_array($errno, TransientApiException::TRANSIENT_CURL_ERRNOS, true)) {
            throw new TransientApiException("cURL ({$errno}): {$error}");
        }
        if ($errno !== 0) {
            throw new \RuntimeException("cURL error ({$errno}): {$error}");
        }
    }

    /**
     * Build a configured cURL handle for one generateContent request. Used by both the
     * single (curl_exec) and batched (curl_multi) paths.
     *
     * @param array<string,mixed> $body
     * @return \CurlHandle
     */
    private function buildHandle(array $body)
    {
        $endpoint = sprintf(self::ENDPOINT_TPL, $this->model);
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'authorization: Bearer ' . $this->apiToken,
                'x-wpcom-ai-feature: ' . $this->feature,
                'content-type: application/json',
            ],
            CURLOPT_POSTFIELDS      => $payload,
            CURLOPT_TIMEOUT         => 180, // image generation can take 60s+
            CURLOPT_CONNECTTIMEOUT  => 15,
        ]);
        return $ch;
    }
}
