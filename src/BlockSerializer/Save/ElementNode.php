<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Save;

final class ElementNode implements SaveNode
{
    /**
     * @param array<string,mixed> $props
     * @param list<SaveNode|string> $children
     */
    public function __construct(
        public readonly string $tag,
        public readonly array $props = [],
        public readonly array $children = [],
    ) {
        if (preg_match('/^[A-Za-z][A-Za-z0-9:-]*$/', $tag) !== 1) {
            throw new \InvalidArgumentException("Invalid save-tree tag '{$tag}'");
        }
    }
}
