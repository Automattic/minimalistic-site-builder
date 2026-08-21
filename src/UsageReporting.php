<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Optional cumulative LLM usage surface consumed by evaluation/reporting tools,
 * and by SectionsStep to notice a host that discards cached_prefixes.
 */
interface UsageReporting
{
    /**
     * Cumulative token usage across every request this implementation has made.
     *
     * `input_tokens` is TOTAL BILLED INPUT: it includes tokens read from, and
     * written to, the prompt cache. This is worth stating because providers
     * disagree. The raw Anthropic Messages API reports `usage.input_tokens`
     * with both cache figures EXCLUDED, so a host that passes that field
     * through unchanged under-reports a cached request by the entire size of
     * its cached prefix — see AnthropicClient::extractUsage(), which adds them
     * back in. A host that cannot separate the figures should report whichever
     * total is largest rather than the uncached remainder.
     *
     * Reporting the cache figures separately as well is encouraged; consumers
     * that care about the distinction read them, and SectionsStep uses them to
     * stay quiet about a host that follows the other convention anyway.
     *
     * @return array{
     *     requests:int,
     *     input_tokens:int,
     *     output_tokens:int,
     *     total_tokens:int,
     *     cache_read_input_tokens?:int,
     *     cache_creation_input_tokens?:int
     * }
     */
    public function usageTotals(): array;
}
