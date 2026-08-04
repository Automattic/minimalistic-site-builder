<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Google Gemini image-generation protocol helpers: pure request-shaping,
 * response-parsing, and prompt-spec math, with NO transport. Independent of
 * how the model is reached (direct API, a proxy, or a host's own gateway) so
 * any ImageClient implementation can build on it — the portable half of image
 * generation.
 *
 * Gemini image models (e.g. gemini-3.1-flash-image) speak `generateContent`:
 * the prompt travels as `contents` parts, generation knobs live under
 * `generationConfig.imageConfig`, and the image comes back base64-encoded in a
 * candidate's `inlineData` part. Unlike the retired Imagen `predict` protocol
 * there is no output-format knob — the model emits PNG — so the JPEG encoding
 * photographic assets ship with is produced client-side ({@see toJpeg}).
 *
 * WpcomImageClient (and any future transport) supplies the I/O; the logic here
 * is free of network and output side effects (the only exceptions are
 * retryBatch's backoff sleep and toJpeg's in-memory re-encode) and
 * unit-testable with plain values.
 */
final class GeminiImage
{
    /**
     * Aspect-ratio values the builder generates with. Gemini accepts more
     * shapes (2:3, 4:5, …), but those are near-duplicates of the card ratios
     * and every extra option widens the spec-writer's chance of mixing ratios
     * within one grid — so the menu stays at these six and arbitrary requested
     * ratios are clamped to the nearest one.
     */
    private const SUPPORTED_ASPECT_RATIOS = [
        '1:1'  => 1.0,
        '3:4'  => 3 / 4,
        '4:3'  => 4 / 3,
        '9:16' => 9 / 16,
        '16:9' => 16 / 9,
        '21:9' => 21 / 9,
    ];

    /**
     * Budget for the text prompt (input tokens). Imagen enforced this as a
     * hard model cap; Gemini accepts far longer prompts, but the composition
     * pipeline is tuned to this budget — a tight prompt keeps the subject
     * dominant instead of drowning it in trailing context.
     */
    public const MAX_PROMPT_TOKENS = 480;

    /** JPEG quality for client-side re-encoding of photographic assets. */
    private const JPEG_QUALITY = 85;

    /**
     * Map the prompt's aspect-ratio keyword to a supported ratio. Unsupported
     * positive numeric ratios are mapped to the nearest supported shape;
     * malformed values fall back to landscape.
     */
    public static function aspectRatio(string $keyword): string
    {
        $normalized = strtolower(trim($keyword));
        $named = match ($normalized) {
            'square'         => '1:1',
            'portrait'       => '9:16',
            'landscape'      => '16:9',
            'ultrawide'      => '21:9',
            'card-landscape' => '4:3',
            'card-portrait'  => '3:4',
            default          => null,
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
     * The image size to request for a given aspect ratio. Wide (16:9, 21:9)
     * images are the ones used full-bleed (heroes, banners, wide features)
     * where a 1K render stretched past ~1366px goes soft — request 2K for
     * those. The smaller square/portrait/card slots stay at 1K to keep cost
     * down.
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
        return in_array(trim($aspectRatio), ['16:9', '21:9'], true) ? '2K' : '1K';
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
     * Conservative token estimate for an image prompt. No local tokenizer is
     * available, so over-estimate (the larger of a word- and a character-based
     * count) to stay safely under the prompt budget.
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
     * The generateContent request body for one prompt. TEXT rides along in the
     * response modalities because not every Gemini image model accepts an
     * IMAGE-only response; interpret() scans past any text parts. The `mime`
     * option has no wire representation here — the model emits PNG, and the
     * transport re-encodes to JPEG ({@see toJpeg}) where the asset calls for it.
     *
     * @internal Delegation seam for the transport; not part of the consumer API.
     *
     * @param array{aspect_ratio?:string,sample_image_size?:?string,mime?:?string} $opts
     * @return array<string,mixed>
     */
    public static function buildBody(string $prompt, array $opts): array
    {
        return [
            'contents' => [[
                'role'  => 'user',
                'parts' => [['text' => $prompt]],
            ]],
            'generationConfig' => [
                'responseModalities' => ['TEXT', 'IMAGE'],
                'imageConfig' => [
                    'aspectRatio' => self::aspectRatio((string) ($opts['aspect_ratio'] ?? '16:9')),
                    'imageSize'   => $opts['sample_image_size'] ?? '1K',
                ],
            ],
        ];
    }

    /**
     * Re-encode image bytes as JPEG, flattened on white. The replacement for
     * Imagen's server-side `outputOptions` knob: Gemini has no output-format
     * parameter and emits PNG, which for the photographic assets (heroes,
     * banners) is several times the bytes of a visually identical JPEG. Bytes
     * that are already JPEG pass through untouched. Fails soft: without a
     * usable imaging extension the original bytes come back — PNG bytes in a
     * `.jpg` file still render everywhere, just heavier.
     */
    public static function toJpeg(string $bytes): string
    {
        if ($bytes === '' || str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return $bytes;
        }
        if (extension_loaded('imagick')) {
            try {
                $im = new \Imagick();
                $im->readImageBlob($bytes);
                $im->setImageBackgroundColor('white');
                $flat = $im->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
                $flat->setImageFormat('jpeg');
                $flat->setImageCompressionQuality(self::JPEG_QUALITY);
                $out = $flat->getImageBlob();
                $flat->clear();
                $im->clear();
                return $out;
            } catch (\ImagickException) {
                // fall through to GD / passthrough
            }
        }
        if (function_exists('imagecreatefromstring')) {
            $img = @imagecreatefromstring($bytes);
            if ($img !== false) {
                $w = imagesx($img);
                $h = imagesy($img);
                $flat = imagecreatetruecolor($w, $h);
                imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
                imagecopy($flat, $img, 0, 0, 0, 0, $w, $h);
                ob_start();
                imagejpeg($flat, null, self::JPEG_QUALITY);
                $out = ob_get_clean();
                imagedestroy($img);
                imagedestroy($flat);
                if (is_string($out) && $out !== '') {
                    return $out;
                }
            }
        }
        return $bytes;
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
     * will retry — so callers can persist progress incrementally. When it is
     * provided, the callback is the delivery path for image bytes: the
     * returned success records omit `bytes` so the batch never holds every
     * image in memory at once.
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
        /** @var array<int,int> $attempts transient retries consumed by each request */
        $attempts = array_fill_keys($pending, 0);
        $retryWave = 0;

        while ($pending !== []) {
            $outcomes = $transport(array_intersect_key($bodies, array_flip($pending)));

            $retry = [];
            $retryWaits = [];
            foreach ($outcomes as $i => $outcome) {
                if ($outcome['ok']) {
                    // A pooled transport may have delivered the bytes out-of-band
                    // already (success is always final) — record the outcome
                    // without inventing a bytes key.
                    $results[$i] = ['ok' => true]
                        + (array_key_exists('bytes', $outcome) ? ['bytes' => $outcome['bytes']] : []);
                    $succeeded++;
                } elseif (($outcome['held'] ?? false) === true) {
                    $retry[] = $i; // never sent — retry without charging the budget
                } elseif (
                    ($outcome['transient'] ?? false)
                    && ($attempts[$i] ?? 0) < count($delays)
                ) {
                    $attempt = $attempts[$i] ?? 0;
                    $attempts[$i] = $attempt + 1;
                    $retryWaits[$i] = $delays[$attempt];
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
                    // The callback just took delivery (typically writing the
                    // image to disk). Retaining a second copy of every image
                    // until the whole batch returns exhausts memory on large
                    // builds (52 images ≈ 150MB), so the returned record
                    // keeps the outcome, not the payload.
                    unset($results[$i]['bytes']);
                }
            }

            $pending = $retry;
            if ($pending !== []) {
                // Wait long enough for every really-attempted transient in
                // this wave. A held-only wave waits zero: no request consumed
                // a retry or owns a backoff slot.
                $wait = $retryWaits === [] ? 0 : max($retryWaits);
                $retryWave++;
                if ($onRetry !== null) {
                    $onRetry(count($pending), $retryWave, $wait);
                }
                sleep($wait);
            }
        }

        ksort($results);
        return ['results' => $results, 'succeeded' => $succeeded];
    }

    /**
     * Interpret a completed transfer at the protocol level: HTTP-status
     * classification + generateContent body parsing. Returns decoded image
     * bytes, or throws TransientApiException (429/5xx), ImageFilteredException
     * (safety filter — retryable AND repairable), or RuntimeException
     * (permanent). Pure — no I/O — so the single and batched paths share it.
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
        $data = is_array($data) ? $data : null;
        $reason = self::filteredReason($data);
        if ($reason !== null) {
            throw new ImageFilteredException('Image safety filter rejected the prompt: ' . $reason);
        }

        $b64 = self::imageData($data);
        if ($b64 === null) {
            throw new \RuntimeException('Image proxy response had no image data: ' . substr($raw, 0, 300));
        }

        $bytes = base64_decode($b64, true);
        if ($bytes === false || $bytes === '') {
            throw new \RuntimeException('Image proxy returned undecodable base64');
        }
        return $bytes;
    }

    /**
     * The base64 image payload in a decoded generateContent response, or null
     * when no candidate carries an inline image part. Text parts (the model
     * narrating what it drew) are skipped, not errors. Public and pure so the
     * extraction is unit-testable.
     *
     * @param ?array<mixed> $data decoded JSON response body
     */
    public static function imageData(?array $data): ?string
    {
        foreach ((array) ($data['candidates'] ?? []) as $candidate) {
            $parts = is_array($candidate) ? ($candidate['content']['parts'] ?? null) : null;
            foreach ((array) $parts as $part) {
                $inline = is_array($part) ? ($part['inlineData'] ?? $part['inline_data'] ?? null) : null;
                $b64 = is_array($inline) ? ($inline['data'] ?? null) : null;
                if (is_string($b64) && $b64 !== '') {
                    return $b64;
                }
            }
        }
        return null;
    }

    /**
     * The safety-filter reason in a decoded generateContent response, or null
     * when the response was not filtered. Gemini signals a rejection as an
     * HTTP 200 in one of three shapes, all without image bytes: a prompt-level
     * `promptFeedback.blockReason`, a candidate finishing for a non-STOP
     * reason (IMAGE_SAFETY, PROHIBITED_CONTENT, SAFETY, RECITATION, …), or a
     * text-only "I can't generate that" refusal that finishes STOP. All three
     * are retryable and repairable by rewriting the prompt. A response that
     * carries image data is never filtered, whatever else rides along. Public
     * and pure so the classification is unit-testable.
     *
     * @param ?array<mixed> $data decoded JSON response body
     */
    public static function filteredReason(?array $data): ?string
    {
        if ($data === null || self::imageData($data) !== null) {
            return null;
        }

        $block = $data['promptFeedback']['blockReason'] ?? null;
        if (is_string($block) && trim($block) !== '') {
            return 'prompt blocked: ' . trim($block);
        }

        foreach ((array) ($data['candidates'] ?? []) as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $finish = $candidate['finishReason'] ?? null;
            if (is_string($finish) && $finish !== '' && $finish !== 'STOP') {
                return 'candidate finished: ' . $finish;
            }
            foreach ((array) ($candidate['content']['parts'] ?? []) as $part) {
                $text = is_array($part) ? ($part['text'] ?? null) : null;
                if (is_string($text) && trim($text) !== '') {
                    return 'model refused with text: ' . mb_substr(trim($text), 0, 200);
                }
            }
        }
        return null;
    }
}
