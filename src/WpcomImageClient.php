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

    private int $requests = 0;

    public function __construct(
        private string $apiToken,
        private string $model = 'imagen-4.0-generate-001',
        private string $feature = 'builder-theme-image',
    ) {}

    /** How many image requests this client has made. */
    public function requestCount(): int
    {
        return $this->requests;
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

    public function generate(string $prompt, array $opts = []): string
    {
        $body = [
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

        $bytes = $this->requestWithRetry($body);
        $this->requests++;
        return $bytes;
    }

    /**
     * POST the request, retrying transient failures (429/5xx, stalls) with backoff.
     *
     * @param array<string,mixed> $body
     */
    private function requestWithRetry(array $body): string
    {
        $delays = [2, 5, 12]; // seconds before retries 1, 2, 3
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

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Connection-level failures: timeout, stall, connect/recv — retryable.
        if (in_array($errno, [7, 28, 35, 52, 55, 56], true)) {
            throw new TransientApiException("cURL ({$errno}): {$error}");
        }
        if ($errno !== 0) {
            throw new RuntimeException("cURL error ({$errno}): {$error}");
        }

        $raw = (string) $raw;
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
