<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * A lone glyph from a script the site's language does not use (frm PR-9b):
 * calderr-like4's about heading shipped as "the long北 light of Amsterdam".
 * The model drops one such character now and then; it is never copy. A run
 * of two or more characters of one script is a word (a place name, a term)
 * and stays, and a site written in a non-Latin script is left alone.
 */
final class StrayGlyph
{
    /** @var list<string> BCP-47 primary subtags whose default script is not Latin. */
    private const NON_LATIN_LANGUAGE_CODES = [
        'ja', 'zh', 'ko', 'ru', 'uk', 'be', 'bg', 'sr', 'mk', 'kk', 'ky', 'mn', 'tg', 'el', 'ar', 'fa', 'ur', 'ps',
        'ug', 'sd', 'ckb', 'he', 'yi', 'th', 'lo', 'km', 'my', 'bo', 'dv', 'hi', 'bn', 'pa', 'gu', 'ta', 'te', 'kn',
        'ml', 'mr', 'ne', 'si', 'as', 'or', 'ka', 'hy', 'am', 'ti',
    ];

    /**
     * One character of a non-Latin script between Latin letters, digits,
     * spaces or punctuation, with no neighbour of its own script. Explicit
     * code-point ranges, not \p{Script}: PCRE2 resolves a script class
     * through script extensions, which puts the middle dot (U+00B7) in Han.
     */
    private const STRAY = '/(?<=[\p{Latin}\p{N}\s\p{P}])'
        . '[\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}\x{1100}-\x{11FF}\x{AC00}-\x{D7AF}\x{0400}-\x{04FF}'
        . '\x{0370}-\x{03FF}\x{0600}-\x{06FF}\x{0590}-\x{05FF}\x{0E00}-\x{0E7F}\x{0900}-\x{097F}\x{0980}-\x{09FF}'
        . '\x{0530}-\x{058F}\x{10A0}-\x{10FF}]'
        . '(?=[\p{Latin}\p{N}\s\p{P}]|$)/u';

    /** Whether the language's default script is Latin; unknown languages are not. */
    public static function latinScript(string $language): bool
    {
        $primary = strtolower(trim((string) explode('-', str_replace('_', '-', trim($language)), 2)[0]));
        return preg_match('/^[a-z]{2,3}$/', $primary) === 1
            && !in_array($primary, self::NON_LATIN_LANGUAGE_CODES, true);
    }

    /**
     * @return array{markup:string,removed:int}
     */
    public static function strip(string $markup, string $language): array
    {
        if (!self::latinScript($language)) {
            return ['markup' => $markup, 'removed' => 0];
        }
        $removed = 0;
        $out = preg_replace_callback('/>([^<]*)</u', static function (array $m) use (&$removed): string {
            $text = preg_replace(self::STRAY, '', $m[1], -1, $count);
            if ($text === null) {
                return $m[0];
            }
            $removed += $count;
            return '>' . $text . '<';
        }, $markup);
        return ['markup' => $out ?? $markup, 'removed' => $removed];
    }
}
