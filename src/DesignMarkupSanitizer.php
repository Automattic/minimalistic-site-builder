<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Shared trust boundary for untrusted design HTML.
 *
 * The implementation lives behind this frozen facade so every design step uses
 * one hardened sanitizer without widening the public contract.
 */
final class DesignMarkupSanitizer
{
    /**
     * @param list<string> $warnings
     */
    public static function sanitize(
        string $html,
        string $path,
        string $context,
        array &$warnings,
    ): string {
        return DesignMarkupSanitizerEngine::sanitize(
            $html,
            $path,
            $context,
            $warnings,
        );
    }
}
