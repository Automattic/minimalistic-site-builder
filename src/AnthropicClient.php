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

    public function __construct(
        private string $apiKey,
        private string $model,
        private int $defaultMaxTokens = 16000,
    ) {}

    public function complete(string $prompt, array $opts = []): string
    {
        $body = [
            'model'      => $opts['model'] ?? $this->model,
            'max_tokens' => $opts['max_tokens'] ?? $this->defaultMaxTokens,
            'messages'   => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];
        if (isset($opts['system'])) {
            $body['system'] = $opts['system'];
        }

        $decoded = $this->post($body);

        // Concatenate all text blocks (defensive: usually one).
        $text = '';
        foreach ($decoded['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }
        if ($text === '') {
            throw new RuntimeException('No text content in response: ' . json_encode($decoded));
        }
        return $text;
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
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function post(array $body): array
    {
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::API_VERSION,
                'content-type: application/json',
            ],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 300,
        ]);

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException("cURL error ({$errno}): {$error}");
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("Anthropic API HTTP {$status}: {$raw}");
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Invalid JSON response: {$raw}");
        }
        return $decoded;
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
