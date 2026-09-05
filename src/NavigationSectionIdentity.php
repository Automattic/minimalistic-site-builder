<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Classifies generated page-plan sections that duplicate the template-owned
 * site navigation (frm PR-1y). Every assembled page receives the header part
 * with its navigation; the spector brief's "edge-to-edge spread navigation"
 * is that header, and the plan still added a "navigation"/"menu" section
 * on every spector build, delivered as a split of image cards. Matching
 * uses whole English words, so a restaurant "menu" section (type
 * `seasonal-menu`, title "Menu") is content and stays.
 */
final class NavigationSectionIdentity
{
    /** @var list<string> */
    private const TOKENS = ['navigation', 'nav'];

    /** @param array<mixed> $section */
    public static function matches(array $section): bool
    {
        foreach (['slug', 'title', 'type'] as $field) {
            if (array_intersect(self::TOKENS, self::tokens($section[$field] ?? null)) !== []) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private static function tokens(mixed $value): array
    {
        if (!is_scalar($value) || trim((string) $value) === '') {
            return [];
        }
        $identity = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '-', trim((string) $value));
        return preg_split('/[^a-z0-9]+/', strtolower((string) $identity), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
