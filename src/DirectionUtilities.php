<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Direction-owned class tokens that HTML-first convert must not eat.
 * Geometry hashes (`be-inline-geometry-…`) stay the transformer's job;
 * these names are promises from designDirection.json or authored HTML.
 */
final class DirectionUtilities
{
    /**
     * Whether a class token is a direction promise the converter should
     * keep on the serialized block.
     */
    public static function isKeepable(string $token): bool
    {
        if ($token === '' || $token === Motion::STATE_CLASS) {
            return false;
        }
        if (str_starts_with($token, 'device--')
            || str_starts_with($token, 'card-style--')
            || str_starts_with($token, 'u-')
            || $token === 'has-accent-font-family'
        ) {
            return true;
        }
        return in_array($token, Motion::kitClasses(), true);
    }

    /**
     * @return list<string>
     */
    public static function extract(string $html): array
    {
        $tokens = [];
        if (preg_match_all('/\bclass(?:Name)?\s*=\s*"([^"]*)"/', $html, $matches) === 0) {
            return [];
        }
        foreach ($matches[1] as $classList) {
            foreach (preg_split('/\s+/', trim((string) $classList)) ?: [] as $token) {
                if (self::isKeepable($token)) {
                    $tokens[] = $token;
                }
            }
        }
        return array_values(array_unique($tokens));
    }

    /**
     * Put keepable tokens from the source HTML back onto converted markup
     * when the transformer dropped them. Smallest unit: the first non-hero
     * section/group root.
     *
     * @return array{0:string,1:list<string>}
     */
    public static function restore(string $sourceHtml, string $converted, string $file = 'plugin/pages'): array
    {
        $wanted = self::extract($sourceHtml);
        if ($wanted === []) {
            return [$converted, []];
        }

        $missing = [];
        foreach ($wanted as $token) {
            if (!preg_match('/\b' . preg_quote($token, '/') . '\b/', $converted)) {
                $missing[] = $token;
            }
        }
        if ($missing === []) {
            return [$converted, []];
        }

        $stamped = self::stampTokens($converted, $missing);
        if ($stamped === $converted) {
            return [$converted, [
                "file='{$file}'; path=\"className\"; authored="
                . Warnings::value($missing)
                . '; delivered=removed; disposition=convert dropped direction utilities and no section root could take them',
            ]];
        }

        return [$stamped, [
            "file='{$file}'; path=\"className\"; authored=missing; delivered="
            . implode(' ', $missing)
            . '; disposition restored direction utilities the transformer dropped',
        ]];
    }

    /**
     * Source authored a real button that convert flattened into a paragraph.
     */
    public static function buttonLossWarning(string $sourceHtml, string $converted, string $file = 'plugin/pages'): ?string
    {
        $sourceHadButton = preg_match(
            '/<(?:a|button)\b[^>]*(?:wp-element-button|wp-block-button__link|\bbutton\b)[^>]*>/i',
            $sourceHtml,
        ) === 1;
        if (!$sourceHadButton) {
            return null;
        }
        if (preg_match('/<!-- wp:buttons?\b|wp-element-button|wp-block-button/', $converted) === 1) {
            return null;
        }
        return "file='{$file}'; path=\"button\"; authored=wp-element-button; delivered=removed; "
            . 'disposition convert flattened a button and the pre-convert control was not restored';
    }

    /**
     * @param list<string> $tokens
     */
    public static function stampTokens(string $markup, array $tokens): string
    {
        $tokens = array_values(array_filter($tokens, static fn (string $t): bool => $t !== ''));
        if ($tokens === [] || $markup === '') {
            return $markup;
        }
        $addition = implode(' ', $tokens);

        $rewritten = preg_replace_callback(
            '/("className"\s*:\s*")([^"]*)(")/',
            static function (array $match) use ($addition, &$done): string {
                if ($done || str_contains($match[2], 'hero-composition')) {
                    return $match[0];
                }
                $done = true;
                return $match[1] . trim($match[2] . ' ' . $addition) . $match[3];
            },
            $markup,
            1,
        );
        if (is_string($rewritten) && $rewritten !== $markup) {
            return $rewritten;
        }

        $done = false;
        $rewritten = preg_replace_callback(
            '/(<!-- wp:group\s*\{)([^}]*)(\})/',
            static function (array $match) use ($addition, &$done): string {
                if ($done || str_contains($match[2], 'hero-composition')) {
                    return $match[0];
                }
                $done = true;
                if (preg_match('/"className"\s*:\s*"/', $match[2]) === 1) {
                    return $match[0];
                }
                $insert = '"className":"' . $addition . '"';
                $attrs = trim($match[2]);
                if ($attrs !== '') {
                    $insert = $attrs . ',' . $insert;
                }
                return $match[1] . $insert . $match[3];
            },
            $markup,
            1,
        );
        if (is_string($rewritten) && $rewritten !== $markup) {
            return $rewritten;
        }

        $done = false;
        $rewritten = preg_replace_callback(
            '/(<section\b[^>]*\bclass=")([^"]*)(")/i',
            static function (array $match) use ($addition, &$done): string {
                if ($done || str_contains($match[2], 'hero-composition')) {
                    return $match[0];
                }
                $done = true;
                return $match[1] . trim($match[2] . ' ' . $addition) . $match[3];
            },
            $markup,
            1,
        );

        return is_string($rewritten) ? $rewritten : $markup;
    }
}
