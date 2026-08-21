<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Whether the brief is clearly a photography or gallery site — a
 * photographer, photojournalism portfolio, or an art/exhibition gallery
 * whose product is pictures on a wall.
 *
 * `subject_is_visual_work` is broader (art, food, architecture) and is not
 * used. Photography tokens may appear anywhere in the spec or prompt.
 * Gallery is matched only as a site-kind word (area, topic, site_type,
 * title, name, prompt), so a bakery whose description mentions "a gallery
 * of loaves" stays false.
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
        $haystack = $kind . "\n" . strtolower((string) ($siteSpec['description'] ?? ''));

        if (preg_match(
            '/\bphotograph(?:s|er|ers|y|ic)?\b|\bphotojournalis[mt]\b|\bphotoshoots?\b/u',
            $haystack
        ) === 1) {
            return true;
        }

        return preg_match('/\bgaller(?:y|ies)\b/u', $kind) === 1;
    }
}
