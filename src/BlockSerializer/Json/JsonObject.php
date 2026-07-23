<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Json;

/**
 * Ordered JSON object.
 *
 * Entries are stored as pairs instead of a PHP associative array because PHP
 * coerces canonical numeric-string keys to integers. JSON.stringify enumerates
 * array-index property names numerically first, then other keys in insertion
 * order; entriesForStringify() implements that rule exactly.
 */
final class JsonObject extends JsonValue implements \Countable, \IteratorAggregate
{
    /** @var list<array{key:string,value:JsonValue}> */
    private array $entries = [];

    /**
     * @param iterable<array{key:string,value:JsonValue}> $entries
     */
    public function __construct(iterable $entries = [])
    {
        foreach ($entries as $entry) {
            $this->set($entry['key'], $entry['value']);
        }
    }

    public function set(string $key, JsonValue $value): void
    {
        foreach ($this->entries as &$entry) {
            if ($entry['key'] === $key) {
                $entry['value'] = $value;
                unset($entry);
                return;
            }
        }
        unset($entry);
        $this->entries[] = ['key' => $key, 'value' => $value];
    }

    public function has(string $key): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry['key'] === $key) {
                return true;
            }
        }
        return false;
    }

    public function get(string $key): ?JsonValue
    {
        foreach ($this->entries as $entry) {
            if ($entry['key'] === $key) {
                return $entry['value'];
            }
        }
        return null;
    }

    public function remove(string $key): void
    {
        foreach ($this->entries as $index => $entry) {
            if ($entry['key'] === $key) {
                array_splice($this->entries, $index, 1);
                return;
            }
        }
    }

    /** @return list<array{key:string,value:JsonValue}> */
    public function entries(): array
    {
        return $this->entries;
    }

    /** @return list<array{key:string,value:JsonValue}> */
    public function entriesForStringify(): array
    {
        $indices = [];
        $ordinary = [];
        foreach ($this->entries as $position => $entry) {
            $index = self::arrayIndex($entry['key']);
            if ($index === null) {
                $ordinary[] = $entry;
            } else {
                $indices[] = ['index' => $index, 'position' => $position, 'entry' => $entry];
            }
        }
        usort(
            $indices,
            static fn (array $left, array $right): int =>
                ($left['index'] <=> $right['index']) ?: ($left['position'] <=> $right['position'])
        );
        return array_merge(
            array_map(static fn (array $item): array => $item['entry'], $indices),
            $ordinary
        );
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /** @return \Traversable<int,array{key:string,value:JsonValue}> */
    public function getIterator(): \Traversable
    {
        yield from $this->entries;
    }

    public function toNative(): \stdClass
    {
        $object = new \stdClass();
        foreach ($this->entries as $entry) {
            $object->{$entry['key']} = $entry['value']->toNative();
        }
        return $object;
    }

    private static function arrayIndex(string $key): ?int
    {
        if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $key) !== 1) {
            return null;
        }
        // The largest ECMAScript array index is 2^32 - 2. Avoid converting a
        // huge authored key before its length and lexical bound are checked.
        $length = strlen($key);
        if ($length > 10 || ($length === 10 && strcmp($key, '4294967294') > 0)) {
            return null;
        }
        return (int) $key;
    }
}
