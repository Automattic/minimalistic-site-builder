<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Normalize one analyze-url/describe response into a reference, or reject it.
 *
 * The gate is positive-evidence rather than a status check: the endpoint
 * returns HTTP 200 with an {error:...} body when its screenshot fails, and
 * returns a confident description of a "Generating Preview" placeholder when
 * the capture was not ready. Requiring usable content closes both, plus
 * whatever third failure shape the endpoint grows next.
 */
final class InspirationBrief
{
    /** Enum values the endpoint's schema declares. Anything else is dropped. */
    private const PAGE_TYPES = ['blog', 'form', 'store', 'login', 'about', 'contact', 'other'];
    private const OWNER_TYPES = ['business', 'individual', 'organization', 'non-profit', 'other'];
    private const COLOR_ROLES = ['background', 'text', 'link', 'accent', 'other'];
    private const MAX_COLORS = 8;
    private const MAX_SECTIONS = 12;

    /** Phrases that mark a description of mShots' placeholder rather than a site. */
    private const PLACEHOLDER_MARKERS = [
        'generating preview', 'generating a preview', 'preview is being generated',
        'loading screen', 'placeholder image', 'still loading', 'no content is visible',
    ];

    /** A mood list longer than this is padding, not signal. */
    private const MAX_MOOD = 5;

    /**
     * typography, layout and mood come only from an analyzer whose schema has
     * a field for them, so they normalize to empty rather than being required.
     *
     * @param  array<mixed> $decoded the JSON-decoded response body
     * @return array{url:string,page_type:string,owner_type:string,style:string,
     *         typography:string,layout:string,mood:list<string>,
     *         colors:list<array{hex:string,name:string,role:string}>,
     *         sections:list<array{category:string,description:string}>}|null
     */
    public static function fromResponse(string $url, array $decoded): ?array
    {
        if (array_key_exists('error', $decoded)) {
            return null;
        }

        $style = self::text($decoded['style'] ?? null);
        if (self::looksLikePlaceholder($style)) {
            return null;
        }

        $colors = self::colors($decoded['colors'] ?? null);
        $sections = self::sections($decoded['sections'] ?? null);
        if ($colors === [] && $sections === []) {
            return null;
        }

        return [
            'url' => $url,
            'page_type' => self::enum($decoded['page_type'] ?? null, self::PAGE_TYPES),
            'owner_type' => self::enum($decoded['owner_type'] ?? null, self::OWNER_TYPES),
            'style' => $style,
            'typography' => self::text($decoded['typography'] ?? null),
            'layout' => self::text($decoded['layout'] ?? null),
            'mood' => self::mood($decoded['mood'] ?? null),
            'colors' => $colors,
            'sections' => $sections,
        ];
    }

    /** @return list<string> */
    private static function mood(mixed $raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $entry) {
            $word = self::text($entry);
            if ($word === '') {
                continue;
            }
            $out[] = $word;
            if (count($out) >= self::MAX_MOOD) {
                break;
            }
        }
        return $out;
    }

    /** @param array<mixed> $decoded the JSON-decoded response body */
    public static function rejectionReason(array $decoded): string
    {
        if (array_key_exists('error', $decoded)) {
            $error = $decoded['error'];
            $message = is_array($error)
                ? self::text($error['message'] ?? null)
                : self::text($error);
            return $message === ''
                ? 'endpoint response contained an error'
                : 'endpoint error: ' . $message;
        }

        $style = self::text($decoded['style'] ?? null);
        if (self::looksLikePlaceholder($style)) {
            return 'response described the mShots placeholder';
        }

        if (self::colors($decoded['colors'] ?? null) === []
            && self::sections($decoded['sections'] ?? null) === []) {
            return 'response contained neither usable colors nor sections';
        }

        return 'response passed the positive-evidence gate';
    }

    private static function looksLikePlaceholder(string $style): bool
    {
        $haystack = strtolower($style);
        foreach (self::PLACEHOLDER_MARKERS as $marker) {
            if (str_contains($haystack, $marker)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<array{hex:string,name:string,role:string}> */
    private static function colors(mixed $raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $hex = self::text($entry['hex'] ?? null);
            if (!preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $hex)) {
                continue;
            }
            $out[] = [
                'hex' => strtolower($hex),
                'name' => self::text($entry['name'] ?? null),
                'role' => self::enum($entry['role'] ?? null, self::COLOR_ROLES),
            ];
            if (count($out) >= self::MAX_COLORS) {
                break;
            }
        }
        return $out;
    }

    /** @return list<array{category:string,description:string}> */
    private static function sections(mixed $raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $category = self::text($entry['category'] ?? null);
            $description = self::text($entry['description'] ?? null);
            if ($category === '' || $description === '') {
                continue;
            }
            $out[] = ['category' => $category, 'description' => $description];
            if (count($out) >= self::MAX_SECTIONS) {
                break;
            }
        }
        return $out;
    }

    /** @param list<string> $allowed */
    private static function enum(mixed $raw, array $allowed): string
    {
        $value = strtolower(self::text($raw));
        return in_array($value, $allowed, true) ? $value : '';
    }

    private static function text(mixed $raw): string
    {
        return is_string($raw) ? trim($raw) : '';
    }
}
