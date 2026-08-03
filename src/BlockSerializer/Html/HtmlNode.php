<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Html;

/**
 * One node in a parsed HTML fragment.
 *
 * Element and text nodes retain their byte ranges in the original fragment.
 * rawHtml()/rawInnerHtml() therefore never normalize authored bytes, while
 * outerHtml()/innerHtml() expose the browser-like serialization used by block
 * attribute sourcing.
 */
final class HtmlNode
{
    public const DOCUMENT = 'document';
    public const ELEMENT = 'element';
    public const TEXT = 'text';
    public const COMMENT = 'comment';

    /** @var list<self> */
    private array $children = [];

    /**
     * Attribute records in source order. Duplicate HTML attributes are
     * discarded after the first, matching the HTML parser.
     *
     * @var list<array{name:string,value:string,hasValue:bool}>
     */
    private array $attributes = [];

    /** @var array<string,int> lower-case attribute name => record index */
    private array $attributeIndex = [];

    private ?self $parent = null;
    private int $innerEnd;
    private int $end;

    /**
     * @param list<array{name:string,value:string,hasValue:bool}> $attributes
     */
    public function __construct(
        private string $source,
        private string $type,
        private int $start,
        private int $innerStart,
        private ?string $name = null,
        array $attributes = [],
        private ?string $data = null,
    ) {
        $this->innerEnd = $innerStart;
        $this->end = $innerStart;

        foreach ($attributes as $attribute) {
            $name = strtolower($attribute['name']);
            if (isset($this->attributeIndex[$name])) {
                continue;
            }
            $this->attributeIndex[$name] = count($this->attributes);
            $this->attributes[] = [
                'name' => $name,
                'value' => $attribute['value'],
                'hasValue' => $attribute['hasValue'],
            ];
        }
    }

    /** @internal Used by HtmlFragment's tokenizer. */
    public function appendChild(self $child): void
    {
        $child->parent = $this;
        $this->children[] = $child;
    }

    /** @internal Used by HtmlFragment's tokenizer. */
    public function closeAt(int $innerEnd, int $end): void
    {
        $this->innerEnd = max($this->innerStart, $innerEnd);
        $this->end = max($this->innerEnd, $end);
    }

    public function isDocument(): bool
    {
        return $this->type === self::DOCUMENT;
    }

    public function isElement(): bool
    {
        return $this->type === self::ELEMENT;
    }

    public function isText(): bool
    {
        return $this->type === self::TEXT;
    }

    public function isComment(): bool
    {
        return $this->type === self::COMMENT;
    }

    /** Lower-case HTML tag name, or null for non-elements. */
    public function tagName(): ?string
    {
        return $this->name;
    }

    public function parent(): ?self
    {
        return $this->parent;
    }

    /** @return list<self> */
    public function children(): array
    {
        return $this->children;
    }

    /** @return list<self> */
    public function elementChildren(): array
    {
        return array_values(array_filter(
            $this->children,
            static fn (self $node): bool => $node->isElement(),
        ));
    }

    public function startOffset(): int
    {
        return $this->start;
    }

    public function innerStartOffset(): int
    {
        return $this->innerStart;
    }

    public function innerEndOffset(): int
    {
        return $this->innerEnd;
    }

    public function endOffset(): int
    {
        return $this->end;
    }

    /** Exact source bytes occupied by this node. */
    public function rawHtml(): string
    {
        if ($this->isDocument()) {
            return $this->source;
        }
        return substr($this->source, $this->start, $this->end - $this->start);
    }

    /** Exact source bytes between this element's opening and closing tags. */
    public function rawInnerHtml(): string
    {
        if (!$this->isElement() && !$this->isDocument()) {
            return '';
        }
        return substr(
            $this->source,
            $this->innerStart,
            $this->innerEnd - $this->innerStart,
        );
    }

    /** Browser-like canonical serialization of this node. */
    public function outerHtml(): string
    {
        if ($this->isDocument()) {
            return $this->innerHtml();
        }
        if ($this->isText()) {
            return self::escapeText($this->data ?? '', $this->parent?->tagName());
        }
        if ($this->isComment()) {
            return '<!--' . ($this->data ?? '') . '-->';
        }

        $tag = $this->name ?? '';
        $html = '<' . $tag;
        foreach ($this->attributes as $attribute) {
            $html .= ' ' . $attribute['name'] . '="'
                . self::escapeAttribute($attribute['value']) . '"';
        }
        $html .= '>';

        if (self::isVoidTag($tag)) {
            return $html;
        }
        return $html . $this->innerHtml() . '</' . $tag . '>';
    }

    /** Browser-like canonical serialization of this node's children. */
    public function innerHtml(): string
    {
        if (!$this->isElement() && !$this->isDocument()) {
            return '';
        }
        $html = '';
        foreach ($this->children as $child) {
            $html .= $child->outerHtml();
        }
        return $html;
    }

    /** Decoded DOM-style textContent (comments do not contribute). */
    public function textContent(): string
    {
        if ($this->isText()) {
            return $this->data ?? '';
        }
        if ($this->isComment()) {
            return '';
        }
        $text = '';
        foreach ($this->children as $child) {
            $text .= $child->textContent();
        }
        return $text;
    }

    public function hasAttribute(string $name): bool
    {
        return isset($this->attributeIndex[strtolower($name)]);
    }

    /**
     * Decoded attribute value. A present boolean attribute returns the empty
     * string, while an absent attribute returns null.
     */
    public function attribute(string $name): ?string
    {
        $index = $this->attributeIndex[strtolower($name)] ?? null;
        return $index === null ? null : $this->attributes[$index]['value'];
    }

    /** @return list<array{name:string,value:string,hasValue:bool}> */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /**
     * A deliberately closed set of DOM properties used by legacy block
     * schemas. Unknown properties fail closed instead of silently becoming
     * null and triggering an unrelated default.
     */
    public function property(string $path): mixed
    {
        $path = trim($path);
        if ($path === 'nodeName' || $path === 'tagName') {
            return $this->isDocument() ? 'BODY' : strtoupper((string) $this->name);
        }
        if ($path === 'innerHTML') {
            return $this->innerHtml();
        }
        if ($path === 'outerHTML') {
            return $this->outerHtml();
        }
        if ($path === 'textContent' || $path === 'innerText') {
            return $this->textContent();
        }
        if ($path === 'className') {
            return $this->attribute('class') ?? '';
        }
        if ($path === 'htmlFor') {
            return $this->attribute('for') ?? '';
        }

        $booleanProperties = [
            'allowFullscreen', 'async', 'autofocus', 'autoplay', 'checked',
            'controls', 'default', 'defer', 'disabled', 'formNoValidate',
            'hidden', 'inert', 'ismap', 'loop', 'multiple', 'muted',
            'noModule', 'noValidate', 'open', 'playsInline', 'readonly',
            'required', 'reversed', 'selected',
        ];
        if (in_array($path, $booleanProperties, true)) {
            return $this->hasAttribute(strtolower($path));
        }

        if ($path === 'value' && $this->name === 'textarea') {
            return $this->textContent();
        }

        // Reflected string properties used by historical schemas. URL
        // properties deliberately return the authored spelling rather than
        // resolving against a host-dependent document URL.
        $reflected = [
            'alt', 'cite', 'colSpan', 'content', 'datetime', 'dir', 'height',
            'href', 'id', 'label', 'lang', 'max', 'min', 'name', 'placeholder',
            'poster', 'rel', 'rowSpan', 'src', 'start', 'target', 'title',
            'type', 'value', 'width',
        ];
        if (in_array($path, $reflected, true)) {
            $attributeName = match ($path) {
                'colSpan' => 'colspan',
                'rowSpan' => 'rowspan',
                'datetime' => 'datetime',
                default => strtolower($path),
            };
            return $this->attribute($attributeName) ?? '';
        }

        throw new \RuntimeException("Unsupported HTML property path: {$path}");
    }

    /** @return list<self> */
    public function querySelectorAll(string $selector): array
    {
        return Selector::compile($selector)->selectAll($this);
    }

    public function querySelector(string $selector): ?self
    {
        $matches = $this->querySelectorAll($selector);
        return $matches[0] ?? null;
    }

    public static function isVoidTag(string $tag): bool
    {
        return in_array(strtolower($tag), [
            'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
            'link', 'meta', 'param', 'source', 'track', 'wbr',
        ], true);
    }

    private static function escapeText(string $text, ?string $parentTag): string
    {
        if ($parentTag === 'script' || $parentTag === 'style') {
            return $text;
        }
        return str_replace(
            ['&', "\u{00A0}", '<', '>'],
            ['&amp;', '&nbsp;', '&lt;', '&gt;'],
            $text,
        );
    }

    private static function escapeAttribute(string $value): string
    {
        // HTML's fragment serializer escapes ampersands, non-breaking spaces,
        // and the active quote delimiter. It leaves <, >, and apostrophes as-is.
        return str_replace(
            ['&', "\u{00A0}", '"'],
            ['&amp;', '&nbsp;', '&quot;'],
            $value,
        );
    }
}
