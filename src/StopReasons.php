<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Provider-generic stop-reason vocabularies and predicates — the single home
 * for classifying how a generation terminated. Providers report abnormal
 * termination in their own words (Anthropic `max_tokens`/`refusal`, OpenAI
 * `length`/`content_filter`, …); the clients normalize onto this shared
 * vocabulary and every recovery layer (JsonBatchRecovery, TextBatchRecovery,
 * the clients' single-request policies) classifies through these predicates,
 * so the sets cannot drift apart per consumer. Pure.
 */
final class StopReasons
{
    /**
     * Stop reasons that mean the response hit the request's own OUTPUT token
     * budget — recoverable by regenerating with a larger max_tokens. A subset
     * of TRUNCATION: `model_context_window_exceeded` is deliberately excluded
     * because no output budget can repair an oversized input, and cache-warm
     * probes (which intentionally use a one-token budget) must not treat it
     * as their expected termination.
     */
    public const OUTPUT_LIMIT = ['max_tokens', 'length'];

    /** Stop reasons that mean the response ran out of token budget. */
    public const TRUNCATION = ['max_tokens', 'length', 'model_context_window_exceeded'];

    /** Stop reasons that mean the provider declined to answer. */
    public const REFUSAL = ['refusal', 'content_filter', 'safety'];

    /** Whether a provider stop reason means the request's output budget ran out. */
    public static function isOutputLimit(mixed $reason): bool
    {
        return self::matches($reason, self::OUTPUT_LIMIT);
    }

    /** Whether a provider stop reason means the response ran out of token budget. */
    public static function isTruncation(mixed $reason): bool
    {
        return self::matches($reason, self::TRUNCATION);
    }

    /** Whether a provider stop reason means the provider declined to answer. */
    public static function isRefusal(mixed $reason): bool
    {
        return self::matches($reason, self::REFUSAL);
    }

    /**
     * Classify provider stop reasons that mean a response is incomplete,
     * as a human-readable error, or null for a normal termination.
     */
    public static function terminationError(mixed $reason): ?string
    {
        $reason = is_string($reason) ? trim($reason) : '';
        if (self::isTruncation($reason)) {
            return "generation was truncated (stop reason: {$reason})";
        }
        if (self::isRefusal($reason)) {
            return "generation was refused or filtered (stop reason: {$reason})";
        }
        return null;
    }

    /** @param list<string> $vocabulary */
    private static function matches(mixed $reason, array $vocabulary): bool
    {
        return is_string($reason) && in_array(trim($reason), $vocabulary, true);
    }
}
