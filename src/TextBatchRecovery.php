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
 * batch. The best abnormal candidate is retained, so a shorter response or a
 * failed regeneration cannot destroy useful earlier output. Regeneration
 * calls are isolated per member: the initial fan-out remains concurrent, but
 * one failed retry cannot prevent a successful sibling retry from being
 * logged and accounted by the transport. Degrading one section beats
 * rejecting the entire theme. The transport callback owns the real calls,
 * usage accounting and logging; this orchestrator is pure apart from STDERR
 * notes. A retained abnormal member carries a keyed degradation note in the
 * TextBatchResult. The caller persists that note only when the corresponding
 * normalized output is actually delivered; this layer has no Project to write
 * to and structural salvage is not guaranteed to change balanced partial text.
 */
final class TextBatchRecovery
{
    /**
     * @param array<array-key,array<string,mixed>> $requests
     * @param callable(array<array-key,array<string,mixed>>):array<array-key,array<string,mixed>|string> $send
     * @return TextBatchResult raw text and keyed degradation notes, ordered as the input
     */
    public static function run(
        array $requests,
        callable $send,
        int $maxRetries = 1,
        int $defaultMaxTokens = 16000,
    ): TextBatchResult
    {
        if ($maxRetries < 0) {
            throw new \InvalidArgumentException('maxRetries must be zero or greater');
        }
        if ($defaultMaxTokens < 1) {
            throw new \InvalidArgumentException('defaultMaxTokens must be greater than zero');
        }
        if ($requests === []) {
            return new TextBatchResult([]);
        }

        $texts = [];
        /** @var array<array-key,list<string>> $notes */
        $notes = [];
        /** @var array<array-key,array<string,mixed>> $candidates */
        $candidates = [];
        $pending = $requests;
        $attempt = 0;

        while ($pending !== []) {
            $active = $pending;
            if ($attempt === 0) {
                // Keep the initial fan-out concurrent. There is no prior
                // candidate to retain if this all-or-nothing call fails.
                $responses = self::responseSet($send($pending), $pending);
            } else {
                // A shared retry responseBatch can perform successful sibling
                // calls and then throw on one permanent failure before it logs
                // or accounts any result. Isolate regeneration calls so every
                // successful response reaches the client's accounting path.
                $responses = [];
                foreach ($pending as $key => $_request) {
                    try {
                        $single = $send([$key => $pending[$key]]);
                    } catch (\Throwable $e) {
                        if (!array_key_exists($key, $candidates)) {
                            throw $e;
                        }
                        $texts[$key] = (string) $candidates[$key]['text'];
                        unset($active[$key]);
                        $message = str_replace(["\r", "\n"], ' ', $e->getMessage());
                        $termination = StopReasons::terminationError(
                            $candidates[$key]['stop_reason'] ?? null
                        ) ?? 'the response ended abnormally';
                        $notes[$key][] = self::retainedNote(
                            $key,
                            $attempt,
                            $termination,
                            $message !== '' ? "regeneration failed: {$message}" : 'regeneration failed',
                        );
                        Narrator::write(
                            "    (regeneration failed for batch request '{$key}'"
                                . ($message !== '' ? ": {$message}" : '')
                                . '; keeping the best prior partial response for salvage)' . "\n"
                        );
                        continue;
                    }
                    $single = self::responseSet($single, [$key => $pending[$key]]);
                    $responses[$key] = $single[$key];
                }
            }

            $retry = [];
            foreach ($active as $key => $_request) {
                $response = self::responseRecord($responses[$key], $key);
                $error = StopReasons::terminationError($response['stop_reason'] ?? null);
                if ($error === null) {
                    $texts[$key] = (string) $response['text'];
                    continue;
                }

                self::retainBestCandidate($candidates, $key, $response);
                if ($attempt < $maxRetries) {
                    $retry[$key] = self::regenerateRequest(
                        $key,
                        $requests[$key],
                        $response,
                        $error,
                        $defaultMaxTokens,
                    );
                    continue;
                }

                // Out of retries: keep the best effort instead of aborting.
                // The markup intake (GeneratedMarkup via MarkupSalvage) trims
                // an incomplete response to its last complete block, so a
                // persistent truncation degrades one part, not the build.
                Narrator::write("    (batch request '{$key}' still incomplete after {$attempt} regeneration(s) — "
                    . "{$error}; keeping the best partial response for salvage)\n");
                $texts[$key] = (string) $candidates[$key]['text'];
                $notes[$key][] = self::retainedNote($key, $attempt, $error);
            }

            if ($retry === []) {
                break;
            }

            $attempt++;
            Narrator::write(
                '    (incomplete response in ' . count($retry) . ' batch request(s); regenerating independently: '
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
        return new TextBatchResult($out, $notes);
    }

    private static function retainedNote(
        string|int $key,
        int $attempts,
        string $termination,
        ?string $retryFailure = null,
    ): string {
        $detail = $retryFailure === null ? '' : "; {$retryFailure}";
        return "part '{$key}': model response remained abnormally terminated after {$attempts} regeneration(s) "
            . "({$termination}{$detail}); best partial response retained and normalized partial markup delivered";
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
        int $defaultMaxTokens,
    ): array {
        $label = (string) ($request['log_label'] ?? $key);
        $retry = $request;
        $retry['log_label'] = $label . '-regenerate';
        $prompt = (string) ($request['prompt'] ?? '');

        if (StopReasons::isTruncation($response['stop_reason'] ?? null)) {
            // Twice the explicit budget, or twice the calling client's
            // effective configurable default when the request relied on it.
            $retry['max_tokens'] = isset($request['max_tokens'])
                ? ((int) $request['max_tokens']) * 2
                : $defaultMaxTokens * 2;
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

    /**
     * Retain the safest provider-generic fallback. Once a non-empty truncated
     * payload exists, a later abnormal regeneration cannot prove that it is
     * structurally better, so keep the earlier one. A later candidate only
     * replaces an empty response, or upgrades a refusal/filter to real partial
     * output. A normally terminated regeneration bypasses this fallback and
     * always wins in the main loop.
     *
     * @param array<array-key,array<string,mixed>> $candidates
     * @param array<string,mixed> $candidate
     */
    private static function retainBestCandidate(array &$candidates, string|int $key, array $candidate): void
    {
        if (!array_key_exists($key, $candidates)
            || self::isBetterCandidate($candidate, $candidates[$key])
        ) {
            $candidates[$key] = $candidate;
        }
    }

    /** @param array<string,mixed> $candidate @param array<string,mixed> $current */
    private static function isBetterCandidate(array $candidate, array $current): bool
    {
        $candidateText = trim((string) $candidate['text']);
        if ($candidateText === '') {
            return false;
        }
        if (trim((string) $current['text']) === '') {
            return true;
        }
        return StopReasons::isTruncation($candidate['stop_reason'] ?? null)
            && !StopReasons::isTruncation($current['stop_reason'] ?? null);
    }

    /**
     * Validate one transport result against the exact subset it was sent.
     *
     * @param array<array-key,array<string,mixed>> $expected
     * @return array<array-key,array<string,mixed>|string>
     */
    private static function responseSet(mixed $responses, array $expected): array
    {
        if (!is_array($responses)) {
            throw new \RuntimeException('text batch transport returned a non-array result');
        }
        $unexpected = array_diff_key($responses, $expected);
        if ($unexpected !== []) {
            throw new \RuntimeException(
                'text batch transport returned unexpected key(s): ' . self::keys(array_keys($unexpected))
            );
        }
        foreach ($expected as $key => $_request) {
            if (!array_key_exists($key, $responses)) {
                throw new \RuntimeException("text batch transport omitted request '{$key}'");
            }
        }
        return $responses;
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
