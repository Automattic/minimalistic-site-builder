<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Attributes;

use Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment;
use Automattic\SiteBuild\BlockSerializer\Html\HtmlNode;
use Automattic\SiteBuild\BlockSerializer\Html\RichText;

/**
 * Resolve registered block attribute schemas against saved HTML.
 *
 * Inputs and outputs deliberately use plain PHP scalars/arrays. Typed JSON
 * object/array identity is owned by the parser/normalization layer; this class
 * only implements Gutenberg's source matchers, type/enum rejection, and
 * defaults. Unsupported source, selector, and property shapes fail closed.
 */
final class AttributeSourcer
{
    private object $missing;

    public function __construct()
    {
        $this->missing = new \stdClass();
    }

    /**
     * @param array<string,array<mixed>> $schemas
     * @param array<string,mixed> $commentAttributes
     * @return array<string,mixed>
     */
    public function source(
        array $schemas,
        array $commentAttributes,
        string $innerHtml,
    ): array {
        return $this->sourceAttributes($schemas, $commentAttributes, $innerHtml);
    }

    /**
     * @param array<string,array<mixed>> $schemas
     * @param array<string,mixed> $commentAttributes
     * @return array<string,mixed>
     */
    public function sourceAttributes(
        array $schemas,
        array $commentAttributes,
        string $innerHtml,
    ): array {
        $fragment = HtmlFragment::parse($innerHtml);
        $attributes = [];
        foreach ($schemas as $key => $schema) {
            if (!is_array($schema)) {
                throw new \RuntimeException("Invalid schema for block attribute {$key}");
            }
            $value = $this->resolveTopLevel(
                (string) $key,
                $schema,
                $commentAttributes,
                $fragment->root(),
                $innerHtml,
            );
            if ($value !== $this->missing) {
                $attributes[(string) $key] = $value;
            }
        }
        return $attributes;
    }

    /**
     * @param array<mixed> $schema
     * @param array<string,mixed> $commentAttributes
     */
    private function resolveTopLevel(
        string $key,
        array $schema,
        array $commentAttributes,
        HtmlNode $context,
        string $rawInnerHtml,
    ): mixed {
        if (!array_key_exists('source', $schema)) {
            $value = array_key_exists($key, $commentAttributes)
                ? $commentAttributes[$key]
                : $this->missing;
        } elseif ($schema['source'] === 'raw') {
            $value = $rawInnerHtml;
        } else {
            $value = $this->resolveSourced($schema, $context);
        }

        if ($value !== $this->missing
            && (!$this->validType($value, $schema['type'] ?? null)
                || !$this->validEnum($value, $schema['enum'] ?? null))) {
            $value = $this->missing;
        }

        if ($value === $this->missing && array_key_exists('default', $schema)) {
            return $schema['default'];
        }
        return $value;
    }

    /** @param array<mixed> $schema */
    private function resolveSourced(array $schema, HtmlNode $context): mixed
    {
        $source = $schema['source'] ?? null;
        if (!is_string($source)) {
            throw new \RuntimeException('Attribute source must be a string');
        }

        return match ($source) {
            'attribute' => $this->sourceHtmlAttribute($schema, $context),
            'property' => $this->sourceProperty($schema, $context),
            'html' => $this->sourceHtml($schema, $context),
            'text' => $this->sourceText($schema, $context),
            'rich-text' => $this->sourceRichText($schema, $context),
            'children' => $this->sourceChildren($schema, $context),
            'node' => $this->sourceNode($schema, $context),
            'query' => $this->sourceQuery($schema, $context),
            'tag' => $this->sourceTag($schema, $context),
            'raw' => throw new \RuntimeException('raw source is only valid at the top level'),
            default => throw new \RuntimeException("Unsupported block attribute source: {$source}"),
        };
    }

    /** @param array<mixed> $schema */
    private function sourceHtmlAttribute(array $schema, HtmlNode $context): mixed
    {
        $attribute = $schema['attribute'] ?? null;
        if (!is_string($attribute) || $attribute === '') {
            throw new \RuntimeException('attribute source requires a non-empty attribute name');
        }
        $target = $this->target($schema, $context);
        if (($schema['type'] ?? null) === 'boolean') {
            return $target !== null && $target->hasAttribute($attribute);
        }
        return $target?->attribute($attribute) ?? $this->missing;
    }

    /** @param array<mixed> $schema */
    private function sourceProperty(array $schema, HtmlNode $context): mixed
    {
        $property = $schema['property'] ?? null;
        if (!is_string($property) || $property === '') {
            throw new \RuntimeException('property source requires a non-empty property path');
        }
        $target = $this->target($schema, $context);
        return $target === null ? $this->missing : $target->property($property);
    }

    /** @param array<mixed> $schema */
    private function sourceHtml(array $schema, HtmlNode $context): string
    {
        $target = $this->target($schema, $context);
        if ($target === null) {
            return '';
        }
        if (array_key_exists('multiline', $schema)) {
            $tag = $schema['multiline'];
            if (!is_string($tag) || $tag === '') {
                throw new \RuntimeException('html multiline must name an element tag');
            }
            $html = '';
            foreach ($target->elementChildren() as $child) {
                if ($child->tagName() === strtolower($tag)) {
                    $html .= $child->outerHtml();
                }
            }
            return $html;
        }
        return $target->innerHtml();
    }

    /** @param array<mixed> $schema */
    private function sourceText(array $schema, HtmlNode $context): mixed
    {
        $target = $this->target($schema, $context);
        return $target?->textContent() ?? $this->missing;
    }

    /** @param array<mixed> $schema */
    private function sourceRichText(array $schema, HtmlNode $context): string
    {
        $target = $this->target($schema, $context);
        if ($target === null) {
            return '';
        }
        return RichText::normalize(
            $target,
            ($schema['__unstablePreserveWhiteSpace'] ?? false) === true,
        );
    }

    /** @param array<mixed> $schema */
    private function sourceChildren(array $schema, HtmlNode $context): array
    {
        $target = $this->target($schema, $context);
        if ($target === null) {
            return [];
        }
        return $this->legacyChildren($target);
    }

    /** @param array<mixed> $schema */
    private function sourceNode(array $schema, HtmlNode $context): mixed
    {
        $target = $this->target($schema, $context);
        return $target === null ? null : $this->legacyNode($target);
    }

    /** @param array<mixed> $schema */
    private function sourceQuery(array $schema, HtmlNode $context): array
    {
        $selector = $schema['selector'] ?? null;
        $query = $schema['query'] ?? null;
        if (!is_string($selector) || trim($selector) === '') {
            throw new \RuntimeException('query source requires a non-empty selector');
        }
        if (!is_array($query)) {
            throw new \RuntimeException('query source requires a query schema');
        }

        $rows = [];
        foreach ($context->querySelectorAll($selector) as $match) {
            $row = [];
            foreach ($query as $key => $subSchema) {
                if (!is_array($subSchema)) {
                    throw new \RuntimeException("Invalid query schema field {$key}");
                }
                // hpq query sub-matchers source directly; type/default handling
                // occurs on the containing top-level query attribute.
                if (($subSchema['source'] ?? null) === 'raw') {
                    throw new \RuntimeException('raw source is unsupported inside query');
                }
                if (!array_key_exists('source', $subSchema)) {
                    throw new \RuntimeException("Query field {$key} requires an explicit source");
                }
                $value = $this->resolveSourced($subSchema, $match);
                if ($value !== $this->missing) {
                    $row[(string) $key] = $value;
                }
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /** @param array<mixed> $schema */
    private function sourceTag(array $schema, HtmlNode $context): mixed
    {
        $target = $this->target($schema, $context);
        if ($target === null) {
            return $this->missing;
        }
        return $target->isDocument() ? 'body' : strtolower((string) $target->tagName());
    }

    /** @param array<mixed> $schema */
    private function target(array $schema, HtmlNode $context): ?HtmlNode
    {
        if (!array_key_exists('selector', $schema) || $schema['selector'] === null
            || $schema['selector'] === '') {
            return $context;
        }
        if (!is_string($schema['selector'])) {
            throw new \RuntimeException('HTML selector must be a string');
        }
        return $context->querySelector($schema['selector']);
    }

    /** @return list<string|array<mixed>> */
    private function legacyChildren(HtmlNode $node): array
    {
        $children = [];
        foreach ($node->children() as $child) {
            if ($child->isText()) {
                $children[] = $child->textContent();
            } elseif ($child->isElement()) {
                $children[] = $this->legacyNode($child);
            }
            // The legacy matcher ignores comments and other node types.
        }
        return $children;
    }

    /** @return array{type:string,props:array<mixed>} */
    private function legacyNode(HtmlNode $node): array
    {
        $props = [];
        foreach ($node->attributes() as $attribute) {
            $props[$attribute['name']] = $attribute['value'];
        }
        $props['children'] = $this->legacyChildren($node);
        return [
            'type' => $node->isDocument() ? 'body' : (string) $node->tagName(),
            'props' => $props,
        ];
    }

    private function validType(mixed $value, mixed $type): bool
    {
        if ($type === null) {
            return true;
        }
        $types = is_array($type) ? $type : [$type];
        foreach ($types as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $valid = match ($candidate) {
                'rich-text' => is_string($value),
                'string' => is_string($value),
                'boolean' => is_bool($value),
                'object' => $value instanceof \stdClass
                    || (is_array($value) && !array_is_list($value)),
                'null' => $value === null,
                'array' => is_array($value) && array_is_list($value),
                // Gutenberg currently checks both integer and number with
                // JavaScript's `typeof value === "number"`.
                'integer', 'number' => is_int($value) || is_float($value),
                default => true,
            };
            if ($valid) {
                return true;
            }
        }
        return false;
    }

    private function validEnum(mixed $value, mixed $enum): bool
    {
        return !is_array($enum) || in_array($value, $enum, true);
    }
}
