<?php
declare(strict_types=1);

/**
 * Image generation via the WPCOM AI proxy (Google Vertex Imagen).
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

    /**
     * Hard cap Imagen enforces on the text prompt (input tokens). We compose the
     * site context + per-image prompt to stay safely under this.
     */
    public const MAX_PROMPT_TOKENS = 480;

    private int $requests = 0;

    /**
     * @param array<int,int> $retryDelays seconds to wait before retries 1..N of
     *        a transient failure (its length is the max retry count)
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

    /**
     * Map the prompt's aspect-ratio keyword to the Imagen ratio string.
     * Accepts either a keyword (square/landscape/portrait) or a ratio as-is.
     */
    public static function aspectRatio(string $keyword): string
    {
        return match (strtolower(trim($keyword))) {
            'square'   => '1:1',
            'portrait' => '9:16',
            'landscape' => '16:9',
            default    => preg_match('/^\d+:\d+$/', trim($keyword)) ? trim($keyword) : '16:9',
        };
    }

    /**
     * Conservative token estimate for an Imagen text prompt. No local tokenizer
     * is available, so over-estimate (the larger of a word- and a character-based
     * count) to stay safely under the hard model limit.
     */
    public static function estimateTokens(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }
        $words = count(preg_split('/\s+/', $text) ?: []);
        $chars = mb_strlen($text);
        return (int) max((int) ceil($words * 1.4), (int) ceil($chars / 4));
    }

    /**
     * Trim text from the end on a word boundary until it fits $maxTokens. Public
     * so ImagePromptComposer can cap a fully-composed prompt at MAX_PROMPT_TOKENS;
     * because the subject leads the prompt, trimming from the end sheds the
     * trailing context first and preserves the subject.
     */
    public static function fitToTokens(string $text, int $maxTokens): string
    {
        if ($maxTokens <= 0) {
            return '';
        }
        if (self::estimateTokens($text) <= $maxTokens) {
            return $text;
        }
        $words = preg_split('/\s+/', trim($text)) ?: [];
        while ($words !== [] && self::estimateTokens(implode(' ', $words)) > $maxTokens) {
            array_pop($words);
        }
        return rtrim(implode(' ', $words), " ,.;:—-");
    }

    public function generate(string $prompt, array $opts = []): string
    {
        $bytes = $this->requestWithRetry(self::buildBody($prompt, $opts));
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
     * @return array<int,array{ok:bool,bytes?:string,error?:string}> keyed by the
     *         same index as $specs (order preserved)
     */
    public function generateBatch(array $specs): array
    {
        if ($specs === []) {
            return [];
        }

        // Bodies keyed by the caller's index.
        $bodies = [];
        foreach ($specs as $i => $spec) {
            $bodies[$i] = self::buildBody((string) $spec['prompt'], [
                'aspect_ratio' => $spec['aspect_ratio'] ?? '16:9',
            ]);
        }

        $out = self::retryBatch($bodies, fn (array $subset): array => $this->multiRequest($subset), $this->retryDelays);
        $this->requests += $out['succeeded'];
        return $out['results'];
    }

    /**
     * Drive a batch transport to completion, retrying ONLY the transient
     * failures with backoff. Pure orchestration (the transport does the I/O, and
     * sleep() paces the rounds) so the retry accounting is unit-testable with a
     * fake transport and zero delays.
     *
     * @param array<int,array<string,mixed>> $bodies request bodies keyed by index
     * @param callable(array<int,array<string,mixed>>):array<int,array{ok:bool,bytes?:string,error?:string,transient?:bool}> $transport
     * @param array<int,int> $delays backoff seconds before each retry (length = max retries)
     * @return array{results:array<int,array{ok:bool,bytes?:string,error?:string}>,succeeded:int}
     */
    public static function retryBatch(array $bodies, callable $transport, array $delays): array
    {
        $results = [];
        $succeeded = 0;
        $pending = array_keys($bodies);
        $attempt = 0;

        while ($pending !== []) {
            $outcomes = $transport(array_intersect_key($bodies, array_flip($pending)));

            $retry = [];
            foreach ($outcomes as $i => $outcome) {
                if ($outcome['ok']) {
                    $results[$i] = ['ok' => true, 'bytes' => $outcome['bytes']];
                    $succeeded++;
                } elseif (($outcome['transient'] ?? false) && $attempt < count($delays)) {
                    $retry[] = $i; // try this one again next round
                } else {
                    $results[$i] = ['ok' => false, 'error' => $outcome['error']];
                }
            }

            $pending = $retry;
            if ($pending !== []) {
                $wait = $delays[$attempt];
                $attempt++;
                fwrite(STDERR, "    (transient image API error on " . count($pending)
                    . " image(s); retry {$attempt} in {$wait}s)\n");
                sleep($wait);
            }
        }

        ksort($results);
        return ['results' => $results, 'succeeded' => $succeeded];
    }

    /**
     * Run a set of predict requests concurrently with curl_multi and classify
     * each transfer. Pure transport — no retry, no request counting.
     *
     * @param array<int,array<string,mixed>> $bodies request body keyed by index
     * @return array<int,array{ok:bool,bytes?:string,error?:string,transient?:bool}>
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
                $out[$i] = ['ok' => true, 'bytes' => self::interpret($raw, $errno, $error, (int) $httpStatus)];
            } catch (TransientApiException $e) {
                $out[$i] = ['ok' => false, 'transient' => true, 'error' => $e->getMessage()];
            } catch (Throwable $e) {
                $out[$i] = ['ok' => false, 'transient' => false, 'error' => $e->getMessage()];
            }
        }

        curl_multi_close($multi);
        return $out;
    }

    /**
     * The Imagen predict request body for one prompt.
     *
     * @param array{aspect_ratio?:string} $opts
     * @return array<string,mixed>
     */
    private static function buildBody(string $prompt, array $opts): array
    {
        return [
            'instances'  => [['prompt' => $prompt]],
            'parameters' => [
                'sampleCount'     => 1,
                'aspectRatio'     => $opts['aspect_ratio'] ?? '16:9',
                'sampleImageSize' => '1K',
                // Ask Imagen for JPEG directly so the bytes match the .jpg asset
                // filenames (it returns PNG otherwise — larger and mislabeled).
                'outputOptions'   => [
                    'mimeType'           => 'image/jpeg',
                    'compressionQuality' => 85,
                ],
            ],
        ];
    }

    /**
     * POST the request, retrying transient failures (429/5xx, stalls) with backoff.
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
            } catch (TransientApiException $e) {
                if ($attempt >= count($delays)) {
                    throw new RuntimeException('Image proxy failed after retries: ' . $e->getMessage(), 0, $e);
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

        return self::interpret((string) $raw, $errno, $error, (int) $status);
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

    /**
     * Interpret a completed transfer: return decoded image bytes, or throw a
     * TransientApiException (retryable: 429/5xx/stall) or RuntimeException
     * (permanent). Pure — no I/O — so the single and batched paths share it.
     *
     * @throws TransientApiException on a retryable failure
     */
    private static function interpret(string $raw, int $errno, string $error, int $status): string
    {
        // Connection-level failures: timeout, stall, connect/recv — retryable.
        if (in_array($errno, [7, 28, 35, 52, 55, 56], true)) {
            throw new TransientApiException("cURL ({$errno}): {$error}");
        }
        if ($errno !== 0) {
            throw new RuntimeException("cURL error ({$errno}): {$error}");
        }

        if ($status === 429 || $status >= 500) {
            throw new TransientApiException("HTTP {$status}: " . substr($raw, 0, 300));
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("Image proxy HTTP {$status}: " . substr($raw, 0, 500));
        }

        $data = json_decode($raw, true);
        $b64 = $data['predictions'][0]['bytesBase64Encoded'] ?? null;
        if (!is_string($b64) || $b64 === '') {
            throw new RuntimeException('Image proxy response had no image data: ' . substr($raw, 0, 300));
        }

        $bytes = base64_decode($b64, true);
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('Image proxy returned undecodable base64');
        }
        return $bytes;
    }
}
