<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Html;

use Automattic\SiteBuild\BlockSerializer\Json\JsJsonEncoder;
use Automattic\SiteBuild\BlockSerializer\Save\ElementNode;
use Automattic\SiteBuild\BlockSerializer\Save\RawNode;
use Automattic\SiteBuild\BlockSerializer\Save\SaveNode;
use Automattic\SiteBuild\BlockSerializer\Save\TextNode;
use Automattic\SiteBuild\BlockSerializer\Supports\StyleEngine;

/** Central React-compatible serializer for renderer save trees. */
final class HtmlSerializer
{
    /** @var array<string,true> */
    private const BOOLEAN = [
        'allowfullscreen' => true, 'async' => true, 'autofocus' => true,
        'autoplay' => true, 'checked' => true, 'controls' => true,
        'default' => true, 'defer' => true, 'disabled' => true,
        'formnovalidate' => true, 'hidden' => true, 'loop' => true,
        'multiple' => true, 'muted' => true, 'nomodule' => true,
        'novalidate' => true, 'open' => true, 'playsinline' => true,
        'readonly' => true, 'required' => true, 'reversed' => true,
        'scoped' => true, 'seamless' => true, 'itemscope' => true,
    ];

    /** @var array<string,true> */
    private const UNITLESS = [
        'animationIterationCount' => true, 'borderImageOutset' => true,
        'borderImageSlice' => true, 'borderImageWidth' => true,
        'boxFlex' => true, 'boxFlexGroup' => true, 'boxOrdinalGroup' => true,
        'columnCount' => true, 'columns' => true, 'flex' => true,
        'flexGrow' => true, 'flexPositive' => true, 'flexShrink' => true,
        'flexNegative' => true, 'flexOrder' => true, 'gridArea' => true,
        'gridColumn' => true, 'gridColumnEnd' => true, 'gridColumnSpan' => true,
        'gridColumnStart' => true, 'gridRow' => true, 'gridRowEnd' => true,
        'gridRowSpan' => true, 'gridRowStart' => true, 'fontWeight' => true,
        'lineClamp' => true, 'lineHeight' => true, 'opacity' => true,
        'order' => true, 'orphans' => true, 'scale' => true,
        'tabSize' => true, 'widows' => true, 'zIndex' => true, 'zoom' => true,
    ];

    public function serialize(SaveNode|string|null $node): string
    {
        if ($node === null) {
            return '';
        }
        if (is_string($node)) {
            return $this->escapeText($node);
        }
        if ($node instanceof RawNode) {
            return $node->html;
        }
        if ($node instanceof TextNode) {
            return $this->escapeText($node->text);
        }
        if (!$node instanceof ElementNode) {
            throw new \RuntimeException('Unsupported save-tree node ' . $node::class);
        }

        $tag = $node->tag;
        $out = '<' . $tag . $this->attributes($node->props);
        if (HtmlNode::isVoidTag($tag)) {
            return $out . '/>';
        }
        $out .= '>';
        foreach ($node->children as $child) {
            $out .= $this->serialize($child);
        }
        return $out . '</' . $tag . '>';
    }

    /** @param array<string,mixed> $props */
    private function attributes(array $props): string
    {
        $out = '';
        foreach ($props as $name => $value) {
            if ($name === 'children' || $name === 'key' || $value === null || $value === false) {
                continue;
            }
            if ($name === 'style') {
                if (!is_array($value) || ($value = $this->style($value)) === '') {
                    continue;
                }
            }
            $htmlName = match ($name) {
                'className' => 'class',
                'htmlFor' => 'for',
                'tabIndex' => 'tabindex',
                'colSpan' => 'colspan',
                'rowSpan' => 'rowspan',
                default => strtolower($name),
            };
            if ($value === true && isset(self::BOOLEAN[$htmlName])) {
                // ReactDOMServer's static markup uses the HTML boolean form.
                $out .= ' ' . $htmlName;
                continue;
            }
            if (is_array($value) || is_object($value)) {
                throw new \RuntimeException("Unsupported HTML attribute value for '{$htmlName}'");
            }
            $serialized = $value === true ? 'true' : (string) $value;
            $out .= ' ' . $htmlName . '="' . $this->escapeAttribute($serialized) . '"';
        }
        return $out;
    }

    /** @param array<string,mixed> $styles */
    private function style(array $styles): string
    {
        $parts = [];
        foreach ($styles as $property => $value) {
            if ($value === null || $value === '' || $value === false) {
                continue;
            }
            if (!is_scalar($value)) {
                throw new \RuntimeException("Unsupported CSS value for '{$property}'");
            }
            if (is_int($value) || is_float($value)) {
                $value = JsJsonEncoder::stringifyNumber($value)
                    . ($value != 0 && !isset(self::UNITLESS[$property]) && !str_starts_with($property, '--')
                        ? 'px'
                        : '');
            }
            $cssName = str_starts_with($property, '--') ? $property : StyleEngine::kebabCase($property);
            $parts[] = $cssName . ':' . (string) $value;
        }
        return implode(';', $parts);
    }

    private function escapeText(string $text): string
    {
        return strtr($text, ['&' => '&amp;', '>' => '&gt;', '<' => '&lt;']);
    }

    private function escapeAttribute(string $value): string
    {
        return strtr($value, [
            '&' => '&amp;', '>' => '&gt;', '<' => '&lt;', '"' => '&quot;',
        ]);
    }
}
