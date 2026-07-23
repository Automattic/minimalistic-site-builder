<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer;

use Automattic\SiteBuild\BlockSerializer\Json\JsJsonEncoder;
use Automattic\SiteBuild\BlockSerializer\Json\JsonNative;
use Automattic\SiteBuild\BlockSerializer\Json\JsonObject;
use Automattic\SiteBuild\BlockSerializer\Registry\BlockRegistry;

/** Schema-filtered block comment attributes and delimiter spelling. */
final class CommentSerializer
{
    public function __construct(private BlockRegistry $registry) {}

    public function attributes(NormalizedBlock $block): JsonObject
    {
        $result = new JsonObject();
        foreach ($this->registry->attributes($block->name) as $key => $schema) {
            if (!is_array($schema) || !$block->typedAttributes->has($key)) {
                continue;
            }
            // Only source-less, non-local values live in the delimiter.
            if (array_key_exists('source', $schema)
                || ($schema['role'] ?? null) === 'local'
                || ($schema['__experimentalRole'] ?? null) === 'local') {
                continue;
            }
            $value = $block->typedAttributes->get($key);
            if ($value === null) {
                continue;
            }
            if (array_key_exists('default', $schema)) {
                $default = JsonNative::fromSchema($schema['default'], $schema);
                if (JsJsonEncoder::stringify($default) === JsJsonEncoder::stringify($value)) {
                    continue;
                }
            }
            $result->set($key, $value);
        }
        return $result;
    }

    public function delimit(string $name, JsonObject $attributes, string $content): string
    {
        $serialized = count($attributes) > 0
            ? JsJsonEncoder::serializeAttributes($attributes) . ' '
            : '';
        $blockName = str_starts_with($name, 'core/') ? substr($name, 5) : $name;
        if ($content === '') {
            return '<!-- wp:' . $blockName . ' ' . $serialized . '/-->';
        }
        return '<!-- wp:' . $blockName . ' ' . $serialized . "-->\n"
            . $content . "\n<!-- /wp:" . $blockName . ' -->';
    }
}
