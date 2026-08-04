<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Shared formatting for warnings.json rows (see Project::addWarnings()). */
final class Warnings
{
    /** Compact authored-value evidence for one actionable warnings.json row. */
    public static function value(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            return get_debug_type($value);
        }
        return mb_strlen($encoded) > 160 ? mb_substr($encoded, 0, 157) . '...' : $encoded;
    }
}
