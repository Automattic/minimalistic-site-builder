<?php
declare(strict_types=1);

/**
 * Anthropic Messages API client (direct to api.anthropic.com).
 *
 * Zero dependencies: a plain cURL POST. One call in, one full response out —
 * no streaming, no tool use, no agentic loop. This is the production transport
 * for the builder; see PROGRESS.md for why the wpcom proxy is not used.
 */
final class AnthropicClient implements Llm
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    /**
     * System preamble sent on EVERY request, single-call and batch alike.
     * Each prompt embeds the user's site brief; whatever the step, user-facing
     * text must come back in that brief's language rather than drifting into
     * English because the surrounding instructions are English. Structural
     * output and machine-readable directives (e.g. the AI_IMAGE alt specs the
     * image pipeline parses) are exempt, so the JSON/markup/CSS/PHP steps and
     * image generation are unaffected. Public so tests can assert it rides on
     * every body.
     */
    public const SYSTEM_PREAMBLE = 'Respect the language of the original user prompt. A request '
        . 'may wrap or quote that prompt inside English instructions — the instruction language is '
        . 'irrelevant; what counts is the language the user\'s own brief is written in. Write ALL '
        . 'user-facing text you produce — titles, headings, body copy, labels, button text, '
        . 'captions — in that language, unless the user explicitly asked for another language or '
        . 'the request names the exact language to use. Output addressed to the build pipeline '
        . 'rather than to site visitors (planning notes, design rationale, scores) is not '
        . 'user-facing. Structural output is exempt: JSON keys, code, block markup, CSS, slugs, '
        . 'file names, other identifiers, and machine-readable directives (such as AI_IMAGE '
        . 'placeholder specs) keep the exact form their instructions prescribe.';

    /**
     * Appended to the system prompt of every JSON call (single and batch) to
     * steer the model toward raw, fence-free JSON. One constant so the wire
     * request and the decode-failure log reconstruction can never drift apart.
     */
    private const JSON_SYSTEM = "\nRespond with a single valid JSON value and nothing else. "
        . 'No prose, no markdown fences.';

    /**
     * Most concurrent in-flight requests per batch. A landing page can fan out
     * to ~10 parts (header, footer, and every section); this cap lets a typical
     * fan-out run as one fully overlapped window while still bounding in-flight
     * transfers so a very wide batch cannot trip the API's concurrent-request /
     * rate limits.
     */
    private const MAX_CONCURRENCY = 10;

    private int $requests = 0;
    private int $inputTokens = 0;
    private int $outputTokens = 0;

    public function __construct(
        private string $apiKey,
        private string $model,
        private int $defaultMaxTokens = 16000,
    ) {}

    /**
     * Cumulative token usage across every request this client has made.
     *
     * @return array{requests:int,input_tokens:int,output_tokens:int,total_tokens:int}
     */
    public function usageTotals(): array
    {
        return [
            'requests'      => $this->requests,
            'input_tokens'  => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens'  => $this->inputTokens + $this->outputTokens,
        ];
    }

    /**
     * Pull token counts from a Messages API response. Input includes cache
     * read/creation tokens so the total reflects everything billed.
     *
     * @param array<string,mixed> $response
     * @return array{input:int,output:int}
     */
    public static function extractUsage(array $response): array
    {
        $u = $response['usage'] ?? [];
        $input = (int) ($u['input_tokens'] ?? 0)
            + (int) ($u['cache_read_input_tokens'] ?? 0)
            + (int) ($u['cache_creation_input_tokens'] ?? 0);
        return ['input' => $input, 'output' => (int) ($u['output_tokens'] ?? 0)];
    }

    public function complete(string $prompt, array $opts = []): string
    {
        // Stream the response: bytes arrive incrementally, so a stalled
        // connection is detected quickly (and retried) instead of blocking the
        // full timeout, and long generations never hit an idle-connection
        // timeout.
        $body = self::bodyFor(['prompt' => $prompt] + $opts, $this->model, $this->defaultMaxTokens);

        $label = (string) ($opts['log_label'] ?? 'request');
        try {
            $res = $this->requestWithRetry($body);
        } catch (\Throwable $e) {
            // Log the failed call too, so an aborted build is still inspectable.
            LlmLogger::log($label, $body, ['text' => '', 'input' => 0, 'output' => 0], 0.0, $e->getMessage());
            throw $e;
        }

        $this->requests++;
        $this->inputTokens += $res['input'];
        $this->outputTokens += $res['output'];

        LlmLogger::log($label, $body, $res, $res['time']);

        if (trim($res['text']) === '') {
            throw new RuntimeException('No text content in streamed response');
        }
        return $res['text'];
    }

    public function completeJson(string $prompt, array $opts = []): array
    {
        // Steer toward raw JSON; still strip fences defensively.
        $opts['system'] = ($opts['system'] ?? '') . self::JSON_SYSTEM;
        $text = $this->complete($prompt, $opts);

        $data = self::decodeJson($text);
        if ($data === null) {
            // The transport call succeeded and was logged OK above; log the
            // decode failure as its own FAILED entry so the transcript reflects
            // that the build died on THIS call, not somewhere downstream. The
            // body is rebuilt through bodyFor so the log carries the request as
            // built (system preamble included) — a sampling parameter stripped
            // mid-retry is the one divergence it can't see.
            $error = "Expected JSON, got: {$text}";
            LlmLogger::log(
                (string) ($opts['log_label'] ?? 'request'),
                self::bodyFor(['prompt' => $prompt] + $opts, $this->model, $this->defaultMaxTokens),
                ['text' => $text, 'input' => 0, 'output' => 0],
                0.0,
                $error,
            );
            throw new RuntimeException($error);
        }
        return $data;
    }

    public function completeJsonBatch(array $requests): array
    {
        $out = [];
        foreach ($this->textBatch($requests, true) as $key => $text) {
            $data = self::decodeJson($text);
            if ($data === null) {
                // Same as completeJson: the transport entry was logged OK, so
                // log the decode failure as its own FAILED entry too, with the
                // body rebuilt as textBatch built it (preamble + JSON steer
                // included; a sampling parameter stripped mid-retry is the one
                // divergence it can't see).
                $error = "batch request '{$key}': expected JSON, got: {$text}";
                $req = $requests[$key];
                LlmLogger::log(
                    (string) ($req['log_label'] ?? $key),
                    self::bodyFor(
                        ['system' => (string) ($req['system'] ?? '') . self::JSON_SYSTEM] + $req,
                        $this->model,
                        $this->defaultMaxTokens,
                    ),
                    ['text' => $text, 'input' => 0, 'output' => 0],
                    0.0,
                    $error,
                );
                throw new RuntimeException($error);
            }
            $out[$key] = $data;
        }
        return $out;
    }

    public function completeBatch(array $requests): array
    {
        return $this->textBatch($requests, false);
    }

    /**
     * Shared concurrent-batch transport for both completeJsonBatch (JSON) and
     * completeBatch (raw text). Builds one Messages body per request, runs them
     * concurrently retrying only transient failures, accrues token usage, and
     * returns each request's raw assistant text keyed as the input.
     *
     * @param array<string,array{prompt:string,system?:string,model?:string,max_tokens?:int}> $requests
     * @return array<string,string> raw text keyed as the input
     */
    private function textBatch(array $requests, bool $json): array
    {
        if ($requests === []) {
            return [];
        }

        // Build one Messages body per request. Each may pin its own model,
        // temperature and token budget, so a single batch can mix (e.g. Haiku
        // plan + Opus theme).
        $bodies = [];
        foreach ($requests as $key => $req) {
            $system = (string) ($req['system'] ?? '');
            if ($json) {
                $system .= self::JSON_SYSTEM;
            }
            $bodies[$key] = self::bodyFor(['system' => $system] + $req, $this->model, $this->defaultMaxTokens);
        }

        // Label a call after its step's explicit log_label, else the request key
        // (already a clean name like "header" or "section-hero").
        $labelFor = fn (string $key): string => (string) ($requests[$key]['log_label'] ?? $key);

        // Run them all concurrently, retrying only the transient failures. A
        // request that fails for good is logged before the batch aborts, so the
        // call that broke the build is still inspectable.
        $results = self::retryTextBatch(
            $bodies,
            fn (array $subset): array => $this->streamMulti($subset),
            [2, 5, 12],
            function (string $key, string $error, float $time) use ($labelFor, &$bodies): void {
                LlmLogger::log($labelFor($key), $bodies[$key], ['text' => '', 'input' => 0, 'output' => 0], $time, $error);
            },
        );

        $out = [];
        foreach ($results as $key => $res) {
            $this->requests++;
            $this->inputTokens += $res['input'];
            $this->outputTokens += $res['output'];

            LlmLogger::log($labelFor($key), $bodies[$key], $res, $res['time']);

            $out[$key] = $res['text'];
        }
        return $out;
    }

    /**
     * Build one streaming Messages API request body from a request spec — the
     * single place the optional per-request knobs (model, max_tokens,
     * temperature, system) are mapped onto the wire format, shared by the
     * single-call and batch paths. Every body carries SYSTEM_PREAMBLE (the
     * respect-the-prompt-language rule) as its system prompt, with any
     * per-request system text appended after it. Temperature is only sent when the caller
     * set one AND the target model still supports sampling parameters, so an
     * unset step keeps the API's default sampling and a step pointed at a
     * sampling-less model (Opus 4.7+, Fable) doesn't 400. Pure — unit-testable.
     *
     * @param array{prompt:string,system?:string,model?:string,max_tokens?:int,temperature?:float} $req
     * @return array<string,mixed>
     */
    public static function bodyFor(array $req, string $defaultModel, int $defaultMaxTokens): array
    {
        $model = (string) ($req['model'] ?? $defaultModel);
        $body = [
            'model'      => $model,
            'max_tokens' => $req['max_tokens'] ?? $defaultMaxTokens,
            'stream'     => true,
            'messages'   => [
                ['role' => 'user', 'content' => (string) $req['prompt']],
            ],
        ];
        if (isset($req['temperature']) && self::supportsSampling($model)) {
            $body['temperature'] = (float) $req['temperature'];
        }
        $system = self::SYSTEM_PREAMBLE;
        if (trim((string) ($req['system'] ?? '')) !== '') {
            $system .= "\n\n" . $req['system'];
        }
        $body['system'] = $system;
        return $body;
    }

    /**
     * Whether a model still accepts the sampling parameters (temperature,
     * top_p, top_k). The API REMOVED them on Claude Opus 4.7/4.8 and Fable —
     * sending one returns HTTP 400 "`temperature` is deprecated for this
     * model". A model this misclassifies as supporting (e.g. a future family
     * that also drops sampling) is still handled: the 400 is detected via
     * rejectedParam() and the request retried without the parameter. Pure.
     */
    public static function supportsSampling(string $model): bool
    {
        return preg_match('/claude-(fable|opus-4-[78])/', $model) !== 1;
    }

    /**
     * Detect the "sampling parameter no longer supported" API rejection in an
     * error payload (raw response body or an exception message containing it)
     * and name the offending parameter, so the caller can strip it and retry
     * instead of aborting the build. Returns null for any other error. Pure.
     */
    public static function rejectedParam(string $error): ?string
    {
        if (preg_match('/`(temperature|top_p|top_k)` is (?:deprecated|not supported)/', $error, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    /**
     * Drive a batched transport to completion, retrying ONLY the transient
     * failures with backoff. Pure orchestration (the transport does the I/O,
     * sleep() paces the rounds) so it is unit-testable with a fake transport and
     * zero delays. Unlike the image batch, a permanently failing request aborts
     * the whole batch — a missing section or theme would break the build, so we
     * fail loud rather than return a partial set.
     *
     * A request rejected for carrying a sampling parameter the model no longer
     * supports (outcome carries `retry_without`) is retried immediately with
     * that parameter stripped from its body — it can't recur (the key is gone),
     * so it doesn't consume a transient-retry attempt. $bodies is by-reference
     * so the caller's post-batch logging reflects what was actually sent.
     *
     * @param array<string,array<string,mixed>> $bodies request bodies keyed by id
     * @param callable(array<string,array<string,mixed>>):array<string,array{ok:bool,text?:string,input?:int,output?:int,error?:string,transient?:bool,retry_without?:string}> $transport
     * @param array<int,int> $delays backoff seconds before each retry (length = max retries)
     * @param null|callable(string,string,float):void $onFailure called with (key, error, time) for a request that fails for good, just before the batch aborts — lets the caller log it
     * @return array<string,array{text:string,input:int,output:int,time:float}>
     */
    public static function retryTextBatch(array &$bodies, callable $transport, array $delays, ?callable $onFailure = null): array
    {
        $results = [];
        $pending = array_keys($bodies);
        $attempt = 0;

        while ($pending !== []) {
            $outcomes = $transport(array_intersect_key($bodies, array_flip($pending)));

            $retry = [];
            $transient = false;
            foreach ($outcomes as $key => $outcome) {
                $dropParam = $outcome['retry_without'] ?? null;
                if ($outcome['ok']) {
                    $results[$key] = [
                        'text'   => (string) ($outcome['text'] ?? ''),
                        'input'  => (int) ($outcome['input'] ?? 0),
                        'output' => (int) ($outcome['output'] ?? 0),
                        'time'   => (float) ($outcome['time'] ?? 0),
                    ];
                } elseif ($dropParam !== null && array_key_exists($dropParam, $bodies[$key])) {
                    unset($bodies[$key][$dropParam]);
                    $retry[] = $key;
                    fwrite(STDERR, "    (model rejected '{$dropParam}' on request '{$key}'; retrying without it)\n");
                } elseif (($outcome['transient'] ?? false) && $attempt < count($delays)) {
                    $retry[] = $key;
                    $transient = true;
                } else {
                    $error = (string) ($outcome['error'] ?? 'unknown');
                    if ($onFailure !== null) {
                        $onFailure($key, $error, (float) ($outcome['time'] ?? 0));
                    }
                    throw new RuntimeException("Anthropic batch request '{$key}' failed: {$error}");
                }
            }

            $pending = $retry;
            // Back off only for genuinely transient failures; a stripped-param
            // retry is deterministic (the offending key is gone) and immediate.
            if ($pending !== [] && $transient) {
                $wait = $delays[$attempt];
                $attempt++;
                fwrite(STDERR, '    (transient API error on ' . count($pending)
                    . " request(s); retry {$attempt} in {$wait}s)\n");
                sleep($wait);
            }
        }

        return $results;
    }

    /**
     * Run a set of streaming Messages requests, at most MAX_CONCURRENCY in
     * flight at once, and classify each transfer. Pure transport — no retry, no
     * request counting, no throwing on a single failure (the orchestrator
     * decides). Bounding concurrency keeps a wide fan-out (every landing-page
     * part at once) from tripping the API's rate limits.
     *
     * @param array<string,array<string,mixed>> $bodies request body keyed by id
     * @return array<string,array{ok:bool,text?:string,input?:int,output?:int,error?:string,transient?:bool}>
     */
    private function streamMulti(array $bodies): array
    {
        $out = [];
        foreach (self::concurrencyWindows($bodies) as $chunk) {
            $out += $this->streamChunk($chunk);
        }
        return $out;
    }

    /**
     * Split a batch into ordered windows of at most MAX_CONCURRENCY requests,
     * preserving keys, so the transport runs each window concurrently and no
     * more than MAX_CONCURRENCY transfers are ever in flight. Pure — unit-testable.
     *
     * @param array<string,array<string,mixed>> $bodies request body keyed by id
     * @return array<int,array<string,array<string,mixed>>>
     */
    public static function concurrencyWindows(array $bodies): array
    {
        return array_chunk($bodies, self::MAX_CONCURRENCY, true);
    }

    /**
     * Run one window of streaming requests concurrently with curl_multi and
     * assemble each SSE body per handle. Mirrors WpcomImageClient::multiRequest.
     *
     * @param array<string,array<string,mixed>> $bodies request body keyed by id
     * @return array<string,array{ok:bool,text?:string,input?:int,output?:int,error?:string,transient?:bool}>
     */
    private function streamChunk(array $bodies): array
    {
        $multi = curl_multi_init();
        $handles = [];
        $raw = [];
        foreach ($bodies as $key => $body) {
            $raw[$key] = '';
            $ch = curl_init(self::ENDPOINT);
            curl_setopt_array($ch, [
                CURLOPT_POST          => true,
                CURLOPT_HTTPHEADER    => [
                    'x-api-key: ' . $this->apiKey,
                    'anthropic-version: ' . self::API_VERSION,
                    'content-type: application/json',
                    'accept: text/event-stream',
                ],
                CURLOPT_POSTFIELDS    => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                CURLOPT_TIMEOUT       => 600,
                CURLOPT_LOW_SPEED_LIMIT => 1,
                CURLOPT_LOW_SPEED_TIME  => 90,
                CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$raw, $key) {
                    $raw[$key] .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $handles[$key] = $ch;
            curl_multi_add_handle($multi, $ch);
        }

        // Drive all transfers to completion (see WpcomImageClient::multiRequest
        // for why the -1 guard against a busy-spin during DNS is needed).
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running && curl_multi_select($multi, 1.0) === -1) {
                usleep(1000);
            }
        } while ($running && $status === CURLM_OK);

        $out = [];
        foreach ($handles as $key => $ch) {
            $errno  = curl_errno($ch);
            $error  = curl_error($ch);
            $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $time   = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
            $out[$key] = self::interpretStream($raw[$key], $errno, $error, $httpStatus, $time);
        }

        curl_multi_close($multi);
        return $out;
    }

    /**
     * Classify one completed streaming transfer into an outcome (never throws),
     * so the batch orchestrator can retry the transient ones. Same transient vs
     * permanent split as streamRequest(): connection-level cURL errors (incl. 6
     * "could not resolve host") and 429/5xx are transient; a clean 4xx or any
     * other cURL error is permanent and aborts the build. Pure — no I/O.
     *
     * @return array{ok:bool,text?:string,input?:int,output?:int,time?:float,error?:string,transient?:bool}
     */
    private static function interpretStream(string $raw, int $errno, string $error, int $status, float $time = 0.0): array
    {
        if ($errno !== 0) {
            return ['ok' => false, 'transient' => self::isTransientCurl($errno), 'error' => "cURL ({$errno}): {$error}"];
        }
        if ($status < 200 || $status >= 300) {
            $out = ['ok' => false, 'transient' => self::isTransientStatus($status), 'error' => "HTTP {$status}: " . self::truncate($raw)];
            // A rejected sampling parameter is recoverable: the orchestrator
            // strips it from the body and retries (see retryTextBatch).
            if (($param = self::rejectedParam($raw)) !== null) {
                $out['retry_without'] = $param;
            }
            return $out;
        }

        $parsed = self::parseSse($raw);
        if ($parsed['error'] !== null) {
            $transient = in_array($parsed['error_type'], ['overloaded_error', 'api_error'], true);
            return ['ok' => false, 'transient' => $transient, 'error' => "stream error: {$parsed['error']}"];
        }
        if (trim($parsed['text']) === '') {
            return ['ok' => false, 'transient' => true, 'error' => 'no text content in streamed response'];
        }
        return ['ok' => true, 'text' => $parsed['text'], 'input' => $parsed['input'], 'output' => $parsed['output'], 'time' => $time];
    }

    /**
     * Connection-level cURL failures worth retrying with backoff. 6 = could not
     * resolve host (the DNS blip that used to abort builds), 7 = could not
     * connect, 28 = timeout, 35 = SSL connect, 52 = empty reply, 55 = send
     * error, 56 = recv error. Any other cURL error (bad URL, cert, etc.) is
     * permanent. Pure.
     */
    private static function isTransientCurl(int $errno): bool
    {
        return in_array($errno, [6, 7, 28, 35, 52, 55, 56], true);
    }

    /**
     * HTTP statuses worth retrying: 429 (rate limit) and any 5xx (server-side).
     * A 4xx other than 429 (bad request, auth, not-found) is the caller's fault
     * and will never succeed on retry, so it is permanent. Pure.
     */
    private static function isTransientStatus(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    /**
     * Run a streaming request, retrying transient failures with backoff. A
     * 400 for a sampling parameter the model no longer supports is retried
     * immediately with that parameter stripped (can't recur — the key is
     * gone). $body is by-reference so the caller logs what was actually sent.
     *
     * @param array<string,mixed> $body
     * @return array{text:string,input:int,output:int,time:float}
     */
    private function requestWithRetry(array &$body): array
    {
        $delays = [2, 5, 12]; // seconds before retries 1, 2, 3
        $attempt = 0;
        while (true) {
            try {
                return $this->streamRequest($body);
            } catch (TransientApiException $e) {
                if ($attempt >= count($delays)) {
                    throw new RuntimeException('Anthropic API failed after retries: ' . $e->getMessage(), 0, $e);
                }
                $wait = $delays[$attempt];
                $attempt++;
                fwrite(STDERR, "    (transient API error: {$e->getMessage()}; retry {$attempt} in {$wait}s)\n");
                sleep($wait);
            } catch (RuntimeException $e) {
                $param = self::rejectedParam($e->getMessage());
                if ($param === null || !array_key_exists($param, $body)) {
                    throw $e;
                }
                unset($body[$param]);
                fwrite(STDERR, "    (model rejected '{$param}'; retrying without it)\n");
            }
        }
    }

    /**
     * POST a streaming Messages request and assemble the text + token usage from
     * the SSE event stream.
     *
     * @param array<string,mixed> $body
     * @return array{text:string,input:int,output:int,time:float}
     * @throws TransientApiException on a retryable failure (DNS, stall, 429, 5xx, overload)
     */
    private function streamRequest(array $body): array
    {
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $raw = '';

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST          => true,
            CURLOPT_HTTPHEADER    => [
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::API_VERSION,
                'content-type: application/json',
                'accept: text/event-stream',
            ],
            CURLOPT_POSTFIELDS    => $payload,
            CURLOPT_TIMEOUT       => 600,  // generous hard cap for long generations
            CURLOPT_LOW_SPEED_LIMIT => 1,  // abort if < 1 byte/s ...
            CURLOPT_LOW_SPEED_TIME  => 90, // ... for 90s (a true stall; pings keep a live stream above this)
            // Accumulate the stream; bytes flowing keeps the connection from
            // idling. We parse the assembled SSE body once below.
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$raw) {
                $raw .= $chunk;
                return strlen($chunk);
            },
        ]);

        curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $time   = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        curl_close($ch);

        // Connection-level failures (DNS, connect, timeout, stall, dropped
        // socket) and 429/5xx are transient — back off and retry. A clean 4xx or
        // any other cURL error is permanent and aborts the build immediately
        // rather than burning the backoff on something that can't recover.
        if ($errno !== 0) {
            if (self::isTransientCurl($errno)) {
                throw new TransientApiException("cURL ({$errno}): {$error}");
            }
            throw new RuntimeException("cURL error ({$errno}): {$error}");
        }
        if ($status < 200 || $status >= 300) {
            if (self::isTransientStatus($status)) {
                throw new TransientApiException("HTTP {$status}: " . self::truncate($raw));
            }
            throw new RuntimeException("Anthropic API HTTP {$status}: " . self::truncate($raw));
        }

        $parsed = self::parseSse($raw);
        if ($parsed['error'] !== null) {
            if (in_array($parsed['error_type'], ['overloaded_error', 'api_error'], true)) {
                throw new TransientApiException("stream error: {$parsed['error']}");
            }
            throw new RuntimeException("stream error: {$parsed['error']}");
        }
        // An empty body is usually a transient hiccup (a stop with no content),
        // so retry it — matching the batch path's interpretStream().
        if (trim($parsed['text']) === '') {
            throw new TransientApiException('no text content in streamed response');
        }

        return ['text' => $parsed['text'], 'input' => $parsed['input'], 'output' => $parsed['output'], 'time' => $time];
    }

    /**
     * Parse an assembled Server-Sent Events body from the Messages API into the
     * concatenated text and token usage. Pure (no I/O) so it can be unit-tested.
     *
     * @return array{text:string,input:int,output:int,error:?string,error_type:string}
     */
    public static function parseSse(string $raw): array
    {
        $text = '';
        $input = 0;
        $output = 0;
        $error = null;
        $errorType = '';

        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            if (!str_starts_with($line, 'data:')) {
                continue;
            }
            $json = trim(substr($line, 5));
            if ($json === '' || $json === '[DONE]') {
                continue;
            }
            $evt = json_decode($json, true);
            if (!is_array($evt)) {
                continue;
            }
            switch ($evt['type'] ?? '') {
                case 'message_start':
                    $u = self::extractUsage(['usage' => $evt['message']['usage'] ?? []]);
                    $input = $u['input'];
                    $output = $u['output'];
                    break;
                case 'content_block_delta':
                    if (($evt['delta']['type'] ?? '') === 'text_delta') {
                        $text .= $evt['delta']['text'] ?? '';
                    }
                    break;
                case 'message_delta':
                    if (isset($evt['usage']['output_tokens'])) {
                        $output = (int) $evt['usage']['output_tokens'];
                    }
                    break;
                case 'error':
                    $error = $evt['error']['message'] ?? 'stream error';
                    $errorType = $evt['error']['type'] ?? '';
                    break;
            }
        }

        return ['text' => $text, 'input' => $input, 'output' => $output, 'error' => $error, 'error_type' => $errorType];
    }

    private static function truncate(string $s, int $max = 300): string
    {
        return strlen($s) > $max ? substr($s, 0, $max) . '…' : $s;
    }

    private static function stripFences(string $text): string
    {
        $text = trim($text);
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```[a-zA-Z]*\n/', '', $text);
            $text = preg_replace('/\n```$/', '', (string) $text);
        }
        return trim((string) $text);
    }

    /**
     * Decode assistant text into a JSON array, tolerating the two mistakes models
     * make most often: wrapping the value in ```json fences, and leaving trailing
     * commas before a closing } or ]. Strict json_decode is tried first; only if
     * that fails do we attempt the trailing-comma repair, so well-formed output is
     * never altered. Returns null when the text isn't recoverable JSON.
     *
     * @return array<mixed>|null
     */
    public static function decodeJson(string $text): ?array
    {
        $json = self::stripFences($text);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            $data = json_decode(self::stripTrailingCommas($json), true);
        }
        return is_array($data) ? $data : null;
    }

    /**
     * Remove commas that sit immediately before a closing } or ] (ignoring
     * whitespace) — invalid JSON that LLMs emit routinely. Walks the string
     * tracking string/escape state so commas inside string values are left
     * untouched.
     */
    private static function stripTrailingCommas(string $json): string
    {
        $out = '';
        $len = strlen($json);
        $inStr = false;
        for ($i = 0; $i < $len; $i++) {
            $ch = $json[$i];
            if ($inStr) {
                $out .= $ch;
                if ($ch === '\\' && $i + 1 < $len) {
                    $out .= $json[$i + 1]; // copy the escaped char verbatim
                    $i++;
                } elseif ($ch === '"') {
                    $inStr = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inStr = true;
                $out .= $ch;
                continue;
            }
            if ($ch === ',') {
                $j = $i + 1;
                while ($j < $len && ctype_space($json[$j])) {
                    $j++;
                }
                if ($j < $len && ($json[$j] === '}' || $json[$j] === ']')) {
                    continue; // drop the trailing comma
                }
            }
            $out .= $ch;
        }
        return $out;
    }
}
