<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Detect abnormal termination (token-limit truncation, provider refusal) in a
 * concurrent raw-text batch and regenerate only the affected members.
 *
 * The JSON batch path already rejects such responses (JsonBatchRecovery's
 * decode step trips on a cut-off value). completeBatch() responses are raw
 * payloads — block markup — with no decode step, so without this check a
 * response that hit the max_tokens ceiling was accepted whole and the build
 * only failed much later, at the section-rhythm root-group gate, discarding
 * every other already-paid-for LLM call (BIGR-716).
 *
 * A truncated member is regenerated once with a doubled output budget
 * (repairing at the same cap re-truncates by construction); a refusal is
 * regenerated cleanly. Unlike the JSON path, a member that STILL terminates
 * abnormally after regeneration is returned as-is rather than aborting the
 * batch: truncated markup is salvageable downstream (MarkupSalvage trims it
 * back to the last complete block), and degrading one section beats rejecting
 * the entire theme. The transport callback owns the real calls, usage
 * accounting and logging; this orchestrator is pure apart from STDERR notes.
 */
final class TextBatchRecovery
{
    /**
     * @param array<array-key,array<string,mixed>> $requests
     * @param callable(array<array-key,array<string,mixed>>):array<array-key,array<string,mixed>|string> $send
     * @return array<array-key,string> raw text keyed and ordered as the input
     */
    public static function run(array $requests, callable $send, int $maxRetries = 1): array
    {
        if ($maxRetries < 0) {
            throw new \InvalidArgumentException('maxRetries must be zero or greater');
        }
        if ($requests === []) {
            return [];
        }

        $texts = [];
        $pending = $requests;
        $attempt = 0;

        while ($pending !== []) {
            $responses = $send($pending);
            if (!is_array($responses)) {
                throw new \RuntimeException('text batch transport returned a non-array result');
            }

            $unexpected = array_diff_key($responses, $pending);
            if ($unexpected !== []) {
                throw new \RuntimeException(
                    'text batch transport returned unexpected key(s): ' . self::keys(array_keys($unexpected))
                );
            }

            $retry = [];
            foreach ($pending as $key => $_request) {
                if (!array_key_exists($key, $responses)) {
                    throw new \RuntimeException("text batch transport omitted request '{$key}'");
                }

                $response = self::responseRecord($responses[$key], $key);
                $error = JsonBatchRecovery::terminationError($response['stop_reason'] ?? null);
                if ($error === null) {
                    $texts[$key] = (string) $response['text'];
                    continue;
                }

                if ($attempt < $maxRetries) {
                    $retry[$key] = self::regenerateRequest($key, $requests[$key], $response, $error);
                    continue;
                }

                // Out of retries: keep the best effort instead of aborting.
                // The markup intake (GeneratedMarkup via MarkupSalvage) trims
                // an incomplete response to its last complete block, so a
                // persistent truncation degrades one part, not the build.
                fwrite(STDERR, "    (batch request '{$key}' still incomplete after {$attempt} regeneration(s) — "
                    . "{$error}; keeping the partial response for salvage)\n");
                $texts[$key] = (string) $response['text'];
            }

            if ($retry === []) {
                break;
            }

            $attempt++;
            fwrite(
                STDERR,
                '    (incomplete response in ' . count($retry) . ' batch request(s); regenerating only: '
                    . self::keys(array_keys($retry)) . ")\n"
            );
            $pending = $retry;
        }

        $out = [];
        foreach ($requests as $key => $_request) {
            if (!array_key_exists($key, $texts)) {
                throw new \RuntimeException("text batch recovery lost request '{$key}'");
            }
            $out[$key] = $texts[$key];
        }
        return $out;
    }

    /**
     * Build the retry request for one abnormally terminated key. A truncation
     * regenerates with a doubled output budget; a refusal regenerates cleanly
     * (the filter is non-deterministic; there is no previous text to repair).
     *
     * @param array<string,mixed> $request
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private static function regenerateRequest(
        string|int $key,
        array $request,
        array $response,
        string $error,
    ): array {
        $label = (string) ($request['log_label'] ?? $key);
        $retry = $request;
        $retry['log_label'] = $label . '-regenerate';
        $prompt = (string) ($request['prompt'] ?? '');

        if (JsonBatchRecovery::isTruncation($response['stop_reason'] ?? null)) {
            // Twice the explicit budget, or twice the clients' 16k default
            // when the request relied on it.
            $retry['max_tokens'] = isset($request['max_tokens'])
                ? ((int) $request['max_tokens']) * 2
                : 32000;
            $retry['prompt'] = $prompt
                . "\n\nYOUR PREVIOUS RESPONSE WAS CUT OFF BY THE OUTPUT LENGTH LIMIT ({$error}). "
                . 'Regenerate the COMPLETE response from scratch, as compactly as the instructions above allow, '
                . 'and return nothing else.';
            return $retry;
        }

        $retry['prompt'] = $prompt
            . "\n\nYOUR PREVIOUS ATTEMPT RETURNED NO USABLE CONTENT ({$error}). "
            . 'Answer again, returning only the content the instructions above describe.';
        return $retry;
    }

    /** @return array<string,mixed> */
    private static function responseRecord(mixed $response, string|int $key): array
    {
        if (is_string($response)) {
            return ['text' => $response];
        }
        if (!is_array($response)) {
            throw new \RuntimeException(
                "text batch response '{$key}' must be a string or a record, got " . get_debug_type($response)
            );
        }
        if (!array_key_exists('text', $response) || !is_string($response['text'])) {
            throw new \RuntimeException("text batch response '{$key}' has no text");
        }
        return $response;
    }

    /** @param array<int|string> $keys */
    private static function keys(array $keys): string
    {
        return implode(', ', array_map(static fn (string|int $key): string => (string) $key, $keys));
    }
}
