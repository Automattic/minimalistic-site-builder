<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssSyntaxScanner;

/**
 * Blocks-path design CSS owns explicitly authored spacing and minimum height. Core emits
 * constrained-child margins with !important and saved blocks carry inline
 * padding/minimum height, so ordinary appendix declarations otherwise silently lose.
 *
 * Promote those properties only on positively identified design-hook layout subjects.
 * No alignment, dimension, breakpoint or spacing value is invented. Leave
 * unsupported selectors, controls, text, pseudo-elements and all other bytes
 * alone. Run after CSS safety/ownership validation, never on HTML-first CSS.
 */
final class AuthoredLayoutCss
{
    /** @return array{css:string,repairs:list<string>} */
    public static function reconcile(string $css, string $markup): array
    {
        $dom = Html::loadUtf8Html('<html><body>' . $markup . '</body></html>', LIBXML_NONET);
        if ($dom === null) {
            throw new \RuntimeException('Cannot inspect delivered layout subjects');
        }
        $elements = iterator_to_array($dom->getElementsByTagName('*'));
        $eligible = [];
        $repairs = [];
        $declarations = CssChecks::scanDeclarations($css);
        $important = array_filter($declarations, static fn (array $row): bool =>
            $row['kind'] === 'style' && CssChecks::splitDeclarationPriority($row['value'])['important']);
        foreach (array_reverse($declarations) as $declaration) {
            if (!$declaration['structurallySafe'] || $declaration['kind'] !== 'style'
                || preg_match('/\A(?:(?:margin|padding)(?:-(?:top|right|bottom|left|(?:inline|block)(?:-(?:start|end))?))?|min-height)\z/', $declaration['property']) !== 1
                || CssChecks::splitDeclarationPriority($declaration['value'])['important']
            ) {
                continue;
            }
            // Flat CSS and media-scoped flat CSS only; never infer nesting.
            foreach ($declaration['ancestors'] as $ancestor) {
                if (!preg_match('/\A\s*@media\b/i', $ancestor)) {
                    continue 2;
                }
            }
            $selector = $declaration['context'];
            $eligible[$selector] ??= self::layoutSubjects($selector, $elements);
            if (!$eligible[$selector]) {
                continue;
            }
            // Promoting an ordinary shorthand must not erase an intentional
            // important longhand (including one in another matching rule).
            // Conservatively leave that spacing family at its authored
            // priority when such a relationship exists on any shared subject.
            $family = explode('-', $declaration['property'])[0];
            foreach ($important as $other) {
                if (explode('-', $other['property'])[0] !== $family) {
                    continue;
                }
                $eligible[$other['context']] ??= self::layoutSubjects($other['context'], $elements);
                if ($eligible[$other['context']] && array_intersect($eligible[$selector], $eligible[$other['context']])) {
                    continue 2;
                }
            }
            $raw = substr($css, $declaration['start'], $declaration['end'] - $declaration['start']);
            // The scanner's span includes the optional semicolon. Insert ahead
            // of it; don't reconstruct values containing comments/functions.
            $replacement = preg_replace('/(;?)(\s*)\z/', ' !important$1$2', $raw, 1);
            $css = substr_replace($css, $replacement, $declaration['start'], $declaration['end'] - $declaration['start']);
            $repairs[] = $selector . ': ' . $declaration['property'] . ':' . $declaration['value'] . ' => authored spacing priority';
        }
        return ['css' => $css, 'repairs' => array_reverse($repairs)];
    }

    /** @return list<int>|false Every branch must select only delivered design layout blocks. */
    private static function layoutSubjects(string $selector, array $elements): array|false
    {
        $branches = self::branches($selector);
        if ($branches === null) {
            return false;
        }
        $subjects = [];
        foreach ($branches as $branch) {
            $parsed = CssSelectorMatcher::parse(trim($branch));
            if (!$parsed['supported']) {
                return false;
            }
            $subject = $parsed['compounds'][array_key_last($parsed['compounds'])];
            if (!array_filter($subject['classes'], static fn (string $class): bool => str_starts_with($class, 'design-'))) {
                return false;
            }
            $found = false;
            foreach ($elements as $element) {
                $match = CssSelectorMatcher::matches($element, $parsed, true);
                if (!$match['supported']) {
                    return false;
                }
                if (!$match['matches']) {
                    continue;
                }
                $classes = preg_split('/\s+/', $element->getAttribute('class'));
                if (!array_intersect($classes, ['wp-block-group', 'wp-block-columns', 'wp-block-column', 'wp-block-cover'])) {
                    return false;
                }
                $found = true;
                $subjects[] = spl_object_id($element);
            }
            if (!$found) {
                return false;
            }
        }
        return array_values(array_unique($subjects));
    }

    /** @return list<string>|null */
    private static function branches(string $selector): ?array
    {
        $state = CssSyntaxScanner::state();
        $start = 0;
        $branches = [];
        for ($offset = 0; $offset < strlen($selector);) {
            if (CssSyntaxScanner::isTopLevel($state) && $selector[$offset] === ',') {
                $branches[] = substr($selector, $start, $offset - $start);
                $start = ++$offset;
                continue;
            }
            $next = CssSyntaxScanner::consume($selector, $offset, $state);
            if ($next === null) {
                return null;
            }
            $offset = $next;
        }
        if (!CssSyntaxScanner::isComplete($state)) {
            return null;
        }
        $branches[] = substr($selector, $start);
        return $branches;
    }
}
