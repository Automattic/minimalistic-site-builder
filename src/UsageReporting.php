<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Optional cumulative LLM usage surface consumed by evaluation/reporting tools. */
interface UsageReporting
{
    /**
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
