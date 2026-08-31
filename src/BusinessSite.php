<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Whether the brief is clearly a business — storefront, shop, restaurant,
 * salon, agency, and similar — as opposed to a personal portfolio, blog,
 * photography site, or gallery.
 *
 * Matched only as site-kind words (area, topic, site_type, title, name).
 * The prompt is not part of the business decision; it is forwarded to
 * PhotographySite so a studio whose only photographer signal is the prompt
 * is not classified as a business.
 */
final class BusinessSite
{
    /**
     * @param array<mixed> $siteSpec
     */
    public static function matches(array $siteSpec, string $prompt = ''): bool
    {
        if (trim((string) ($siteSpec['persona_name'] ?? '')) !== '') {
            return false;
        }
        if (PhotographySite::matches($siteSpec, $prompt)) {
            return false;
        }

        $kind = strtolower(implode("\n", [
            (string) ($siteSpec['area'] ?? ''),
            (string) ($siteSpec['topic'] ?? ''),
            (string) ($siteSpec['site_type'] ?? ''),
            (string) ($siteSpec['title'] ?? ''),
            (string) ($siteSpec['name'] ?? ''),
        ]));

        return preg_match(
            '/\b(?:business(?:es)?|storefronts?|shops?|stores?|retail(?:ers?)?|restaurants?|cafés?|cafes?|baker(?:y|ies)|bars?|salons?|spas?|clinics?|gyms?|studios?|agenc(?:y|ies)|consultanc(?:y|ies)|firms?|saas|hotels?|boutiques?)\b/u',
            $kind
        ) === 1;
    }
}
