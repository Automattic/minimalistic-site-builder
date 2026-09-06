<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Tools;

use Automattic\SiteBuild\BlockSerializer\CommentSerializer;
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
        $attrs = $spec['attrs'];
        $content = $this->saves->save($spec['name'], $attrs, $inner, '');
        $typed = $spec['typed'] ?? ($attrs === [] ? new JsonObject() : JsonValue::fromNative($attrs));
        if (!$typed instanceof JsonObject) {
            throw new \RuntimeException("Attributes of {$spec['name']} must be an object");
        }
        $delimAttrs = $this->comments->attributes(new NormalizedBlock($spec['name'], $typed, $attrs));
        return $this->comments->delimit($spec['name'], $delimAttrs, $content);
    }
}
