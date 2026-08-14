<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssSyntaxScanner;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter;
use Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment;
use Automattic\SiteBuild\BlockSerializer\Html\HtmlNode;
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
            $findings = [];
            $seen = [];
            foreach (self::analysis($css, $markup) as $entry) {
                $key = serialize($entry['finding']);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $findings[] = $entry['finding'];
            }
            return $findings;
        } catch (Throwable) {
            return self::fallbackFindings($css);
        }
    }

    /** @return array{value_start:int,value_end:int}|null */
    public static function repairTarget(string $css, string $markup, array $finding): ?array
    {
        try {
            $targets = [];
            foreach (self::analysis($css, $markup) as $entry) {
                if ($entry['finding'] !== $finding || $entry['target'] === null) {
                    continue;
                }
                $key = $entry['target']['value_start'] . ':' . $entry['target']['value_end'];
                $targets[$key] = [
                    'value_start' => $entry['target']['value_start'],
                    'value_end' => $entry['target']['value_end'],
                ];
            }
            return count($targets) === 1 ? array_values($targets)[0] : null;
        } catch (Throwable) {
            return null;
        }
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
     * Resolve rendered pairs per element, retaining winning declaration source
     * internally so effectful repair never guesses from the frozen public row.
     *
     * @return list<array{
     *   finding:array{selector:string,status:string,fg:?string,bg:?string,ratio:?float,suggested:?string},
     *   target:?array{property:string,value:string,evaluation:string,value_start:int,value_end:int,important:bool},
     *   order:int
     * }>
     */
    private static function analysis(string $css, string $markup): array
    {
        $prepared = [];
        $entries = [];
        $customProperties = self::customProperties($css);
        foreach (self::rules($css) as $rule) {
            $parsed = CssSelectorMatcher::parse($rule['selector']);
            $supported = $rule['complete'] && self::isSupportedSelector($parsed);
            $prepared[] = [
                'rule' => $rule,
                'parsed' => $parsed,
                'supported' => $supported,
                'specificity' => $supported ? self::specificity($parsed) : 0,
                'matched' => false,
            ];
            if (!$supported) {
                $entries[] = [
                    'finding' => self::unverified($rule['selector']),
                    'target' => null,
                    'order' => $rule['source_start'],
                ];
            }
        }

        foreach (self::elements($markup) as $element) {
            $matching = [];
            foreach ($prepared as $index => $item) {
                if (!$item['supported']) {
                    continue;
                }
                $match = CssSelectorMatcher::matches($element, $item['parsed']);
                if (!($match['supported'] ?? false) || !($match['matches'] ?? false)) {
                    continue;
                }
                $prepared[$index]['matched'] = true;
                $matching[] = $item;
            }
            if ($matching === []) {
                continue;
            }

            $fgDeclaration = self::winningDeclaration($matching, ['color'], $customProperties);
            $bgDeclaration = self::winningDeclaration(
                $matching,
                ['background', 'background-color'],
                $customProperties,
            );
            $ownBackground = $fgDeclaration === null
                ? null
                : self::backgroundOnSameRule($matching, $fgDeclaration, $customProperties);
            if ($ownBackground !== null) {
                $bgDeclaration = $ownBackground;
            }
            $selector = $fgDeclaration['selector']
                ?? $bgDeclaration['selector']
                ?? $matching[count($matching) - 1]['rule']['selector'];
            if ($fgDeclaration === null || $bgDeclaration === null
                || $fgDeclaration['state'] !== 'resolved'
                || $bgDeclaration['state'] !== 'resolved') {
                $entries[] = [
                    'finding' => self::unverified($selector),
                    'target' => null,
                    'order' => $fgDeclaration['source_order']
                        ?? $bgDeclaration['source_order']
                        ?? $matching[count($matching) - 1]['rule']['source_start'],
                ];
                continue;
            }

            $fg = $fgDeclaration['color'];
            $bg = $bgDeclaration['color'];
            if ($fg === null || $bg === null || $bg['alpha'] < 1.0) {
                $entries[] = [
                    'finding' => self::unverified($selector),
                    'target' => null,
                    'order' => $fgDeclaration['source_order'],
                ];
                continue;
            }
            $renderedFg = ContrastMath::compositeOver($fg['rgb'], $fg['alpha'], $bg['rgb']);
            $ratio = ContrastMath::ratio($renderedFg, $bg['rgb']);
            if ($ratio >= ContrastMath::NORMAL_TEXT) {
                $entries[] = [
                    'finding' => [
                        'selector' => $selector,
                        'status' => 'pass',
                        'fg' => $fgDeclaration['value'],
                        'bg' => $bgDeclaration['value'],
                        'ratio' => $ratio,
                        'suggested' => null,
                    ],
                    'target' => null,
                    'order' => $fgDeclaration['source_order'],
                ];
                continue;
            }
            $entries[] = [
                'finding' => [
                    'selector' => $selector,
                    'status' => 'fail',
                    'fg' => $fgDeclaration['value'],
                    'bg' => $bgDeclaration['value'],
                    'ratio' => $ratio,
                    'suggested' => self::nudge($fg['rgb'], $fg['alpha'], $bg['rgb']),
                ],
                'target' => $fgDeclaration['declaration'],
                'order' => $fgDeclaration['source_order'],
            ];
        }

        foreach ($prepared as $item) {
            if ($item['supported'] && !$item['matched']) {
                $entries[] = [
                    'finding' => self::unverified($item['rule']['selector']),
                    'target' => null,
                    'order' => $item['rule']['source_start'],
                ];
            }
        }
        usort($entries, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);
        return $entries;
    }

    /**
     * @param list<array{rule:array<string,mixed>,parsed:array<string,mixed>,supported:bool,specificity:int,matched:bool}> $matching
     * @param list<string> $properties
     * @param array<string,string> $customProperties
     * @return array{
     *   selector:string,state:string,color:?array{rgb:array{0:int,1:int,2:int},alpha:float},
     *   value:string,declaration:array{property:string,value:string,evaluation:string,value_start:int,value_end:int,important:bool},
     *   important:bool,specificity:int,source_order:int
     * }|null
     */
    /**
     * If the winning color came from a rule that also sets a solid background,
     * that is the authored pair. Mixing it with a page-fill background is how
     * `.panel--mustard { color: var(--ink); background: var(--mustard) }` was
     * judged against body ink (ratio ~1) and rewritten to mid-gray.
     *
     * @param list<array{rule:array<string,mixed>,parsed:array<string,mixed>,supported:bool,specificity:int,matched:bool}> $matching
     * @param array<string,mixed> $fgDeclaration
     * @param array<string,string> $customProperties
     * @return array<string,mixed>|null
     */
    private static function backgroundOnSameRule(
        array $matching,
        array $fgDeclaration,
        array $customProperties,
    ): ?array {
        $fgStart = $fgDeclaration['declaration']['value_start'] ?? null;
        if (!is_int($fgStart)) {
            return null;
        }
        foreach ($matching as $item) {
            $ownsColor = false;
            $background = null;
            foreach ($item['rule']['declarations'] as $declaration) {
                $resolved = self::resolvedDeclaration($item, $declaration, $customProperties);
                if ($resolved === null) {
                    continue;
                }
                if ($declaration['property'] === 'color'
                    && ($declaration['value_start'] ?? null) === $fgStart
                ) {
                    $ownsColor = true;
                }
                if (in_array($declaration['property'], ['background', 'background-color'], true)) {
                    $background = $resolved;
                }
            }
            if ($ownsColor && $background !== null && $background['state'] === 'resolved') {
                return $background;
            }
        }
        return null;
    }

    /**
     * @param array{rule:array<string,mixed>,specificity:int} $item
     * @param array<string,mixed> $declaration
     * @param array<string,string> $customProperties
     * @return array<string,mixed>|null
     */
    private static function resolvedDeclaration(array $item, array $declaration, array $customProperties): ?array
    {
        if (!in_array($declaration['property'], ['color', 'background', 'background-color'], true)) {
            return null;
        }
        [$state, $color] = self::declarationColor(
            $declaration['evaluation'],
            $declaration['property'],
            $customProperties,
        );
        if ($state === 'invalid') {
            return null;
        }
        return [
            'selector' => $item['rule']['selector'],
            'state' => $state,
            'color' => $color,
            'value' => $declaration['value'],
            'declaration' => $declaration,
            'important' => $declaration['important'],
            'specificity' => $item['specificity'],
            'source_order' => $declaration['value_start'],
        ];
    }

    private static function winningDeclaration(array $matching, array $properties, array $customProperties): ?array
    {
        $winner = null;
        foreach ($matching as $item) {
            foreach ($item['rule']['declarations'] as $declaration) {
                if (!in_array($declaration['property'], $properties, true)) {
                    continue;
                }
                [$state, $color] = self::declarationColor(
                    $declaration['evaluation'],
                    $declaration['property'],
                    $customProperties,
                );
                if ($state === 'invalid') {
                    continue;
                }
                $candidate = [
                    'selector' => $item['rule']['selector'],
                    'state' => $state,
                    'color' => $color,
                    'value' => $declaration['value'],
                    'declaration' => $declaration,
                    'important' => $declaration['important'],
                    'specificity' => $item['specificity'],
                    'source_order' => $declaration['value_start'],
                ];
                if ($winner === null || self::outranks($candidate, $winner)) {
                    $winner = $candidate;
                }
            }
        }
        return $winner;
    }

    /**
     * @param array<string,string> $customProperties
     * @return array{0:string,1:?array{rgb:array{0:int,1:int,2:int},alpha:float}}
     */
    private static function declarationColor(string $value, string $property, array $customProperties): array
    {
        // Token-based designs express every color as var(--x); resolve it to a
        // literal first so real low-contrast pairs are linted, not skipped.
        $color = self::color(self::resolveVars($value, $customProperties));
        if ($color !== null) {
            return ['resolved', $color];
        }
        if ($property === 'background') {
            // Any complete, nonempty shorthand that is not exactly one solid
            // resolved color still resets background-color. Keep it as the
            // cascade winner but mark the rendered background unverified;
            // skipping it would expose a stale earlier longhand as evidence.
            return ['unresolved', null];
        }

        $lower = strtolower(trim($value));
        $commonUnresolved = in_array($lower, [
            'inherit', 'initial', 'unset', 'revert', 'revert-layer', 'currentcolor', 'transparent',
        ], true)
            || preg_match('/^(?:var|rgb|rgba|hsl|hsla|oklch|lab|lch|color|color-mix|light-dark)\s*\(/i', $value) === 1
            || preg_match('/^[a-z]+$/i', $value) === 1;
        return $commonUnresolved ? ['unresolved', null] : ['invalid', null];
    }

    /** @param array<string,mixed> $candidate @param array<string,mixed> $winner */
    private static function outranks(array $candidate, array $winner): bool
    {
        if ($candidate['important'] !== $winner['important']) {
            return $candidate['important'];
        }
        if ($candidate['specificity'] !== $winner['specificity']) {
            return $candidate['specificity'] > $winner['specificity'];
        }
        return $candidate['source_order'] > $winner['source_order'];
    }

    /** @param array<string,mixed> $parsed */
    private static function specificity(array $parsed): int
    {
        $specificity = 0;
        foreach ($parsed['compounds'] ?? [] as $compound) {
            $specificity += count($compound['classes'] ?? []);
        }
        return $specificity;
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
     * @param array{selector:string,source_start:int,complete:bool,declarations:list<array{property:string,value:string,evaluation:string,value_start:int,value_end:int,important:bool}>} $rule
     * @param list<string> $properties
     * @return array{property:string,value:string,evaluation:string,value_start:int,value_end:int,important:bool}|null
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
        $value = trim($value);
        if (preg_match('/^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/i', $value) === 1
            || preg_match('/^rgba?\([^)]*\)$/i', $value) === 1) {
            $colors = ContrastMath::parseCssColors($value);
            if (count($colors) === 1) {
                return $colors[0];
            }
        }

        if (preg_match('/^#([0-9a-f]{4}|[0-9a-f]{8})$/i', $value, $hex) === 1) {
            $digits = $hex[1];
            if (strlen($digits) === 4) {
                $rgbHex = $digits[0] . $digits[0] . $digits[1] . $digits[1] . $digits[2] . $digits[2];
                $alphaHex = $digits[3] . $digits[3];
            } else {
                $rgbHex = substr($digits, 0, 6);
                $alphaHex = substr($digits, 6, 2);
            }
            $rgb = ContrastMath::hexToRgb($rgbHex);
            return $rgb === null ? null : [
                'rgb' => $rgb,
                'alpha' => hexdec($alphaHex) / 255,
            ];
        }

        if (preg_match('/^rgba?\((.*)\)$/is', $value, $functional) !== 1
            || str_contains($functional[1], ',')) {
            return null;
        }
        $slash = CssValueSplitter::splitTopLevel($functional[1], ['/']);
        if (count($slash) > 2) {
            return null;
        }
        $channels = CssValueSplitter::splitTopLevelWhitespace($slash[0] ?? '');
        if (count($channels) !== 3) {
            return null;
        }
        $rgb = [];
        foreach ($channels as $channel) {
            $parsed = self::rgbChannel($channel);
            if ($parsed === null) {
                return null;
            }
            $rgb[] = $parsed;
        }
        $alpha = isset($slash[1]) ? self::alphaChannel($slash[1]) : 1.0;
        if ($alpha === null) {
            return null;
        }
        return ['rgb' => $rgb, 'alpha' => $alpha];
    }

    private static function rgbChannel(string $channel): ?int
    {
        $channel = trim($channel);
        if (preg_match('/^([+-]?(?:\d+(?:\.\d*)?|\.\d+))%$/', $channel, $percent) === 1) {
            return (int) round(max(0.0, min(100.0, (float) $percent[1])) * 255 / 100);
        }
        if (preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/', $channel) !== 1) {
            return null;
        }
        return (int) round(max(0.0, min(255.0, (float) $channel)));
    }

    private static function alphaChannel(string $alpha): ?float
    {
        $alpha = trim($alpha);
        if (preg_match('/^([+-]?(?:\d+(?:\.\d*)?|\.\d+))%$/', $alpha, $percent) === 1) {
            return max(0.0, min(100.0, (float) $percent[1])) / 100;
        }
        if (preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/', $alpha) !== 1) {
            return null;
        }
        return max(0.0, min(1.0, (float) $alpha));
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
        $document = new DOMDocument();
        $root = $document->createElement('div');
        $document->appendChild($root);
        $elements = [];
        foreach (HtmlFragment::parse($markup)->children() as $node) {
            self::appendElementTree($document, $root, $node, $elements);
        }
        return $elements;
    }

    /** @param list<DOMElement> $elements */
    private static function appendElementTree(
        DOMDocument $document,
        DOMElement $parent,
        HtmlNode $node,
        array &$elements,
    ): void {
        if (!$node->isElement()) {
            return;
        }
        // Class-only selectors need hierarchy + class tokens, not browser HTML
        // recovery. Build matcher input without invoking libxml's HTML parser or
        // touching its process-global error queue.
        $element = $document->createElement('div');
        $class = $node->attribute('class');
        if ($class !== null && preg_match('//u', $class) === 1) {
            $element->setAttribute('class', $class);
        }
        $parent->appendChild($element);
        $elements[] = $element;
        foreach ($node->children() as $child) {
            self::appendElementTree($document, $element, $child, $elements);
        }
    }

    /**
     * @return list<array{selector:string,source_start:int,complete:bool,declarations:list<array{property:string,value:string,evaluation:string,value_start:int,value_end:int,important:bool}>}>
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
                    'source_start' => $offset,
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

    /** @return list<array{property:string,value:string,evaluation:string,value_start:int,value_end:int,important:bool}> */
    private static function declarations(string $css, int $start, int $end): array
    {
        $declarations = [];
        foreach (self::segments($css, $start, $end) as $segment) {
            $declaration = self::parseDeclaration($css, $segment['start'], $segment['end']);
            if ($declaration !== null) {
                $declarations[] = $declaration;
            }
        }
        return $declarations;
    }

    /**
     * Split a rule body into top-level ";"-delimited declaration ranges,
     * honoring strings/comments/nesting via the shared syntax scanner.
     *
     * @return list<array{start:int,end:int}>
     */
    private static function segments(string $css, int $start, int $end): array
    {
        $segments = [];
        $segmentStart = $start;
        $state = CssSyntaxScanner::state();
        for ($offset = $start; $offset <= $end;) {
            if ($offset === $end || (CssSyntaxScanner::isTopLevel($state) && $css[$offset] === ';')) {
                $segments[] = ['start' => $segmentStart, 'end' => $offset];
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
        return $segments;
    }

    /**
     * Custom-property name -> raw value across every top-level rule, source
     * order so later definitions win. Names stay case-sensitive; only var()
     * resolution consults this, so non-color values simply never resolve.
     *
     * @return array<string,string>
     */
    private static function customProperties(string $css): array
    {
        $map = [];
        $offset = 0;
        $length = strlen($css);
        while ($offset < $length) {
            $open = self::nextTopLevel($css, $offset, '{');
            if ($open === null) {
                break;
            }
            $close = self::closingBrace($css, $open + 1);
            if ($close === null) {
                break;
            }
            $selector = self::cleanSelector(substr($css, $offset, $open - $offset));
            if ($selector !== '' && !str_starts_with($selector, '@')) {
                foreach (self::segments($css, $open + 1, $close) as $segment) {
                    self::parseCustomProperty($css, $segment['start'], $segment['end'], $map);
                }
            }
            $offset = $close + 1;
        }
        return $map;
    }

    /** @param array<string,string> $map */
    private static function parseCustomProperty(string $css, int $start, int $end, array &$map): void
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
                return;
            }
            $offset = $next;
        }
        if ($colon === null) {
            return;
        }
        $property = self::commentsMasked(substr($css, $start, $colon - $start));
        if ($property === null) {
            return;
        }
        $property = trim($property);
        if (!str_starts_with($property, '--') || $property === '--') {
            return;
        }
        $masked = self::commentsMasked(substr($css, $colon + 1, $end - $colon - 1));
        if ($masked === null) {
            return;
        }
        $value = trim($masked);
        if ($value !== '') {
            $map[$property] = $value;
        }
    }

    /**
     * Substitute a whole-value var(--name[, fallback]) reference with its
     * resolved literal: the custom-property map, else the fallback, else left
     * intact (so it stays unresolved). Resolves transitively; a visited set
     * breaks cycles. Anything not a lone var() passes through unchanged, so
     * literal-color CSS is byte-identical.
     *
     * @param array<string,string> $map
     * @param array<string,bool> $visited
     */
    private static function resolveVars(string $value, array $map, array $visited = []): string
    {
        $value = trim($value);
        if (preg_match('/^var\(\s*(--[A-Za-z0-9_-]+)\s*(?:,\s*(.*?))?\s*\)$/is', $value, $match) !== 1) {
            return $value;
        }
        $name = $match[1];
        if (isset($map[$name])) {
            if (isset($visited[$name])) {
                return $value;
            }
            $visited[$name] = true;
            return self::resolveVars($map[$name], $map, $visited);
        }
        if (($match[2] ?? '') !== '') {
            return self::resolveVars($match[2], $map, $visited);
        }
        return $value;
    }

    /** @return array{property:string,value:string,evaluation:string,value_start:int,value_end:int,important:bool}|null */
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
        $propertySource = substr($css, $start, $colon - $start);
        $propertyMasked = self::commentsMasked($propertySource);
        if ($propertyMasked === null) {
            return null;
        }
        $property = strtolower(trim($propertyMasked));
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
        $masked = self::commentsMasked($value);
        if ($masked === null) {
            return null;
        }
        $isImportant = false;
        if (preg_match('/!\s*important\s*$/i', $masked, $important, PREG_OFFSET_CAPTURE)) {
            $isImportant = true;
            $valueEnd = $valueStart + $important[0][1];
            while ($valueEnd > $valueStart && CssSyntaxScanner::isCssWhitespace($css[$valueEnd - 1])) {
                $valueEnd--;
            }
            $value = substr($css, $valueStart, $valueEnd - $valueStart);
            $masked = substr($masked, 0, $important[0][1]);
        }
        $evaluation = trim($masked);
        if ($value === '' || $evaluation === '') {
            return null;
        }
        return [
            'property' => $property,
            'value' => $value,
            'evaluation' => $evaluation,
            'value_start' => $valueStart,
            'value_end' => $valueEnd,
            'important' => $isImportant,
        ];
    }

    /**
     * Replace comments outside CSS strings with same-length spaces. Offsets in
     * the returned view therefore map exactly to original generated CSS.
     */
    private static function commentsMasked(string $source): ?string
    {
        $masked = $source;
        $quote = '';
        $length = strlen($source);
        for ($offset = 0; $offset < $length;) {
            $character = $source[$offset];
            if ($quote !== '') {
                if ($character === '\\') {
                    $next = CssSyntaxScanner::escapeEnd($source, $offset);
                    if ($next === null) {
                        return null;
                    }
                    $offset = $next;
                    continue;
                }
                if ($character === $quote) {
                    $quote = '';
                }
                $offset++;
                continue;
            }
            if ($character === '"' || $character === "'") {
                $quote = $character;
                $offset++;
                continue;
            }
            if ($character === '/' && ($source[$offset + 1] ?? '') === '*') {
                $close = strpos($source, '*/', $offset + 2);
                if ($close === false) {
                    return null;
                }
                $end = $close + 2;
                for ($index = $offset; $index < $end; $index++) {
                    if (!CssSyntaxScanner::isCssWhitespace($masked[$index])) {
                        $masked[$index] = ' ';
                    }
                }
                $offset = $end;
                continue;
            }
            if ($character === '\\') {
                $next = CssSyntaxScanner::escapeEnd($source, $offset);
                if ($next === null) {
                    return null;
                }
                $offset = $next;
                continue;
            }
            $offset++;
        }
        return $quote === '' ? $masked : null;
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
