<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssSyntaxScanner;

/**
 * Removes CSS dependencies that would make a generated theme fetch third-party
 * resources.
 *
 * This is a deliberately small lexical scrubber, not a CSS formatter. It
 * preserves every byte outside a removed @import statement or declaration.
 * Malformed boundaries are left unchanged because retaining the pre-transform
 * bytes is safer than guessing where generated CSS ends.
 */
final class CssScrub
{
    /**
     * @return array{
     *     css:string,
     *     removals:list<array{
     *         kind:string,
     *         authored_value:string,
     *         delivered_value:string,
     *         disposition:string
     *     }>
     * }
     */
    public static function scrub(string $css): array
    {
        $length = strlen($css);
        if ($length === 0) {
            return ['css' => '', 'removals' => []];
        }

        /** @var array<string,array{start:int,end:int,kind:string}> $ranges */
        $ranges = [];

        for ($offset = 0; $offset < $length;) {
            if (self::startsComment($css, $offset)) {
                $offset = self::commentEnd($css, $offset);
                continue;
            }

            $byte = $css[$offset];
            if ($byte === '"' || $byte === "'") {
                $offset = self::stringEnd($css, $offset);
                continue;
            }

            $importIdentifierEnd = $byte === '@'
                ? self::importIdentifierEndAt($css, $offset)
                : null;
            if ($importIdentifierEnd !== null) {
                $end = self::atRuleStatementEnd($css, $importIdentifierEnd);
                if ($end !== null) {
                    $ranges["{$offset}:{$end}"] = [
                        'start' => $offset,
                        'end'   => $end,
                        'kind'  => 'import',
                    ];
                    $offset = $end;
                    continue;
                }
            }

            $url = self::urlAt($css, $offset);
            if ($url !== null) {
                if (self::isExternalUrl($url['value'])) {
                    $declaration = self::declarationRange($css, $offset);
                    if ($declaration !== null) {
                        $key = "{$declaration['start']}:{$declaration['end']}";
                        $ranges[$key] = [
                            'start' => $declaration['start'],
                            'end'   => $declaration['end'],
                            'kind'  => 'external_url_declaration',
                        ];
                    }
                }

                $offset = $url['end'];
                continue;
            }

            $identifier = self::identifierAt($css, $offset);
            if ($identifier !== null) {
                $offset = $identifier['end'];
                continue;
            }

            $offset++;
        }

        if ($ranges === []) {
            return ['css' => $css, 'removals' => []];
        }

        $ordered = array_values($ranges);
        usort(
            $ordered,
            static fn (array $left, array $right): int =>
                [$left['start'], $left['end']] <=> [$right['start'], $right['end']]
        );

        $removals = [];
        foreach ($ordered as $range) {
            $authored = substr($css, $range['start'], $range['end'] - $range['start']);
            $removals[] = [
                'kind'            => $range['kind'],
                'authored_value'  => $authored,
                'delivered_value' => 'removed',
                'disposition'     => $range['kind'] === 'import'
                    ? 'removed_import'
                    : 'removed_external_url',
            ];
        }

        for ($index = count($ordered) - 1; $index >= 0; $index--) {
            $range = $ordered[$index];
            $css = substr($css, 0, $range['start']) . substr($css, $range['end']);
        }

        return ['css' => $css, 'removals' => $removals];
    }

    private static function importIdentifierEndAt(string $css, int $offset): ?int
    {
        $identifier = self::identifierAt($css, $offset + 1);
        return $identifier !== null && strcasecmp($identifier['value'], 'import') === 0
            ? $identifier['end']
            : null;
    }

    private static function atRuleStatementEnd(string $css, int $offset): ?int
    {
        $length = strlen($css);
        $parentheses = 0;
        $brackets = 0;
        $braceInsideNesting = false;

        while ($offset < $length) {
            if (self::startsComment($css, $offset)) {
                $commentEnd = strpos($css, '*/', $offset + 2);
                if ($commentEnd === false) {
                    return $length;
                }
                $offset = $commentEnd + 2;
                continue;
            }

            $byte = $css[$offset];
            if ($byte === '"' || $byte === "'") {
                $stringEnd = self::completeStringEnd($css, $offset);
                if ($stringEnd === null) {
                    return self::hasRuleBrace($css, $offset + 1) ? null : $length;
                }
                $offset = $stringEnd;
                continue;
            }
            if ($byte === '\\') {
                $escapeEnd = CssSyntaxScanner::escapeEnd($css, $offset);
                if ($escapeEnd === null) {
                    return null;
                }
                $offset = $escapeEnd;
                continue;
            }
            if ($byte === '(') {
                $parentheses++;
            } elseif ($byte === ')') {
                if ($parentheses > 0) {
                    $parentheses--;
                }
            } elseif ($byte === '[') {
                $brackets++;
            } elseif ($byte === ']') {
                if ($brackets > 0) {
                    $brackets--;
                }
            } elseif ($parentheses === 0 && $brackets === 0 && $byte === ';') {
                return $offset + 1;
            } elseif ($byte === '{' || $byte === '}') {
                if ($parentheses === 0 && $brackets === 0) {
                    return null;
                }
                $braceInsideNesting = true;
            }

            $offset++;
        }

        return ($parentheses !== 0 || $brackets !== 0) && $braceInsideNesting
            ? null
            : $length;
    }

    /**
     * @return array{end:int,value:string}|null
     */
    private static function urlAt(string $css, int $offset): ?array
    {
        $length = strlen($css);
        if (
            $offset > 0
            && (self::isIdentifierByte($css[$offset - 1]) || ord($css[$offset - 1]) >= 0x80)
        ) {
            return null;
        }

        $identifier = self::identifierAt($css, $offset);
        if ($identifier === null || strcasecmp($identifier['value'], 'url') !== 0) {
            return null;
        }

        $cursor = self::skipWhitespaceAndComments($css, $identifier['end']);
        if ($cursor >= $length || $css[$cursor] !== '(') {
            return null;
        }
        $cursor = self::skipWhitespaceAndComments($css, $cursor + 1);
        if ($cursor >= $length) {
            return null;
        }

        if ($css[$cursor] === '"' || $css[$cursor] === "'") {
            $quote = $css[$cursor];
            $valueStart = $cursor + 1;
            $cursor++;

            while ($cursor < $length) {
                if ($css[$cursor] === '\\') {
                    $cursor += min(2, $length - $cursor);
                    continue;
                }
                if ($css[$cursor] === $quote) {
                    $value = substr($css, $valueStart, $cursor - $valueStart);
                    $cursor = self::skipWhitespaceAndComments($css, $cursor + 1);
                    if ($cursor < $length && $css[$cursor] === ')') {
                        return ['end' => $cursor + 1, 'value' => trim($value)];
                    }
                    return null;
                }
                $cursor++;
            }

            return null;
        }

        $valueStart = $cursor;
        while ($cursor < $length) {
            $byte = $css[$cursor];
            if ($byte === '\\') {
                $cursor += min(2, $length - $cursor);
                continue;
            }
            if ($byte === ')') {
                return [
                    'end'   => $cursor + 1,
                    'value' => trim(substr($css, $valueStart, $cursor - $valueStart)),
                ];
            }
            if ($byte === '{' || $byte === '}') {
                return null;
            }
            $cursor++;
        }

        return null;
    }

    /**
     * @return array{end:int,value:string}|null
     */
    private static function identifierAt(string $css, int $offset): ?array
    {
        $length = strlen($css);
        $start = $offset;

        while ($offset < $length) {
            if ($css[$offset] === '\\') {
                $escaped = $css[$offset + 1] ?? null;
                if (
                    $escaped === null
                    || $escaped === "\n"
                    || $escaped === "\r"
                    || $escaped === "\f"
                ) {
                    return null;
                }

                $end = CssSyntaxScanner::escapeEnd($css, $offset);
                if ($end === null) {
                    return null;
                }
                $offset = $end;
                continue;
            }

            if (!self::isIdentifierByte($css[$offset]) && ord($css[$offset]) < 0x80) {
                break;
            }
            $offset++;
        }

        if ($offset === $start) {
            return null;
        }

        $decoded = self::decodeCssEscapes(substr($css, $start, $offset - $start));
        return $decoded === null ? null : ['end' => $offset, 'value' => $decoded];
    }

    private static function isExternalUrl(string $url): bool
    {
        $decoded = self::decodeCssEscapes($url);
        return $decoded !== null && preg_match('/^(?:https?:|\\/\\/)/i', $decoded) === 1;
    }

    private static function decodeCssEscapes(string $value): ?string
    {
        $decoded = '';
        $length = strlen($value);

        for ($offset = 0; $offset < $length;) {
            if ($value[$offset] !== '\\') {
                $decoded .= $value[$offset];
                $offset++;
                continue;
            }

            $end = CssSyntaxScanner::escapeEnd($value, $offset);
            if ($end === null) {
                return null;
            }

            $escapedOffset = $offset + 1;
            $escaped = $value[$escapedOffset];
            if (!ctype_xdigit($escaped)) {
                if ($escaped !== "\n" && $escaped !== "\r" && $escaped !== "\f") {
                    $decoded .= $escaped;
                }
                $offset = $end;
                continue;
            }

            $hexEnd = $escapedOffset;
            while (
                $hexEnd < $length
                && $hexEnd < $escapedOffset + 6
                && ctype_xdigit($value[$hexEnd])
            ) {
                $hexEnd++;
            }
            $codePoint = hexdec(substr($value, $escapedOffset, $hexEnd - $escapedOffset));
            if ($codePoint > 0 && $codePoint <= 0x7f) {
                $decoded .= chr($codePoint);
            } else {
                // Non-ASCII escapes cannot contribute to an ASCII network scheme.
                $decoded .= "\u{FFFD}";
            }
            $offset = $end;
        }

        return $decoded;
    }

    /**
     * @return array{start:int,end:int}|null
     */
    private static function declarationRange(string $css, int $urlOffset): ?array
    {
        $length = strlen($css);
        $segmentStart = 0;
        $braceDepth = 0;
        $parentheses = 0;

        for ($offset = 0; $offset < $urlOffset;) {
            if (self::startsComment($css, $offset)) {
                $offset = self::commentEnd($css, $offset);
                continue;
            }

            $byte = $css[$offset];
            if ($byte === '"' || $byte === "'") {
                $offset = self::stringEnd($css, $offset);
                continue;
            }
            if ($byte === '(') {
                $parentheses++;
            } elseif ($byte === ')' && $parentheses > 0) {
                $parentheses--;
            } elseif ($parentheses === 0 && $byte === '{') {
                $braceDepth++;
                $segmentStart = $offset + 1;
            } elseif ($parentheses === 0 && $byte === '}') {
                $braceDepth = max(0, $braceDepth - 1);
                $segmentStart = $offset + 1;
            } elseif ($parentheses === 0 && $byte === ';') {
                $segmentStart = $offset + 1;
            }
            $offset++;
        }

        if ($braceDepth === 0) {
            return null;
        }

        $start = self::skipWhitespaceAndComments($css, $segmentStart);
        $colon = null;
        $parentheses = 0;

        for ($offset = $start; $offset < $length;) {
            if (self::startsComment($css, $offset)) {
                $offset = self::commentEnd($css, $offset);
                continue;
            }

            $byte = $css[$offset];
            if ($byte === '"' || $byte === "'") {
                $offset = self::stringEnd($css, $offset);
                continue;
            }
            if ($byte === '(') {
                $parentheses++;
            } elseif ($byte === ')') {
                if ($parentheses === 0) {
                    return null;
                }
                $parentheses--;
            } elseif ($parentheses === 0 && $byte === ':' && $colon === null) {
                $colon = $offset;
            } elseif ($parentheses === 0 && $byte === '{') {
                return null;
            } elseif ($parentheses === 0 && $byte === ';') {
                if ($colon === null || $colon >= $urlOffset || $urlOffset >= $offset) {
                    return null;
                }
                return ['start' => $start, 'end' => $offset + 1];
            } elseif ($parentheses === 0 && $byte === '}') {
                if ($colon === null || $colon >= $urlOffset || $urlOffset >= $offset) {
                    return null;
                }
                return ['start' => $start, 'end' => $offset];
            }

            $offset++;
        }

        return null;
    }

    private static function startsComment(string $css, int $offset): bool
    {
        return isset($css[$offset + 1]) && $css[$offset] === '/' && $css[$offset + 1] === '*';
    }

    private static function hasRuleBrace(string $css, int $offset): bool
    {
        return strpos($css, '{', $offset) !== false || strpos($css, '}', $offset) !== false;
    }

    private static function commentEnd(string $css, int $offset): int
    {
        $end = strpos($css, '*/', $offset + 2);
        return $end === false ? strlen($css) : $end + 2;
    }

    private static function stringEnd(string $css, int $offset): int
    {
        $length = strlen($css);
        $quote = $css[$offset];
        $offset++;

        while ($offset < $length) {
            if ($css[$offset] === '\\') {
                $offset += min(2, $length - $offset);
                continue;
            }
            if ($css[$offset] === $quote) {
                return $offset + 1;
            }
            $offset++;
        }

        return $length;
    }

    private static function completeStringEnd(string $css, int $offset): ?int
    {
        $length = strlen($css);
        $quote = $css[$offset];
        $offset++;

        while ($offset < $length) {
            if ($css[$offset] === '\\') {
                $escapeEnd = CssSyntaxScanner::escapeEnd($css, $offset);
                if ($escapeEnd === null) {
                    return null;
                }
                $offset = $escapeEnd;
                continue;
            }
            if ($css[$offset] === $quote) {
                return $offset + 1;
            }
            if ($css[$offset] === "\n" || $css[$offset] === "\r" || $css[$offset] === "\f") {
                return null;
            }
            $offset++;
        }

        return null;
    }

    private static function skipWhitespaceAndComments(string $css, int $offset): int
    {
        $length = strlen($css);

        while ($offset < $length) {
            while ($offset < $length && str_contains(" \t\r\n\f", $css[$offset])) {
                $offset++;
            }
            if (!self::startsComment($css, $offset)) {
                break;
            }
            $offset = self::commentEnd($css, $offset);
        }

        return $offset;
    }

    private static function isIdentifierByte(string $byte): bool
    {
        return ctype_alnum($byte) || $byte === '_' || $byte === '-';
    }
}
