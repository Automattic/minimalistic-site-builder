<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Classifies generated page-plan sections that duplicate template-owned
 * footer chrome. The page-plan prompt commits `slug` and `type` to English
 * identifiers, so matching uses whole English words rather than broad
 * substrings that would misclassify words such as "footerless".
 */
final class FooterSectionIdentity
{
    /** @var list<string> */
    private const TOKENS = [
        'footer',
    ];

    /** @var list<string> */
    private const EXACT_IDENTITIES = [
        'colophon',
    ];

    /** @var list<list<string>> */
    private const TOKEN_SETS = [
        ['site', 'chrome'],
    ];

    /** @param array<mixed> $section */
    public static function matches(array $section): bool
    {
        foreach (['slug', 'title', 'type'] as $field) {
            $tokens = self::tokens($section[$field] ?? null);
            if (array_intersect(self::TOKENS, $tokens) !== []) {
                return true;
            }
            $identity = implode('-', $tokens);
            if (in_array($identity, self::EXACT_IDENTITIES, true)) {
                return true;
            }
            foreach (self::TOKEN_SETS as $required) {
                if (array_diff($required, $tokens) === []) {
                    return true;
                }
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
        return preg_split(
            '/[^a-z0-9]+/',
            strtolower((string) $identity),
            -1,
            PREG_SPLIT_NO_EMPTY
        ) ?: [];
    }
}
