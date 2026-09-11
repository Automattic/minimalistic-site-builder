<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Image request totals include retries and failed transfers. */
interface ImageUsageReporting
{
    /** @return array<string,mixed> */
    public function imageUsageTotals(): array;
}
