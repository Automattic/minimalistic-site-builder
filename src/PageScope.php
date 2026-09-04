<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * The class WordPress puts on `<body>` so a delivered page can be told apart
 * from its siblings in CSS.
 *
 * A design page is `site.css` plus that page's own `<style data-page-css>`
 * chunk. Delivering every chunk in one global stylesheet lets one page's
 * element rules restyle every other page, so `page-styles` scopes each chunk
 * with this class and `finalize-theme` publishes it through `body_class`.
 * The key is the page slug, which is also the `post_name` the content plugin
 * creates the page with.
 */
final class PageScope
{
    public const CLASS_PREFIX = 'blocks-engine-page-';
    public const EDITOR_WRAPPER_CLASS = 'editor-styles-wrapper';

    public static function bodyClass(string $pageSlug): string
    {
        return self::CLASS_PREFIX . ProjectStore::slugify($pageSlug);
    }

    /**
     * Page slugs that already have a scoped rule in a delivered stylesheet.
     *
     * @return list<string>
     */
    public static function slugsIn(string $css): array
    {
        if (preg_match_all(
            '/\.' . preg_quote(self::CLASS_PREFIX, '/') . '([A-Za-z0-9_-]+)/',
            $css,
            $matches,
        ) === false) {
            return [];
        }
        $slugs = array_values(array_unique($matches[1]));
        sort($slugs, SORT_STRING);
        return $slugs;
    }

    /**
     * The rules from $css that apply to one page, rewritten so they match the
     * block editor canvas (`:where(.editor-styles-wrapper)`) instead of the
     * front-end body class. Empty when that page has no scoped rules.
     */
    public static function editorCss(string $css, string $pageSlug): string
    {
        $class = self::bodyClass($pageSlug);
        return self::filterAndRewrite($css, $class);
    }

    private static function filterAndRewrite(string $css, string $class): string
    {
        $length = strlen($css);
        $offset = 0;
        $out = '';
        while ($offset < $length) {
            $open = self::findTopLevel($css, $offset, '{');
            $semi = self::findTopLevel($css, $offset, ';');
            if ($open === null && $semi === null) {
                break;
            }
            if ($semi !== null && ($open === null || $semi < $open)) {
                $offset = $semi + 1;
                continue;
            }
            $prelude = substr($css, $offset, $open - $offset);
            $close = self::matchingBrace($css, $open);
            if ($close === null) {
                break;
            }
            $body = substr($css, $open + 1, $close - $open - 1);
            $offset = $close + 1;
            $at = self::atRuleName($prelude);
            if ($at !== null) {
                if (!in_array($at, ['media', 'supports', 'container', 'layer'], true)) {
                    continue;
                }
                $inner = self::filterAndRewrite($body, $class);
                if (trim($inner) !== '') {
                    $out .= rtrim($prelude) . '{' . $inner . '}';
                }
                continue;
            }
            $kept = [];
            foreach (self::splitSelectorList($prelude) as $branch) {
                if (self::selectorMentionsClass($branch, $class)) {
                    $kept[] = self::rewriteSelector($branch, $class);
                }
            }
            if ($kept !== []) {
                $out .= implode(',', $kept) . '{' . $body . '}';
            }
        }
        return $out;
    }

    private static function selectorMentionsClass(string $selector, string $class): bool
    {
        return preg_match(
            '/(?:^|[^A-Za-z0-9_-])\.' . preg_quote($class, '/') . '(?:[^A-Za-z0-9_-]|$)/',
            $selector,
        ) === 1;
    }

    private static function rewriteSelector(string $selector, string $class): string
    {
        $prefix = preg_quote(self::CLASS_PREFIX, '/');
        $rewritten = preg_replace_callback(
            '/:where\(\s*\.' . $prefix . '[A-Za-z0-9_-]+(?:\s*,\s*\.' . $prefix . '[A-Za-z0-9_-]+)*\s*\)/',
            static function (array $match) use ($class): string {
                if (!self::selectorMentionsClass($match[0], $class)) {
                    return $match[0];
                }
                return ':where(.' . self::EDITOR_WRAPPER_CLASS . ')';
            },
            $selector,
        );
        return $rewritten ?? $selector;
    }

    /** @return list<string> */
    private static function splitSelectorList(string $prelude): array
    {
        $parts = [];
        $buf = '';
        $length = strlen($prelude);
        $offset = 0;
        $depth = 0;
        while ($offset < $length) {
            $skipped = self::skipCommentOrString($prelude, $offset);
            if ($skipped !== null) {
                $buf .= substr($prelude, $offset, $skipped - $offset);
                $offset = $skipped;
                continue;
            }
            $byte = $prelude[$offset];
            if ($byte === '(') {
                $depth++;
            } elseif ($byte === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($byte === ',' && $depth === 0) {
                $parts[] = $buf;
                $buf = '';
                $offset++;
                continue;
            }
            $buf .= $byte;
            $offset++;
        }
        $parts[] = $buf;
        return $parts;
    }

    private static function atRuleName(string $prelude): ?string
    {
        if (preg_match('/^\s*@([A-Za-z][A-Za-z0-9-]*)/', $prelude, $match) !== 1) {
            return null;
        }
        return strtolower($match[1]);
    }

    private static function findTopLevel(string $css, int $offset, string $needle): ?int
    {
        $length = strlen($css);
        $depth = 0;
        while ($offset < $length) {
            $skipped = self::skipCommentOrString($css, $offset);
            if ($skipped !== null) {
                $offset = $skipped;
                continue;
            }
            $byte = $css[$offset];
            if ($byte === '(') {
                $depth++;
            } elseif ($byte === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0 && $byte === $needle) {
                return $offset;
            }
            $offset++;
        }
        return null;
    }

    private static function matchingBrace(string $css, int $open): ?int
    {
        $length = strlen($css);
        $depth = 0;
        $offset = $open;
        while ($offset < $length) {
            $skipped = self::skipCommentOrString($css, $offset);
            if ($skipped !== null) {
                $offset = $skipped;
                continue;
            }
            $byte = $css[$offset];
            if ($byte === '{') {
                $depth++;
            } elseif ($byte === '}') {
                $depth--;
                if ($depth === 0) {
                    return $offset;
                }
            }
            $offset++;
        }
        return null;
    }

    private static function skipCommentOrString(string $css, int $offset): ?int
    {
        $length = strlen($css);
        if ($offset >= $length) {
            return null;
        }
        if ($css[$offset] === '/' && ($css[$offset + 1] ?? '') === '*') {
            $end = strpos($css, '*/', $offset + 2);
            return $end === false ? $length : $end + 2;
        }
        $quote = $css[$offset];
        if ($quote !== '"' && $quote !== "'") {
            return null;
        }
        $offset++;
        while ($offset < $length) {
            if ($css[$offset] === '\\') {
                $offset += 2;
                continue;
            }
            if ($css[$offset] === $quote) {
                return $offset + 1;
            }
            $offset++;
        }
        return $length;
    }
}
