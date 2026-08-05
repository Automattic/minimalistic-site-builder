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
}
