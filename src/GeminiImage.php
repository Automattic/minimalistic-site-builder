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
 * candidate's `inlineData` part. Output format is requested through
 * `imageConfig.imageOutputOptions`; the response's declared MIME and byte
 * signature are checked before delivery, with client-side JPEG encoding kept
 * only as a fallback for a proxy/model that ignores that option.
 *
 * WpcomImageClient (and any future transport) supplies the I/O; the logic here
 * is free of network and output side effects (the only exceptions are
 * retryBatch's backoff sleep and toJpeg's in-memory re-encode) and
 * unit-testable with plain values.
 */
final class GeminiImage
{
    /**
     * Aspect-ratio values the builder generates with. The prompt vocabulary
     * stays deliberately small, while deterministic direction execution also
     * uses the exact 2:3, 3:2, and 4:5 canvases needed by the committed crop
     * system. Arbitrary requested ratios are clamped to the nearest entry.
     */
    private const SUPPORTED_ASPECT_RATIOS = [
        '1:1'  => 1.0,
        '2:3'  => 2 / 3,
        '3:4'  => 3 / 4,
        '4:3'  => 4 / 3,
        '3:2'  => 3 / 2,
        '4:5'  => 4 / 5,
        '9:16' => 9 / 16,
        '16:9' => 16 / 9,
        '21:9' => 21 / 9,
    ];

    /**
     * Machine-readable Gemini outcomes that unambiguously mean a policy or
     * safety filter rejected the prompt/candidate. Everything else is an
     * ordinary no-image response: finish reasons such as MAX_TOKENS, NO_IMAGE,
     * and MALFORMED_FUNCTION_CALL must not trigger safety retries or an LLM
     * prompt rewrite.
     */
    private const FILTERED_REASONS = [
        'SAFETY',
        'RECITATION',
        'BLOCKLIST',
        'PROHIBITED_CONTENT',
        'SPII',
        'IMAGE_SAFETY',
        'IMAGE_PROHIBITED_CONTENT',
        'IMAGE_RECITATION',
        'MODEL_ARMOR',
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
     * IMAGE-only response; interpret() scans past any text parts. Ask Vertex
     * for the asset's actual delivery format so the common path needs no local
     * image extension. compressionQuality is a JPEG-only option.
     *
     * @internal Delegation seam for the transport; not part of the consumer API.
     *
     * @param array{aspect_ratio?:string,sample_image_size?:?string,mime?:?string} $opts
     * @return array<string,mixed>
     */
    public static function buildBody(string $prompt, array $opts): array
    {
        $mime = self::canonicalMime((string) ($opts['mime'] ?? '')) ?? 'image/jpeg';
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            $mime = 'image/jpeg';
        }
        $outputOptions = ['mimeType' => $mime];
        if ($mime === 'image/jpeg') {
            $outputOptions['compressionQuality'] = self::JPEG_QUALITY;
        }

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
                    'imageOutputOptions' => $outputOptions,
                ],
            ],
        ];
    }

    /**
     * MIME inferred from the encoded bytes. The response declaration and the
     * target filename are not trusted: only a recognized signature may cross
     * the delivery boundary.
     */
    public static function mimeFromBytes(string $bytes): ?string
    {
        $magic = null;
        if (str_starts_with($bytes, "\xFF\xD8\xFF") && self::hasCompleteJpegContainer($bytes)) {
            $magic = 'image/jpeg';
        } elseif (
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n")
            && self::hasCompletePngContainer($bytes)
        ) {
            $magic = 'image/png';
        }
        if ($magic === null) {
            return null;
        }

        // A signature alone also prefixes truncated/crafted garbage. Confirm a
        // complete, positive-dimension image header using PHP's dependency-free
        // decoder before accepting the bytes for delivery.
        $info = @getimagesizefromstring($bytes);
        if (!is_array($info) || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
            return null;
        }
        $decoded = self::canonicalMime(is_string($info['mime'] ?? null) ? $info['mime'] : null);
        return $decoded === $magic ? $magic : null;
    }

    /**
     * Walk the JPEG marker stream through a real scan and its EOI marker.
     * getimagesizefromstring() stops after the dimensions and accepts a file
     * truncated before its pixel stream finishes, so header probing alone is
     * not a delivery postcondition.
     */
    private static function hasCompleteJpegContainer(string $bytes): bool
    {
        $size = strlen($bytes);
        if ($size < 4 || substr($bytes, 0, 2) !== "\xFF\xD8") {
            return false;
        }

        $position = 2;
        $sawScan = false;
        while ($position < $size) {
            if (ord($bytes[$position]) !== 0xFF) {
                return false;
            }
            while ($position < $size && ord($bytes[$position]) === 0xFF) {
                $position++;
            }
            if ($position >= $size) {
                return false;
            }

            $marker = ord($bytes[$position]);
            $position++;
            if ($marker === 0xD9) { // EOI
                return $sawScan && $position === $size;
            }
            if ($marker === 0x00 || $marker === 0xD8) {
                return false;
            }
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                continue; // Standalone TEM / restart marker.
            }
            if ($position + 2 > $size) {
                return false;
            }
            $segmentLength = unpack('nlength', substr($bytes, $position, 2))['length'];
            if ($segmentLength < 2 || $segmentLength > $size - $position) {
                return false;
            }
            $position += $segmentLength;
            if ($marker !== 0xDA) { // SOS begins entropy-coded scan data.
                continue;
            }

            $sawScan = true;
            while ($position < $size) {
                $nextMarker = strpos($bytes, "\xFF", $position);
                if ($nextMarker === false) {
                    return false;
                }
                $position = $nextMarker + 1;
                while ($position < $size && ord($bytes[$position]) === 0xFF) {
                    $position++;
                }
                if ($position >= $size) {
                    return false;
                }
                $scanMarker = ord($bytes[$position]);
                $position++;
                if ($scanMarker === 0x00 || ($scanMarker >= 0xD0 && $scanMarker <= 0xD7)) {
                    continue; // Byte-stuffed data / restart marker inside scan.
                }
                if ($scanMarker === 0xD9) {
                    return $position === $size;
                }
                // Progressive JPEGs can carry another marker and scan. Rewind
                // to its 0xFF so the outer segment walker validates it.
                $position = $nextMarker;
                break;
            }
        }
        return false;
    }

    /** Require a complete, CRC-valid PNG chunk stream ending exactly at IEND. */
    private static function hasCompletePngContainer(string $bytes): bool
    {
        $size = strlen($bytes);
        $position = 8;
        $sawHeader = false;
        $sawData = false;

        while ($position < $size) {
            if ($size - $position < 12) {
                return false;
            }
            $length = unpack('Nlength', substr($bytes, $position, 4))['length'];
            if ($length > $size - $position - 12) {
                return false;
            }
            $type = substr($bytes, $position + 4, 4);
            $data = substr($bytes, $position + 8, $length);
            $storedCrc = substr($bytes, $position + 8 + $length, 4);
            if (!hash_equals(hash('crc32b', $type . $data, true), $storedCrc)) {
                return false;
            }
            $position += 12 + $length;

            if (!$sawHeader) {
                if ($type !== 'IHDR' || $length !== 13) {
                    return false;
                }
                $sawHeader = true;
                continue;
            }
            if ($type === 'IHDR') {
                return false;
            }
            if ($type === 'IDAT') {
                $sawData = true;
            }
            if ($type === 'IEND') {
                return $length === 0 && $sawData && $position === $size;
            }
        }
        return false;
    }

    /**
     * Enforce the requested delivery MIME using byte magic as the source of
     * truth. A stale/missing inlineData MIME is harmless when the bytes already
     * match. If a JPEG request comes back in another recognized image format,
     * use the local encoder as a bounded fallback and verify its output too.
     * Anything that still disagrees is rejected so PNG bytes can never be
     * written under a .jpg filename.
     *
     * @param null|callable(string):string $jpegEncoder test seam; production
     *        defaults to {@see toJpeg}
     */
    public static function ensureMime(
        string $bytes,
        ?string $declaredMime,
        string $requestedMime,
        ?callable $jpegEncoder = null,
    ): string {
        $requested = self::canonicalMime($requestedMime);
        if (!in_array($requested, ['image/jpeg', 'image/png'], true)) {
            throw new \InvalidArgumentException("Unsupported requested image MIME: {$requestedMime}");
        }

        $detected = self::mimeFromBytes($bytes);
        if ($detected === $requested) {
            return $bytes;
        }

        if ($requested === 'image/jpeg' && $detected !== null) {
            $encoder = $jpegEncoder ?? [self::class, 'toJpeg'];
            try {
                $converted = $encoder($bytes);
            } catch (\Throwable) {
                $converted = '';
            }
            if (is_string($converted) && self::mimeFromBytes($converted) === 'image/jpeg') {
                return $converted;
            }
        }

        $declared = trim((string) $declaredMime);
        $declared = $declared !== '' ? $declared : 'missing';
        $actual = $detected ?? 'unrecognized';
        $fallback = $requested === 'image/jpeg'
            ? 'local JPEG conversion unavailable or failed'
            : 'no safe local conversion available';
        throw new \RuntimeException(
            "Image proxy format mismatch: requested {$requested}; declared {$declared}; "
            . "detected {$actual}; {$fallback}; delivered removed"
        );
    }

    /**
     * Re-encode image bytes as JPEG, flattened on white. This is only a fallback
     * for a server/proxy that ignored imageOutputOptions. Bytes already carrying
     * JPEG magic pass through untouched. Without a usable imaging extension the
     * original bytes come back; {@see ensureMime} verifies the result and rejects
     * it rather than allowing those bytes to be mislabeled.
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
     * @param null|callable(int):void $sleeper Test seam for the backoff waits; defaults to sleep().
     * @return array{results:array<int,array{ok:bool,bytes?:string,error?:string,filtered?:bool}>,succeeded:int}
     */
    public static function retryBatch(
        array $bodies,
        callable $transport,
        array $delays,
        ?callable $onRetry = null,
        ?callable $onResult = null,
        ?callable $sleeper = null,
    ): array
    {
        $sleeper ??= static function (int $seconds): void {
            sleep($seconds);
        };
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
                // this wave; held-only waves charge the first backoff (see
                // CurlMultiPool::heldWaveWait).
                $wait = CurlMultiPool::heldWaveWait($retryWaits, $delays);
                $retryWave++;
                if ($onRetry !== null) {
                    $onRetry(count($pending), $retryWave, $wait);
                }
                $sleeper($wait);
            }
        }

        ksort($results);
        return ['results' => $results, 'succeeded' => $succeeded];
    }

    /**
     * Interpret a completed transfer at the protocol level: HTTP-status
     * classification + generateContent body parsing. Returns decoded image
     * bytes and the response's declared MIME, or throws TransientApiException
     * (429/5xx), ImageFilteredException (safety filter — retryable AND
     * repairable), or RuntimeException (permanent). Pure — no I/O — so the
     * single and batched paths share it.
     *
     * The transport classifies its own connection-level failures (e.g. cURL
     * errnos) BEFORE calling this, so nothing transport-specific lives here.
     *
     * @internal Delegation seam for the transport; not part of the consumer API.
     *
     * @return array{bytes:string,mime:?string}
     * @throws TransientApiException on a retryable HTTP status (429/5xx)
     * @throws ImageFilteredException when the safety filter rejected the prompt
     */
    public static function interpret(string $raw, int $status): array
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

        $part = self::imagePart($data);
        if ($part === null) {
            $detail = self::noImageReason($data) ?? substr($raw, 0, 300);
            throw new \RuntimeException('Image proxy response had no image data: ' . $detail);
        }

        $bytes = base64_decode($part['data'], true);
        if ($bytes === false || $bytes === '') {
            throw new \RuntimeException('Image proxy returned undecodable base64');
        }
        return ['bytes' => $bytes, 'mime' => $part['mime']];
    }

    /**
     * The first final inline image payload. Gemini 3 may include internal
     * `thought: true` image parts before the authored result; those are never a
     * deliverable asset and must be skipped.
     *
     * @param ?array<mixed> $data decoded JSON response body
     * @return ?array{data:string,mime:?string}
     */
    public static function imagePart(?array $data): ?array
    {
        foreach ((array) ($data['candidates'] ?? []) as $candidate) {
            $parts = is_array($candidate) ? ($candidate['content']['parts'] ?? null) : null;
            foreach ((array) $parts as $part) {
                if (!is_array($part) || ($part['thought'] ?? false) === true) {
                    continue;
                }
                $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;
                $b64 = is_array($inline) ? ($inline['data'] ?? null) : null;
                if (!is_string($b64) || $b64 === '') {
                    continue;
                }
                $mime = $inline['mimeType'] ?? $inline['mime_type'] ?? null;
                return [
                    'data' => $b64,
                    'mime' => is_string($mime) && trim($mime) !== '' ? trim($mime) : null,
                ];
            }
        }
        return null;
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
        return self::imagePart($data)['data'] ?? null;
    }

    /** Normalize MIME aliases/parameters used by response metadata. */
    private static function canonicalMime(?string $mime): ?string
    {
        $mime = strtolower(trim((string) $mime));
        $mime = trim(explode(';', $mime, 2)[0]);
        return match ($mime) {
            'image/jpeg', 'image/jpg', 'image/pjpeg' => 'image/jpeg',
            'image/png', 'image/x-png' => 'image/png',
            default => null,
        };
    }

    /**
     * The safety-filter reason in a decoded generateContent response, or null
     * when the response was not filtered. Only explicit, machine-readable
     * policy/safety outcomes qualify: neither an arbitrary non-STOP finish
     * reason nor text without an image proves that safety filtering occurred.
     * A response that carries image data is never filtered, whatever else
     * rides along. Public and pure so the classification is unit-testable.
     *
     * @param ?array<mixed> $data decoded JSON response body
     */
    public static function filteredReason(?array $data): ?string
    {
        if ($data === null || self::imageData($data) !== null) {
            return null;
        }

        $block = self::filteredOutcome($data['promptFeedback']['blockReason'] ?? null);
        if ($block !== null) {
            return 'prompt blocked: ' . $block;
        }

        foreach ((array) ($data['candidates'] ?? []) as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $finish = self::filteredOutcome($candidate['finishReason'] ?? null);
            if ($finish !== null) {
                return 'candidate finished: ' . $finish;
            }
        }
        return null;
    }

    /** Return a normalized policy/safety outcome, never a generic finish reason. */
    private static function filteredOutcome(mixed $reason): ?string
    {
        if (!is_string($reason)) {
            return null;
        }
        $reason = strtoupper(trim($reason));
        return in_array($reason, self::FILTERED_REASONS, true) ? $reason : null;
    }

    /**
     * Preserve the useful response detail when a non-policy response has no
     * image. This remains an ordinary permanent per-image failure; it is not a
     * safety signal and therefore does not enter retry or prompt-repair flows.
     *
     * @param ?array<mixed> $data decoded JSON response body
     */
    private static function noImageReason(?array $data): ?string
    {
        if ($data === null) {
            return null;
        }

        $block = $data['promptFeedback']['blockReason'] ?? null;
        if (is_string($block) && trim($block) !== '') {
            return 'prompt block reason: ' . mb_substr(trim($block), 0, 200);
        }

        foreach ((array) ($data['candidates'] ?? []) as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $finish = $candidate['finishReason'] ?? null;
            $finish = is_string($finish) && trim($finish) !== '' ? trim($finish) : null;
            $message = $candidate['finishMessage'] ?? null;
            $message = is_string($message) && trim($message) !== '' ? trim($message) : null;
            $text = null;
            foreach ((array) ($candidate['content']['parts'] ?? []) as $part) {
                $candidateText = is_array($part) ? ($part['text'] ?? null) : null;
                if (is_string($candidateText) && trim($candidateText) !== '') {
                    $text = trim($candidateText);
                    break;
                }
            }

            if ($finish !== null && strtoupper($finish) !== 'STOP') {
                $detail = 'candidate finished: ' . $finish;
                if ($message !== null) {
                    $detail .= ' (' . mb_substr($message, 0, 200) . ')';
                } elseif ($text !== null) {
                    $detail .= '; text: ' . mb_substr($text, 0, 200);
                }
                return $detail;
            }
            if ($text !== null) {
                return 'text-only response: ' . mb_substr($text, 0, 200);
            }
            if ($finish !== null) {
                return 'candidate finished: ' . $finish;
            }
        }

        return null;
    }
}
