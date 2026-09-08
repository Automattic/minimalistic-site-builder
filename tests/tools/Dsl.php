<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Tools;

/**
 * Parses the section DSL Julien proposed (Slack, 2026-09-08) into the spec
 * trees SpecRenderer already renders:
 *
 *   <group tag="section">
 *     <columns gap="small">
 *       <column justify="center"><heading level="3">…</heading></column>
 *     </columns>
 *   </group>
 *
 * The DSL is a design vocabulary — `padding="large"`, `gap="small"`,
 * `layout="stack"` — rather than Gutenberg's attribute names, which is the
 * point: a model writes it the way it writes Tailwind. This class is the
 * translation layer; the block registry stays the authority on what the
 * markup must be, so anything that parses renders as valid Gutenberg.
 *
 * A parse error names the element, so the error can go back to a cheap model
 * for one repair pass instead of failing the build.
 */
final class Dsl
{
    /** DSL element -> block name. Anything else is a parse error. */
    private const BLOCKS = [
        'group' => 'core/group',
        'columns' => 'core/columns',
        'column' => 'core/column',
        'heading' => 'core/heading',
        'text' => 'core/paragraph',
        'image' => 'core/image',
        'list' => 'core/list',
        'item' => 'core/list-item',
        'buttons' => 'core/buttons',
        'button' => 'core/button',
        'quote' => 'core/quote',
        'separator' => 'core/separator',
    ];

    /** Size words -> the pipeline's spacing presets. */
    private const SIZES = [
        'xsmall' => 'xs', 'small' => 'sm', 'medium' => 'md',
        'large' => 'lg', 'xlarge' => 'xl', 'xxlarge' => 'xxl',
    ];

    /** Elements whose text content is an attribute, not children. */
    private const TEXT_ATTR = [
        'core/heading' => 'content',
        'core/paragraph' => 'content',
        'core/list-item' => 'content',
        'core/button' => 'text',
    ];

    /** @return list<array<string,mixed>> */
    public static function parse(string $dsl): array
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // One wrapper so a fragment with several roots parses, and so the
        // parser reports the DSL's own line numbers.
        $ok = $doc->loadXML("<dsl>\n$dsl\n</dsl>", LIBXML_COMPACT);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok) {
            $first = $errors[0] ?? null;
            $where = $first ? " at line {$first->line}" : '';
            $why = $first ? trim($first->message) : 'malformed XML';
            throw new \RuntimeException("DSL does not parse$where: $why");
        }
        return self::children($doc->documentElement);
    }

    /** @return list<array<string,mixed>> */
    private static function children(\DOMElement $parent): array
    {
        $out = [];
        foreach ($parent->childNodes as $node) {
            if ($node instanceof \DOMElement) {
                $out[] = self::element($node);
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function element(\DOMElement $el): array
    {
        $name = self::BLOCKS[$el->tagName] ?? null;
        if ($name === null) {
            $known = implode(', ', array_keys(self::BLOCKS));
            throw new \RuntimeException("unknown element <{$el->tagName}> on line {$el->getLineNo()}; known elements: $known");
        }
        $attrs = self::attributes($name, $el);
        if (isset(self::TEXT_ATTR[$name])) {
            // Rich text: keep the inline markup the author wrote (<strong>, <a>).
            $inner = '';
            foreach ($el->childNodes as $child) {
                $inner .= $el->ownerDocument->saveHTML($child);
            }
            $attrs[self::TEXT_ATTR[$name]] = trim($inner);
            return ['name' => $name, 'attrs' => $attrs, 'innerBlocks' => []];
        }
        return ['name' => $name, 'attrs' => $attrs, 'innerBlocks' => self::children($el)];
    }

    /** @return array<string,mixed> */
    private static function attributes(string $block, \DOMElement $el): array
    {
        $attrs = [];
        $style = [];
        $layout = null;
        $orientation = null;
        $justify = null;

        foreach ($el->attributes as $attr) {
            $value = $attr->value;
            switch ($attr->name) {
                case 'tag':
                    $attrs['tagName'] = $value;
                    break;
                case 'anchor':
                    $attrs['anchor'] = $value;
                    break;
                case 'padding':
                    $style['spacing']['padding'] = array_fill_keys(
                        ['top', 'bottom', 'left', 'right'],
                        self::size($value, $el)
                    );
                    break;
                case 'gap':
                    $style['spacing']['blockGap'] = self::size($value, $el);
                    break;
                case 'layout':
                    $layout = $value;
                    break;
                case 'justify':
                    $justify = $value;
                    break;
                case 'size':
                    // A flex child that fills the free space.
                    if ($value === 'grow') {
                        $style['layout']['selfStretch'] = 'fill';
                        $style['layout']['flexSize'] = null;
                    }
                    break;
                case 'level':
                    $attrs['level'] = (int) $value;
                    break;
                case 'width':
                    $attrs['width'] = $value;
                    break;
                case 'aspect-ratio':
                    $attrs['aspectRatio'] = $value;
                    break;
                case 'query':
                    // The image prompt, in the placeholder shape the pipeline
                    // already resolves after generation.
                    $attrs['alt'] = $value;
                    $attrs['url'] = 'AI_IMAGE';
                    break;
                case 'href':
                    $attrs['url'] = $value;
                    break;
                case 'background':
                    $attrs['backgroundColor'] = $value;
                    break;
                case 'color':
                    $attrs['textColor'] = $value;
                    break;
                default:
                    throw new \RuntimeException("unknown attribute {$attr->name} on <{$el->tagName}> at line {$el->getLineNo()}");
            }
        }

        // `justify` reads as vertical alignment on a column or a columns row,
        // and as flex justification anywhere else.
        if ($justify !== null && in_array($block, ['core/column', 'core/columns'], true)) {
            $attrs['verticalAlignment'] = $justify;
            $justify = null;
        }
        if ($layout !== null || $justify !== null) {
            $orientation = match ($layout) {
                'stack', null => 'vertical',
                'row' => 'horizontal',
                default => throw new \RuntimeException("unknown layout \"$layout\" on line {$el->getLineNo()}; use stack or row"),
            };
            $attrs['layout'] = array_filter([
                'type' => 'flex',
                'orientation' => $orientation,
                'justifyContent' => $justify,
            ], fn ($v) => $v !== null);
        }
        if ($style !== []) {
            $attrs['style'] = $style;
        }
        return $attrs;
    }

    private static function size(string $word, \DOMElement $el): string
    {
        $slug = self::SIZES[$word] ?? null;
        if ($slug === null) {
            $known = implode(', ', array_keys(self::SIZES));
            throw new \RuntimeException("unknown size \"$word\" on <{$el->tagName}> at line {$el->getLineNo()}; use one of: $known");
        }
        return "var:preset|spacing|$slug";
    }
}
