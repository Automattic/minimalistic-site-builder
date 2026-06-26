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
        $body = [
            'model'      => $opts['model'] ?? $this->model,
            'max_tokens' => $opts['max_tokens'] ?? $this->defaultMaxTokens,
            'stream'     => true,
            'messages'   => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];
        if (isset($opts['system'])) {
            $body['system'] = $opts['system'];
        }

        $res = $this->requestWithRetry($body);

        $this->requests++;
        $this->inputTokens += $res['input'];
        $this->outputTokens += $res['output'];

        if (trim($res['text']) === '') {
            throw new RuntimeException('No text content in streamed response');
        }
        return $res['text'];
    }

    public function completeJson(string $prompt, array $opts = []): array
    {
        // Steer toward raw JSON; still strip fences defensively.
        $opts['system'] = ($opts['system'] ?? '')
            . "\nRespond with a single valid JSON value and nothing else. "
            . 'No prose, no markdown fences.';
        $text = $this->complete($prompt, $opts);
        $json = self::stripFences($text);

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException("Expected JSON, got: {$text}");
        }
        return $data;
    }

    /**
     * Run a streaming request, retrying transient failures with backoff.
     *
     * @param array<string,mixed> $body
     * @return array{text:string,input:int,output:int}
     */
    private function requestWithRetry(array $body): array
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
            }
        }
    }

    /**
     * POST a streaming Messages request and assemble the text + token usage from
     * the SSE event stream.
     *
     * @param array<string,mixed> $body
     * @return array{text:string,input:int,output:int}
     * @throws TransientApiException on a retryable failure (stall, 429, 5xx, overload)
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
        curl_close($ch);

        // Connection-level failures: timeout, stall, connect/recv errors — retryable.
        if (in_array($errno, [7, 28, 35, 52, 55, 56], true)) {
            throw new TransientApiException("cURL ({$errno}): {$error}");
        }
        if ($errno !== 0) {
            throw new RuntimeException("cURL error ({$errno}): {$error}");
        }

        if ($status === 429 || $status >= 500) {
            throw new TransientApiException("HTTP {$status}: " . self::truncate($raw));
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("Anthropic API HTTP {$status}: {$raw}");
        }

        $parsed = self::parseSse($raw);
        if ($parsed['error'] !== null) {
            if (in_array($parsed['error_type'], ['overloaded_error', 'api_error'], true)) {
                throw new TransientApiException("stream error: {$parsed['error']}");
            }
            throw new RuntimeException("stream error: {$parsed['error']}");
        }

        return ['text' => $parsed['text'], 'input' => $parsed['input'], 'output' => $parsed['output']];
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
}
