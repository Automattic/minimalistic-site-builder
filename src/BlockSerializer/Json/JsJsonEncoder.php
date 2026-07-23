<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Json;

/** JSON.stringify plus Gutenberg's block-comment attribute escaping. */
final class JsJsonEncoder
{
    /** @var array<int,true> recursion stack used to reject cyclic typed values */
    private array $ancestors = [];

    /**
     * JavaScript JSON.stringify(). Root-level undefined is represented by null,
     * just as JavaScript returns the value undefined rather than a string.
     */
    public static function stringify(JsonValue $value): ?string
    {
        return (new self())->encodeValue($value);
    }

    /**
     * Gutenberg serializeAttributes(): stringify then make the JSON safe inside
     * an HTML block comment. Replacement order matches @wordpress/blocks.
     */
    public static function serializeAttributes(JsonObject $attributes): string
    {
        $json = self::stringify($attributes);
        if ($json === null) {
            throw new \LogicException('A JSON object cannot stringify to undefined');
        }

        return str_replace(
            ['\\\\', '--', '<', '>', '&', '\\"'],
            ['\\u005c', '\\u002d\\u002d', '\\u003c', '\\u003e', '\\u0026', '\\u0022'],
            $json
        );
    }

    /** Explicit alias used by the block-comment serializer. */
    public static function encodeCommentAttributes(JsonObject $attributes): string
    {
        return self::serializeAttributes($attributes);
    }

    /** JavaScript Number.toString spelling used outside JSON comments too. */
    public static function stringifyNumber(int|float $value): string
    {
        return self::number(new JsonNumber((float) $value));
    }

    private function encodeValue(JsonValue $value): ?string
    {
        if ($value instanceof JsonUndefined) {
            return null;
        }
        if ($value instanceof JsonNull) {
            return 'null';
        }
        if ($value instanceof JsonBoolean) {
            return $value->value ? 'true' : 'false';
        }
        if ($value instanceof JsonString) {
            return self::string($value->value);
        }
        if ($value instanceof JsonNumber) {
            return self::number($value);
        }
        if ($value instanceof JsonArray) {
            return $this->array($value);
        }
        if ($value instanceof JsonObject) {
            return $this->object($value);
        }
        throw new \LogicException('Unknown typed JSON value: ' . $value::class);
    }

    private function object(JsonObject $object): string
    {
        $this->enter($object);
        try {
            $members = [];
            foreach ($object->entriesForStringify() as $entry) {
                $encoded = $this->encodeValue($entry['value']);
                if ($encoded === null) {
                    continue; // JSON.stringify omits undefined object values.
                }
                $members[] = self::string($entry['key']) . ':' . $encoded;
            }
            return '{' . implode(',', $members) . '}';
        } finally {
            $this->leave($object);
        }
    }

    private function array(JsonArray $array): string
    {
        $this->enter($array);
        try {
            $items = [];
            foreach ($array->items() as $value) {
                // Undefined and otherwise non-serializable array entries become
                // null rather than disappearing and shifting following entries.
                $items[] = $this->encodeValue($value) ?? 'null';
            }
            return '[' . implode(',', $items) . ']';
        } finally {
            $this->leave($array);
        }
    }

    private function enter(JsonValue $value): void
    {
        $id = spl_object_id($value);
        if (isset($this->ancestors[$id])) {
            throw new \RuntimeException('Converting circular structure to JSON');
        }
        $this->ancestors[$id] = true;
    }

    private function leave(JsonValue $value): void
    {
        unset($this->ancestors[spl_object_id($value)]);
    }

    private static function string(string $value): string
    {
        try {
            return JsStringCodec::encode($value);
        } catch (\InvalidArgumentException $error) {
            throw new \InvalidArgumentException(
                'String is not representable as JavaScript JSON: ' . $error->getMessage(),
                0,
                $error
            );
        }
    }

    private static function number(JsonNumber $number): string
    {
        if (!$number->isFinite()) {
            return 'null';
        }
        if ($number->value == 0.0) {
            // JSON.stringify(-0) intentionally loses the sign.
            return '0';
        }

        $negative = $number->value < 0.0;
        $absolute = abs($number->value);
        $shortest = self::shortestRoundTrip($absolute);

        // PHP and JavaScript both use shortest-round-trip decimal digits, but
        // choose exponential notation at different thresholds. Normalize the
        // PHP spelling into digits + decimal-point position, then apply ECMA's
        // [-6, 21) fixed-notation window.
        $shortest = strtolower($shortest);
        $parts = explode('e', $shortest, 2);
        $mantissa = $parts[0];
        $explicitExponent = isset($parts[1]) ? (int) $parts[1] : 0;
        $dot = strpos($mantissa, '.');
        $decimalPosition = ($dot === false ? strlen($mantissa) : $dot) + $explicitExponent;
        $digits = str_replace('.', '', $mantissa);

        // Normalize a fixed PHP spelling such as 0.0001 as well as an
        // exponential spelling. Leading zeroes are left of the significant
        // coefficient and therefore move the decimal position with them.
        $leadingZeroes = strspn($digits, '0');
        if ($leadingZeroes > 0) {
            $digits = substr($digits, $leadingZeroes);
            $decimalPosition -= $leadingZeroes;
        }

        // JSON_PRESERVE_ZERO_FRACTION can contribute a non-significant final 0
        // (1.0); removing all trailing zeroes is safe only while compensating
        // through the already-computed decimal position.
        $digits = rtrim($digits, '0');
        if ($digits === '') {
            return '0';
        }

        $scientificExponent = $decimalPosition - 1;
        if ($scientificExponent >= 21 || $scientificExponent <= -7) {
            $coefficient = $digits[0];
            if (strlen($digits) > 1) {
                $coefficient .= '.' . substr($digits, 1);
            }
            $rendered = $coefficient . 'e'
                . ($scientificExponent >= 0 ? '+' : '-')
                . (string) abs($scientificExponent);
        } elseif ($decimalPosition <= 0) {
            $rendered = '0.' . str_repeat('0', -$decimalPosition) . $digits;
        } elseif ($decimalPosition >= strlen($digits)) {
            $rendered = $digits . str_repeat('0', $decimalPosition - strlen($digits));
        } else {
            $rendered = substr($digits, 0, $decimalPosition)
                . '.' . substr($digits, $decimalPosition);
        }

        return ($negative ? '-' : '') . $rendered;
    }

    /**
     * PHP's modern dtoa path provides the same shortest-round-trip digits used
     * by JavaScript when serialize_precision is -1. Some hosts override that
     * ini value globally, so scope the setting around this conversion instead
     * of letting deployment configuration alter canonical block bytes.
     */
    private static function shortestRoundTrip(float $number): string
    {
        $previous = ini_get('serialize_precision');
        $changed = $previous !== false && $previous !== '-1';
        if ($changed && ini_set('serialize_precision', '-1') === false) {
            throw new \RuntimeException('serialize_precision must be changeable for JavaScript number encoding');
        }
        try {
            try {
                return json_encode(
                    $number,
                    JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
                );
            } catch (\JsonException $error) {
                throw new \LogicException('Finite number could not be encoded', 0, $error);
            }
        } finally {
            if ($changed) {
                ini_set('serialize_precision', (string) $previous);
            }
        }
    }
}
