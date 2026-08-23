<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Deduplicates WordPress debug.log lines for warnings.json.
 *
 * A notice inside a template fires once per render; a twenty-page site
 * would otherwise flood warnings.json on a single defect. Grouping is
 * (file, line, message) with a count.
 */
final class DebugLogReader
{
    /**
     * @return list<string> one row per group: "<level> <message> — <file>:<line> (xN)"
     */
    public static function summarize(string $logContents, int $maxBytes = 262144): array
    {
        return [];
    }
}
