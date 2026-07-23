<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Json;

/** @implements \IteratorAggregate<int,JsonValue> */
final class JsonArray extends JsonValue implements \Countable, \IteratorAggregate
{
    /** @var list<JsonValue> */
    private array $items;

    /** @param list<JsonValue> $items */
    public function __construct(array $items = [])
    {
        foreach ($items as $item) {
            if (!$item instanceof JsonValue) {
                throw new \InvalidArgumentException('JsonArray items must be JsonValue instances');
            }
        }
        $this->items = array_values($items);
    }

    public function push(JsonValue $value): void
    {
        $this->items[] = $value;
    }

    public function set(int $index, JsonValue $value): void
    {
        if ($index < 0 || $index >= count($this->items)) {
            throw new \OutOfBoundsException("JSON array index out of bounds: {$index}");
        }
        $this->items[$index] = $value;
    }

    public function get(int $index): JsonValue
    {
        if (!isset($this->items[$index])) {
            throw new \OutOfBoundsException("JSON array index out of bounds: {$index}");
        }
        return $this->items[$index];
    }

    /** @return list<JsonValue> */
    public function items(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** @return \Traversable<int,JsonValue> */
    public function getIterator(): \Traversable
    {
        yield from $this->items;
    }

    public function toNative(): array
    {
        return array_map(static fn (JsonValue $value): mixed => $value->toNative(), $this->items);
    }
}
