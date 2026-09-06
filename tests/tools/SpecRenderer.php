<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Tools;

use Automattic\SiteBuild\BlockSerializer\CommentSerializer;
use Automattic\SiteBuild\BlockSerializer\Json\JsonArray;
use Automattic\SiteBuild\BlockSerializer\Json\JsonDecoder;
use Automattic\SiteBuild\BlockSerializer\Json\JsonNative;
use Automattic\SiteBuild\BlockSerializer\Json\JsonObject;
use Automattic\SiteBuild\BlockSerializer\Json\JsonValue;
use Automattic\SiteBuild\BlockSerializer\NormalizedBlock;
use Automattic\SiteBuild\BlockSerializer\ParagraphFixer;
use Automattic\SiteBuild\BlockSerializer\Registry\BlockRegistry;
use Automattic\SiteBuild\BlockSerializer\Save\SaveStrategyRegistry;

/**
 * Renders a block document from a spec tree alone: {name, attrs, innerBlocks}.
 * No authored markup is consulted. Shared by the round-trip harness and the
 * generation experiment so both measure the same path.
 */
final class SpecRenderer
{
    private SaveStrategyRegistry $saves;
    private CommentSerializer $comments;
    private ParagraphFixer $paragraphs;

    public function __construct(private BlockRegistry $registry)
    {
        $this->saves = new SaveStrategyRegistry($registry);
        $this->comments = new CommentSerializer($registry);
        $this->paragraphs = new ParagraphFixer();
    }

    /**
     * Decode `{"blocks": [...]}` written by a model with the repo's JS-faithful
     * decoder, so `{}` survives, into specs carrying both forms of attrs.
     *
     * @return list<array{name:string,attrs:array<string,mixed>,typed:JsonObject,innerBlocks:list<array<mixed>>}>
     */
    public static function fromJson(string $raw): array
    {
        $root = (new JsonDecoder($raw))->decode();
        $blocks = $root instanceof JsonObject ? $root->get('blocks') : null;
        if (!$blocks instanceof JsonArray) {
            throw new \RuntimeException('top level must be {"blocks": [...]}');
        }
        return self::specsFrom($blocks);
    }

    /** @return list<array{name:string,attrs:array<string,mixed>,typed:JsonObject,innerBlocks:list<array<mixed>>}> */
    private static function specsFrom(JsonArray $blocks): array
    {
        $out = [];
        foreach ($blocks->items() as $block) {
            if (!$block instanceof JsonObject) {
                throw new \RuntimeException('block must be an object');
            }
            $attrs = $block->get('attrs') ?? new JsonObject();
            $inner = $block->get('innerBlocks') ?? new JsonArray();
            if (!$attrs instanceof JsonObject || !$inner instanceof JsonArray) {
                throw new \RuntimeException('attrs must be an object and innerBlocks an array');
            }
            $out[] = [
                'name' => (string) JsonNative::value($block->get('name')),
                'attrs' => JsonNative::objectToArray($attrs),
                'typed' => $attrs,
                'innerBlocks' => self::specsFrom($inner),
            ];
        }
        return $out;
    }

    /**
     * @param list<array{name:string,attrs:array<string,mixed>,innerBlocks?:list<array<mixed>>}> $blocks
     */
    public function document(array $blocks): string
    {
        $out = array_map(fn (array $b) => $this->block($b), $blocks);
        // The Serializer normalizes its own output the same way: sourcing a
        // paragraph's content yields the whole <p>, which nests on re-render.
        return $this->paragraphs->fix(implode("\n\n", $out))->html;
    }

    /**
     * `typed` is the JS-faithful form of `attrs`. Pass it whenever the spec came
     * from JSON text: a PHP array cannot tell `{}` from `[]`, and the delimiter
     * must carry the object the author wrote.
     *
     * @param array{name:string,attrs:array<string,mixed>,typed?:JsonObject,innerBlocks?:list<array<mixed>>} $spec
     */
    public function block(array $spec): string
    {
        $inner = implode("\n\n", array_map(fn (array $c) => $this->block($c), $spec['innerBlocks'] ?? []));
        $attrs = $this->escapeText($spec['name'], $spec['attrs']);
        $content = $this->saves->save($spec['name'], $attrs, $inner, '');
        $typed = $spec['typed'] ?? ($attrs === [] ? new JsonObject() : JsonValue::fromNative($attrs));
        if (!$typed instanceof JsonObject) {
            throw new \RuntimeException("Attributes of {$spec['name']} must be an object");
        }
        $delimAttrs = $this->comments->attributes(new NormalizedBlock($spec['name'], $typed, $attrs));
        return $this->comments->delimit($spec['name'], $delimAttrs, $content);
    }

    /**
     * A spec author writes text as text: "Breath & Movement". Once that text
     * is saved into HTML it must read "Breath &amp; Movement", which is what
     * the pipeline's own serializer produces. Escape a bare `&` in every
     * attribute the registry sources from HTML text, following `query`
     * sub-schemas so table cells are covered. Entities the author already
     * wrote, and inline tags in rich text, are left alone.
     *
     * @param array<string,mixed> $attrs
     * @return array<string,mixed>
     */
    private function escapeText(string $name, array $attrs): array
    {
        return $this->escapeBySchema($attrs, $this->registry->attributes($name));
    }

    /**
     * @param array<string,mixed> $values
     * @param array<string,mixed> $schemas
     * @return array<string,mixed>
     */
    private function escapeBySchema(array $values, array $schemas): array
    {
        foreach ($schemas as $key => $schema) {
            if (!is_array($schema) || !array_key_exists($key, $values)) {
                continue;
            }
            $source = $schema['source'] ?? null;
            if (in_array($source, ['rich-text', 'html', 'text'], true) && is_string($values[$key])) {
                $values[$key] = preg_replace('/&(?![a-zA-Z][a-zA-Z0-9]*;|#[0-9]+;|#x[0-9a-fA-F]+;)/', '&amp;', $values[$key]);
            } elseif ($source === 'query' && is_array($values[$key]) && is_array($schema['query'] ?? null)) {
                foreach ($values[$key] as $i => $row) {
                    if (is_array($row)) {
                        $values[$key][$i] = $this->escapeBySchema($row, $schema['query']);
                    }
                }
            }
        }
        return $values;
    }
}
