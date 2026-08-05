<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Shared plumbing for the model-CSS validators (page-styles and custom-motion):
 * the checks that keep generated CSS safe to append to style.css. Each step
 * supplies only its policy — which at-rules are allowed, which selectors count
 * as scoped — and its own problem wording where the rulesets differ.
 * All methods are pure — unit-testable. Callers strip comments first.
 */
final class CssChecks
{
    /**
     * Whether the braces balance. Walks the depth instead of comparing
     * totals: a leading stray `}` balanced by a trailing open brace nets to
     * zero but still escapes whatever rule or wrapper the CSS is concatenated
     * into.
     */
    public static function braceDepthBalanced(string $css): bool
    {
        $depth = 0;
        foreach (str_split($css) as $char) {
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}' && --$depth < 0) {
                return false;
            }
        }
        return $depth === 0;
    }

    /**
     * The problem string when the CSS uses a resource-loading value form, or
     * null when clean. url() is not the only one: image-set("…"), image("…"),
     * cross-fade() and friends fetch too (including with vendor prefixes,
     * which is why the match is a bare substring).
     */
    public static function resourceLoadingProblem(string $css): ?string
    {
        if (preg_match('/(?:image-set|cross-fade|element|paint|url|src|image)\s*\(/i', $css) === 1) {
            return 'resource-loading CSS functions (url(), image-set(), image(), cross-fade(), …) are not allowed';
        }
        return null;
    }

    /**
     * display:none / visibility:hidden anywhere in the CSS — generated
     * content must stay visible.
     *
     * @return string[]
     */
    public static function hiddenContentProblems(string $css): array
    {
        $problems = [];
        if (preg_match('/(?<![-\w])display\s*:\s*none(?:\s*!important)?\s*(?=;|}|$)/i', $css) === 1) {
            $problems[] = 'display:none hides generated content';
        }
        if (preg_match('/(?<![-\w])visibility\s*:\s*hidden(?:\s*!important)?\s*(?=;|}|$)/i', $css) === 1) {
            $problems[] = 'visibility:hidden hides generated content';
        }
        return $problems;
    }

    /**
     * One problem per at-rule used outside the allowlist.
     *
     * @param string[] $allowed lowercase at-rule names
     * @return string[]
     */
    public static function disallowedAtRules(string $css, array $allowed): array
    {
        $problems = [];
        if (preg_match_all('/@([a-zA-Z-]+)/', $css, $atRules) > 0) {
            foreach (array_unique($atRules[1]) as $at) {
                if (!in_array(strtolower($at), $allowed, true)) {
                    $problems[] = "disallowed at-rule: @{$at}";
                }
            }
        }
        return $problems;
    }

    /**
     * The style-rule selectors (split on commas) the predicate rejects.
     * @media preludes are dropped first so only rule selectors precede a '{';
     * the stray closing braces that leaves behind don't affect the match.
     *
     * @param callable(string): bool $isAllowed
     * @return string[]
     */
    public static function unscopedSelectors(string $css, callable $isAllowed): array
    {
        $unscoped = [];
        $rules = (string) preg_replace('/@media[^{]*\{/i', '', $css);
        if (preg_match_all('/(?:^|[{}])\s*([^{};]+?)\s*\{/s', $rules, $m) > 0) {
            foreach ($m[1] as $selectorList) {
                foreach (explode(',', $selectorList) as $selector) {
                    $selector = trim($selector);
                    if (!$isAllowed($selector)) {
                        $unscoped[] = $selector;
                    }
                }
            }
        }
        return $unscoped;
    }
}
