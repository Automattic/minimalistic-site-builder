<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssSyntaxScanner;
use DOMDocument;
use DOMElement;
use Throwable;

/** Internal implementation behind the frozen, pure CssContrastCheck facade. */
final class CssContrastCheckEngine
{
    /**
     * @return list<array{
     *     selector: string,
     *     status: string,
     *     fg: ?string,
     *     bg: ?string,
     *     ratio: ?float,
     *     suggested: ?string
     * }>
     */
    public static function check(string $css, string $markup): array
    {
        try {
            $rules = self::rules($css);
            $elements = self::elements($markup);
            $findings = [];
            foreach ($rules as $rule) {
                $findings[] = self::finding($rule, $elements);
            }
            return $findings;
        } catch (Throwable) {
            return self::fallbackFindings($css);
        }
    }

    /** @return array{css:string,replaced:bool} */
    public static function adjustOne(string $css, string $selector, string $authored, string $suggested): array
    {
        try {
            foreach (self::rules($css) as $rule) {
                if ($rule['selector'] !== $selector) {
                    continue;
                }
                $color = self::declaration($rule, ['color']);
                if ($color === null || $color['value'] !== $authored) {
                    continue;
                }
                return [
                    'css' => substr($css, 0, $color['value_start'])
                        . $suggested
                        . substr($css, $color['value_end']),
                    'replaced' => true,
                ];
            }
        } catch (Throwable) {
            // Generated CSS that cannot be transformed is delivered unchanged.
        }
        return ['css' => $css, 'replaced' => false];
    }

    public static function authoredContext(string $css, string $selector): string
    {
        try {
            foreach (self::rules($css) as $rule) {
                if ($rule['selector'] !== $selector) {
                    continue;
                }
                $fg = self::declaration($rule, ['color']);
                $bg = self::declaration($rule, ['background', 'background-color']);
                return sprintf(
                    'color=%s;background=%s',
                    $fg['value'] ?? 'unresolved',
                    $bg['value'] ?? 'unresolved',
                );
            }
        } catch (Throwable) {
            // Warning still identifies file and selector when parsing failed.
        }
        return 'unresolved';
    }

    /**
     * @param array{selector:string,complete:bool,declarations:list<array{property:string,value:string,value_start:int,value_end:int}>} $rule
     * @param list<DOMElement> $elements
     * @return array{selector:string,status:string,fg:?string,bg:?string,ratio:?float,suggested:?string}
     */
    private static function finding(array $rule, array $elements): array
    {
        $unverified = self::unverified($rule['selector']);
        if (!$rule['complete']) {
            return $unverified;
        }
        $parsedSelector = CssSelectorMatcher::parse($rule['selector']);
        if (!self::isSupportedSelector($parsedSelector)) {
            return $unverified;
        }

        $matched = false;
        foreach ($elements as $element) {
            $result = CssSelectorMatcher::matches($element, $parsedSelector);
            if (!($result['supported'] ?? false)) {
                return $unverified;
            }
            if ($result['matches'] ?? false) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            return $unverified;
        }

        $fgDeclaration = self::declaration($rule, ['color']);
        $bgDeclaration = self::declaration($rule, ['background', 'background-color']);
        if ($fgDeclaration === null || $bgDeclaration === null) {
            return $unverified;
        }
        $fg = self::color($fgDeclaration['value']);
        $bg = self::color($bgDeclaration['value']);
        if ($fg === null || $bg === null || $bg['alpha'] < 1.0) {
            return $unverified;
        }

        $renderedFg = ContrastMath::compositeOver($fg['rgb'], $fg['alpha'], $bg['rgb']);
        $ratio = ContrastMath::ratio($renderedFg, $bg['rgb']);
        if ($ratio >= ContrastMath::NORMAL_TEXT) {
            return [
                'selector' => $rule['selector'],
                'status' => 'pass',
                'fg' => $fgDeclaration['value'],
                'bg' => $bgDeclaration['value'],
                'ratio' => $ratio,
                'suggested' => null,
            ];
        }

        return [
            'selector' => $rule['selector'],
            'status' => 'fail',
            'fg' => $fgDeclaration['value'],
            'bg' => $bgDeclaration['value'],
            'ratio' => $ratio,
            'suggested' => self::nudge($fg['rgb'], $fg['alpha'], $bg['rgb']),
        ];
    }

    /**
     * Narrower than the vendored matcher's full domain by design: one
     * class-only compound, or two class-only compounds joined by direct child.
     *
     * @param array<string,mixed> $parsed
     */
    private static function isSupportedSelector(array $parsed): bool
    {
        if (!($parsed['supported'] ?? false) || ($parsed['pseudo_state_suffix_span'] ?? null) !== null) {
            return false;
        }
        $compounds = $parsed['compounds'] ?? [];
        $combinators = $parsed['combinators'] ?? [];
        if (!(count($compounds) === 1 && $combinators === [])
            && !(count($compounds) === 2 && $combinators === ['>'])) {
            return false;
        }
        foreach ($compounds as $compound) {
            if (($compound['type'] ?? null) !== null
                || ($compound['universal'] ?? false)
                || ($compound['classes'] ?? []) === []
                || ($compound['ids'] ?? []) !== []
                || ($compound['attributes'] ?? []) !== []
                || ($compound['nth_child'] ?? null) !== null
                || ($compound['first_child'] ?? false)
                || ($compound['last_child'] ?? false)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array{selector:string,complete:bool,declarations:list<array{property:string,value:string,value_start:int,value_end:int}>} $rule
     * @param list<string> $properties
     * @return array{property:string,value:string,value_start:int,value_end:int}|null
     */
    private static function declaration(array $rule, array $properties): ?array
    {
        for ($index = count($rule['declarations']) - 1; $index >= 0; $index--) {
            if (in_array($rule['declarations'][$index]['property'], $properties, true)) {
                return $rule['declarations'][$index];
            }
        }
        return null;
    }

    /** @return array{rgb:array{0:int,1:int,2:int},alpha:float}|null */
    private static function color(string $value): ?array
    {
        if (!preg_match('/^(?:#[0-9a-f]{3}(?:[0-9a-f]{3})?|rgba?\([^)]*\))$/i', $value)) {
            return null;
        }
        $colors = ContrastMath::parseCssColors($value);
        if (count($colors) !== 1) {
            return null;
        }
        return $colors[0];
    }

    /**
     * @param array{0:int,1:int,2:int} $fg
     * @param array{0:int,1:int,2:int} $bg
     */
    private static function nudge(array $fg, float $alpha, array $bg): string
    {
        $black = self::firstPassingNudge($fg, $alpha, $bg, [0, 0, 0]);
        $white = self::firstPassingNudge($fg, $alpha, $bg, [255, 255, 255]);
        $winner = $black['step'] <= $white['step'] ? $black : $white;
        if ($winner['step'] === PHP_INT_MAX) {
            $rendered = ContrastMath::compositeOver($fg, $alpha, $bg);
            $black = self::firstPassingNudge($rendered, 1.0, $bg, [0, 0, 0]);
            $white = self::firstPassingNudge($rendered, 1.0, $bg, [255, 255, 255]);
            $winner = $black['step'] <= $white['step'] ? $black : $white;
            $alpha = 1.0;
        }
        $rgb = $winner['rgb'];
        if ($alpha < 1.0) {
            $alphaText = rtrim(rtrim(sprintf('%.6F', $alpha), '0'), '.');
            return sprintf('rgba(%d, %d, %d, %s)', $rgb[0], $rgb[1], $rgb[2], $alphaText);
        }
        return sprintf('#%02X%02X%02X', $rgb[0], $rgb[1], $rgb[2]);
    }

    /**
     * @param array{0:int,1:int,2:int} $fg
     * @param array{0:int,1:int,2:int} $bg
     * @param array{0:int,1:int,2:int} $target
     * @return array{step:int,rgb:array{0:int,1:int,2:int}}
     */
    private static function firstPassingNudge(array $fg, float $alpha, array $bg, array $target): array
    {
        for ($step = 1; $step <= 255; $step++) {
            $candidate = [];
            foreach ([0, 1, 2] as $channel) {
                $candidate[$channel] = (int) round(
                    $fg[$channel] + ($target[$channel] - $fg[$channel]) * ($step / 255),
                );
            }
            $rendered = ContrastMath::compositeOver($candidate, $alpha, $bg);
            if (ContrastMath::ratio($rendered, $bg) >= ContrastMath::NORMAL_TEXT) {
                return ['step' => $step, 'rgb' => $candidate];
            }
        }
        return ['step' => PHP_INT_MAX, 'rgb' => $target];
    }

    /** @return list<DOMElement> */
    private static function elements(string $markup): array
    {
        if (!class_exists(DOMDocument::class)) {
            return [];
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            if (!$document->loadHTML(
                '<div data-css-contrast-root="">' . $markup . '</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
            )) {
                return [];
            }
            $elements = [];
            foreach ($document->getElementsByTagName('*') as $element) {
                if ($element instanceof DOMElement) {
                    $elements[] = $element;
                }
            }
            return $elements;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @return list<array{selector:string,complete:bool,declarations:list<array{property:string,value:string,value_start:int,value_end:int}>}>
     */
    private static function rules(string $css): array
    {
        $rules = [];
        $offset = 0;
        $length = strlen($css);
        while ($offset < $length) {
            $open = self::nextTopLevel($css, $offset, '{');
            if ($open === null) {
                break;
            }
            $rawSelector = substr($css, $offset, $open - $offset);
            $selector = self::cleanSelector($rawSelector);
            $close = self::closingBrace($css, $open + 1);
            $bodyEnd = $close ?? $length;
            if ($selector !== '') {
                $rules[] = [
                    'selector' => $selector,
                    'complete' => $close !== null,
                    'declarations' => str_starts_with($selector, '@')
                        ? []
                        : self::declarations($css, $open + 1, $bodyEnd),
                ];
            }
            if ($close === null) {
                break;
            }
            $offset = $close + 1;
        }
        return $rules;
    }

    private static function cleanSelector(string $selector): string
    {
        $withoutComments = preg_replace('~/\*.*?\*/~s', '', $selector);
        return trim(is_string($withoutComments) ? $withoutComments : $selector);
    }

    private static function nextTopLevel(string $css, int $offset, string $needle): ?int
    {
        $state = CssSyntaxScanner::state();
        $length = strlen($css);
        while ($offset < $length) {
            if (CssSyntaxScanner::isTopLevel($state) && $css[$offset] === $needle) {
                return $offset;
            }
            $next = CssSyntaxScanner::consume($css, $offset, $state);
            if ($next === null) {
                return null;
            }
            $offset = $next;
        }
        return null;
    }

    private static function closingBrace(string $css, int $offset): ?int
    {
        $state = CssSyntaxScanner::state();
        $depth = 0;
        $length = strlen($css);
        while ($offset < $length) {
            if (CssSyntaxScanner::isTopLevel($state)) {
                if ($css[$offset] === '{') {
                    $depth++;
                } elseif ($css[$offset] === '}') {
                    if ($depth === 0) {
                        return $offset;
                    }
                    $depth--;
                }
            }
            $next = CssSyntaxScanner::consume($css, $offset, $state);
            if ($next === null) {
                return null;
            }
            $offset = $next;
        }
        return null;
    }

    /** @return list<array{property:string,value:string,value_start:int,value_end:int}> */
    private static function declarations(string $css, int $start, int $end): array
    {
        $declarations = [];
        $segmentStart = $start;
        $state = CssSyntaxScanner::state();
        for ($offset = $start; $offset <= $end;) {
            $atEnd = $offset === $end;
            if ($atEnd || (CssSyntaxScanner::isTopLevel($state) && $css[$offset] === ';')) {
                $declaration = self::parseDeclaration($css, $segmentStart, $offset);
                if ($declaration !== null) {
                    $declarations[] = $declaration;
                }
                $segmentStart = $offset + 1;
                $offset++;
                continue;
            }
            $next = CssSyntaxScanner::consume($css, $offset, $state);
            if ($next === null) {
                break;
            }
            $offset = $next;
        }
        return $declarations;
    }

    /** @return array{property:string,value:string,value_start:int,value_end:int}|null */
    private static function parseDeclaration(string $css, int $start, int $end): ?array
    {
        $state = CssSyntaxScanner::state();
        $colon = null;
        for ($offset = $start; $offset < $end;) {
            if (CssSyntaxScanner::isTopLevel($state) && $css[$offset] === ':') {
                $colon = $offset;
                break;
            }
            $next = CssSyntaxScanner::consume($css, $offset, $state);
            if ($next === null) {
                return null;
            }
            $offset = $next;
        }
        if ($colon === null) {
            return null;
        }
        $property = strtolower(trim(substr($css, $start, $colon - $start)));
        if (!preg_match('/^(?:color|background|background-color)$/', $property)) {
            return null;
        }
        $valueStart = $colon + 1;
        while ($valueStart < $end && CssSyntaxScanner::isCssWhitespace($css[$valueStart])) {
            $valueStart++;
        }
        $valueEnd = $end;
        while ($valueEnd > $valueStart && CssSyntaxScanner::isCssWhitespace($css[$valueEnd - 1])) {
            $valueEnd--;
        }
        $value = substr($css, $valueStart, $valueEnd - $valueStart);
        if (preg_match('/!\s*important\s*$/i', $value, $important, PREG_OFFSET_CAPTURE)) {
            $valueEnd = $valueStart + $important[0][1];
            while ($valueEnd > $valueStart && CssSyntaxScanner::isCssWhitespace($css[$valueEnd - 1])) {
                $valueEnd--;
            }
            $value = substr($css, $valueStart, $valueEnd - $valueStart);
        }
        if ($value === '') {
            return null;
        }
        return [
            'property' => $property,
            'value' => $value,
            'value_start' => $valueStart,
            'value_end' => $valueEnd,
        ];
    }

    /** @return array{selector:string,status:string,fg:null,bg:null,ratio:null,suggested:null} */
    private static function unverified(string $selector): array
    {
        return [
            'selector' => $selector,
            'status' => 'unverified',
            'fg' => null,
            'bg' => null,
            'ratio' => null,
            'suggested' => null,
        ];
    }

    /** @return list<array{selector:string,status:string,fg:null,bg:null,ratio:null,suggested:null}> */
    private static function fallbackFindings(string $css): array
    {
        $findings = [];
        if (preg_match_all('/([^{}]+)\{/s', $css, $matches)) {
            foreach ($matches[1] as $selector) {
                $selector = self::cleanSelector($selector);
                if ($selector !== '') {
                    $findings[] = self::unverified($selector);
                }
            }
        }
        return $findings;
    }
}
