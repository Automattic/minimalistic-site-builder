<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Html;

/**
 * Strict CSS selector compiler for the closed grammar present in the pinned
 * block registry.
 *
 * Supported: comma-separated groups, descendant and child combinators, tag,
 * universal, class, id, attribute presence/value operators, and the registry's
 * `:not([attribute])` form. Unsupported syntax throws before any file commit.
 */
final class Selector
{
    /**
     * @param list<array{
     *   compounds:list<array{
     *     tag:?string,
     *     ids:list<string>,
     *     classes:list<string>,
     *     attributes:list<array{name:string,operator:?string,value:?string}>,
     *     not:list<array<mixed>>
     *   }>,
     *   combinators:list<string>
     * }> $groups
     */
    private function __construct(private array $groups) {}

    public static function compile(string $selector): self
    {
        $selector = trim($selector);
        if ($selector === '') {
            throw new \RuntimeException('Unsupported empty HTML selector');
        }

        $groups = [];
        foreach (self::splitGroups($selector) as $group) {
            $groups[] = self::parseGroup($group);
        }
        return new self($groups);
    }

    /** @return list<HtmlNode> descendants in document order, without duplicates */
    public function selectAll(HtmlNode $context): array
    {
        $matches = [];
        $visit = function (HtmlNode $node) use (&$visit, &$matches): void {
            foreach ($node->children() as $child) {
                if ($child->isElement() && $this->matches($child)) {
                    $matches[] = $child;
                }
                $visit($child);
            }
        };
        $visit($context);
        return $matches;
    }

    public function matches(HtmlNode $node): bool
    {
        if (!$node->isElement()) {
            return false;
        }
        foreach ($this->groups as $group) {
            if ($this->matchesGroup($node, $group)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private static function splitGroups(string $selector): array
    {
        $groups = [];
        $start = 0;
        $brackets = 0;
        $parentheses = 0;
        $quote = null;
        $length = strlen($selector);

        for ($i = 0; $i < $length; $i++) {
            $char = $selector[$i];
            if ($quote !== null) {
                if ($char === '\\') {
                    throw new \RuntimeException("Unsupported CSS escape in selector: {$selector}");
                }
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '[') {
                $brackets++;
            } elseif ($char === ']') {
                $brackets--;
            } elseif ($char === '(') {
                $parentheses++;
            } elseif ($char === ')') {
                $parentheses--;
            } elseif ($char === ',' && $brackets === 0 && $parentheses === 0) {
                $group = trim(substr($selector, $start, $i - $start));
                if ($group === '') {
                    throw new \RuntimeException("Malformed selector group: {$selector}");
                }
                $groups[] = $group;
                $start = $i + 1;
            }
            if ($brackets < 0 || $parentheses < 0) {
                throw new \RuntimeException("Malformed HTML selector: {$selector}");
            }
        }
        if ($quote !== null || $brackets !== 0 || $parentheses !== 0) {
            throw new \RuntimeException("Malformed HTML selector: {$selector}");
        }
        $last = trim(substr($selector, $start));
        if ($last === '') {
            throw new \RuntimeException("Malformed selector group: {$selector}");
        }
        $groups[] = $last;
        return $groups;
    }

    /** @return array{compounds:list<array<mixed>>,combinators:list<string>} */
    private static function parseGroup(string $group): array
    {
        $compounds = [];
        $combinators = [];
        $offset = 0;
        $length = strlen($group);

        while ($offset < $length) {
            while ($offset < $length && self::isWhitespace($group[$offset])) {
                $offset++;
            }
            if ($offset >= $length) {
                break;
            }

            [$compound, $offset] = self::parseCompound($group, $offset);
            $compounds[] = $compound;

            $hadWhitespace = false;
            while ($offset < $length && self::isWhitespace($group[$offset])) {
                $hadWhitespace = true;
                $offset++;
            }
            if ($offset >= $length) {
                break;
            }
            if ($group[$offset] === '>') {
                $combinators[] = 'child';
                $offset++;
                while ($offset < $length && self::isWhitespace($group[$offset])) {
                    $offset++;
                }
                if ($offset >= $length) {
                    throw new \RuntimeException("Selector ends in child combinator: {$group}");
                }
                continue;
            }
            if ($hadWhitespace) {
                $combinators[] = 'descendant';
                continue;
            }
            throw new \RuntimeException("Unsupported selector syntax near: " . substr($group, $offset));
        }

        if ($compounds === [] || count($combinators) !== count($compounds) - 1) {
            throw new \RuntimeException("Malformed HTML selector: {$group}");
        }
        return ['compounds' => $compounds, 'combinators' => $combinators];
    }

    /** @return array{0:array<mixed>,1:int} */
    private static function parseCompound(string $selector, int $offset): array
    {
        $length = strlen($selector);
        $compound = [
            'tag' => null,
            'ids' => [],
            'classes' => [],
            'attributes' => [],
            'not' => [],
        ];
        $consumed = false;

        if ($selector[$offset] === '*') {
            $compound['tag'] = '*';
            $offset++;
            $consumed = true;
        } elseif (self::isIdentifierStart($selector[$offset])) {
            [$tag, $offset] = self::readIdentifier($selector, $offset);
            $compound['tag'] = strtolower($tag);
            $consumed = true;
        }

        while ($offset < $length) {
            $char = $selector[$offset];
            if ($char === '.' || $char === '#') {
                $kind = $char;
                $offset++;
                if ($offset >= $length || !self::isIdentifierStart($selector[$offset])) {
                    throw new \RuntimeException("Malformed class/id selector: {$selector}");
                }
                [$value, $offset] = self::readIdentifier($selector, $offset);
                $compound[$kind === '.' ? 'classes' : 'ids'][] = $value;
                $consumed = true;
                continue;
            }
            if ($char === '[') {
                [$attribute, $offset] = self::parseAttributeSelector($selector, $offset);
                $compound['attributes'][] = $attribute;
                $consumed = true;
                continue;
            }
            if (substr($selector, $offset, 5) === ':not(') {
                [$negative, $offset] = self::parseNot($selector, $offset);
                $compound['not'][] = $negative;
                $consumed = true;
                continue;
            }
            if ($char === '>' || self::isWhitespace($char)) {
                break;
            }
            // Sibling combinators, pseudo classes other than the pinned :not
            // form, namespace selectors, and escapes are outside the manifest.
            throw new \RuntimeException("Unsupported selector syntax near: " . substr($selector, $offset));
        }

        if (!$consumed) {
            throw new \RuntimeException("Malformed HTML selector: {$selector}");
        }
        return [$compound, $offset];
    }

    /** @return array{0:array{name:string,operator:?string,value:?string},1:int} */
    private static function parseAttributeSelector(string $selector, int $offset): array
    {
        $end = self::findClosing($selector, $offset + 1, ']');
        if ($end === null) {
            throw new \RuntimeException("Unclosed attribute selector: {$selector}");
        }
        $body = trim(substr($selector, $offset + 1, $end - $offset - 1));
        $pattern = '/^([a-zA-Z_][a-zA-Z0-9_.:-]*)\s*'
            . '(?:(~=|\|=|\^=|\$=|\*=|=)\s*'
            . '(?:"([^"]*)"|\'([^\']*)\'|([^\s]+)))?$/';
        if (preg_match($pattern, $body, $match) !== 1) {
            throw new \RuntimeException("Unsupported attribute selector: [{$body}]");
        }
        $operator = ($match[2] ?? '') !== '' ? $match[2] : null;
        $value = null;
        if ($operator !== null) {
            if (isset($match[3]) && $match[3] !== '') {
                $value = $match[3];
            } elseif (isset($match[4]) && $match[4] !== '') {
                $value = $match[4];
            } else {
                $value = $match[5] ?? '';
            }
        }
        return [[
            'name' => strtolower($match[1]),
            'operator' => $operator,
            'value' => $value,
        ], $end + 1];
    }

    /** @return array{0:array<mixed>,1:int} */
    private static function parseNot(string $selector, int $offset): array
    {
        $bodyStart = $offset + 5;
        $end = self::findClosing($selector, $bodyStart, ')');
        if ($end === null) {
            throw new \RuntimeException("Unclosed :not() selector: {$selector}");
        }
        $body = trim(substr($selector, $bodyStart, $end - $bodyStart));
        if ($body === '' || str_contains($body, ',') || str_contains($body, '>')) {
            throw new \RuntimeException("Unsupported :not() selector: {$body}");
        }
        [$compound, $consumed] = self::parseCompound($body, 0);
        while ($consumed < strlen($body) && self::isWhitespace($body[$consumed])) {
            $consumed++;
        }
        if ($consumed !== strlen($body)) {
            throw new \RuntimeException("Unsupported :not() selector: {$body}");
        }
        return [$compound, $end + 1];
    }

    private static function findClosing(string $selector, int $offset, string $closing): ?int
    {
        $quote = null;
        $length = strlen($selector);
        for ($i = $offset; $i < $length; $i++) {
            $char = $selector[$i];
            if ($quote !== null) {
                if ($char === '\\') {
                    throw new \RuntimeException("Unsupported CSS escape in selector: {$selector}");
                }
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === $closing) {
                return $i;
            }
        }
        return null;
    }

    /** @param array{compounds:list<array<mixed>>,combinators:list<string>} $group */
    private function matchesGroup(HtmlNode $node, array $group): bool
    {
        $index = count($group['compounds']) - 1;
        if (!self::matchesCompound($node, $group['compounds'][$index])) {
            return false;
        }

        while ($index > 0) {
            $relation = $group['combinators'][$index - 1];
            $index--;
            if ($relation === 'child') {
                $node = $node->parent();
                if ($node === null || !self::matchesCompound($node, $group['compounds'][$index])) {
                    return false;
                }
                continue;
            }

            $ancestor = $node->parent();
            while ($ancestor !== null
                && !self::matchesCompound($ancestor, $group['compounds'][$index])) {
                $ancestor = $ancestor->parent();
            }
            if ($ancestor === null) {
                return false;
            }
            $node = $ancestor;
        }
        return true;
    }

    /** @param array<mixed> $compound */
    private static function matchesCompound(HtmlNode $node, array $compound): bool
    {
        if (!$node->isElement()) {
            return false;
        }
        if ($compound['tag'] !== null && $compound['tag'] !== '*'
            && $node->tagName() !== $compound['tag']) {
            return false;
        }
        foreach ($compound['ids'] as $id) {
            if ($node->attribute('id') !== $id) {
                return false;
            }
        }
        $classes = preg_split('/[\x20\t\r\n\f]+/', trim($node->attribute('class') ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($compound['classes'] as $class) {
            if (!in_array($class, $classes, true)) {
                return false;
            }
        }
        foreach ($compound['attributes'] as $attribute) {
            if (!self::matchesAttribute($node, $attribute)) {
                return false;
            }
        }
        foreach ($compound['not'] as $negative) {
            if (self::matchesCompound($node, $negative)) {
                return false;
            }
        }
        return true;
    }

    /** @param array{name:string,operator:?string,value:?string} $selector */
    private static function matchesAttribute(HtmlNode $node, array $selector): bool
    {
        if (!$node->hasAttribute($selector['name'])) {
            return false;
        }
        if ($selector['operator'] === null) {
            return true;
        }
        $actual = $node->attribute($selector['name']) ?? '';
        $expected = $selector['value'] ?? '';
        return match ($selector['operator']) {
            '=' => $actual === $expected,
            '~=' => in_array($expected, preg_split('/\s+/', trim($actual), -1, PREG_SPLIT_NO_EMPTY) ?: [], true),
            '|=' => $actual === $expected || str_starts_with($actual, $expected . '-'),
            '^=' => str_starts_with($actual, $expected),
            '$=' => str_ends_with($actual, $expected),
            '*=' => str_contains($actual, $expected),
            default => false,
        };
    }

    /** @return array{0:string,1:int} */
    private static function readIdentifier(string $selector, int $offset): array
    {
        $start = $offset;
        $length = strlen($selector);
        while ($offset < $length && self::isIdentifierChar($selector[$offset])) {
            $offset++;
        }
        return [substr($selector, $start, $offset - $start), $offset];
    }

    private static function isIdentifierStart(string $char): bool
    {
        return preg_match('/[a-zA-Z_-]/', $char) === 1;
    }

    private static function isIdentifierChar(string $char): bool
    {
        return preg_match('/[a-zA-Z0-9_-]/', $char) === 1;
    }

    private static function isWhitespace(string $char): bool
    {
        return $char === ' ' || $char === "\t" || $char === "\n"
            || $char === "\r" || $char === "\f";
    }
}
