<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Shared load/locate/sanitize boundary for untrusted design HTML.
 *
 * Island-pages and transform-chrome both consume this so landmark location
 * and sanitizing cannot drift apart. parse() reports genuine tag mismatches
 * and truncation; it ignores the HTML5-unknown-tag notices libxml emits for
 * every real design page.
 */
final class DesignDocument
{
    private const VOID_ELEMENTS = [
        'area',
        'base',
        'br',
        'col',
        'embed',
        'hr',
        'img',
        'input',
        'link',
        'meta',
        'param',
        'source',
        'track',
        'wbr',
    ];

    private const RAW_TEXT_ELEMENTS = [
        'script',
        'style',
        'title',
        'textarea',
    ];

    /** HTML5 elements whose end tag may be omitted. */
    private const OPTIONAL_END_TAGS = [
        'html',
        'head',
        'body',
        'li',
        'dt',
        'dd',
        'p',
        'rt',
        'rp',
        'optgroup',
        'option',
        'colgroup',
        'caption',
        'thead',
        'tbody',
        'tfoot',
        'tr',
        'td',
        'th',
    ];

    private function __construct(private \DOMDocument $dom)
    {
    }

    /**
     * @param list<string> $structuralErrors
     */
    public static function parse(string $html, array &$structuralErrors = []): ?self
    {
        $structuralErrors = [];
        if ($html === '') {
            $structuralErrors[] = 'document is empty';
            return null;
        }

        $structuralErrors = self::sourceStructuralErrors($html);

        $dom = Html::loadUtf8Html($html, LIBXML_NONET);
        if ($dom === null) {
            if ($structuralErrors === []) {
                $structuralErrors[] = 'document failed to load';
            }
            return null;
        }

        self::reportMainStructure($dom, $structuralErrors);

        return new self($dom);
    }

    public function main(): ?\DOMElement
    {
        $body = $this->bodyElement();
        if ($body === null) {
            return null;
        }
        $found = [];
        foreach ($this->dom->getElementsByTagName('main') as $element) {
            $found[] = $element;
        }
        if (count($found) !== 1 || $found[0]->parentNode !== $body) {
            return null;
        }
        return $found[0];
    }

    public function header(): ?\DOMElement
    {
        return $this->topLevelLandmark('header');
    }

    public function footer(): ?\DOMElement
    {
        return $this->topLevelLandmark('footer');
    }

    public function html(\DOMElement $element): string
    {
        return $this->serialize($element);
    }

    /**
     * @param list<string> $warnings
     */
    public function sanitizedHtml(
        \DOMElement $element,
        string $path,
        string $context,
        array &$warnings,
    ): string {
        $root = $element->cloneNode(true);
        if (!$root instanceof \DOMElement) {
            throw new \RuntimeException('failed to clone design element');
        }
        $stripped = [];
        self::stripProcessingInstructions($root, $stripped);
        foreach ($stripped as $authored) {
            $warnings[] = "malformed_design: {$path} context {$context}; authored {$authored}; "
                . 'delivered removed; disposition removed';
        }
        return DesignMarkupSanitizer::sanitize(
            $this->serialize($root),
            $path,
            $context,
            $warnings,
        );
    }

    public function styles(): string
    {
        $css = '';
        foreach ($this->dom->getElementsByTagName('style') as $style) {
            $css .= $style->textContent;
        }
        return $css;
    }

    private function bodyElement(): ?\DOMElement
    {
        $body = $this->dom->getElementsByTagName('body')->item(0);
        return $body instanceof \DOMElement ? $body : null;
    }

    private function topLevelLandmark(string $tag): ?\DOMElement
    {
        $body = $this->bodyElement();
        if ($body === null) {
            return null;
        }
        foreach ($body->childNodes as $child) {
            if ($child instanceof \DOMElement && strtolower($child->nodeName) === $tag) {
                return $child;
            }
        }
        return null;
    }

    private function serialize(\DOMElement $element): string
    {
        $html = $this->dom->saveHTML($element);
        if ($html === false) {
            throw new \RuntimeException('failed to serialize design element');
        }
        return $html;
    }

    /**
     * @param list<string> $stripped
     */
    private static function stripProcessingInstructions(\DOMNode $node, array &$stripped): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_PI_NODE) {
                $serialized = $node->ownerDocument !== null
                    ? $node->ownerDocument->saveHTML($child)
                    : false;
                $stripped[] = is_string($serialized) && $serialized !== ''
                    ? $serialized
                    : '<?' . $child->nodeName . '?>';
                $node->removeChild($child);
                continue;
            }
            self::stripProcessingInstructions($child, $stripped);
        }
    }

    /**
     * @param list<string> $structuralErrors
     */
    private static function reportMainStructure(\DOMDocument $dom, array &$structuralErrors): void
    {
        $mains = $dom->getElementsByTagName('main');
        if ($mains->length > 1) {
            $structuralErrors[] = 'document has more than one main element';
            return;
        }
        if ($mains->length === 0) {
            return;
        }
        $main = $mains->item(0);
        $body = $dom->getElementsByTagName('body')->item(0);
        if (
            $main instanceof \DOMElement
            && $body instanceof \DOMElement
            && $main->parentNode !== $body
        ) {
            $structuralErrors[] = 'main is not a direct child of body';
        }
    }

    /**
     * Source-level tag stack. libxml auto-closes at EOF (so truncation is
     * silent) and treats tags inside <script> as real markup (so "</div>"
     * in a string is a false mismatch).
     *
     * @return list<string>
     */
    private static function sourceStructuralErrors(string $html): array
    {
        $errors = [];
        $stack = [];
        $offset = 0;
        $length = strlen($html);
        while ($offset < $length) {
            $start = strpos($html, '<', $offset);
            if ($start === false) {
                break;
            }

            if (substr($html, $start, 4) === '<!--') {
                $end = self::commentEnd($html, $start);
                if ($end === null) {
                    $errors[] = 'document truncated inside a comment';
                    return $errors;
                }
                $offset = $end;
                continue;
            }

            if (substr($html, $start, 2) === '<!' || substr($html, $start, 2) === '<?') {
                $end = strpos($html, '>', $start + 2);
                if ($end === false) {
                    $errors[] = 'document truncated inside a declaration';
                    return $errors;
                }
                $offset = $end + 1;
                continue;
            }

            $tag = self::sourceTagAt($html, $start);
            if ($tag === null) {
                if (preg_match('/\G<\/?[A-Za-z]/', $html, $unused, 0, $start) === 1) {
                    $errors[] = 'document truncated inside a tag';
                    return $errors;
                }
                $offset = $start + 1;
                continue;
            }

            $offset = $tag['end'];
            $name = $tag['name'];

            if ($tag['closing']) {
                if (in_array($name, self::VOID_ELEMENTS, true)) {
                    continue;
                }
                self::popOptionalUntil($stack, $name);
                $top = $stack === [] ? null : $stack[array_key_last($stack)];
                if ($top !== $name) {
                    $errors[] = $top === null
                        ? "Unexpected end tag : {$name}"
                        : "Opening and ending tag mismatch: {$top} and {$name}";
                    continue;
                }
                array_pop($stack);
                continue;
            }

            if (in_array($name, self::VOID_ELEMENTS, true) || $tag['self_closing']) {
                continue;
            }

            if (in_array($name, self::RAW_TEXT_ELEMENTS, true)) {
                $close = self::rawTextCloseTag($html, $name, $offset);
                if ($close === null) {
                    $errors[] = "document truncated inside <{$name}>";
                    return $errors;
                }
                $offset = $close['end'];
                continue;
            }

            $stack[] = $name;
        }

        while ($stack !== []) {
            $top = $stack[array_key_last($stack)];
            if (!in_array($top, self::OPTIONAL_END_TAGS, true)) {
                break;
            }
            array_pop($stack);
        }
        if ($stack !== []) {
            $errors[] = 'document truncated with unclosed ' . implode(', ', $stack);
        }
        return $errors;
    }

    /**
     * @param list<string> $stack
     */
    private static function popOptionalUntil(array &$stack, string $name): void
    {
        while ($stack !== []) {
            $top = $stack[array_key_last($stack)];
            if ($top === $name || !in_array($top, self::OPTIONAL_END_TAGS, true)) {
                return;
            }
            array_pop($stack);
        }
    }

    /**
     * @return array{end:int,name:string,closing:bool,self_closing:bool}|null
     */
    private static function sourceTagAt(string $html, int $start): ?array
    {
        if (
            preg_match(
                '/\G<(\/?)([A-Za-z][A-Za-z0-9:-]*)(?=[\x09\x0A\x0C\x0D\x20\/>])/',
                $html,
                $match,
                0,
                $start,
            ) !== 1
        ) {
            return null;
        }
        $end = self::tagEnd($html, $start + strlen($match[0]));
        if ($end === null) {
            return null;
        }
        $inner = substr($html, $start, $end - $start);
        return [
            'end'          => $end,
            'name'         => strtolower($match[2]),
            'closing'      => $match[1] === '/',
            'self_closing' => str_ends_with(rtrim($inner, '>'), '/'),
        ];
    }

    private static function tagEnd(string $html, int $offset): ?int
    {
        $quote = null;
        for ($length = strlen($html); $offset < $length; $offset++) {
            $char = $html[$offset];
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
                return $offset + 1;
            }
        }
        return null;
    }

    /**
     * @return array{end:int}|null
     */
    private static function rawTextCloseTag(string $html, string $name, int $offset): ?array
    {
        while (($start = stripos($html, '</' . $name, $offset)) !== false) {
            $tag = self::sourceTagAt($html, $start);
            if ($tag !== null && $tag['closing'] && $tag['name'] === $name) {
                return ['end' => $tag['end']];
            }
            $offset = $start + 2;
        }
        return null;
    }

    private static function commentEnd(string $html, int $start): ?int
    {
        $offset = $start + 4;
        while (($end = strpos($html, '>', $offset)) !== false) {
            if (
                substr($html, max($start + 4, $end - 2), 2) === '--'
                || substr($html, max($start + 4, $end - 3), 3) === '--!'
            ) {
                return $end + 1;
            }
            $offset = $end + 1;
        }
        return null;
    }
}
