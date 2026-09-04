<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Whether the brief is clearly a photography or gallery site — a
 * photographer, photojournalism portfolio, or an art/exhibition gallery
 * whose product is pictures on a wall.
 *
 * `subject_is_visual_work` is broader (art, food, architecture) and is not
 * used. Photography and gallery are both matched only as site-kind words
 * (area, topic, site_type, title, name, prompt). A bakery whose description
 * mentions "warm photography of the pastry case" or "a gallery of loaves"
 * stays false. The image-style adjective `photographic` (photographic
 * imagery, photographic in style) is not a site-kind word.
 */
final class PhotographySite
{
    /**
     * @param array<mixed> $siteSpec
     */
    public static function matches(array $siteSpec, string $prompt = ''): bool
    {
        $kind = strtolower(implode("\n", [
            (string) ($siteSpec['area'] ?? ''),
            (string) ($siteSpec['topic'] ?? ''),
            (string) ($siteSpec['site_type'] ?? ''),
            (string) ($siteSpec['title'] ?? ''),
            (string) ($siteSpec['name'] ?? ''),
            $prompt,
        ]));

        if (preg_match(
            '/\bphotograph(?:s|er|ers|y)?\b|\bphotojournalis[mt]\b|\bphotoshoots?\b/u',
            $kind
        ) === 1) {
            return true;
        }

        return preg_match('/\bgaller(?:y|ies)\b/u', $kind) === 1;
    }
}
