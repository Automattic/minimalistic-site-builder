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
final class OpenAiCompatibleClient implements Llm
{
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

    /**
     * @param string $apiKey            Bearer token (OPENAI_API_KEY / XAI_API_KEY)
     * @param string $model             Default model when a request does not pin one
     * @param string $baseUrl           API root, e.g. https://api.x.ai/v1 (no trailing slash required)
     * @param int    $defaultMaxTokens  max_tokens default for completions
     * @param string $provider          Selects provider-specific request quirks
     *                                   (token-limit key, temperature support):
     *                                   'openai' or 'xai'. See maxTokensParam().
     */
    public function __construct(
        private string $apiKey,
        private string $model,
        string $baseUrl = 'https://api.openai.com/v1',
        private int $defaultMaxTokens = 16000,
        private string $provider = 'openai',
    ) {
        $this->endpoint = rtrim($baseUrl, '/') . '/chat/completions';
    }

    /** Resolved chat-completions URL (for tests / diagnostics). */
    public function endpoint(): string
    {
        return $this->endpoint;
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
     *        tolerate_empty accepts a successful whitespace-only response as ''
     *        without retrying; it is intended only for cache-warm probes.
     */
    public function complete(string $prompt, array $opts = []): string
    {
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
        );
    }

    public function completeBatch(array $requests): array
    {
        return TextBatchRecovery::run(
            $requests,
            fn (array $subset): array => $this->responseBatch($subset, false),
        );
    }

    /**
     * Shared concurrent-batch transport for completeJsonBatch and completeBatch.
     *
     * @param array<array-key,array<string,mixed>> $requests
     * @return array<array-key,array<string,mixed>>
     */
    private function responseBatch(array $requests, bool $json): array
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
            $bodies[$key] = self::bodyFor(['system' => $system] + $req, $this->model, $this->defaultMaxTokens, $this->provider);
        }

        // Keys may be ints too (PHP coerces numeric keys), so admit both.
        $labelFor = fn (string|int $key): string => (string) ($requests[$key]['log_label'] ?? $key);

        $results = AnthropicClient::retryTextBatch(
            $bodies,
            fn (array $subset): array => $this->streamMulti($subset, $json),
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

            $res['log_path'] = LlmLogger::log($labelFor($key), $bodies[$key], $res, $res['time']);
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
     * @return array<string,mixed>
     */
    public static function bodyFor(array $req, string $defaultModel, int $defaultMaxTokens, string $provider = 'openai'): array
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

        $maxTokens = (int) ($req['max_tokens'] ?? $defaultMaxTokens);
        $body = [
            'model'      => $model,
            'stream'     => true,
            // So the final SSE chunk carries usage (OpenAI + most compat hosts).
            'stream_options' => ['include_usage' => true],
            'messages'   => $messages,
        ] + self::maxTokensParam($provider, $model, $maxTokens);

        // Reasoning models (OpenAI o-series / gpt-5+) reject any non-default
        // temperature, so omit it rather than 400. Sending nothing == the API
        // default (1), which is what those models require anyway.
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
        }
        return $body;
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
     * Whether $provider + $model only accepts the default sampling temperature.
     * True for OpenAI reasoning models (o-series / gpt-5+), which 400 on any
     * temperature other than 1. xAI (Grok) accepts arbitrary temperatures, so
     * this is scoped to the OpenAI provider only.
     */
    public static function restrictsTemperature(string $provider, string $model): bool
    {
        return $provider === 'openai' && preg_match('/^gpt-[34]/', $model) !== 1;
    }

    /**
     * @param array<array-key,array<string,mixed>> $bodies
     * @return array<array-key,array{ok:bool,text?:string,input?:int,output?:int,error?:string,transient?:bool,retry_without?:string,time?:float,stop_reason?:?string}>
     */
    private function streamMulti(array $bodies, bool $allowEmptyTerminal = false): array
    {
        $out = [];
        foreach (AnthropicClient::concurrencyWindows($bodies) as $chunk) {
            $out += $this->streamChunk($chunk, $allowEmptyTerminal);
        }
        return $out;
    }

    /**
     * @param array<array-key,array<string,mixed>> $bodies
     * @return array<array-key,array{ok:bool,text?:string,input?:int,output?:int,error?:string,transient?:bool,retry_without?:string,time?:float,stop_reason?:?string}>
     */
    private function streamChunk(array $bodies, bool $allowEmptyTerminal = false): array
    {
        $multi = curl_multi_init();
        $handles = [];
        $raw = [];
        foreach ($bodies as $key => $body) {
            $raw[$key] = '';
            $ch = curl_init($this->endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST          => true,
                CURLOPT_HTTPHEADER    => $this->headers(),
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
            $out[$key] = self::interpretStream(
                $raw[$key],
                $errno,
                $error,
                $httpStatus,
                $time,
                $allowEmptyTerminal,
            );
        }

        curl_multi_close($multi);
        return $out;
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
        bool $allowEmptyTerminal = false,
    ): array
    {
        if ($errno !== 0) {
            return ['ok' => false, 'transient' => self::isTransientCurl($errno), 'error' => "cURL ({$errno}): {$error}", 'time' => $time];
        }
        if ($status < 200 || $status >= 300) {
            $out = ['ok' => false, 'transient' => self::isTransientStatus($status), 'error' => "HTTP {$status}: " . self::truncate($raw), 'time' => $time];
            if (($param = self::rejectedParam($raw)) !== null) {
                $out['retry_without'] = $param;
            }
            return $out;
        }

        $parsed = self::parseSse($raw);
        if ($parsed['error'] !== null) {
            // Treat provider "overloaded" style messages as transient when obvious.
            $transient = str_contains(strtolower($parsed['error']), 'overloaded')
                || str_contains(strtolower($parsed['error']), 'rate limit');
            return ['ok' => false, 'transient' => $transient, 'error' => "stream error: {$parsed['error']}", 'time' => $time];
        }
        if (trim($parsed['text']) === '' && !(
            $allowEmptyTerminal
            && JsonBatchRecovery::terminationError($parsed['stop_reason']) !== null
        )) {
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
        return in_array($errno, [6, 7, 28, 35, 52, 55, 56], true);
    }

    private static function isTransientStatus(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    /**
     * @param array<string,mixed> $body
     * @return array{text:string,input:int,output:int,time:float,stop_reason:?string}
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
     * Drive one request to completion with a fakeable transport seam. A
     * successful empty response is transient unless tolerate_empty is set.
     *
     * @param array<string,mixed> $body
     * @param callable(array<string,mixed>):array{text:string,input:int,output:int,time:float} $transport
     * @param list<int> $delays
     * @return array{text:string,input:int,output:int,time:float}
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
            } catch (TransientApiException $e) {
                if ($attempt >= count($delays)) {
                    throw new \RuntimeException('OpenAI-compatible API failed after retries: ' . $e->getMessage(), 0, $e);
                }
                $wait = $delays[$attempt];
                $attempt++;
                fwrite(STDERR, "    (transient API error: {$e->getMessage()}; retry {$attempt} in {$wait}s)\n");
                sleep($wait);
            } catch (\RuntimeException $e) {
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
     * @param array<string,mixed> $body
     * @return array{text:string,input:int,output:int,time:float,stop_reason:?string}
     */
    private function streamRequest(array $body): array
    {
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $raw = '';

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST          => true,
            CURLOPT_HTTPHEADER    => $this->headers(),
            CURLOPT_POSTFIELDS    => $payload,
            CURLOPT_TIMEOUT       => 600,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME  => 90,
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$raw) {
                $raw .= $chunk;
                return strlen($chunk);
            },
        ]);

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
        if ($status < 200 || $status >= 300) {
            if (self::isTransientStatus($status)) {
                throw new TransientApiException("HTTP {$status}: " . self::truncate($raw));
            }
            throw new \RuntimeException("OpenAI-compatible API HTTP {$status}: " . self::truncate($raw));
        }

        $parsed = self::parseSse($raw);
        if ($parsed['error'] !== null) {
            $msg = "stream error: {$parsed['error']}";
            if (str_contains(strtolower($parsed['error']), 'overloaded')
                || str_contains(strtolower($parsed['error']), 'rate limit')) {
                throw new TransientApiException($msg);
            }
            throw new \RuntimeException($msg);
        }
        // Empty-text handling lives in retrySingleRequest so tolerate_empty
        // (cache-warm probes) can accept a blank one-token reply without a
        // transient retry loop.
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
     *
     * @return array{text:string,input:int,output:int,error:?string,stop_reason:?string}
     */
    public static function parseSse(string $raw): array
    {
        $text = '';
        $input = 0;
        $output = 0;
        $error = null;
        $stopReason = null;

        // Non-stream JSON response (provider ignored stream:true or returned error JSON).
        $trimmed = trim($raw);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $json = json_decode($trimmed, true);
            if (is_array($json)) {
                if (isset($json['error'])) {
                    $err = $json['error'];
                    $error = is_array($err)
                        ? (string) ($err['message'] ?? json_encode($err))
                        : (string) $err;
                    return ['text' => '', 'input' => 0, 'output' => 0, 'error' => $error, 'stop_reason' => null];
                }
                $message = $json['choices'][0]['message'] ?? null;
                if (is_array($message)) {
                    $refusal = is_string($message['refusal'] ?? null)
                        && trim((string) $message['refusal']) !== '';
                    if (!array_key_exists('content', $message) && !$refusal) {
                        return [
                            'text' => '',
                            'input' => 0,
                            'output' => 0,
                            'error' => null,
                            'stop_reason' => null,
                        ];
                    }

                    $text = is_string($message['content'] ?? null) ? $message['content'] : '';
                    $usage = self::extractUsage($json);
                    $stopReason = $refusal
                        ? 'refusal'
                        : (isset($json['choices'][0]['finish_reason'])
                        ? (string) $json['choices'][0]['finish_reason']
                        : null);
                    return [
                        'text'        => $text,
                        'input'       => $usage['input'],
                        'output'      => $usage['output'],
                        'error'       => null,
                        'stop_reason' => $stopReason,
                    ];
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
            if (isset($evt['error'])) {
                $err = $evt['error'];
                $error = is_array($err)
                    ? (string) ($err['message'] ?? json_encode($err))
                    : (string) $err;
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

        return [
            'text'        => $text,
            'input'       => $input,
            'output'      => $output,
            'error'       => $error,
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
