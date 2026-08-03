<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

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
     * image generation are unaffected. The preamble also carries today's date
     * so time-anchored copy (footer copyright years, "as of" mentions) doesn't
     * fall back to the model's stale training-data sense of "now". The text
     * lives in prompts/system-preamble.md with the rest of the prompts; read
     * once per process and cached. Public so tests can assert it rides on
     * every body.
     */
    public static function systemPreamble(): string
    {
        static $preamble = null;
        if ($preamble === null) {
            $file = dirname(__DIR__) . '/prompts/system-preamble.md';
            $text = is_file($file) ? (string) file_get_contents($file) : '';
            if (trim($text) === '') {
                throw new \RuntimeException("Missing prompt template: {$file}");
            }
            $preamble = PromptRenderer::fill(trim($text), ['current_date' => gmdate('j F Y')]);
        }
        return $preamble;
    }

    /**
     * Appended to the system prompt of every JSON call (single and batch) to
     * steer the model toward raw, fence-free JSON. One constant keeps the
     * single-request and concurrent-batch paths aligned.
     */
    private const JSON_SYSTEM = "\nRespond with a single valid JSON value and nothing else. "
        . 'No prose, no markdown fences.';

    /**
     * Most concurrent in-flight requests per batch. The rolling pool keeps up
     * to this many transfers running and refills a slot the moment its
     * transfer completes, so a wide batch is bounded (no tripping the API's
     * concurrent-request / rate limits) without ever stalling behind a
     * window barrier.
     */
    private const MAX_CONCURRENCY = 10;

    private int $requests = 0;
    private int $inputTokens = 0;
    private int $outputTokens = 0;
    private int $cacheReadInputTokens = 0;
    private int $cacheCreationInputTokens = 0;

    public function __construct(
        private string $apiKey,
        private string $model,
        private int $defaultMaxTokens = 16000,
    ) {}

    /**
     * Cumulative token usage across every request this client has made.
     *
     * @return array{requests:int,input_tokens:int,output_tokens:int,total_tokens:int,cache_read_input_tokens:int,cache_creation_input_tokens:int}
     */
    public function usageTotals(): array
    {
        return [
            'requests'      => $this->requests,
            'input_tokens'  => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens'  => $this->inputTokens + $this->outputTokens,
            'cache_read_input_tokens' => $this->cacheReadInputTokens,
            'cache_creation_input_tokens' => $this->cacheCreationInputTokens,
        ];
    }

    /**
     * Pull token counts from a Messages API response. Input includes cache
     * read/creation tokens so the total reflects everything billed.
     *
     * @param array<string,mixed> $response
     * @return array{input:int,output:int,cache_read_input_tokens:int,cache_creation_input_tokens:int}
     */
    public static function extractUsage(array $response): array
    {
        $u = $response['usage'] ?? [];
        $cacheRead = (int) ($u['cache_read_input_tokens'] ?? 0);
        $cacheCreation = (int) ($u['cache_creation_input_tokens'] ?? 0);
        $input = (int) ($u['input_tokens'] ?? 0) + $cacheRead + $cacheCreation;
        return [
            'input' => $input,
            'output' => (int) ($u['output_tokens'] ?? 0),
            'cache_read_input_tokens' => $cacheRead,
            'cache_creation_input_tokens' => $cacheCreation,
        ];
    }

    /**
     * @param array{system?:string,model?:string,max_tokens?:int,temperature?:float,cached_prefixes?:list<string>,tolerate_empty?:bool,log_label?:string} $opts
     *        tolerate_empty accepts a successful whitespace-only response as ''
     *        without retrying; it is intended only for cache-warm probes.
     */
    public function complete(string $prompt, array $opts = []): string
    {
        // Stream the response: bytes arrive incrementally, so a stalled
        // connection is detected quickly (and retried) instead of blocking the
        // full timeout, and long generations never hit an idle-connection
        // timeout.
        $body = self::bodyFor(['prompt' => $prompt] + $opts, $this->model, $this->defaultMaxTokens);

        $label = (string) ($opts['log_label'] ?? 'request');
        $tolerateEmpty = ($opts['tolerate_empty'] ?? false) === true;
        try {
            $res = $this->requestWithRetry($body, $tolerateEmpty);
        } catch (\Throwable $e) {
            // Log the failed call too, so an aborted build is still inspectable.
            LlmLogger::log($label, $body, ['text' => '', 'input' => 0, 'output' => 0], 0.0, $e->getMessage());
            throw $e;
        }

        $this->requests++;
        $this->inputTokens += $res['input'];
        $this->outputTokens += $res['output'];
        $this->cacheReadInputTokens += $res['cache_read_input_tokens'];
        $this->cacheCreationInputTokens += $res['cache_creation_input_tokens'];

        LlmLogger::log($label, $body, $res, $res['time']);

        // Unreachable in practice: retrySingleRequest already converts an
        // empty non-tolerated response into a transient failure. Kept as a
        // final guard so a transport change can't silently return ''.
        if (!$tolerateEmpty && trim($res['text']) === '') {
            throw new \RuntimeException('No text content in streamed response');
        }
        return $res['text'];
    }

    public function completeJson(string $prompt, array $opts = []): array
    {
        $result = $this->completeJsonBatch(['request' => ['prompt' => $prompt] + $opts]);
        return $result['request'];
    }

    public function completeJsonBatch(array $requests): array
    {
        return JsonBatchRecovery::run(
            $requests,
            fn (array $subset): array => $this->responseBatch($subset, true),
            defaultMaxTokens: $this->defaultMaxTokens,
        );
    }

    public function completeBatch(array $requests): TextBatchResult
    {
        return TextBatchRecovery::run(
            $requests,
            fn (array $subset): array => $this->responseBatch($subset, false),
            defaultMaxTokens: $this->defaultMaxTokens,
        );
    }

    /**
     * Shared concurrent-batch transport for both completeJsonBatch (JSON) and
     * completeBatch (raw text). Builds one Messages body per request, runs them
     * concurrently retrying only transient failures, accrues token usage, and
     * returns each response record keyed as the input.
     *
     * @param array<array-key,array{prompt:string,system?:string,model?:string,max_tokens?:int,temperature?:float,json_schema?:array{name:string,schema:array<string,mixed>},cached_prefixes?:list<string>}> $requests
     * @return array<array-key,array<string,mixed>>
     */
    private function responseBatch(array $requests, bool $json): array
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
        // (already a clean name like "header" or "section-hero"). Keys may be
        // ints too (PHP coerces numeric keys), so the type must admit both.
        $labelFor = fn (string|int $key): string => (string) ($requests[$key]['log_label'] ?? $key);

        // Run them all concurrently, retrying only the transient failures. A
        // request that fails for good is logged before the batch aborts, so the
        // call that broke the build is still inspectable.
        $results = self::retryTextBatch(
            $bodies,
            fn (array $subset): array => $this->streamMulti($subset),
            [2, 5, 12],
            function (string|int $key, string $error, float $time) use ($labelFor, &$bodies): void {
                LlmLogger::log($labelFor($key), $bodies[$key], ['text' => '', 'input' => 0, 'output' => 0], $time, $error);
            },
        );

        $out = [];
        foreach ($results as $key => $res) {
            $this->requests++;
            $this->inputTokens += $res['input'];
            $this->outputTokens += $res['output'];
            $this->cacheReadInputTokens += $res['cache_read_input_tokens'];
            $this->cacheCreationInputTokens += $res['cache_creation_input_tokens'];

            $res['log_path'] = LlmLogger::log($labelFor($key), $bodies[$key], $res, $res['time']);
            $res['model'] = (string) $bodies[$key]['model'];
            $out[$key] = $res;
        }
        return $out;
    }

    /**
     * Build one streaming Messages API request body from a request spec — the
     * single place the optional per-request knobs (model, max_tokens,
     * temperature, system, cached_prefixes) are mapped onto the wire format,
     * shared by the single-call and batch paths. Every cached_prefixes entry is
     * an ordered reusable prompt layer and becomes a leading text content block
     * with `cache_control: {"type":"ephemeral"}`; the varying prompt is the
     * final unmarked text block. Blank layers are ignored. At most three
     * nonblank cache layers are accepted, keeping one of Anthropic's four
     * request breakpoints in reserve. With no effective cached_prefixes,
     * message content remains the original prompt string.
     * Every body carries the system preamble (the
     * respect-the-prompt-language rule from prompts/system-preamble.md) as its
     * system prompt, with any per-request system text appended after it.
     * Temperature is only sent when the caller
     * set one AND the target model still supports sampling parameters, so an
     * unset step keeps the API's default sampling and a step pointed at a
     * sampling-less model (Opus 4.7+, Fable) doesn't 400. Pure apart from the
     * cached preamble read — unit-testable.
     *
     * @param array{prompt:string,system?:string,model?:string,max_tokens?:int,temperature?:float,json_schema?:array{name:string,schema:array<string,mixed>},cached_prefixes?:list<string>} $req
     * @return array<string,mixed>
     */
    public static function bodyFor(array $req, string $defaultModel, int $defaultMaxTokens): array
    {
        $model = (string) ($req['model'] ?? $defaultModel);
        $cachedPrefixes = [];
        if (array_key_exists('cached_prefixes', $req)) {
            $providedPrefixes = $req['cached_prefixes'];
            if (!is_array($providedPrefixes) || !array_is_list($providedPrefixes)) {
                throw new \RuntimeException('cached_prefixes must be a list of strings');
            }
            foreach ($providedPrefixes as $index => $prefix) {
                if (!is_string($prefix)) {
                    throw new \RuntimeException("cached_prefixes[{$index}] must be a string");
                }
                if (trim($prefix) !== '') {
                    $cachedPrefixes[] = $prefix;
                }
            }
        }
        if (count($cachedPrefixes) > 3) {
            throw new \RuntimeException('Anthropic requests support at most three cached_prefixes');
        }
        $content = (string) $req['prompt'];
        if ($cachedPrefixes !== []) {
            $content = [];
            foreach ($cachedPrefixes as $prefix) {
                $content[] = [
                    'type' => 'text',
                    'text' => (string) $prefix,
                    'cache_control' => ['type' => 'ephemeral'],
                ];
            }
            $content[] = ['type' => 'text', 'text' => (string) $req['prompt']];
        }
        $body = [
            'model'      => $model,
            'max_tokens' => $req['max_tokens'] ?? $defaultMaxTokens,
            'stream'     => true,
            'messages'   => [
                ['role' => 'user', 'content' => $content],
            ],
        ];
        if (isset($req['temperature']) && self::supportsSampling($model)) {
            $body['temperature'] = (float) $req['temperature'];
        }
        if (self::thinksByDefault($model)) {
            $body['thinking'] = ['type' => 'disabled'];
        }
        if (isset($req['json_schema'])) {
            $spec = $req['json_schema'];
            if (!is_array($spec) || !is_array($spec['schema'] ?? null)) {
                throw new \InvalidArgumentException('json_schema must contain a schema array');
            }
            $body['output_config'] = [
                'format' => [
                    'type'   => 'json_schema',
                    'schema' => $spec['schema'],
                ],
            ];
        }
        $system = self::systemPreamble();
        if (trim((string) ($req['system'] ?? '')) !== '') {
            $system .= "\n\n" . $req['system'];
        }
        $body['system'] = $system;
        return $body;
    }

    /**
     * Whether a model still accepts the sampling parameters (temperature,
     * top_p, top_k). The API REMOVED them on Claude Opus 4.7/4.8 and the
     * whole Claude 5 family (Fable, Mythos, Opus 5, Sonnet 5) — sending one returns
     * HTTP 400 "`temperature` is deprecated for this model". A model this
     * misclassifies as supporting (e.g. a future family that also drops
     * sampling) is still handled: the 400 is detected via rejectedParam()
     * and the request retried without the parameter. Pure.
     */
    public static function supportsSampling(string $model): bool
    {
        return preg_match('/claude-(fable|mythos|opus-4-[78]|opus-5|sonnet-5)/', $model) !== 1;
    }

    /**
     * Models that run adaptive thinking when the request omits the `thinking`
     * parameter (Opus 5 does; Opus 4.8 and earlier do not). For these we send
     * an explicit `thinking: {type: "disabled"}` — the pipeline's latency and
     * token budgets were tuned on non-thinking models, and thinking tokens
     * also count against max_tokens. Valid only at effort <= high (we never
     * send effort, and the API default is high). Deliberately narrow: Fable 5
     * rejects an explicit "disabled" with a 400, so it must never match here.
     * Pure.
     */
    public static function thinksByDefault(string $model): bool
    {
        return preg_match('/claude-opus-5/', $model) === 1;
    }

    /**
     * Detect a recoverable API parameter rejection in an error payload (raw
     * response body or an exception message containing it) and name the
     * offending parameter, so the caller can strip it and retry instead of
     * aborting the build. Returns null for any other error. Pure.
     */
    public static function rejectedParam(string $error): ?string
    {
        if (preg_match('/`(temperature|top_p|top_k)` is (?:deprecated|not supported)/', $error, $m) === 1) {
            return $m[1];
        }
        // Deliberately recovery-biased: a false positive only strips caching
        // and retries uncached, which is safer than aborting the build.
        if (str_contains($error, 'cache_control')) {
            return 'cache_control';
        }
        // Same bias for the thinking control: `thinking: {type: "disabled"}` is
        // only valid at effort <= high, so a future request that raises effort
        // would 400 on every large-tier call. Stripping it runs the model with
        // its default adaptive thinking — a slower, more expensive request
        // rather than an aborted build.
        if (str_contains($error, 'thinking')) {
            return 'thinking';
        }
        return null;
    }

    /**
     * Classify recoverable parameter rejections from a complete HTTP response.
     * Only 400 responses qualify; callers must pass the full, untruncated body.
     * Pure.
     */
    public static function rejectedParamForHttpError(int $status, string $raw): ?string
    {
        return $status === 400 ? self::rejectedParam($raw) : null;
    }

    /**
     * Remove every occurrence of a rejected request key, including nested
     * content-block metadata such as cache_control. Returns whether anything
     * was removed, making a strip-and-retry deterministic and one-shot.
     *
     * @param array<string|int,mixed> $value
     */
    private static function stripRejectedParam(array &$value, string $param): bool
    {
        $removed = false;
        foreach ($value as $key => &$item) {
            if ((string) $key === $param) {
                unset($value[$key]);
                $removed = true;
            } elseif (is_array($item) && self::stripRejectedParam($item, $param)) {
                $removed = true;
            }
        }
        unset($item);
        return $removed;
    }

    /**
     * Drive a batched transport to completion, retrying ONLY the transient
     * failures with backoff. Pure orchestration (the transport does the I/O,
     * sleep() paces the rounds) so it is unit-testable with a fake transport and
     * zero delays. Unlike the image batch, a permanently failing request aborts
     * the whole batch — a missing section or theme would break the build, so we
     * fail loud rather than return a partial set.
     *
     * A request rejected for carrying a recoverable parameter (outcome carries
     * `retry_without`) is retried immediately with every occurrence stripped
     * from its body — it can't recur (the key is gone), so it doesn't consume a
     * transient-retry attempt. An outcome carrying `held` (the pool declined
     * to send the request after a sibling was rate-limited) is likewise
     * retried without consuming the budget: it says nothing about its own
     * request, and the really-attempted sibling's gate still bounds the batch.
     * $bodies is by-reference so the caller's post-batch logging reflects what
     * was actually sent.
     *
     * @param array<array-key,array<string,mixed>> $bodies request bodies keyed by id
     * @param callable(array<array-key,array<string,mixed>>):array<array-key,array{ok:bool,text?:string,input?:int,output?:int,cache_read_input_tokens?:int,cache_creation_input_tokens?:int,error?:string,transient?:bool,held?:bool,retry_without?:string,stop_reason?:?string}> $transport
     * @param array<int,int> $delays backoff seconds before each retry (length = max retries)
     * @param null|callable(string|int,string,float):void $onFailure called with (key, error, time) for a request that fails for good, just before the batch aborts — lets the caller log it
     * @return array<array-key,array{text:string,input:int,output:int,cache_read_input_tokens:int,cache_creation_input_tokens:int,time:float,stop_reason:?string}>
     */
    public static function retryTextBatch(array &$bodies, callable $transport, array $delays, ?callable $onFailure = null): array
    {
        $results = [];
        $pending = array_keys($bodies);
        /** @var array<array-key,int> $attempts transient retries consumed by each request */
        $attempts = array_fill_keys($pending, 0);
        $retryWave = 0;

        while ($pending !== []) {
            $transientRetry = [];
            $retryWaits = [];
            $immediate = $pending;

            // Complete every deterministic strip retry before delaying any
            // transient sibling. A stripped request that then fails transiently
            // joins the same deferred retry round and consumes no extra attempt.
            while ($immediate !== []) {
                $outcomes = $transport(array_intersect_key($bodies, array_flip($immediate)));
                $stripRetry = [];
                foreach ($outcomes as $key => $outcome) {
                    $dropParam = $outcome['retry_without'] ?? null;
                    if ($outcome['ok']) {
                        $results[$key] = [
                            'text'   => (string) ($outcome['text'] ?? ''),
                            'input'  => (int) ($outcome['input'] ?? 0),
                            'output' => (int) ($outcome['output'] ?? 0),
                            'cache_read_input_tokens' => (int) ($outcome['cache_read_input_tokens'] ?? 0),
                            'cache_creation_input_tokens' => (int) ($outcome['cache_creation_input_tokens'] ?? 0),
                            'time'   => (float) ($outcome['time'] ?? 0),
                            'stop_reason' => isset($outcome['stop_reason'])
                                ? (string) $outcome['stop_reason']
                                : null,
                        ];
                    } elseif ($dropParam !== null && self::stripRejectedParam($bodies[$key], $dropParam)) {
                        $stripRetry[] = $key;
                        Narrator::write("    (model rejected '{$dropParam}' on request '{$key}'; retrying without it)\n");
                    } elseif (($outcome['held'] ?? false) === true) {
                        // A held launch was never sent, so its outcome carries
                        // no information about the request — it must not burn
                        // or inherit another request's finite transient budget,
                        // and "launch held" must never be the error a batch
                        // aborts with. Bounded all the same: a hold only exists
                        // because a sibling was really attempted and
                        // rate-limited, and that sibling's own transient gate
                        // below still aborts the batch if the event outlasts
                        // its per-request budget.
                        $transientRetry[] = $key;
                    } elseif (
                        ($outcome['transient'] ?? false)
                        && ($attempts[$key] ?? 0) < count($delays)
                    ) {
                        $attempt = $attempts[$key] ?? 0;
                        $attempts[$key] = $attempt + 1;
                        $retryWaits[$key] = $delays[$attempt];
                        $transientRetry[] = $key;
                    } else {
                        $error = (string) ($outcome['error'] ?? 'unknown');
                        if ($onFailure !== null) {
                            $onFailure($key, $error, (float) ($outcome['time'] ?? 0));
                        }
                        throw new \RuntimeException("LLM batch request '{$key}' failed: {$error}");
                    }
                }
                $immediate = $stripRetry;
            }

            $pending = $transientRetry;
            if ($pending !== []) {
                // Wait long enough for every really-attempted transient in
                // this wave. A held-only wave waits zero: no request consumed
                // a retry or owns a backoff slot.
                $wait = $retryWaits === [] ? 0 : max($retryWaits);
                $retryWave++;
                Narrator::write('    (transient API error on ' . count($pending)
                    . " request(s); retry {$retryWave} in {$wait}s)\n");
                sleep($wait);
            }
        }

        return $results;
    }

    /**
     * Run a set of streaming Messages requests through the rolling pool — at
     * most MAX_CONCURRENCY in flight, the freed slot refilled the moment any
     * transfer completes — and classify each transfer. Pure transport — no
     * retry, no request counting, no throwing on a single failure (the
     * orchestrator decides). Bounding in-flight transfers keeps a wide fan-out
     * (every planned section at once) from tripping the API's rate limits, and
     * a 429 on any member holds all further launches for the round: the held
     * members come back transient, so retryTextBatch re-sends them after its
     * backoff instead of the pool firing the whole batch into a rate-limit
     * event as fast as the 429s bounce back.
     *
     * @param array<array-key,array<string,mixed>> $bodies request body keyed by id
     * @return array<array-key,array{ok:bool,text?:string,input?:int,output?:int,cache_read_input_tokens?:int,cache_creation_input_tokens?:int,error?:string,transient?:bool,held?:bool,retry_without?:string,stop_reason?:?string}>
     */
    private function streamMulti(array $bodies): array
    {
        if ($bodies === []) {
            return [];
        }
        $multi = curl_multi_init();
        /** @var array<array-key,\CurlHandle> $handles */
        $handles = [];
        /** @var array<int,string|int> $keysById spl_object_id(handle) => request key */
        $keysById = [];
        $raw = [];

        /** @var array<array-key,array<string,mixed>> $queuedOutcomes synthetic transient outcomes (refused adds, held launches) drained by $await before any I/O */
        $queuedOutcomes = [];
        // Once any member 429s, further launches this round are held: firing
        // the rest of the batch into a rate-limit event as fast as the 429s
        // bounce back only extends the penalty. Held members return transient
        // and retryTextBatch re-sends them after its backoff round.
        $holding = false;

        $start = function (string|int $key, array $body) use ($multi, &$handles, &$keysById, &$raw, &$queuedOutcomes, &$holding): void {
            if ($holding) {
                $queuedOutcomes[$key] = [
                    'ok' => false,
                    'transient' => true,
                    // Never sent: retryTextBatch retries held keys without
                    // charging its finite transient budget.
                    'held' => true,
                    'error' => 'launch held: a sibling request was rate-limited (HTTP 429)',
                ];
                return;
            }
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
            // A refused add would otherwise be a phantom in-flight transfer
            // that never produces a CURLMSG_DONE. Queue it as a transient
            // outcome instead; retryTextBatch re-sends it on a fresh stack.
            if (curl_multi_add_handle($multi, $ch) !== CURLM_OK) {
                curl_close($ch);
                unset($raw[$key]);
                $queuedOutcomes[$key] = [
                    'ok' => false,
                    'transient' => true,
                    'error' => 'curl_multi_add_handle refused the transfer',
                ];
                return;
            }
            $handles[$key] = $ch;
            $keysById[spl_object_id($ch)] = $key;
        };

        // Classify one finished transfer and release its handle (and slot).
        $finish = function (string|int $key, \CurlHandle $ch) use ($multi, &$handles, &$keysById, &$raw, &$holding): array {
            $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpStatus === 429) {
                $holding = true;
            }
            $outcome = self::interpretStream(
                $raw[$key],
                curl_errno($ch),
                curl_error($ch),
                $httpStatus,
                (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME),
            );
            unset($handles[$key], $keysById[spl_object_id($ch)], $raw[$key]);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
            return $outcome;
        };

        $await = function () use ($multi, &$handles, &$keysById, &$queuedOutcomes, $finish): array {
            if ($queuedOutcomes !== []) {
                $done = $queuedOutcomes;
                $queuedOutcomes = [];
                return $done;
            }
            // Drive the stack until at least one transfer finishes (see
            // WpcomImageClient::multiRequest for the -1 busy-spin guard).
            do {
                $status = curl_multi_exec($multi, $running);
                $done = [];
                while (($msg = curl_multi_info_read($multi)) !== false) {
                    if ($msg['msg'] !== CURLMSG_DONE) {
                        continue;
                    }
                    $key = $keysById[spl_object_id($msg['handle'])]
                        ?? throw new \RuntimeException('rolling pool got a completion for an unregistered curl handle');
                    $done[$key] = $finish($key, $msg['handle']);
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
            // interpretStream marks severed streams and never-responded
            // transfers (status 0) transient — rather than hanging the pool
            // or discarding sibling responses.
            $done = [];
            foreach ($handles as $key => $ch) {
                $done[$key] = $finish($key, $ch);
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
     * Split a batch into ordered windows of at most MAX_CONCURRENCY requests,
     * preserving keys. The Anthropic transport itself now rolls a single pool
     * (rollingPool above); this remains the shared chunking helper for the
     * OpenAI-compatible client's windowed transport. Pure — unit-testable.
     *
     * @param array<array-key,array<string,mixed>> $bodies request body keyed by id
     * @return array<int,array<array-key,array<string,mixed>>>
     */
    public static function concurrencyWindows(array $bodies, ?int $maxConcurrency = null): array
    {
        return array_chunk($bodies, max(1, $maxConcurrency ?? self::MAX_CONCURRENCY), true);
    }

    /**
     * Classify one completed streaming transfer into an outcome (never throws),
     * so the batch orchestrator can retry the transient ones. Same transient vs
     * permanent split as streamRequest(): connection-level cURL errors (incl. 6
     * "could not resolve host"), 429/5xx, and a transfer with no response at
     * all (status 0 — the rolling pool's CURLM-failure fallback classifies
     * handles that never received headers) are transient; a clean 4xx or any
     * other cURL error is permanent and aborts the build. Public for tests,
     * like parseSse. Pure — no I/O.
     *
     * @return array{ok:bool,text?:string,input?:int,output?:int,cache_read_input_tokens?:int,cache_creation_input_tokens?:int,time?:float,error?:string,transient?:bool,retry_without?:string,stop_reason?:?string}
     */
    public static function interpretStream(
        string $raw,
        int $errno,
        string $error,
        int $status,
        float $time = 0.0,
    ): array
    {
        if ($errno !== 0) {
            return ['ok' => false, 'transient' => self::isTransientCurl($errno), 'error' => "cURL ({$errno}): {$error}", 'time' => $time];
        }
        // Status 0 with no cURL error: the transfer stopped before any
        // response headers arrived (a CURLM-level failure or a rejected add).
        // Operational, not the request's fault — retry it.
        if ($status === 0) {
            return ['ok' => false, 'transient' => true, 'error' => 'no response received before the transfer stopped', 'time' => $time];
        }
        if ($status < 200 || $status >= 300) {
            $param = self::rejectedParamForHttpError($status, $raw);
            $out = ['ok' => false, 'transient' => self::isTransientStatus($status), 'error' => "HTTP {$status}: " . self::truncate($raw), 'time' => $time];
            // A rejected recoverable parameter is stripped from the body before
            // the orchestrator retries (see retryTextBatch).
            if ($param !== null) {
                $out['retry_without'] = $param;
            }
            return $out;
        }

        $parsed = self::parseSse($raw);
        if ($parsed['error'] !== null) {
            $transient = in_array($parsed['error_type'], ['overloaded_error', 'api_error'], true);
            return ['ok' => false, 'transient' => $transient, 'error' => "stream error: {$parsed['error']}", 'time' => $time];
        }
        // Every successful Messages stream ends with a message_delta carrying
        // stop_reason. A 200 stream without one was severed mid-response (a
        // dropped connection can cut every multiplexed stream at once with no
        // cURL error): the partial text must not be mistaken for a completion
        // and salvaged downstream — retry the member instead.
        if ($parsed['stop_reason'] === null) {
            return [
                'ok' => false,
                'transient' => true,
                'error' => 'stream severed before completion (no stop_reason received)',
                'time' => $time,
            ];
        }
        // Preserve recognized abnormal terminal responses (including empty
        // refusals and zero-token truncations) for the batch recovery layer.
        // An ordinary successful empty response remains transient.
        if (trim($parsed['text']) === ''
            && JsonBatchRecovery::terminationError($parsed['stop_reason']) === null
        ) {
            return ['ok' => false, 'transient' => true, 'error' => 'no text content in streamed response', 'time' => $time];
        }
        return [
            'ok' => true,
            'text' => $parsed['text'],
            'input' => $parsed['input'],
            'output' => $parsed['output'],
            'cache_read_input_tokens' => $parsed['cache_read_input_tokens'],
            'cache_creation_input_tokens' => $parsed['cache_creation_input_tokens'],
            'time' => $time,
            'stop_reason' => $parsed['stop_reason'],
        ];
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
     * 400 for a recoverable parameter the model rejects is retried immediately
     * with that parameter stripped (can't recur — the key is gone). $body is
     * by-reference so the caller logs what was actually sent.
     *
     * @param array<string,mixed> $body
     * @return array{text:string,input:int,output:int,cache_read_input_tokens:int,cache_creation_input_tokens:int,time:float,stop_reason:?string}
     */
    private function requestWithRetry(array &$body, bool $tolerateEmpty = false): array
    {
        return self::retrySingleRequest(
            $body,
            fn (array $requestBody): array => $this->streamRequest($requestBody),
            [2, 5, 12],
            $tolerateEmpty,
        );
    }

    /**
     * Drive one request to completion, retrying transient failures with the
     * supplied backoff. A successful empty response is transient by default;
     * tolerate_empty converts it to an immediate empty-string success.
     *
     * @param array<string,mixed> $body
     * @param callable(array<string,mixed>):array{text:string,input:int,output:int,cache_read_input_tokens:int,cache_creation_input_tokens:int,time:float} $transport
     * @param list<int> $delays
     * @return array{text:string,input:int,output:int,cache_read_input_tokens:int,cache_creation_input_tokens:int,time:float}
     */
    public static function retrySingleRequest(
        array &$body,
        callable $transport,
        array $delays,
        bool $tolerateEmpty = false,
    ): array
    {
        $attempt = 0;
        while (true) {
            try {
                $result = $transport($body);
                if (trim($result['text']) === '') {
                    if (!$tolerateEmpty) {
                        throw new TransientApiException('no text content in streamed response');
                    }
                    $result['text'] = '';
                }
                return $result;
            } catch (RejectedApiParameterException $e) {
                $param = $e->parameter;
                if (!self::stripRejectedParam($body, $param)) {
                    throw $e;
                }
                Narrator::write("    (model rejected '{$param}'; retrying without it)\n");
            } catch (TransientApiException $e) {
                if ($attempt >= count($delays)) {
                    throw new \RuntimeException('Anthropic API failed after retries: ' . $e->getMessage(), 0, $e);
                }
                $wait = $delays[$attempt];
                $attempt++;
                Narrator::write("    (transient API error: {$e->getMessage()}; retry {$attempt} in {$wait}s)\n");
                sleep($wait);
            }
        }
    }

    /**
     * POST a streaming Messages request and assemble the text + token usage from
     * the SSE event stream.
     *
     * @param array<string,mixed> $body
     * @return array{text:string,input:int,output:int,cache_read_input_tokens:int,cache_creation_input_tokens:int,time:float,stop_reason:?string}
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
            throw new \RuntimeException("cURL error ({$errno}): {$error}");
        }
        if ($status < 200 || $status >= 300) {
            if (self::isTransientStatus($status)) {
                throw new TransientApiException("HTTP {$status}: " . self::truncate($raw));
            }
            $rejectedParam = self::rejectedParamForHttpError($status, $raw);
            $message = "Anthropic API HTTP {$status}: " . self::truncate($raw);
            if ($rejectedParam !== null) {
                throw new RejectedApiParameterException($message, $rejectedParam);
            }
            throw new \RuntimeException($message);
        }

        $parsed = self::parseSse($raw);
        if ($parsed['error'] !== null) {
            if (in_array($parsed['error_type'], ['overloaded_error', 'api_error'], true)) {
                throw new TransientApiException("stream error: {$parsed['error']}");
            }
            throw new \RuntimeException("stream error: {$parsed['error']}");
        }
        // Every successful Messages stream ends with a message_delta carrying
        // stop_reason; a 200 stream without one was severed mid-response.
        // Retry rather than return the partial text as a completion.
        if ($parsed['stop_reason'] === null) {
            throw new TransientApiException('stream severed before completion (no stop_reason received)');
        }
        // Empty-text handling lives in retrySingleRequest so tolerate_empty
        // (cache-warm probes) can accept a blank one-token reply without a
        // transient retry loop.
        return [
            'text' => $parsed['text'],
            'input' => $parsed['input'],
            'output' => $parsed['output'],
            'cache_read_input_tokens' => $parsed['cache_read_input_tokens'],
            'cache_creation_input_tokens' => $parsed['cache_creation_input_tokens'],
            'time' => $time,
            'stop_reason' => $parsed['stop_reason'],
        ];
    }

    /**
     * Parse an assembled Server-Sent Events body from the Messages API into the
     * concatenated text and token usage. Pure (no I/O) so it can be unit-tested.
     *
     * @return array{text:string,input:int,output:int,cache_read_input_tokens:int,cache_creation_input_tokens:int,error:?string,error_type:string,stop_reason:?string}
     */
    public static function parseSse(string $raw): array
    {
        $text = '';
        $input = 0;
        $output = 0;
        $cacheRead = 0;
        $cacheCreation = 0;
        $error = null;
        $errorType = '';
        $stopReason = null;

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
                    $cacheRead = $u['cache_read_input_tokens'];
                    $cacheCreation = $u['cache_creation_input_tokens'];
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
                    if (isset($evt['delta']['stop_reason'])) {
                        $stopReason = (string) $evt['delta']['stop_reason'];
                    }
                    break;
                case 'error':
                    $error = $evt['error']['message'] ?? 'stream error';
                    $errorType = $evt['error']['type'] ?? '';
                    break;
            }
        }

        return [
            'text' => $text,
            'input' => $input,
            'output' => $output,
            'cache_read_input_tokens' => $cacheRead,
            'cache_creation_input_tokens' => $cacheCreation,
            'error' => $error,
            'error_type' => $errorType,
            'stop_reason' => $stopReason,
        ];
    }

    private static function truncate(string $s, int $max = 300): string
    {
        return strlen($s) > $max ? substr($s, 0, $max) . '…' : $s;
    }

    /**
     * Provider-neutral decoder entry point retained on the concrete client for
     * callers that need defensive parsing without making a request.
     *
     * @return array<mixed>|null
     */
    public static function decodeJson(string $text): ?array
    {
        return JsonDecoder::decode($text);
    }

    /** @return array{data:?array,error:?string} */
    public static function decodeJsonResult(string $text): array
    {
        return JsonDecoder::decodeResult($text);
    }
}
