<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Google Imagen protocol helpers: pure request-shaping, response-parsing, and
 * prompt-spec math, with NO transport. Independent of how Imagen is reached
 * (direct API, a proxy, or a host's own gateway) so any ImageClient
 * implementation can build on it — the portable half of image generation.
 *
 * WpcomImageClient (and any future transport) supplies the I/O; the logic here
 * is free of network and output side effects (the only exception is retryBatch's
 * backoff sleep) and unit-testable with plain values.
 */
final class Imagen
{
    /** Aspect-ratio values accepted by Imagen's generation endpoint. */
    private const SUPPORTED_ASPECT_RATIOS = [
        '1:1'  => 1.0,
        '3:4'  => 3 / 4,
        '4:3'  => 4 / 3,
        '9:16' => 9 / 16,
        '16:9' => 16 / 9,
    ];

    /**
     * Hard cap Imagen enforces on the text prompt (input tokens). We compose the
     * site context + per-image prompt to stay safely under this.
     */
    public const MAX_PROMPT_TOKENS = 480;

    /**
     * Map the prompt's aspect-ratio keyword to an Imagen-supported ratio.
     * Unsupported positive numeric ratios are mapped to the nearest supported
     * shape; malformed values fall back to landscape.
     */
    public static function aspectRatio(string $keyword): string
    {
        $normalized = strtolower(trim($keyword));
        $named = match ($normalized) {
            'square'   => '1:1',
            'portrait' => '9:16',
            'landscape' => '16:9',
            default    => null,
        };
        if ($named !== null) {
            return $named;
        }
        if (isset(self::SUPPORTED_ASPECT_RATIOS[$normalized])) {
            return $normalized;
        }
        if (!preg_match('/^(\d+):(\d+)$/', $normalized, $match)) {
            return '16:9';
        }

        $width = (int) $match[1];
        $height = (int) $match[2];
        if ($width < 1 || $height < 1) {
            return '16:9';
        }
        $requested = $width / $height;
        $closest = '16:9';
        $distance = INF;
        foreach (self::SUPPORTED_ASPECT_RATIOS as $ratio => $value) {
            $candidate = abs(log($requested / $value));
            if ($candidate < $distance) {
                $closest = $ratio;
                $distance = $candidate;
            }
        }
        return $closest;
    }

    /**
     * The Imagen sample size to request for a given aspect ratio. Wide (16:9)
     * images are the ones used full-bleed (heroes, banners, wide features) where
     * a 1K render stretched past ~1366px goes soft — request 2K for those. The
     * smaller square/portrait slots stay at 1K to keep cost down.
     *
     * Transparent assets are always 1K, whatever their ratio: they are
     * decorative line art (flourishes, ornaments, logo marks) rendered small
     * on the page, never full-bleed, and line art downscales gracefully. A 1K
     * render quarters the pixels to generate and key and roughly cuts the
     * shipped PNG bytes to a third.
     */
    public static function sampleImageSize(string $aspectRatio, bool $transparent = false): string
    {
        if ($transparent) {
            return '1K';
        }
        return trim($aspectRatio) === '16:9' ? '2K' : '1K';
    }

    /**
     * The output MIME type an asset filename calls for. `.png` assets are the
     * transparent-background ones (decorative flourishes, ornaments, logo
     * marks); everything else is photographic and stays JPEG for size.
     */
    public static function mimeForFilename(string $filename): string
    {
        return preg_match('/\.png$/i', trim($filename)) === 1 ? 'image/png' : 'image/jpeg';
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

    /**
     * The Imagen predict request body for one prompt.
     *
     * @internal Delegation seam for the transport; not part of the consumer API.
     *
     * @param array{aspect_ratio?:string,sample_image_size?:?string,mime?:?string} $opts
     * @return array<string,mixed>
     */
    public static function buildBody(string $prompt, array $opts): array
    {
        // Ask Imagen for the format matching the asset filename: JPEG for the
        // photographic default (it returns PNG otherwise — larger and
        // mislabeled), PNG for transparent-background assets (JPEG has no
        // alpha channel). compressionQuality is a JPEG-only knob.
        $mime = ($opts['mime'] ?? null) ?: 'image/jpeg';
        $outputOptions = ['mimeType' => $mime];
        if ($mime === 'image/jpeg') {
            $outputOptions['compressionQuality'] = 85;
        }

        return [
            'instances'  => [['prompt' => $prompt]],
            'parameters' => [
                'sampleCount'     => 1,
                'aspectRatio'     => self::aspectRatio((string) ($opts['aspect_ratio'] ?? '16:9')),
                'sampleImageSize' => $opts['sample_image_size'] ?? '1K',
                'outputOptions'   => $outputOptions,
            ],
        ];
    }

    /**
     * Drive a batch transport to completion, retrying ONLY the retryable
     * failures (transient transport errors and safety-filtered prompts) with
     * backoff. Orchestration only: the transport does the I/O, sleep() paces the
     * rounds, and the optional $onRetry reports each backoff (so the CLI's
     * progress line stays in the transport, not here). Unit-testable with a
     * fake transport and zero delays.
     *
     * An outcome carrying `held` (the pool declined to send the request after
     * a sibling was rate-limited) retries without consuming the finite delay
     * budget: it says nothing about its own request, and the really-attempted
     * sibling's gate still bounds the batch — a never-attempted image must not
     * degrade to a placeholder failure.
     *
     * The optional $onResult fires once per body when its result is FINAL
     * (success, or failure with retries exhausted) — never for an outcome that
     * will retry — so callers can persist progress incrementally.
     *
     * @param array<int,array<string,mixed>> $bodies request bodies keyed by index
     * @param callable(array<int,array<string,mixed>>):array<int,array{ok:bool,bytes?:string,error?:string,transient?:bool,held?:bool,filtered?:bool}> $transport
     * @param array<int,int> $delays backoff seconds before each retry (length = max retries)
     * @param callable(int,int,int):void|null $onRetry called before each backoff with (pending count, attempt #, wait seconds)
     * @param callable(int,array{ok:bool,bytes?:string,error?:string,filtered?:bool}):void|null $onResult called once per index with its final result
     * @return array{results:array<int,array{ok:bool,bytes?:string,error?:string,filtered?:bool}>,succeeded:int}
     */
    public static function retryBatch(
        array $bodies,
        callable $transport,
        array $delays,
        ?callable $onRetry = null,
        ?callable $onResult = null,
    ): array
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
                } elseif (($outcome['held'] ?? false) === true) {
                    $retry[] = $i; // never sent — retry without charging the budget
                } elseif (($outcome['transient'] ?? false) && $attempt < count($delays)) {
                    $retry[] = $i; // try this one again next round
                } else {
                    // Keep the filtered flag on the final failure so the caller
                    // can tell a safety-filtered prompt (repairable by
                    // rewriting it) from a transport failure.
                    $results[$i] = ['ok' => false, 'error' => $outcome['error']]
                        + (($outcome['filtered'] ?? false) ? ['filtered' => true] : []);
                }
                if ($onResult !== null && array_key_exists($i, $results)) {
                    $onResult($i, $results[$i]);
                }
            }

            $pending = $retry;
            if ($pending !== []) {
                // Held keys can outlive the delay schedule; clamp to its last
                // (or zero) instead of indexing past the end.
                $wait = $delays === [] ? 0 : $delays[min($attempt, count($delays) - 1)];
                $attempt++;
                if ($onRetry !== null) {
                    $onRetry(count($pending), $attempt, $wait);
                }
                sleep($wait);
            }
        }

        ksort($results);
        return ['results' => $results, 'succeeded' => $succeeded];
    }

    /**
     * Interpret a completed transfer at the protocol level: HTTP-status
     * classification + Imagen body parsing. Returns decoded image bytes, or
     * throws TransientApiException (429/5xx), ImageFilteredException (safety
     * filter — retryable AND repairable), or RuntimeException (permanent).
     * Pure — no I/O — so the single and batched paths share it.
     *
     * The transport classifies its own connection-level failures (e.g. cURL
     * errnos) BEFORE calling this, so nothing transport-specific lives here.
     *
     * @internal Delegation seam for the transport; not part of the consumer API.
     *
     * @throws TransientApiException on a retryable HTTP status (429/5xx)
     * @throws ImageFilteredException when the safety filter rejected the prompt
     */
    public static function interpret(string $raw, int $status): string
    {
        if ($status === 429 || $status >= 500) {
            throw new TransientApiException("HTTP {$status}: " . substr($raw, 0, 300));
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("Image proxy HTTP {$status}: " . substr($raw, 0, 500));
        }

        $data = json_decode($raw, true);
        $reason = self::filteredReason(is_array($data) ? $data : null);
        if ($reason !== null) {
            throw new ImageFilteredException('Image safety filter rejected the prompt: ' . $reason);
        }

        $b64 = $data['predictions'][0]['bytesBase64Encoded'] ?? null;
        if (!is_string($b64) || $b64 === '') {
            throw new \RuntimeException('Image proxy response had no image data: ' . substr($raw, 0, 300));
        }

        $bytes = base64_decode($b64, true);
        if ($bytes === false || $bytes === '') {
            throw new \RuntimeException('Image proxy returned undecodable base64');
        }
        return $bytes;
    }

    /**
     * The safety-filter reason in a decoded predict response, or null when the
     * response was not filtered. Imagen signals a Responsible-AI rejection as
     * an HTTP 200 whose prediction carries raiFilteredReason instead of image
     * bytes. Public and pure so the classification is unit-testable.
     *
     * @param ?array<mixed> $data decoded JSON response body
     */
    public static function filteredReason(?array $data): ?string
    {
        foreach ((array) ($data['predictions'] ?? []) as $prediction) {
            $reason = is_array($prediction) ? ($prediction['raiFilteredReason'] ?? null) : null;
            if (is_string($reason) && trim($reason) !== '') {
                return trim($reason);
            }
        }
        return null;
    }
}
