<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Html;

/**
 * Dependency-free, source-span HTML fragment parser for block attribute
 * sourcing.
 *
 * This is intentionally a bounded HTML parser, not a DOM implementation. It
 * handles the ordinary and recoverably malformed fragments exercised by the
 * supported block registry while retaining every original byte range. The
 * companion canonical serializer on HtmlNode supplies the DOM-like innerHTML
 * spelling expected by Gutenberg's source matchers.
 */
final class HtmlFragment
{
    private function __construct(
        private string $source,
        private HtmlNode $root,
    ) {}

    public static function parse(string $source): self
    {
        $length = strlen($source);
        $root = new HtmlNode($source, HtmlNode::DOCUMENT, 0, 0);
        /** @var list<HtmlNode> $stack */
        $stack = [$root];
        $offset = 0;

        while ($offset < $length) {
            $parent = $stack[count($stack) - 1];

            // Raw-text elements do not recognize tags other than their own
            // closer. This prevents JavaScript/CSS comparisons from becoming
            // accidental HTML nodes.
            $parentTag = $parent->tagName();
            if ($parentTag === 'script' || $parentTag === 'style'
                || $parentTag === 'textarea' || $parentTag === 'title') {
                $close = stripos($source, '</' . $parentTag, $offset);
                if ($close === false) {
                    self::appendText($parent, $source, $offset, $length);
                    $offset = $length;
                    break;
                }
                if ($close > $offset) {
                    self::appendText($parent, $source, $offset, $close);
                    $offset = $close;
                }
            }

            $lt = strpos($source, '<', $offset);
            if ($lt === false) {
                self::appendText($stack[count($stack) - 1], $source, $offset, $length);
                $offset = $length;
                break;
            }
            if ($lt > $offset) {
                self::appendText($stack[count($stack) - 1], $source, $offset, $lt);
                $offset = $lt;
            }

            if (substr($source, $offset, 4) === '<!--') {
                $commentEnd = strpos($source, '-->', $offset + 4);
                $end = $commentEnd === false ? $length : $commentEnd + 3;
                $dataEnd = $commentEnd === false ? $length : $commentEnd;
                $comment = new HtmlNode(
                    $source,
                    HtmlNode::COMMENT,
                    $offset,
                    $end,
                    null,
                    [],
                    substr($source, $offset + 4, $dataEnd - ($offset + 4)),
                );
                $comment->closeAt($end, $end);
                $stack[count($stack) - 1]->appendChild($comment);
                $offset = $end;
                continue;
            }

            // Markup declarations and processing instructions are not part of
            // the block sourcing grammar. Preserve them as raw text rather
            // than inventing an element.
            if (substr($source, $offset, 2) === '<!'
                || substr($source, $offset, 2) === '<?') {
                $tagEnd = self::scanTagEnd($source, $offset + 2);
                if ($tagEnd === null) {
                    self::appendText($stack[count($stack) - 1], $source, $offset, $length);
                    $offset = $length;
                } else {
                    self::appendText($stack[count($stack) - 1], $source, $offset, $tagEnd);
                    $offset = $tagEnd;
                }
                continue;
            }

            $tagEnd = self::scanTagEnd($source, $offset + 1);
            if ($tagEnd === null) {
                // A literal '<' in text. Consume only that byte so a later
                // valid tag remains discoverable.
                self::appendText($stack[count($stack) - 1], $source, $offset, $offset + 1);
                $offset++;
                continue;
            }

            $rawTag = substr($source, $offset, $tagEnd - $offset);
            if (preg_match('/^<\s*\/\s*([a-zA-Z][a-zA-Z0-9:-]*)[^>]*>$/s', $rawTag, $closeMatch) === 1) {
                $name = strtolower($closeMatch[1]);
                self::closeStackAt($stack, $name, $offset, $tagEnd);
                $offset = $tagEnd;
                continue;
            }

            if (preg_match('/^<\s*([a-zA-Z][a-zA-Z0-9:-]*)/s', $rawTag, $openMatch) !== 1) {
                self::appendText($stack[count($stack) - 1], $source, $offset, $offset + 1);
                $offset++;
                continue;
            }

            $name = strtolower($openMatch[1]);
            self::implicitlyCloseForStartTag($stack, $name, $offset);
            [$attributes, $selfClosing] = self::parseAttributes($rawTag, strlen($openMatch[0]));

            $node = new HtmlNode(
                $source,
                HtmlNode::ELEMENT,
                $offset,
                $tagEnd,
                $name,
                $attributes,
            );
            $stack[count($stack) - 1]->appendChild($node);

            if (HtmlNode::isVoidTag($name) || $selfClosing) {
                $node->closeAt($tagEnd, $tagEnd);
            } else {
                $stack[] = $node;
            }
            $offset = $tagEnd;
        }

        while (count($stack) > 1) {
            $node = array_pop($stack);
            $node->closeAt($length, $length);
        }
        $root->closeAt($length, $length);

        return new self($source, $root);
    }

    public function root(): HtmlNode
    {
        return $this->root;
    }

    /** Exact original fragment bytes. */
    public function rawHtml(): string
    {
        return $this->source;
    }

    /** DOM-like canonical serialization of the fragment. */
    public function innerHtml(): string
    {
        return $this->root->innerHtml();
    }

    public function textContent(): string
    {
        return $this->root->textContent();
    }

    /** @return list<HtmlNode> */
    public function children(): array
    {
        return $this->root->children();
    }

    /** @return list<HtmlNode> */
    public function querySelectorAll(string $selector): array
    {
        return $this->root->querySelectorAll($selector);
    }

    public function querySelector(string $selector): ?HtmlNode
    {
        return $this->root->querySelector($selector);
    }

    private static function appendText(HtmlNode $parent, string $source, int $start, int $end): void
    {
        if ($end <= $start) {
            return;
        }
        $raw = substr($source, $start, $end - $start);
        // Script/style contents are raw text: browsers never decode entities
        // there, unlike RCDATA elements (textarea, title) and normal text.
        $decoded = HtmlNode::isRawTextTag($parent->tagName())
            ? $raw
            : html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $node = new HtmlNode(
            $source,
            HtmlNode::TEXT,
            $start,
            $end,
            null,
            [],
            $decoded,
        );
        $node->closeAt($end, $end);
        $parent->appendChild($node);
    }

    /** Return the byte offset immediately after '>', respecting quotes. */
    private static function scanTagEnd(string $source, int $offset): ?int
    {
        $quote = null;
        $length = strlen($source);
        for ($i = $offset; $i < $length; $i++) {
            $char = $source[$i];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }
            if ($char === '>') {
                return $i + 1;
            }
        }
        return null;
    }

    /**
     * @return array{0:list<array{name:string,value:string,hasValue:bool}>,1:bool}
     */
    private static function parseAttributes(string $rawTag, int $offset): array
    {
        $attributes = [];
        $length = strlen($rawTag);
        $selfClosing = false;

        while ($offset < $length) {
            while ($offset < $length && self::isHtmlWhitespace($rawTag[$offset])) {
                $offset++;
            }
            if ($offset >= $length || $rawTag[$offset] === '>') {
                break;
            }
            if ($rawTag[$offset] === '/') {
                $selfClosing = true;
                $offset++;
                continue;
            }

            $nameStart = $offset;
            while ($offset < $length
                && !self::isHtmlWhitespace($rawTag[$offset])
                && !in_array($rawTag[$offset], ['/', '>', '='], true)) {
                $offset++;
            }
            if ($offset === $nameStart) {
                $offset++;
                continue;
            }
            $name = strtolower(substr($rawTag, $nameStart, $offset - $nameStart));
            while ($offset < $length && self::isHtmlWhitespace($rawTag[$offset])) {
                $offset++;
            }

            $hasValue = false;
            $rawValue = '';
            if ($offset < $length && $rawTag[$offset] === '=') {
                $hasValue = true;
                $offset++;
                while ($offset < $length && self::isHtmlWhitespace($rawTag[$offset])) {
                    $offset++;
                }
                if ($offset < $length && ($rawTag[$offset] === '"' || $rawTag[$offset] === "'")) {
                    $quote = $rawTag[$offset++];
                    $valueStart = $offset;
                    while ($offset < $length && $rawTag[$offset] !== $quote) {
                        $offset++;
                    }
                    $rawValue = substr($rawTag, $valueStart, $offset - $valueStart);
                    if ($offset < $length) {
                        $offset++;
                    }
                } else {
                    $valueStart = $offset;
                    // A slash glued to an unquoted value belongs to that value
                    // (`src=x/>` => `src="x/"`) in the HTML tokenizer.
                    while ($offset < $length
                        && !self::isHtmlWhitespace($rawTag[$offset])
                        && $rawTag[$offset] !== '>') {
                        $offset++;
                    }
                    $rawValue = substr($rawTag, $valueStart, $offset - $valueStart);
                }
            }

            $attributes[] = [
                'name' => $name,
                'value' => html_entity_decode($rawValue, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'hasValue' => $hasValue,
            ];
        }

        return [$attributes, $selfClosing];
    }

    /** @param list<HtmlNode> $stack */
    private static function closeStackAt(
        array &$stack,
        string $name,
        int $closeStart,
        int $closeEnd,
    ): void {
        $found = null;
        for ($i = count($stack) - 1; $i >= 1; $i--) {
            if ($stack[$i]->tagName() === $name) {
                $found = $i;
                break;
            }
        }
        if ($found === null) {
            return; // stray closer, ignored by the fragment tree
        }

        while (count($stack) - 1 > $found) {
            $node = array_pop($stack);
            $node->closeAt($closeStart, $closeStart);
        }
        $node = array_pop($stack);
        $node->closeAt($closeStart, $closeEnd);
    }

    /**
     * A small set of HTML optional-end-tag rules needed by saved block markup.
     * It prevents repeated list/table/paragraph children from incorrectly
     * nesting when an authored fragment omits optional closers.
     *
     * @param list<HtmlNode> $stack
     */
    private static function implicitlyCloseForStartTag(array &$stack, string $name, int $at): void
    {
        $top = $stack[count($stack) - 1]->tagName();
        $sameTagClosers = [
            'li' => ['li'],
            'dt' => ['dt', 'dd'],
            'dd' => ['dt', 'dd'],
            'rt' => ['rt', 'rp'],
            'rp' => ['rt', 'rp'],
            'option' => ['option'],
            'optgroup' => ['optgroup'],
            'tr' => ['tr'],
            'th' => ['th', 'td'],
            'td' => ['th', 'td'],
            'thead' => ['thead', 'tbody', 'tfoot'],
            'tbody' => ['tbody', 'tfoot'],
            'tfoot' => ['tbody'],
        ];
        if (isset($sameTagClosers[$name]) && in_array($top, $sameTagClosers[$name], true)) {
            $node = array_pop($stack);
            $node->closeAt($at, $at);
            $top = $stack[count($stack) - 1]->tagName();
        }

        $blockTags = [
            'address', 'article', 'aside', 'blockquote', 'div', 'dl', 'fieldset',
            'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header',
            'hr', 'main', 'menu', 'nav', 'ol', 'p', 'pre', 'section', 'table',
            'ul',
        ];
        if ($top === 'p' && in_array($name, $blockTags, true)) {
            $node = array_pop($stack);
            $node->closeAt($at, $at);
        }
    }

    private static function isHtmlWhitespace(string $char): bool
    {
        return $char === ' ' || $char === "\t" || $char === "\n"
            || $char === "\r" || $char === "\f";
    }
}
