<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Decode a concurrent JSON batch and regenerate only malformed members.
 *
 * The transport callback owns the real calls, usage accounting and logging.
 * This pure orchestrator retains successful siblings, sends every malformed
 * key together as one repair subset, and preserves the input key order.
 */
final class JsonBatchRecovery
{
    /**
     * @param array<array-key,array<string,mixed>> $requests
     * @param callable(array<array-key,array<string,mixed>>):array<array-key,array<string,mixed>|string> $send
     * @return array<array-key,array<mixed>>
     */
    public static function run(array $requests, callable $send, int $maxRetries = 1): array
    {
        if ($maxRetries < 0) {
            throw new \InvalidArgumentException('maxRetries must be zero or greater');
        }
        if ($requests === []) {
            return [];
        }

        $decoded = [];
        $pending = $requests;
        $attempt = 0;

        while ($pending !== []) {
            $responses = $send($pending);
            if (!is_array($responses)) {
                throw new \RuntimeException('JSON batch transport returned a non-array result');
            }

            $unexpected = array_diff_key($responses, $pending);
            if ($unexpected !== []) {
                throw new \RuntimeException(
                    'JSON batch transport returned unexpected key(s): ' . self::keys(array_keys($unexpected))
                );
            }

            $retry = [];
            $failures = [];
            foreach ($pending as $key => $_request) {
                if (!array_key_exists($key, $responses)) {
                    throw new \RuntimeException("JSON batch transport omitted request '{$key}'");
                }

                $response = self::responseRecord($responses[$key], $key);
                $terminationError = self::terminationError($response['stop_reason'] ?? null);
                $result = $terminationError === null
                    ? JsonDecoder::decodeResult((string) $response['text'])
                    : ['data' => null, 'error' => $terminationError];

                if ($result['data'] !== null) {
                    $decoded[$key] = $result['data'];
                    continue;
                }

                $error = (string) ($result['error'] ?? 'unknown JSON decode error');
                if ($attempt < $maxRetries) {
                    $retry[$key] = self::repairRequest($key, $requests[$key], $response, $error);
                    continue;
                }
                $failures[] = self::failure($key, $requests[$key], $response, $error, $attempt);
            }

            if ($failures !== []) {
                throw new \RuntimeException(implode("\n", $failures));
            }
            if ($retry === []) {
                break;
            }

            $attempt++;
            fwrite(
                STDERR,
                '    (invalid JSON in ' . count($retry) . ' batch request(s); repairing only: '
                    . self::keys(array_keys($retry)) . ")\n"
            );
            $pending = $retry;
        }

        $out = [];
        foreach ($requests as $key => $_request) {
            if (!array_key_exists($key, $decoded)) {
                throw new \RuntimeException("JSON batch recovery lost request '{$key}'");
            }
            $out[$key] = $decoded[$key];
        }
        return $out;
    }

    /** @param array<string,mixed>|string $response @return array<string,mixed> */
    private static function responseRecord(array|string $response, string|int $key): array
    {
        if (is_string($response)) {
            return ['text' => $response];
        }
        if (!array_key_exists('text', $response) || !is_string($response['text'])) {
            throw new \RuntimeException("JSON batch response '{$key}' has no text");
        }
        return $response;
    }

    /**
     * @param array<string,mixed> $request
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private static function repairRequest(
        string|int $key,
        array $request,
        array $response,
        string $error,
    ): array {
        $label = (string) ($request['log_label'] ?? $key);
        $previous = (string) $response['text'];
        $repair = $request;
        $repair['log_label'] = $label . '-json-repair';
        $repair['prompt'] = (string) ($request['prompt'] ?? '')
            . "\n\nYOUR PREVIOUS RESPONSE WAS INVALID JSON. Repair its syntax without changing, omitting, "
            . "or adding any substantive content. Return the complete corrected JSON value and nothing else.\n"
            . "Parser/termination error: {$error}\n"
            . "<previous_response>\n{$previous}\n</previous_response>";
        return $repair;
    }

    /** Classify provider stop reasons that mean a JSON response is incomplete. */
    public static function terminationError(mixed $reason): ?string
    {
        $reason = is_string($reason) ? trim($reason) : '';
        if (in_array($reason, ['max_tokens', 'length'], true)) {
            return "generation was truncated (stop reason: {$reason})";
        }
        if (in_array($reason, ['refusal', 'content_filter', 'safety'], true)) {
            return "generation was refused or filtered (stop reason: {$reason})";
        }
        return null;
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $response */
    private static function failure(
        string|int $key,
        array $request,
        array $response,
        string $error,
        int $attempts,
    ): string {
        $model = (string) ($response['model'] ?? $request['model'] ?? 'default');
        $stop = trim((string) ($response['stop_reason'] ?? ''));
        $logPath = trim((string) ($response['log_path'] ?? ''));
        $message = "batch request '{$key}' returned invalid JSON after {$attempts} repair attempt(s): {$error}"
            . " (model: {$model}" . ($stop !== '' ? ", stop reason: {$stop}" : '') . ')';
        if ($logPath !== '') {
            $message .= "; response log: {$logPath}";
        }
        return $message;
    }

    /** @param array<int|string> $keys */
    private static function keys(array $keys): string
    {
        return implode(', ', array_map(static fn (string|int $key): string => (string) $key, $keys));
    }
}
