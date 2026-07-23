<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Parser;

/** @implements \IteratorAggregate<int,DocumentNode> */
final class Document implements \Countable, \IteratorAggregate
{
    /** @param list<DocumentNode> $nodes */
    public function __construct(
        public string $source,
        private array $nodes,
    ) {}

    /** @return list<DocumentNode> */
    public function nodes(): array
    {
        return $this->nodes;
    }

    public function count(): int
    {
        return count($this->nodes);
    }

    /** @return \Traversable<int,DocumentNode> */
    public function getIterator(): \Traversable
    {
        yield from $this->nodes;
    }

    /**
     * Pinned default-parser shaped data for oracle comparisons.
     *
     * @return list<array<string,mixed>>
     */
    public function toParsedBlocks(): array
    {
        return array_map(self::nodeToParsedBlock(...), $this->nodes);
    }

    /** @return array<string,mixed> */
    private static function nodeToParsedBlock(DocumentNode $node): array
    {
        if ($node instanceof FreeformNode) {
            return [
                'blockName' => null,
                'attrs' => new \stdClass(),
                'innerBlocks' => [],
                'innerHTML' => $node->content,
                'innerContent' => [$node->content],
            ];
        }

        return [
            'blockName' => $node->name,
            'attrs' => $node->attributes?->toNative(),
            'innerBlocks' => array_map(self::nodeToParsedBlock(...), $node->innerBlocks),
            'innerHTML' => $node->innerHTML,
            'innerContent' => $node->innerContent,
        ];
    }
}
