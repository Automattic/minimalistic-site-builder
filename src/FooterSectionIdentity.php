<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Classifies generated page-plan sections that duplicate template-owned
 * footer chrome. Identity fields are model-authored and may be localized, so
 * matching uses reviewed whole-word aliases rather than one English spelling
 * or broad substrings that would misclassify words such as "footerless".
 */
final class FooterSectionIdentity
{
    /** @var list<string> */
    private const TOKENS = [
        'footer',
        'rodape',
        'fusszeile',
        'voettekst',
        'stopka',
        'subsol',
    ];

    /** @var list<string> */
    private const EXACT_IDENTITIES = [
        'colophon',
    ];

    /** @var list<list<string>> */
    private const TOKEN_SETS = [
        ['site', 'chrome'],
        ['pie', 'de', 'pagina'],
        ['pied', 'de', 'page'],
        ['pie', 'di', 'pagina'],
        ['peu', 'de', 'pagina'],
        ['orri', 'oina'],
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
        $identity = strtr(mb_strtolower((string) $identity), [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a',
            'ç' => 'c',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ñ' => 'n',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ß' => 'ss',
        ]);
        return preg_split(
            '/[^a-z0-9]+/',
            $identity,
            -1,
            PREG_SPLIT_NO_EMPTY
        ) ?: [];
    }
}
