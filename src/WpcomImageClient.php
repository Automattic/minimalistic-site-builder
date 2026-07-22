<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Image generation transport via the WPCOM AI proxy (Google Vertex Imagen).
 *
 * The wpcom-specific half of image generation: the proxy endpoint, the
 * feature-slugged auth header, and the cURL I/O. Everything protocol-shaped
 * (request bodies, response classification, prompt-spec math, batch-retry
 * orchestration) lives in {@see Imagen}, so a different host can ship its own
 * ImageClient over the same protocol without copying any of this.
 *
 * Zero dependencies: a plain cURL POST, matching AnthropicClient's style. This
 * is the one proxy route that works for the builder — the GOOGLE_VERTEX_API_TOKEN
 * is scoped to Google Vertex, and telex uses the same endpoint for theme images.
 * See PROGRESS.md (Phase 0) for why Claude cannot go through the proxy.
 */
final class WpcomImageClient implements ImageClient
{
    private const ENDPOINT_TPL =
        'https://public-api.wordpress.com/wpcom/v2/ai-api-proxy/v1/publishers/google/models/%s:predict';

    private int $requests = 0;

    /**
     * @param array<int,int> $retryDelays seconds to wait before retries 1..N of
     *        a retryable failure — transient transport errors AND
     *        safety-filtered prompts, which the non-deterministic filter can
     *        pass on a later attempt (its length is the max retry count)
     */
    public function __construct(
        private string $apiToken,
        private string $model = 'imagen-4.0-generate-001',
        private string $feature = 'builder-theme-image',
        private array $retryDelays = [2, 5, 12],
    ) {}

    /** How many image requests this client has made. */
    public function requestCount(): int
    {
        return $this->requests;
    }

    /** The Imagen model this client generates with (used for request logging). */
    public function model(): string
    {
        return $this->model;
    }

    public function generate(string $prompt, array $opts = []): string
    {
        $bytes = $this->requestWithRetry(Imagen::buildBody($prompt, $opts));
        $this->requests++;
        return $bytes;
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
    public function generateBatch(array $specs): array
    {
        if ($specs === []) {
            return [];
        }

        // Bodies keyed by the caller's index.
        $bodies = [];
        foreach ($specs as $i => $spec) {
            $bodies[$i] = Imagen::buildBody((string) $spec['prompt'], [
                'aspect_ratio'      => $spec['aspect_ratio'] ?? '16:9',
                'sample_image_size' => $spec['sample_image_size'] ?? null,
                'mime'              => $spec['mime'] ?? null,
            ]);
        }

        $out = Imagen::retryBatch(
            $bodies,
            fn (array $subset): array => $this->multiRequest($subset),
            $this->retryDelays,
            static function (int $count, int $attempt, int $wait): void {
                fwrite(STDERR, "    (retryable image API failure on {$count} image(s); retry {$attempt} in {$wait}s)\n");
            }
        );
        $this->requests += $out['succeeded'];
        return $out['results'];
    }

    /**
     * Run a set of predict requests concurrently with curl_multi and classify
     * each transfer. Pure transport — no retry, no request counting.
     *
     * @param array<int,array<string,mixed>> $bodies request body keyed by index
     * @return array<int,array{ok:bool,bytes?:string,error?:string,transient?:bool,filtered?:bool}>
     */
    private function multiRequest(array $bodies): array
    {
        $multi = curl_multi_init();
        $handles = [];
        foreach ($bodies as $i => $body) {
            $ch = $this->buildHandle($body);
            $handles[$i] = $ch;
            curl_multi_add_handle($multi, $ch);
        }

        // Drive all transfers to completion. curl_multi_select() blocks until
        // there is activity; it returns -1 when there is no socket to wait on
        // yet (e.g. during DNS), so guard against a busy-spin in that case.
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running && curl_multi_select($multi, 1.0) === -1) {
                usleep(1000);
            }
        } while ($running && $status === CURLM_OK);

        $out = [];
        foreach ($handles as $i => $ch) {
            $raw    = (string) curl_multi_getcontent($ch);
            $errno  = curl_errno($ch);
            $error  = curl_error($ch);
            $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);

            try {
                self::throwOnTransportError($errno, $error);
                $out[$i] = ['ok' => true, 'bytes' => Imagen::interpret($raw, (int) $httpStatus)];
            } catch (ImageFilteredException $e) {
                // The safety filter is non-deterministic: retry like a
                // transient failure, but keep the filtered flag so the caller
                // can repair the prompt once the retries run out.
                $out[$i] = ['ok' => false, 'transient' => true, 'filtered' => true, 'error' => $e->getMessage()];
            } catch (TransientApiException $e) {
                $out[$i] = ['ok' => false, 'transient' => true, 'error' => $e->getMessage()];
            } catch (\Throwable $e) {
                $out[$i] = ['ok' => false, 'transient' => false, 'error' => $e->getMessage()];
            }
        }

        curl_multi_close($multi);
        return $out;
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
                fwrite(STDERR, "    (transient image API error: {$e->getMessage()}; retry {$attempt} in {$wait}s)\n");
                sleep($wait);
            }
        }
    }

    /**
     * One predict call. Returns decoded image bytes.
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
        return Imagen::interpret((string) $raw, (int) $status);
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
        // Connection-level failures: timeout, stall, connect/recv — retryable.
        if (in_array($errno, [7, 28, 35, 52, 55, 56], true)) {
            throw new TransientApiException("cURL ({$errno}): {$error}");
        }
        if ($errno !== 0) {
            throw new \RuntimeException("cURL error ({$errno}): {$error}");
        }
    }

    /**
     * Build a configured cURL handle for one predict request. Used by both the
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
