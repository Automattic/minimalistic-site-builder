<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Public CSS-token extraction contract.
 *
 * Frozen facade: implementation lives behind this boundary so the shared
 * signature and shaped return contract remain read-only after Phase 1.
 */
final class CssTokenExtractor
{
    /**
     * @return array{
     *     palette: list<array{color:string,count:int}>,
     *     fonts: list<string>,
     *     spacing: list<string>
     * }
     */
    public static function extract(string $css): array
    {
        return CssTokenExtractorEngine::extract($css);
    }
}
