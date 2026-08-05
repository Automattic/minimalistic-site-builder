<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Json;

/** Boundary conversions which keep typed JSON authoritative until output. */
final class JsonNative
{
    /** @return array<string,mixed> */
    public static function objectToArray(?JsonObject $object): array
    {
        if ($object === null) {
            return [];
        }
        $result = [];
        foreach ($object->entries() as $entry) {
            $result[$entry['key']] = self::value($entry['value']);
        }
        return $result;
    }

    public static function value(JsonValue $value): mixed
    {
        if ($value instanceof JsonObject) {
            return self::objectToArray($value);
        }
        if ($value instanceof JsonArray) {
            return array_map(self::value(...), $value->items());
        }
        return $value->toNative();
    }

    /**
     * Convert a sourced/default PHP value using the schema to disambiguate an
     * empty JSON object from an empty JSON array.
     *
     * @param array<string,mixed> $schema
     */
    public static function fromSchema(mixed $value, array $schema): JsonValue
    {
        $types = $schema['type'] ?? null;
        $types = is_array($types) ? $types : [$types];
        if ($value === [] && in_array('object', $types, true) && !in_array('array', $types, true)) {
            return new JsonObject();
        }
        return JsonValue::fromNative($value);
    }
}
