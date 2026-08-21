<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Reading "did the host actually send the cached layers?" out of usage totals.
 *
 * Two places ask that question — SectionsStep's warm-up probe at runtime, and
 * LlmConformance's usage probe in CI — and they must answer it identically. It
 * is one measurement with two callers, not two heuristics that happen to look
 * alike; the moment their thresholds drift, a host can pass the CI gate and
 * still trip the runtime warning, or worse, the other way round.
 *
 * The subtle half is what `input_tokens` means. AnthropicClient folds cache
 * reads and creations INTO it (see extractUsage()), while the raw Anthropic
 * Messages API reports `usage.input_tokens` with both EXCLUDED. Under that
 * second convention a perfectly conformant host bills a cached 2,400-token
 * prefix almost entirely as `cache_creation_input_tokens` on the first call and
 * as `cache_read_input_tokens` on every call after it — leaving an
 * `input_tokens` delta that looks exactly like a discarded layer, and shrinking
 * further the better the caching works. Reading the raw field alone therefore
 * accuses the hosts that are doing it right.
 */
final class BilledInput
{
    /**
     * Fraction of the estimated prefix a conformant host must bill.
     *
     * Set low on purpose: a host that sent the layers bills MORE than the
     * estimate (the system preamble and the varying prompt are extra), while a
     * host that dropped them bills a rounding error. Only a collapse trips it,
     * so tokenizer differences between providers can never make it flaky.
     */
    public const DISCARD_RATIO = 0.5;

    /**
     * Input tokens a host billed for a single call, from cumulative usage
     * snapshots taken either side of it.
     *
     * Taking the larger of the two readings satisfies both conventions above
     * and double-counts neither: a folded total already exceeds its own cache
     * components, and a raw total is corrected by them.
     *
     * The totals are cumulative and per-client, so this reads as one call's
     * usage only while nothing else is spending on the same Llm.
     *
     * @param array<string,mixed> $before cumulative totals before the call
     * @param array<string,mixed> $after  cumulative totals after it
     */
    public static function delta(array $before, array $after): int
    {
        $delta = static fn (string $key): int
            => (int) ($after[$key] ?? 0) - (int) ($before[$key] ?? 0);

        return max(
            $delta('input_tokens'),
            $delta('cache_read_input_tokens') + $delta('cache_creation_input_tokens'),
        );
    }

    /**
     * Tokens a set of cached layers should be worth.
     *
     * ~4 bytes per token is a deliberate under-estimate of the real count,
     * which biases every comparison that uses it toward staying silent.
     *
     * @param list<string> $prefixes
     */
    public static function estimateTokens(array $prefixes): int
    {
        $bytes = 0;
        foreach ($prefixes as $prefix) {
            $bytes += strlen($prefix);
        }
        return intdiv($bytes, 4);
    }

    /**
     * Did the host bill so little that the layers cannot have been sent?
     *
     * Callers must gate on the layers being big enough to distinguish from
     * ordinary system-prompt overhead before trusting this.
     */
    public static function looksDiscarded(int $expectedTokens, int $observedTokens): bool
    {
        return $observedTokens < (int) ($expectedTokens * self::DISCARD_RATIO);
    }
}
