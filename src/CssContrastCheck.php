<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Public CSS contrast-check contract.
 *
 * Frozen facade: inspection stays pure, while implementation and effectful
 * adjustment live behind separate boundaries.
 */
final class CssContrastCheck
{
    /**
     * @return list<array{
     *     selector: string,
     *     status: string,
     *     fg: ?string,
     *     bg: ?string,
     *     ratio: ?float,
     *     suggested: ?string
     * }>
     */
    public static function check(string $css, string $markup, float $normalText = ContrastMath::NORMAL_TEXT): array
    {
        return CssContrastCheckEngine::check($css, $markup, $normalText);
    }
}
