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
    public static function run(
        array $requests,
        callable $send,
        int $maxRetries = 1,
        int $defaultMaxTokens = 16000,
    ): array
    {
        if ($maxRetries < 0) {
            throw new \InvalidArgumentException('maxRetries must be zero or greater');
        }
        if ($defaultMaxTokens < 1) {
            throw new \InvalidArgumentException('defaultMaxTokens must be greater than zero');
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
                    $retry[$key] = self::repairRequest($key, $requests[$key], $response, $error, $defaultMaxTokens);
                    continue;
                }
                $failures[$key] = self::failure($key, $requests[$key], $response, $error, $attempt);
            }

            if ($failures !== []) {
                throw new GeneratedJsonException(
                    $failures,
                    self::orderedResults($requests, $decoded),
                );
            }
            if ($retry === []) {
                break;
            }

            $attempt++;
            Narrator::write(
                '    (invalid JSON in ' . count($retry) . ' batch request(s); repairing only: '
                    . self::keys(array_keys($retry)) . ")\n"
            );
            $pending = $retry;
        }

        $out = self::orderedResults($requests, $decoded);
        foreach ($requests as $key => $_request) {
            if (!array_key_exists($key, $out)) {
                throw new \RuntimeException("JSON batch recovery lost request '{$key}'");
            }
        }
        return $out;
    }

    /**
     * Restore input order while retaining only results decoded so far.
     *
     * @param array<array-key,array<string,mixed>> $requests
     * @param array<array-key,array<mixed>>        $decoded
     * @return array<array-key,array<mixed>>
     */
    private static function orderedResults(array $requests, array $decoded): array
    {
        $out = [];
        foreach ($requests as $key => $_request) {
            if (array_key_exists($key, $decoded)) {
                $out[$key] = $decoded[$key];
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function responseRecord(mixed $response, string|int $key): array
    {
        if (is_string($response)) {
            return ['text' => $response];
        }
        if (!is_array($response)) {
            throw new \RuntimeException(
                "JSON batch response '{$key}' must be a string or a record, got " . get_debug_type($response)
            );
        }
        if (!array_key_exists('text', $response) || !is_string($response['text'])) {
            throw new \RuntimeException("JSON batch response '{$key}' has no text");
        }
        return $response;
    }

    /**
     * Build the retry request for one failed key, shaped by WHY it failed:
     * a syntax error gets the previous text back for a targeted repair; a
     * truncation regenerates with a doubled output budget (repairing at the
     * same cap re-truncates by construction, and re-embedding the cut-off
     * text only doubles the input cost); a refusal regenerates cleanly (the
     * filter is non-deterministic; there is no previous text to repair).
     *
     * @param array<string,mixed> $request
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private static function repairRequest(
        string|int $key,
        array $request,
        array $response,
        string $error,
        int $defaultMaxTokens,
    ): array {
        $label = (string) ($request['log_label'] ?? $key);
        $repair = $request;
        $repair['log_label'] = $label . '-json-repair';
        $prompt = (string) ($request['prompt'] ?? '');
        $reason = is_string($response['stop_reason'] ?? null) ? trim($response['stop_reason']) : '';

        if (self::isTruncation($reason)) {
            // Twice the explicit budget, or twice the calling client's
            // effective configurable default when the request relied on it.
            $repair['max_tokens'] = isset($request['max_tokens'])
                ? ((int) $request['max_tokens']) * 2
                : $defaultMaxTokens * 2;
            $repair['prompt'] = $prompt
                . "\n\nYOUR PREVIOUS RESPONSE WAS CUT OFF BY THE OUTPUT LENGTH LIMIT ({$error}). "
                . "Regenerate the COMPLETE JSON value from scratch, as compactly as the schema allows, "
                . "and return nothing else.";
            return $repair;
        }

        if (StopReasons::isRefusal($reason)) {
            $repair['prompt'] = $prompt
                . "\n\nYOUR PREVIOUS ATTEMPT RETURNED NO USABLE CONTENT ({$error}). "
                . "Answer again, returning only the JSON value the instructions above describe.";
            return $repair;
        }

        $previous = (string) $response['text'];
        $repair['prompt'] = $prompt
            . "\n\nYOUR PREVIOUS RESPONSE WAS INVALID JSON. Repair its syntax without changing, omitting, "
            . "or adding any substantive content. Return the complete corrected JSON value and nothing else.\n"
            . "Parser/termination error: {$error}\n"
            . "<previous_response>\n{$previous}\n</previous_response>";
        return $repair;
    }

    /**
     * Whether a provider stop reason means the output budget ran out.
     * Delegates to the shared vocabulary; kept public because callers predate
     * {@see StopReasons}.
     */
    public static function isTruncation(mixed $reason): bool
    {
        return StopReasons::isTruncation($reason);
    }

    /** Classify provider stop reasons that mean a JSON response is incomplete. */
    public static function terminationError(mixed $reason): ?string
    {
        return StopReasons::terminationError($reason);
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
