<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer;

use Automattic\SiteBuild\BlockSerializer\Html\HtmlNode;
use Automattic\SiteBuild\CssChecks;

/** Resolve the effective text-align signal in one inline style attribute. */
final class TextAlignmentCss
{
    /**
     * Values whose meaning is fully determined by the declaration itself.
     *
     * CSS-wide keywords inherit/reset a value outside this element, while
     * substitutions such as var()/env() depend on runtime state. Unknown and
     * multi-token values may also be rejected by the browser. None is sound
     * evidence that dropping an authored alignment class preserves layout.
     */
    private const DIRECT_VALUES = [
        'left',
        'right',
        'center',
        'start',
        'end',
        'justify',
        'match-parent',
        'justify-all',
    ];

    private const CSS_WIDE_VALUES = [
        'initial', 'inherit', 'unset', 'revert', 'revert-layer',
    ];

    /**
     * Produce the comparison value used for a comment/inline pair. Plain CSS
     * identifiers are ASCII-case-insensitive in this property; opaque
     * expressions are not — in particular custom-property names inside var()
     * remain case-sensitive.
     */
    public static function comparisonValue(string $value): string
    {
        $decoded = CssChecks::decodeIdentifier(trim($value));
        if (preg_match('/^-?[_a-zA-Z][-_a-zA-Z0-9]*$/D', $decoded) === 1) {
            return strtolower($decoded);
        }
        return (string) preg_replace_callback(
            '/^(-?[_a-zA-Z][-_a-zA-Z0-9]*)\(/',
            static fn (array $match): string => strtolower($match[1]) . '(',
            $decoded,
        );
    }

    /**
     * Compare the browser cascade before and after ParagraphFixer's exact,
     * case-preserving associative style map (last value, first key position).
     *
     * @return array{preserves:bool,projected:string,authored:string,delivered:string}
     */
    public static function paragraphProjection(string $style): array
    {
        $projected = self::projectParagraphStyle($style);
        $before = self::projectionProperty($style, 'text-align');
        $after = self::projectionProperty($projected, 'text-align');
        if (!self::sameProjectionState($before, $after)) {
            return self::projectionResult(false, $projected, $before, $after);
        }

        $value = $before['value'] ?? '';
        if (in_array($value, [
            'start', 'end', 'match-parent',
            'initial', 'inherit', 'unset', 'revert', 'revert-layer',
        ], true) || self::substitution($value) || ($before['property'] ?? null) === 'all') {
            foreach (['direction', 'writing-mode'] as $property) {
                $dependencyBefore = self::projectionProperty($style, $property);
                $dependencyAfter = self::projectionProperty($projected, $property);
                if (!self::sameProjectionState($dependencyBefore, $dependencyAfter)) {
                    return self::projectionResult(false, $projected, $before, $after);
                }
            }
        }

        $pending = array_merge(
            self::variableReferences((string) ($before['value'] ?? '')),
            self::variableReferences((string) ($after['value'] ?? '')),
        );
        $seen = [];
        while ($pending !== []) {
            $property = (string) array_shift($pending);
            if (isset($seen[$property])) {
                continue;
            }
            $seen[$property] = true;
            $dependencyBefore = self::projectionProperty($style, $property);
            $dependencyAfter = self::projectionProperty($projected, $property);
            if (!self::sameProjectionState($dependencyBefore, $dependencyAfter)) {
                return self::projectionResult(false, $projected, $before, $after);
            }
            foreach ([$dependencyBefore, $dependencyAfter] as $state) {
                array_push($pending, ...self::variableReferences((string) ($state['value'] ?? '')));
            }
        }
        return self::projectionResult(true, $projected, $before, $after);
    }

    private static function projectParagraphStyle(string $style): string
    {
        $map = [];
        foreach (explode(';', $style) as $declaration) {
            $colon = strpos($declaration, ':');
            if ($colon === false || $colon === 0) {
                continue;
            }
            $property = trim(substr($declaration, 0, $colon));
            if ($property !== '') {
                $map[$property] = trim(substr($declaration, $colon + 1));
            }
        }
        $projected = [];
        foreach ($map as $property => $value) {
            $projected[] = $property . ':' . $value;
        }
        return implode(';', $projected);
    }

    /** @return array{property:string,value:string,authored:string,important:bool}|null */
    private static function projectionProperty(string $style, string $wanted): ?array
    {
        $winner = null;
        $winnerImportant = false;
        foreach (CssChecks::scanDeclarations($style, true) as $declaration) {
            if ($declaration['kind'] !== 'declaration-list') {
                continue;
            }
            $rawProperty = self::rawProperty($declaration['raw']);
            $plainProperty = $rawProperty === null
                ? null
                : self::withoutTokenJoiningComments($rawProperty);
            if ($plainProperty === null) {
                continue;
            }
            $decoded = CssChecks::decodeIdentifier(trim($plainProperty));
            $property = str_starts_with($decoded, '--') ? $decoded : strtolower($decoded);
            $allApplies = $property === 'all'
                && !str_starts_with($wanted, '--')
                && !in_array($wanted, ['direction', 'unicode-bidi'], true);
            if ($property !== $wanted && !$allApplies) {
                continue;
            }
            if (!$declaration['structurallySafe']) {
                $candidate = [
                    'property' => "\0opaque",
                    'value' => trim($declaration['raw']),
                    'authored' => trim($declaration['raw']),
                    'important' => true,
                ];
            } else {
                $plainValue = self::withoutTokenJoiningComments($declaration['value']);
                if ($plainValue === null) {
                    continue;
                }
                // splitDeclarationPriority is safe only after the comment
                // boundary check proves it cannot join priority tokens.
                $priority = CssChecks::splitDeclarationPriority($plainValue);
                $value = self::comparisonValue($priority['value']);
                if (!self::projectionValueIsValid($property, $wanted, $value)) {
                    continue;
                }
                $candidate = [
                    'property' => $property,
                    'value' => $value,
                    'authored' => $property . ':' . $declaration['value'],
                    'important' => $priority['important'],
                ];
            }
            if ($winner === null || $candidate['important'] || !$winnerImportant) {
                $winner = $candidate;
                $winnerImportant = $candidate['important'];
            }
        }
        return $winner;
    }

    private static function projectionValueIsValid(string $property, string $wanted, string $value): bool
    {
        if ($value === '') {
            return false;
        }
        if (str_starts_with($property, '--')) {
            return true;
        }
        if ($property === 'all') {
            return in_array($value, self::CSS_WIDE_VALUES, true) || self::substitution($value);
        }
        if ($wanted === 'text-align') {
            return in_array($value, array_merge(self::DIRECT_VALUES, self::CSS_WIDE_VALUES), true)
                || self::substitution($value);
        }
        if ($wanted === 'direction') {
            return in_array($value, array_merge(['ltr', 'rtl'], self::CSS_WIDE_VALUES), true)
                || self::substitution($value);
        }
        if ($wanted === 'writing-mode') {
            return in_array($value, array_merge([
                'horizontal-tb', 'vertical-rl', 'vertical-lr', 'sideways-rl', 'sideways-lr',
            ], self::CSS_WIDE_VALUES), true) || self::substitution($value);
        }
        return true;
    }

    private static function substitution(string $value): bool
    {
        return preg_match('/^(?:var|env)\(/i', $value) === 1;
    }

    /** @return list<string> */
    private static function variableReferences(string $value): array
    {
        preg_match_all('/(?<![-_a-zA-Z0-9])var\(\s*(--[-_a-zA-Z0-9]+)/i', $value, $matches);
        return array_values(array_unique($matches[1] ?? []));
    }

    /** @param array<string,mixed>|null $before @param array<string,mixed>|null $after */
    private static function sameProjectionState(?array $before, ?array $after): bool
    {
        if ($before === null || $after === null) {
            return $before === $after;
        }
        return $before['property'] === $after['property']
            && $before['value'] === $after['value']
            && $before['important'] === $after['important'];
    }

    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     * @return array{preserves:bool,projected:string,authored:string,delivered:string}
     */
    private static function projectionResult(
        bool $preserves,
        string $projected,
        ?array $before,
        ?array $after,
    ): array {
        return [
            'preserves' => $preserves,
            'projected' => $projected,
            'authored' => $before['authored'] ?? 'none',
            'delivered' => $after['authored'] ?? 'none',
        ];
    }

    /** @return array{safe:bool,value:string,authored:string,important:bool}|null */
    public static function effectiveInline(HtmlNode $root): ?array
    {
        $effective = null;
        $effectiveImportant = false;
        foreach (CssChecks::scanDeclarations($root->attribute('style') ?? '', true) as $declaration) {
            if ($declaration['kind'] !== 'declaration-list') {
                continue;
            }
            $property = strtolower(trim($declaration['property']));
            if (!in_array($property, ['text-align', 'all'], true)) {
                continue;
            }
            $rawProperty = self::rawProperty($declaration['raw']);
            $propertyWithTrivia = $rawProperty === null
                ? null
                : self::withoutTokenJoiningComments($rawProperty);
            if ($propertyWithTrivia === null
                || strtolower(CssChecks::decodeIdentifier(trim($propertyWithTrivia))) !== $property
            ) {
                return [
                    'safe' => false,
                    'value' => '',
                    'authored' => trim($declaration['raw']),
                    'important' => false,
                ];
            }
            if (!$declaration['structurallySafe']) {
                return [
                    'safe' => false,
                    'value' => '',
                    'authored' => $property . ':' . $declaration['value'],
                    'important' => false,
                ];
            }

            $valueWithTrivia = self::withoutTokenJoiningComments($declaration['value']);
            if ($valueWithTrivia === null) {
                return [
                    'safe' => false,
                    'value' => '',
                    'authored' => $property . ':' . $declaration['value'],
                    'important' => false,
                ];
            }
            $resolved = CssChecks::splitDeclarationPriority($valueWithTrivia);
            $candidate = null;
            if ($property === 'text-align') {
                $value = self::comparisonValue($resolved['value']);
                $candidate = [
                    'safe' => in_array($value, self::DIRECT_VALUES, true),
                    'value' => $value,
                    'authored' => 'text-align:' . $declaration['value'],
                    'important' => $resolved['important'],
                ];
            } elseif (CssChecks::isShapeAffectingDeclaration('all', $resolved['value'])) {
                $candidate = [
                    'safe' => false,
                    'value' => '',
                    'authored' => 'all:' . $declaration['value'],
                    'important' => $resolved['important'],
                ];
            }
            if ($candidate === null) {
                continue;
            }

            $important = $resolved['important'];
            if ($effective === null || $important || !$effectiveImportant) {
                $effective = $candidate;
                $effectiveImportant = $important;
            }
        }
        return $effective;
    }

    /** Return the authored property bytes before the first structural colon. */
    private static function rawProperty(string $declaration): ?string
    {
        $length = strlen($declaration);
        for ($i = 0; $i < $length;) {
            $char = $declaration[$i];
            if ($char === '/' && ($declaration[$i + 1] ?? '') === '*') {
                $close = strpos($declaration, '*/', $i + 2);
                if ($close === false) {
                    return null;
                }
                $i = $close + 2;
                continue;
            }
            if ($char === '\\') {
                $i = min($length, $i + 2);
                continue;
            }
            if ($char === ':') {
                return substr($declaration, 0, $i);
            }
            ++$i;
        }
        return null;
    }

    /**
     * Remove comments without touching comment-shaped bytes inside strings,
     * but only where deleting the trivia cannot join its neighboring tokens.
     * Returning null for an unsafe or unclosed comment makes the declaration
     * an opaque signal instead of repairing malformed CSS here.
     */
    private static function withoutTokenJoiningComments(string $css): ?string
    {
        $plain = '';
        $length = strlen($css);
        for ($i = 0; $i < $length;) {
            $char = $css[$i];
            if ($char === '/' && ($css[$i + 1] ?? '') === '*') {
                $runEnd = $i;
                do {
                    $close = strpos($css, '*/', $runEnd + 2);
                    if ($close === false) {
                        return null;
                    }
                    $runEnd = $close + 2;
                } while (substr($css, $runEnd, 2) === '/*');

                $before = $i === 0 ? null : $css[$i - 1];
                $after = $runEnd >= $length ? null : $css[$runEnd];
                if (!self::commentBoundaryIsTokenSafe($before, $after)) {
                    return null;
                }
                $i = $runEnd;
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $plain .= $char;
                ++$i;
                while ($i < $length) {
                    $plain .= $css[$i];
                    if ($css[$i] === '\\' && $i + 1 < $length) {
                        $plain .= $css[++$i];
                    } elseif ($css[$i] === $quote) {
                        ++$i;
                        break;
                    }
                    ++$i;
                }
                continue;
            }
            if ($char === '\\' && $i + 1 < $length) {
                $plain .= $char . $css[$i + 1];
                $i += 2;
                continue;
            }
            $plain .= $char;
            ++$i;
        }
        return $plain;
    }

    private static function commentBoundaryIsTokenSafe(?string $before, ?string $after): bool
    {
        if ($before === null || $after === null
            || ctype_space($before) || ctype_space($after)
        ) {
            return true;
        }

        // Delimiters already end a token on the indicated side. An opening
        // parenthesis is intentionally absent on the right: `var/**/(` is an
        // identifier plus a delimiter, not the var() function produced by
        // deleting the comment.
        return str_contains('([{)},:;!', $before)
            || str_contains(')]{},:;!', $after);
    }
}
