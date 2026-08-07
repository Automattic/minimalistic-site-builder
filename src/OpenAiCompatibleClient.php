<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * OpenAI Chat Completions API client (generic OpenAI-compatible HTTP transport).
 *
 * Zero dependencies: plain cURL POST to `{baseUrl}/chat/completions` with
 * Bearer auth. Same Llm contract as AnthropicClient — streaming, concurrent
 * batches, JSON steer, system preamble — so the pipeline can swap providers
 * without step changes.
 *
 * Works with any OpenAI-compatible host. Defaults target OpenAI; for xAI set
 * baseUrl to https://api.x.ai/v1 (see make_llm() LLM_PROVIDER=xai).
 */
final class OpenAiCompatibleClient implements FinishReasonAwareLlm, UsageReporting
{
    /** K3's default max-effort reasoning shares this budget with its answer. */
    private const KIMI_K3_MIN_MAX_TOKENS = 65536;

    /**
     * Ceiling on an honored Retry-After wait, in seconds.
     *
     * Every other wait in this transport is bounded (the curl timeout, the
     * low-speed abort, the fixed retry slots); an unclamped Retry-After is not.
     * A quota 429 answering `Retry-After: 3600` — or an absolute-date header
     * read against a skewed clock — would otherwise park a build for hours
     * behind one STDERR line, and the demo runner never times its children out.
     * Past the cap the request takes the ordinary transient path and fails in
     * seconds, which is the actionable outcome for a quota error.
     */
    private const MAX_RETRY_AFTER_WAIT = 120;

    /**
     * Appended to the system prompt of every JSON call (single and batch) to
     * steer the model toward raw, fence-free JSON. Kept in sync with the
     * Anthropic client so both provider paths receive the same instruction.
     */
    private const JSON_SYSTEM = "\nRespond with a single valid JSON value and nothing else. "
        . 'No prose, no markdown fences.';

    private int $requests = 0;
    private int $inputTokens = 0;
    private int $outputTokens = 0;
    private string $endpoint;
    private ?string $lastFinishReason = null;
    private ?\Closure $singleTransport;

    /**
     * @param string $apiKey            Bearer token (OPENAI_API_KEY / XAI_API_KEY)
     * @param string $model             Default model when a request does not pin one
     * @param string $baseUrl           API root, e.g. https://api.x.ai/v1 (no trailing slash required)
     * @param int    $defaultMaxTokens  max_tokens default for completions
     * @param string $provider          Selects provider-specific request quirks
     *                                   (token-limit key, temperature support):
     *                                   'openai', 'xai', or 'openrouter'.
     *                                   See maxTokensParam().
     * @param int    $timeoutSeconds    Hard timeout for one streamed request
     * @param int    $maxConcurrency    Most simultaneous requests in one batch
     * @param ?callable $singleTransport Optional single-request transport seam for tests
     */
    public function __construct(
        private string $apiKey,
        private string $model,
        string $baseUrl = 'https://api.openai.com/v1',
        private int $defaultMaxTokens = 16000,
        private string $provider = 'openai',
        private int $timeoutSeconds = 600,
        private int $maxConcurrency = 10,
        ?callable $singleTransport = null,
    ) {
        $this->endpoint = rtrim($baseUrl, '/') . '/chat/completions';
        $this->singleTransport = $singleTransport === null
            ? null
            : \Closure::fromCallable($singleTransport);
    }

    /** Resolved chat-completions URL (for tests / diagnostics). */
    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function lastFinishReason(): ?string
    {
        return $this->lastFinishReason;
    }

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
     * Pull token counts from an OpenAI-style usage object (or a full response
     * that nests one under "usage"). Accepts both OpenAI names (prompt_tokens /
     * completion_tokens) and Anthropic-style aliases some proxies emit.
     *
     * @param array<string,mixed> $response
     * @return array{input:int,output:int}
     */
    public static function extractUsage(array $response): array
    {
        $u = isset($response['usage']) && is_array($response['usage'])
            ? $response['usage']
            : $response;
        $input = (int) ($u['prompt_tokens'] ?? $u['input_tokens'] ?? 0)
            + (int) ($u['prompt_cache_hit_tokens'] ?? 0);
        $output = (int) ($u['completion_tokens'] ?? $u['output_tokens'] ?? 0);
        return ['input' => $input, 'output' => $output];
    }

    /**
     * @param array{system?:string,model?:string,max_tokens?:int,temperature?:float,cached_prefixes?:list<string>,tolerate_empty?:bool,log_label?:string} $opts
     *        tolerate_empty accepts a successful cache-warm probe even when
     *        its one-token budget produces a truncation stop reason.
     */
    public function complete(string $prompt, array $opts = []): string
    {
        $this->lastFinishReason = null;
        $body = self::bodyFor(['prompt' => $prompt] + $opts, $this->model, $this->defaultMaxTokens, $this->provider);

        $label = (string) ($opts['log_label'] ?? 'request');
        $tolerateEmpty = ($opts['tolerate_empty'] ?? false) === true;
        try {
            $res = $this->requestWithRetry($body, $tolerateEmpty);
        } catch (\Throwable $e) {
            LlmLogger::log($label, $body, ['text' => '', 'input' => 0, 'output' => 0], 0.0, $e->getMessage());
            throw $e;
        }

        $this->requests++;
        $this->inputTokens += $res['input'];
        $this->outputTokens += $res['output'];

        LlmLogger::log($label, $body, $res, $res['time']);

        // Unreachable in practice: retrySingleRequest already converts an
        // empty non-tolerated response into a transient failure. Kept as a
        // final guard so a transport change can't silently return ''.
        if (!$tolerateEmpty && trim($res['text']) === '') {
            throw new \RuntimeException('No text content in streamed response');
        }
        $stopReason = $res['stop_reason'] ?? null;
        $this->lastFinishReason = is_string($stopReason) && trim($stopReason) !== ''
            ? trim($stopReason)
            : null;
        return $res['text'];
    }

    public function completeJson(string $prompt, array $opts = []): array
    {
        $result = $this->completeJsonBatch(['request' => ['prompt' => $prompt] + $opts]);
        return $result['request'];
    }

    public function completeJsonBatch(array $requests): array
    {
        $requests = $this->withEffectiveMaxTokens($requests);
        return JsonBatchRecovery::run(
            $requests,
            fn (array $subset): array => $this->responseBatch($subset, true),
            defaultMaxTokens: $this->defaultMaxTokens,
        );
    }

    /**
     * Make Kimi K3's implicit first-attempt budget explicit so either recovery
     * layer can double the real value after truncation. Other providers retain
     * their existing implicit-budget behavior.
     *
     * @param array<array-key,array<string,mixed>> $requests
     * @return array<array-key,array<string,mixed>>
     */
    private function withEffectiveMaxTokens(array $requests): array
    {
        foreach ($requests as $key => $request) {
            $model = (string) ($request['model'] ?? $this->model);
            if (isset($request['max_tokens']) || !self::isKimiK3($this->provider, $model)) {
                continue;
            }
            $requests[$key]['max_tokens'] = self::effectiveMaxTokens(
                $request,
                $model,
                $this->defaultMaxTokens,
                $this->provider,
            );
        }
        return $requests;
    }

    public function completeBatch(array $requests): TextBatchResult
    {
        $requests = $this->withEffectiveMaxTokens($requests);
        return TextBatchRecovery::run(
            $requests,
            fn (array $subset): array => $this->responseBatch($subset, false),
            defaultMaxTokens: $this->defaultMaxTokens,
        );
    }

    /**
     * Shared concurrent-batch transport for completeJsonBatch and completeBatch.
     *
     * @param array<array-key,array<string,mixed>> $requests
     * @param null|callable(array<array-key,array<string,mixed>>):array<array-key,array<string,mixed>> $transport
     *        Test seam; defaults to the live concurrent transport.
     * @return array<array-key,array<string,mixed>>
     */
    private function responseBatch(array $requests, bool $json, ?callable $transport = null): array
    {
        if ($requests === []) {
            return [];
        }

        $bodies = [];
        foreach ($requests as $key => $req) {
            $system = (string) ($req['system'] ?? '');
            if ($json) {
                $system .= self::JSON_SYSTEM;
            }
            $bodies[$key] = self::bodyFor(
                ['system' => $system] + $req,
                $this->model,
                $this->defaultMaxTokens,
                $this->provider,
                $json,
            );
        }

        // Keys may be ints too (PHP coerces numeric keys), so admit both.
        $labelFor = fn (string|int $key): string => (string) ($requests[$key]['log_label'] ?? $key);

        $transport ??= fn (array $subset): array => $this->streamMulti($subset);
        $onFailure = function (string|int $key, string $error, float $time) use ($labelFor, &$bodies): void {
            LlmLogger::log($labelFor($key), $bodies[$key], ['text' => '', 'input' => 0, 'output' => 0], $time, $error);
        };
        $logPaths = [];
        $onSuccess = function (string|int $key, array $res) use ($labelFor, &$bodies, &$logPaths): void {
            $this->requests++;
            $this->inputTokens += $res['input'];
            $this->outputTokens += $res['output'];
            $logPaths[$key] = LlmLogger::log($labelFor($key), $bodies[$key], $res, $res['time']);
        };
        $delays = [2, 5, 12];
        $results = $this->provider === 'openrouter'
            ? self::retryOpenRouterBatch($bodies, $transport, $delays, $onFailure, onSuccess: $onSuccess)
            : AnthropicClient::retryTextBatch($bodies, $transport, $delays, $onFailure, onSuccess: $onSuccess);

        $out = [];
        foreach ($results as $key => $res) {
            $res['log_path'] = $logPaths[$key] ?? null;
            $res['model'] = (string) $bodies[$key]['model'];
            $out[$key] = $res;
        }
        return $out;
    }

    /**
     * Build one streaming Chat Completions request body. System text (preamble
     * + optional per-request system) becomes a system message. Reusable cached
     * prefixes are joined ahead of the varying user prompt as plain text;
     * OpenAI-compatible providers apply automatic server-side prefix caching,
     * so no cache_control markers are sent. stream_options.include_usage asks
     * the provider to attach token usage on the final SSE chunk.
     *
     * The token-limit key and whether a custom temperature is sent both depend
     * on $provider + model: OpenAI's o-series / gpt-5+ reasoning models want
     * max_completion_tokens and only accept the default temperature. See
     * maxTokensParam() and restrictsTemperature().
     *
     * @param array{prompt:string,system?:string,model?:string,max_tokens?:int,temperature?:float,json_schema?:array{name:string,schema:array<string,mixed>},cached_prefixes?:list<string>} $req
     * @param bool $json Whether this is a JSON completion. OpenRouter generic
     *                   JSON calls request JSON-object mode when no schema was
     *                   supplied; existing providers keep their prior shape.
     * @return array<string,mixed>
     */
    public static function bodyFor(
        array $req,
        string $defaultModel,
        int $defaultMaxTokens,
        string $provider = 'openai',
        bool $json = false,
    ): array
    {
        $model = (string) ($req['model'] ?? $defaultModel);
        $system = AnthropicClient::systemPreamble();
        if (trim((string) ($req['system'] ?? '')) !== '') {
            $system .= "\n\n" . $req['system'];
        }

        $userPrompt = '';
        foreach ($req['cached_prefixes'] ?? [] as $prefix) {
            if (trim($prefix) !== '') {
                $userPrompt .= rtrim($prefix, "\r\n") . "\n\n";
            }
        }
        $userPrompt .= (string) $req['prompt'];

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $userPrompt],
        ];

        $maxTokens = self::effectiveMaxTokens($req, $model, $defaultMaxTokens, $provider);
        $body = [
            'model'      => $model,
            'stream'     => true,
            // So the final SSE chunk carries usage (OpenAI + most compat hosts).
            'stream_options' => ['include_usage' => true],
            'messages'   => $messages,
        ] + self::maxTokensParam($provider, $model, $maxTokens);

        // K2.5 is a hybrid model whose optional reasoning defaults on at
        // OpenRouter. Builder calls need direct JSON/code/markup, not thousands
        // of hidden thinking tokens, so use its fast non-thinking mode.
        if (self::isKimiK25($provider, $model)) {
            $body['reasoning'] = ['enabled' => false];
        }

        // OpenAI reasoning models reject a non-default temperature. Keep Kimi
        // K3 on its provider sampling default too: this profile is tuned
        // around K3's default max-effort reasoning behavior.
        if (isset($req['temperature']) && !self::restrictsTemperature($provider, $model)) {
            $body['temperature'] = (float) $req['temperature'];
        }
        if (isset($req['json_schema'])) {
            $spec = $req['json_schema'];
            if (!is_array($spec) || trim((string) ($spec['name'] ?? '')) === ''
                || !is_array($spec['schema'] ?? null)
            ) {
                throw new \InvalidArgumentException('json_schema must contain a name and schema array');
            }
            $body['response_format'] = [
                'type'        => 'json_schema',
                'json_schema' => [
                    'name'   => (string) $spec['name'],
                    'strict' => true,
                    'schema' => $spec['schema'],
                ],
            ];
        } elseif ($json && $provider === 'openrouter') {
            $body['response_format'] = ['type' => 'json_object'];
        }
        return $body;
    }

    /** @param array<string,mixed> $req */
    private static function effectiveMaxTokens(
        array $req,
        string $model,
        int $defaultMaxTokens,
        string $provider,
    ): int {
        $maxTokens = (int) ($req['max_tokens'] ?? $defaultMaxTokens);
        if (!isset($req['max_tokens']) && self::isKimiK3($provider, $model)) {
            $maxTokens = max($maxTokens, self::KIMI_K3_MIN_MAX_TOKENS);
        }
        return $maxTokens;
    }

    /**
     * Correct token-limit key for a provider + model.
     *
     * OpenAI's o-series and gpt-5+ (reasoning) models and all xAI models want
     * `max_completion_tokens`; legacy OpenAI (gpt-3*, gpt-4*) and everything
     * else use `max_tokens`. Ported from telex's AiClientFactory::maxTokensParam.
     *
     * @return array{max_tokens?:int,max_completion_tokens?:int}
     */
    public static function maxTokensParam(string $provider, string $model, int $value): array
    {
        $needsMaxCompletionTokens = match ($provider) {
            'xai'    => true,
            'openai' => preg_match('/^gpt-[34]/', $model) !== 1,
            default  => false,
        };

        return $needsMaxCompletionTokens
            ? ['max_completion_tokens' => $value]
            : ['max_tokens' => $value];
    }

    /**
     * Whether $provider + $model should stay at its default sampling
     * temperature. OpenAI reasoning models (o-series / gpt-5+) require that;
     * the OpenRouter Kimi K3 profile deliberately keeps its provider default
     * alongside default max-effort reasoning. xAI (Grok) accepts arbitrary
     * temperatures.
     */
    public static function restrictsTemperature(string $provider, string $model): bool
    {
        return ($provider === 'openai' && preg_match('/^gpt-[34]/', $model) !== 1)
            || self::isKimiK3($provider, $model);
    }

    private static function isKimiK3(string $provider, string $model): bool
    {
        $model = self::withoutRoutingVariant($model);
        return $provider === 'openrouter'
            && preg_match('~^moonshotai/kimi-k3(?:-\d{4,})?$~', $model) === 1;
    }

    private static function isKimiK25(string $provider, string $model): bool
    {
        $model = self::withoutRoutingVariant($model);
        return $provider === 'openrouter'
            && preg_match('~^moonshotai/kimi-k2\.5(?:-\d{4,})?$~', $model) === 1;
    }

    private static function withoutRoutingVariant(string $model): string
    {
        return preg_replace('/:[^:]+$/', '', $model) ?? $model;
    }

    /**
     * Reuse the shared batch retry policy while extending only OpenRouter's
     * waits when a response supplied Retry-After. Deadlines are captured when
     * the header arrives, so time spent completing concurrent siblings and the
     * shared helper's normal backoff already counts toward the server's wait.
     *
     * @param array<array-key,array<string,mixed>> $bodies
     * @param callable(array<array-key,array<string,mixed>>):array<array-key,array<string,mixed>> $transport
     * @param list<int> $delays
     * @param null|callable(string|int,string,float):void $onFailure
     * @param null|callable(int):void $sleeper Test seam for the remaining
     *        Retry-After wait and the shared helper's backoff waits
     * @param null|callable():int $clock Test seam returning the current epoch
     * @param null|callable(string|int,array<string,mixed>):void $onSuccess
     * @return array<array-key,array<string,mixed>>
     */
    public static function retryOpenRouterBatch(
        array &$bodies,
        callable $transport,
        array $delays,
        ?callable $onFailure = null,
        ?callable $sleeper = null,
        ?callable $clock = null,
        ?callable $onSuccess = null,
    ): array {
        /** @var array<array-key,int> $retryAfterDeadlineByKey */
        $retryAfterDeadlineByKey = [];
        $clock ??= static fn (): int => time();
        $sleeper ??= static function (int $seconds): void {
            sleep($seconds);
        };

        $wrappedTransport = function (array $subset) use (
            $transport,
            $sleeper,
            $clock,
            &$retryAfterDeadlineByKey,
        ): array {
            $retryingDeadlines = array_intersect_key($retryAfterDeadlineByKey, $subset);
            if ($retryingDeadlines !== []) {
                $deadline = max($retryingDeadlines);
                $remainingWait = min(
                    self::MAX_RETRY_AFTER_WAIT,
                    max(0, $deadline - $clock()),
                );
                if ($remainingWait > 0) {
                    Narrator::write("    (OpenRouter Retry-After still requires {$remainingWait}s after normal backoff)\n");
                    $sleeper($remainingWait);
                }
                foreach (array_keys($retryingDeadlines) as $key) {
                    unset($retryAfterDeadlineByKey[$key]);
                }
            }

            $outcomes = $transport($subset);
            foreach ($outcomes as $key => $outcome) {
                if (
                    ($outcome['transient'] ?? false)
                    && !isset($outcome['retry_without'])
                    && isset($outcome['retry_after_at'])
                ) {
                    $retryAfterDeadlineByKey[$key] = max(0, (int) $outcome['retry_after_at']);
                }
            }
            return $outcomes;
        };

        return AnthropicClient::retryTextBatch(
            $bodies,
            $wrappedTransport,
            $delays,
            $onFailure,
            $sleeper,
            $onSuccess,
        );
    }

    private static function captureRetryAfterHeader(
        string $line,
        ?int &$deadline,
        ?int $now = null,
    ): int
    {
        $header = trim($line);
        if (preg_match('/^Retry-After\s*:\s*(.+)$/i', $header, $matches) === 1) {
            $deadline = self::retryAfterDeadline(trim($matches[1]), $now ?? time());
        }
        return strlen($line);
    }

    /**
     * Parse HTTP Retry-After as either delay-seconds or an absolute HTTP date.
     */
    private static function retryAfterDeadline(?string $value, int $now): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d+$/', $value) === 1) {
            return $now + max(0, (int) $value);
        }

        $at = strtotime($value);
        if ($at === false) {
            return null;
        }
        return max($now, $at);
    }

    /**
     * Run a set of streaming Chat Completions requests through the shared
     * curl_multi rolling pool — at most maxConcurrency in flight, the freed
     * slot refilled the moment any transfer completes — and classify each
     * transfer via interpretStream. Previously windowed (a slow member
     * stalled every request behind its window barrier); the rolling pool also
     * brings the 429 launch-hold and refused-add handling (see CurlMultiPool)
     * that the pooled clients already had, whose transient/held outcomes the
     * batch retry layer re-sends after its backoff.
     *
     * @param array<array-key,array<string,mixed>> $bodies
     * @return array<array-key,array{ok:bool,text?:string,input?:int,output?:int,error?:string,transient?:bool,held?:bool,retry_without?:string,retry_after_at?:int,time?:float,stop_reason?:?string}>
     */
    private function streamMulti(array $bodies): array
    {
        $raw = [];
        $retryAfterDeadlines = [];

        $buildHandle = function (string|int $key, array $body) use (&$raw, &$retryAfterDeadlines): \CurlHandle {
            $raw[$key] = '';
            $ch = curl_init($this->endpoint);
            $options = [
                CURLOPT_POST          => true,
                CURLOPT_HTTPHEADER    => $this->headers(),
                CURLOPT_POSTFIELDS    => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                CURLOPT_TIMEOUT       => $this->timeoutSeconds,
                CURLOPT_LOW_SPEED_LIMIT => 1,
                CURLOPT_LOW_SPEED_TIME  => 90,
                CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$raw, $key) {
                    $raw[$key] .= $chunk;
                    return strlen($chunk);
                },
            ];
            if ($this->provider === 'openrouter') {
                $retryAfterDeadlines[$key] = null;
                $options[CURLOPT_HEADERFUNCTION] = static function ($ch, string $line) use (
                    &$retryAfterDeadlines,
                    $key,
                ): int {
                    return self::captureRetryAfterHeader($line, $retryAfterDeadlines[$key]);
                };
            }
            curl_setopt_array($ch, $options);
            return $ch;
        };

        $classify = function (string|int $key, \CurlHandle $ch, int $httpStatus) use (&$raw, &$retryAfterDeadlines): array {
            $outcome = self::interpretStream(
                $raw[$key],
                curl_errno($ch),
                curl_error($ch),
                $httpStatus,
                (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME),
                true,
                $this->provider,
            );
            if (
                $this->provider === 'openrouter'
                && !($outcome['ok'] ?? false)
                && ($outcome['transient'] ?? false)
            ) {
                $retryAfterDeadline = $retryAfterDeadlines[$key] ?? null;
                if ($retryAfterDeadline !== null) {
                    $outcome['retry_after_at'] = $retryAfterDeadline;
                }
            }
            unset($raw[$key], $retryAfterDeadlines[$key]);
            return $outcome;
        };

        return (new CurlMultiPool())->run($bodies, $buildHandle, $classify, $this->maxConcurrency);
    }

    /**
     * @return array{ok:bool,text?:string,input?:int,output?:int,time?:float,error?:string,transient?:bool,retry_without?:string,stop_reason?:?string}
     */
    private static function interpretStream(
        string $raw,
        int $errno,
        string $error,
        int $status,
        float $time = 0.0,
        bool $preserveAbnormalTerminal = true,
        string $provider = 'openai',
    ): array
    {
        if ($errno !== 0) {
            return ['ok' => false, 'transient' => self::isTransientCurl($errno), 'error' => "cURL ({$errno}): {$error}", 'time' => $time];
        }
        // Status 0 with no cURL error: the transfer stopped before any
        // response headers arrived (a CURLM-level failure). Operational, not
        // the request's fault — retry it. Mirrors AnthropicClient.
        if ($status === 0) {
            return ['ok' => false, 'transient' => true, 'error' => 'no response received before the transfer stopped', 'time' => $time];
        }
        $parsed = null;
        $typedTerminalReason = null;
        if ($provider === 'openrouter') {
            $parsed = self::parseSse($raw, $provider);
            $typedTerminalReason = self::typedTerminalStopReason($parsed);
        }
        if (($status < 200 || $status >= 300) && $typedTerminalReason === null) {
            $out = [
                'ok' => false,
                'transient' => self::isTransientStatus($status, $provider),
                'error' => "HTTP {$status}: " . self::truncate($raw),
                'time' => $time,
            ];
            if (($param = self::rejectedParam($raw)) !== null) {
                $out['retry_without'] = $param;
            }
            return $out;
        }

        $parsed ??= self::parseSse($raw, $provider);
        if ($typedTerminalReason !== null) {
            // OpenRouter reports some semantic completion states inside an SSE
            // error envelope after the HTTP 200 stream has started. They are
            // not transport failures: preserve any partial text and translate
            // them to the provider-generic stop reasons consumed by the batch
            // recovery layers below.
            $parsed['stop_reason'] = $typedTerminalReason;
        } elseif ($parsed['error'] !== null) {
            $transient = $provider === 'openrouter'
                ? self::isTransientStreamError($parsed)
                : self::isLegacyTransientStreamError($parsed);
            return ['ok' => false, 'transient' => $transient, 'error' => "stream error: {$parsed['error']}", 'time' => $time];
        }
        $terminationError = StopReasons::terminationError($parsed['stop_reason']);
        // Batch callers preserve abnormal terminal responses so
        // TextBatchRecovery / JsonBatchRecovery can selectively regenerate
        // only the affected member. Keep the explicit false mode for callers
        // that need the legacy immediate rejection policy.
        if (!$preserveAbnormalTerminal && StopReasons::isTruncation($parsed['stop_reason'])) {
            return [
                'ok' => false,
                'transient' => false,
                'error' => "stream error: {$terminationError}",
                'time' => $time,
            ];
        }
        // Preserve recognized abnormal terminal responses (including empty
        // refusals and zero-token truncations) for the batch recovery layer.
        // An ordinary successful empty response remains transient.
        if (trim($parsed['text']) === ''
            && $terminationError === null
        ) {
            return ['ok' => false, 'transient' => true, 'error' => 'no text content in streamed response', 'time' => $time];
        }
        return [
            'ok'          => true,
            'text'        => $parsed['text'],
            'input'       => $parsed['input'],
            'output'      => $parsed['output'],
            'time'        => $time,
            'stop_reason' => $parsed['stop_reason'],
        ];
    }

    private static function isTransientCurl(int $errno): bool
    {
        return in_array($errno, TransientApiException::TRANSIENT_CURL_ERRNOS, true);
    }

    private static function isTransientStatus(int $status, string $provider = 'openai'): bool
    {
        return $status === 429
            || $status >= 500
            || ($provider === 'openrouter' && $status === 408);
    }

    /**
     * Classify an error delivered inside an otherwise-successful HTTP stream.
     * OpenRouter can emit provider failures (including a numeric 5xx code) as
     * an SSE error object after the HTTP 200 headers have already been sent.
     * Preserve and use that structured data instead of relying only on English
     * message fragments.
     *
     * @param array{error:?string,error_code:int|string|null,error_type:string} $parsed
     */
    public static function isTransientStreamError(array $parsed): bool
    {
        $code = $parsed['error_code'] ?? null;
        if (is_numeric($code)) {
            $status = (int) $code;
            if (self::isTransientStatus($status, 'openrouter')) {
                return true;
            }
        }

        $haystack = strtolower(implode(' ', [
            (string) ($parsed['error_type'] ?? ''),
            (string) ($parsed['error'] ?? ''),
        ]));
        foreach ([
            'overload', 'rate limit', 'rate_limit', 'timeout', 'timed out',
            'api_error', 'server_error', 'provider_unavailable',
            'provider unavailable', 'service unavailable',
        ] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Preserve the pre-OpenRouter stream retry policy for existing providers.
     *
     * @param array{error:?string} $parsed
     */
    private static function isLegacyTransientStreamError(array $parsed): bool
    {
        $message = strtolower((string) ($parsed['error'] ?? ''));
        return str_contains($message, 'overloaded')
            || str_contains($message, 'rate limit');
    }

    /**
     * Translate OpenRouter's typed terminal SSE errors into the stop-reason
     * vocabulary shared by JsonBatchRecovery and TextBatchRecovery.
     *
     * These error types describe how generation ended after streaming began,
     * so callers must retain the text received before the terminal event.
     * Context-window and account/token-credit errors deliberately remain real
     * failures: increasing an output budget cannot repair either condition.
     *
     * @param array{error:?string,error_type:string} $parsed
     */
    private static function typedTerminalStopReason(array $parsed): ?string
    {
        if ($parsed['error'] === null) {
            return null;
        }

        return match (strtolower(trim((string) ($parsed['error_type'] ?? '')))) {
            'max_tokens_exceeded' => 'max_tokens',
            'refusal' => 'refusal',
            'content_policy_violation' => 'content_filter',
            default => null,
        };
    }

    /**
     * @param array<string,mixed> $body
     * @return array{text:string,input:int,output:int,time:float,stop_reason:?string}
     */
    private function requestWithRetry(array &$body, bool $tolerateEmpty = false): array
    {
        $retryAfterDeadline = null;
        $transport = $this->singleTransport;
        if ($transport === null) {
            $transport = function (array $requestBody) use (&$retryAfterDeadline): array {
                return $this->streamRequest($requestBody, $retryAfterDeadline);
            };
        }
        $retryDelay = null;
        if ($this->provider === 'openrouter') {
            $retryDelay = static function (int $fallback) use (&$retryAfterDeadline): int {
                $remaining = $retryAfterDeadline === null
                    ? 0
                    : min(self::MAX_RETRY_AFTER_WAIT, max(0, $retryAfterDeadline - time()));
                return max($fallback, $remaining);
            };
        }

        return self::retrySingleRequest(
            $body,
            $transport,
            [2, 5, 12],
            $tolerateEmpty,
            $this->provider === 'openrouter',
            $retryDelay,
            null,
            $this->provider === 'openrouter' && !$tolerateEmpty,
        );
    }

    /**
     * Drive one request to completion with a fakeable transport seam. A
     * successful empty response is transient unless tolerate_empty is set.
     * That option also accepts a truncation stop reason because cache-warm
     * probes intentionally use a one-token output budget.
     *
     * @param array<string,mixed> $body
     * @param callable(array<string,mixed>):array{text:string,input:int,output:int,time:float,stop_reason?:?string} $transport
     * @param list<int> $delays
     * @param bool $recoverTerminalReasons Reject refused/filtered responses and
     *        regenerate one output-limit truncation with a doubled budget;
     *        enabled only for the new OpenRouter transport.
     * @param null|callable(int):int $retryDelay Resolve a provider-specific
     *        wait from the configured fallback after a transient failure.
     * @param null|callable(int):void $sleeper Test seam; defaults to sleep().
     * @param bool $surfaceTruncatedPartial Return a non-empty truncated result
     *        so caller-owned continuation recovery can append to it.
     * @return array{text:string,input:int,output:int,time:float,stop_reason?:?string}
     */
    public static function retrySingleRequest(
        array &$body,
        callable $transport,
        array $delays,
        bool $tolerateEmpty = false,
        bool $recoverTerminalReasons = false,
        ?callable $retryDelay = null,
        ?callable $sleeper = null,
        bool $surfaceTruncatedPartial = false,
    ): array
    {
        $sleeper ??= static function (int $seconds): void {
            sleep($seconds);
        };
        $attempt = 0;
        $retriedTruncation = false;
        while (true) {
            try {
                $result = $transport($body);
                $empty = trim($result['text']) === '';
                $stopReason = $result['stop_reason'] ?? null;
                // Deliberately narrower than isTruncation — see StopReasons::OUTPUT_LIMIT.
                $probeReachedOutputLimit = StopReasons::isOutputLimit($stopReason);
                $terminationError = $recoverTerminalReasons
                    ? StopReasons::terminationError($stopReason)
                    : null;
                if ($surfaceTruncatedPartial
                    && !$empty
                    && TextBatchRecovery::isTruncation($stopReason)
                ) {
                    return $result;
                }
                if ($terminationError !== null && !($tolerateEmpty && $probeReachedOutputLimit)) {
                    if ($probeReachedOutputLimit && !$retriedTruncation) {
                        $newBudget = self::prepareSingleTruncationRetry($body);
                        $retriedTruncation = true;
                        Narrator::write("    (OpenRouter response was truncated; regenerating once with {$newBudget} max tokens)\n");
                        continue;
                    }
                    throw new \RuntimeException("stream error: {$terminationError}");
                }
                if ($empty) {
                    if (!$tolerateEmpty) {
                        throw new TransientApiException('no text content in streamed response');
                    }
                    $result['text'] = '';
                }
                return $result;
            } catch (TransientApiException $e) {
                if ($attempt >= count($delays)) {
                    throw new \RuntimeException('OpenAI-compatible API failed after retries: ' . $e->getMessage(), 0, $e);
                }
                $fallback = $delays[$attempt];
                $wait = $retryDelay === null
                    ? $fallback
                    : max($fallback, (int) $retryDelay($fallback));
                $attempt++;
                Narrator::write("    (transient API error: {$e->getMessage()}; retry {$attempt} in {$wait}s)\n");
                $sleeper($wait);
            } catch (\RuntimeException $e) {
                $param = self::rejectedParam($e->getMessage());
                if ($param === null || !array_key_exists($param, $body)) {
                    throw $e;
                }
                unset($body[$param]);
                Narrator::write("    (model rejected '{$param}'; retrying without it)\n");
            }
        }
    }

    /**
     * Increase one OpenRouter single-completion budget and ask for a clean
     * regeneration. This path is never used for existing providers.
     *
     * @param array<string,mixed> $body
     */
    private static function prepareSingleTruncationRetry(array &$body): int
    {
        $tokenKey = array_key_exists('max_tokens', $body)
            ? 'max_tokens'
            : (array_key_exists('max_completion_tokens', $body) ? 'max_completion_tokens' : null);
        if ($tokenKey === null || (int) $body[$tokenKey] <= 0) {
            throw new \RuntimeException(
                'stream error: generation was truncated, but the output token budget is unavailable',
            );
        }

        $body[$tokenKey] = (int) $body[$tokenKey] * 2;
        $messages = $body['messages'] ?? null;
        if (is_array($messages)) {
            foreach (array_reverse(array_keys($messages)) as $key) {
                $message = $body['messages'][$key] ?? null;
                if (!is_array($message)
                    || ($message['role'] ?? null) !== 'user'
                    || !is_string($message['content'] ?? null)
                ) {
                    continue;
                }
                $body['messages'][$key]['content'] = rtrim($message['content'])
                    . "\n\nYOUR PREVIOUS RESPONSE WAS CUT OFF BY THE OUTPUT LENGTH LIMIT. "
                    . 'Regenerate the COMPLETE response from scratch, as compactly as the instructions above allow, '
                    . 'and return nothing else.';
                break;
            }
        }
        return (int) $body[$tokenKey];
    }

    /**
     * @param array<string,mixed> $body
     * @return array{text:string,input:int,output:int,time:float,stop_reason:?string}
     */
    private function streamRequest(array $body, ?int &$retryAfterDeadline = null): array
    {
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $raw = '';
        $retryAfterDeadline = null;

        $ch = curl_init($this->endpoint);
        $options = [
            CURLOPT_POST          => true,
            CURLOPT_HTTPHEADER    => $this->headers(),
            CURLOPT_POSTFIELDS    => $payload,
            CURLOPT_TIMEOUT       => $this->timeoutSeconds,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME  => 90,
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$raw) {
                $raw .= $chunk;
                return strlen($chunk);
            },
        ];
        if ($this->provider === 'openrouter') {
            $options[CURLOPT_HEADERFUNCTION] = static function ($ch, string $line) use (
                &$retryAfterDeadline,
            ): int {
                return self::captureRetryAfterHeader($line, $retryAfterDeadline);
            };
        }
        curl_setopt_array($ch, $options);

        curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $time   = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        curl_close($ch);

        if ($errno !== 0) {
            if (self::isTransientCurl($errno)) {
                throw new TransientApiException("cURL ({$errno}): {$error}");
            }
            throw new \RuntimeException("cURL error ({$errno}): {$error}");
        }
        return self::interpretSingleStream($raw, $time, $this->provider, $status);
    }

    /**
     * Interpret a successful HTTP response for complete(). OpenRouter can
     * report output exhaustion as a typed SSE error; expose that as a normal
     * stop reason so the cache-warm exception and ordinary truncation policy
     * remain centralized in retrySingleRequest().
     *
     * @return array{text:string,input:int,output:int,time:float,stop_reason:?string}
     */
    private static function interpretSingleStream(
        string $raw,
        float $time,
        string $provider,
        int $status = 200,
    ): array
    {
        $parsed = $provider === 'openrouter'
            ? self::parseSse($raw, $provider)
            : null;
        $typedTerminalReason = $parsed === null
            ? null
            : self::typedTerminalStopReason($parsed);

        if (($status < 200 || $status >= 300) && $typedTerminalReason === null) {
            if (self::isTransientStatus($status, $provider)) {
                throw new TransientApiException("HTTP {$status}: " . self::truncate($raw));
            }
            throw new \RuntimeException("OpenAI-compatible API HTTP {$status}: " . self::truncate($raw));
        }

        $parsed ??= self::parseSse($raw, $provider);
        if ($typedTerminalReason !== null) {
            $parsed['error'] = null;
            $parsed['stop_reason'] = $typedTerminalReason;
        }
        if ($parsed['error'] !== null) {
            $msg = "stream error: {$parsed['error']}";
            $transient = $provider === 'openrouter'
                ? self::isTransientStreamError($parsed)
                : self::isLegacyTransientStreamError($parsed);
            if ($transient) {
                throw new TransientApiException($msg);
            }
            throw new \RuntimeException($msg);
        }
        return [
            'text'        => $parsed['text'],
            'input'       => $parsed['input'],
            'output'      => $parsed['output'],
            'time'        => $time,
            'stop_reason' => $parsed['stop_reason'],
        ];
    }

    /** @return list<string> */
    private function headers(): array
    {
        return [
            'Authorization: Bearer ' . $this->apiKey,
            'content-type: application/json',
            'accept: text/event-stream',
        ];
    }

    /**
     * Parse an OpenAI-style Chat Completions SSE body into concatenated text
     * and token usage. Handles:
     *   - choices[0].delta.content (streaming tokens)
     *   - choices[0].message.content (non-stream JSON body if a host ignores stream)
     *   - message.refusal / delta.refusal from structured-output safety refusals
     *   - usage on any chunk (final chunk when stream_options.include_usage)
     *   - data: [DONE]
     *   - top-level error objects some hosts send mid-stream
     *   - OpenRouter's choices[0].error extension when provider=openrouter
     *
     * The provider's finish_reason is exposed as the cross-provider
     * `stop_reason`. JSON recovery uses it to distinguish malformed output
     * from truncation and refusal without accepting a partial response.
     *
     * @param string $provider Nested choice errors are an OpenRouter extension;
     *                         existing providers retain their earlier parsing.
     * @return array{text:string,input:int,output:int,error:?string,error_code:int|string|null,error_type:string,stop_reason:?string}
     */
    public static function parseSse(string $raw, string $provider = 'openai'): array
    {
        $text = '';
        $input = 0;
        $output = 0;
        $error = null;
        $errorCode = null;
        $errorType = '';
        $stopReason = null;

        // Non-stream JSON response (provider ignored stream:true or returned error JSON).
        $trimmed = trim($raw);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $json = json_decode($trimmed, true);
            if (is_array($json)) {
                if (isset($json['error'])) {
                    [$error, $errorCode, $errorType] = self::parseError($json['error']);
                    return self::parsedResponse('', 0, 0, $error, $errorCode, $errorType, null);
                }
                $choice = $json['choices'][0] ?? null;
                if ($provider === 'openrouter' && is_array($choice) && isset($choice['error'])) {
                    [$error, $errorCode, $errorType] = self::parseError($choice['error']);
                    $stopReason = isset($choice['finish_reason'])
                        ? (string) $choice['finish_reason']
                        : null;
                    $message = $choice['message'] ?? null;
                    $text = is_array($message) && is_string($message['content'] ?? null)
                        ? $message['content']
                        : '';
                    $usage = self::extractUsage($json);
                    return self::parsedResponse(
                        $text,
                        $usage['input'],
                        $usage['output'],
                        $error,
                        $errorCode,
                        $errorType,
                        $stopReason,
                    );
                }
                $message = is_array($choice) ? ($choice['message'] ?? null) : null;
                if (is_array($message)) {
                    $refusal = is_string($message['refusal'] ?? null)
                        && trim((string) $message['refusal']) !== '';
                    if (!array_key_exists('content', $message) && !$refusal) {
                        return self::parsedResponse('', 0, 0, null, null, '', null);
                    }

                    $text = is_string($message['content'] ?? null) ? $message['content'] : '';
                    $stopReason = $refusal
                        ? 'refusal'
                        : (isset($choice['finish_reason'])
                        ? (string) $choice['finish_reason']
                        : null);
                    $usage = self::extractUsage($json);
                    return self::parsedResponse(
                        $text,
                        $usage['input'],
                        $usage['output'],
                        null,
                        null,
                        '',
                        $stopReason,
                    );
                }
            }
        }

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
            $streamError = $evt['error']
                ?? ($provider === 'openrouter' ? ($evt['choices'][0]['error'] ?? null) : null);
            if ($streamError !== null) {
                [$error, $errorCode, $errorType] = self::parseError($streamError);
                if ($stopReason !== 'refusal' && isset($evt['choices'][0]['finish_reason'])) {
                    $stopReason = (string) $evt['choices'][0]['finish_reason'];
                }
                if (isset($evt['usage']) && is_array($evt['usage'])) {
                    $usage = self::extractUsage($evt);
                    $input = $usage['input'];
                    $output = $usage['output'];
                }
                continue;
            }
            $delta = $evt['choices'][0]['delta']['content'] ?? null;
            if (is_string($delta) && $delta !== '') {
                $text .= $delta;
            }
            // Some hosts send the full message on the last chunk instead of deltas.
            $msgContent = $evt['choices'][0]['message']['content'] ?? null;
            if (is_string($msgContent) && $msgContent !== '' && $text === '') {
                $text = $msgContent;
            }
            $refusal = $evt['choices'][0]['delta']['refusal']
                ?? $evt['choices'][0]['message']['refusal']
                ?? null;
            if (is_string($refusal) && trim($refusal) !== '') {
                $stopReason = 'refusal';
            } elseif ($stopReason !== 'refusal' && isset($evt['choices'][0]['finish_reason'])) {
                $stopReason = (string) $evt['choices'][0]['finish_reason'];
            }
            if (isset($evt['usage']) && is_array($evt['usage'])) {
                $usage = self::extractUsage($evt);
                $input = $usage['input'];
                $output = $usage['output'];
            }
        }

        return self::parsedResponse(
            $text,
            $input,
            $output,
            $error,
            $errorCode,
            $errorType,
            $stopReason,
        );
    }

    /** @return array{0:string,1:int|string|null,2:string} */
    private static function parseError(mixed $error): array
    {
        if (!is_array($error)) {
            return [(string) $error, null, ''];
        }
        $message = (string) ($error['message'] ?? json_encode($error));
        $code = $error['code'] ?? null;
        $type = (string) (
            $error['metadata']['error_type']
            ?? $error['type']
            ?? ''
        );
        return [$message, is_int($code) || is_string($code) ? $code : null, $type];
    }

    /**
     * @return array{text:string,input:int,output:int,error:?string,error_code:int|string|null,error_type:string,stop_reason:?string}
     */
    private static function parsedResponse(
        string $text,
        int $input,
        int $output,
        ?string $error,
        int|string|null $errorCode,
        string $errorType,
        ?string $stopReason,
    ): array {
        return [
            'text'        => $text,
            'input'       => $input,
            'output'      => $output,
            'error'       => $error,
            'error_code'  => $errorCode,
            'error_type'  => $errorType,
            'stop_reason' => $stopReason,
        ];
    }

    private static function truncate(string $s, int $max = 300): string
    {
        return strlen($s) > $max ? substr($s, 0, $max) . '…' : $s;
    }

    /**
     * Name of a sampling parameter the model rejected, so the caller can drop it
     * and retry. Safety net behind bodyFor()'s proactive omission: covers models
     * or hosts whose temperature rules we didn't predict.
     *
     * Recognises OpenAI's wording — e.g.
     *   "Unsupported value: 'temperature' does not support 0.9 with this model."
     *   "Unsupported parameter: 'top_p' is not supported with this model."
     * — then falls back to AnthropicClient's matcher for the shared Anthropic
     * phrasing. Scoped to sampling params (never max_tokens, which we key
     * correctly up front via maxTokensParam()).
     */
    public static function rejectedParam(string $error): ?string
    {
        if (preg_match(
            "/'(temperature|top_p|top_k|presence_penalty|frequency_penalty)'\\s+(?:is not supported|does not support|is unsupported)/",
            $error,
            $m,
        ) === 1) {
            return $m[1];
        }
        return AnthropicClient::rejectedParam($error);
    }
}
