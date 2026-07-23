<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Json;

/**
 * A JSON value with JavaScript-compatible type identity.
 *
 * PHP arrays cannot distinguish an empty JSON object from an empty JSON array,
 * and PHP integers do not have JavaScript Number semantics.  Block comment
 * attributes therefore stay in this typed representation until serialization.
 */
abstract class JsonValue
{
    /** Parse one complete JSON value. */
    public static function parse(string $json): self
    {
        return (new JsonDecoder($json))->decode();
    }

    /** Parse one complete JSON value, returning null for invalid JSON. */
    public static function tryParse(string $json): ?self
    {
        try {
            return self::parse($json);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Convert ordinary PHP data into typed JSON data.
     *
     * stdClass is always an object. PHP arrays are arrays only when they are
     * lists; associative arrays become objects. Integers are deliberately cast
     * to IEEE-754 doubles, matching JavaScript's sole Number type.
     */
    public static function fromNative(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }
        if ($value === null) {
            return new JsonNull();
        }
        if (is_string($value)) {
            return new JsonString($value);
        }
        if (is_bool($value)) {
            return new JsonBoolean($value);
        }
        if (is_int($value) || is_float($value)) {
            return new JsonNumber((float) $value);
        }
        if ($value instanceof \stdClass) {
            $object = new JsonObject();
            foreach (get_object_vars($value) as $key => $entry) {
                $object->set((string) $key, self::fromNative($entry));
            }
            return $object;
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return new JsonArray(array_map(self::fromNative(...), $value));
            }
            $object = new JsonObject();
            foreach ($value as $key => $entry) {
                $object->set((string) $key, self::fromNative($entry));
            }
            return $object;
        }

        throw new \InvalidArgumentException('Value cannot be represented as JSON');
    }

    /** Convert to ordinary PHP data while retaining object identity as stdClass. */
    abstract public function toNative(): mixed;
}
