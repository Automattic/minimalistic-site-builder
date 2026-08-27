<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\TreeGraph;

/**
 * Minimal draft-07 subset validator, ported from x-pipeline's
 * pipeline/lib/schema.mjs. Supports exactly the keywords the tree-graph
 * schemas (schemas/tree/*.json) use and ignores anything else — house rule
 * carried over from the source: no full-blown schema library.
 *
 * Values follow the json_decode($text, true) convention: JSON objects are
 * associative arrays, JSON arrays are list arrays, and an empty PHP array is
 * ambiguous — it counts as BOTH an empty object and an empty array.
 */
final class Schema
{
    /**
     * Validate $value against $schema; returns a list of
     * ['path' => string, 'message' => string] issues (empty = valid).
     *
     * @param array<string,mixed> $schema
     * @return list<array{path:string,message:string}>
     */
    public static function validate(array $schema, mixed $value, string $path = ''): array
    {
        $issues = [];
        self::check($schema, $value, $path, $issues);
        return $issues;
    }

    /**
     * @param array<string,mixed>                     $schema
     * @param list<array{path:string,message:string}> $issues appended in place
     */
    private static function check(array $schema, mixed $value, string $path, array &$issues): void
    {
        if (array_key_exists('type', $schema)) {
            $types = is_array($schema['type']) ? $schema['type'] : [$schema['type']];
            $ok = false;
            foreach ($types as $want) {
                if (self::typeMatches($value, (string) $want)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                $issues[] = [
                    'path'    => $path,
                    'message' => 'expected ' . implode('|', array_map('strval', $types)) . ', got ' . self::typeName($value),
                ];
                return; // wrong type: deeper checks are noise
            }
        }

        if (array_key_exists('oneOf', $schema) && is_array($schema['oneOf'])) {
            $passing = 0;
            foreach ($schema['oneOf'] as $sub) {
                if (is_array($sub) && self::validate($sub, $value, $path) === []) {
                    $passing++;
                }
            }
            if ($passing !== 1) {
                $issues[] = [
                    'path'    => $path,
                    'message' => 'must match exactly one of ' . count($schema['oneOf']) . " alternatives (matched {$passing})",
                ];
            }
        }

        if (array_key_exists('const', $schema) && !self::sameValue($value, $schema['const'])) {
            $issues[] = ['path' => $path, 'message' => 'expected const ' . json_encode($schema['const'], JSON_UNESCAPED_SLASHES)];
        }

        if (array_key_exists('enum', $schema) && is_array($schema['enum'])) {
            $found = false;
            foreach ($schema['enum'] as $allowed) {
                if (self::sameValue($value, $allowed)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $issues[] = ['path' => $path, 'message' => 'expected one of ' . implode(', ', array_map('strval', $schema['enum']))];
            }
        }

        if (is_string($value)) {
            if (isset($schema['pattern']) && is_string($schema['pattern'])) {
                $pattern = '~' . str_replace('~', '\\~', $schema['pattern']) . '~u';
                if (@preg_match($pattern, $value) !== 1) {
                    $issues[] = ['path' => $path, 'message' => "does not match {$schema['pattern']}"];
                }
            }
            if (isset($schema['minLength']) && mb_strlen($value) < (int) $schema['minLength']) {
                $issues[] = ['path' => $path, 'message' => "shorter than minLength {$schema['minLength']}"];
            }
        }

        if (is_int($value) || is_float($value)) {
            if (($schema['type'] ?? null) === 'integer' && !self::isIntegral($value)) {
                $issues[] = ['path' => $path, 'message' => 'expected an integer'];
            }
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $issues[] = ['path' => $path, 'message' => "below minimum {$schema['minimum']}"];
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $issues[] = ['path' => $path, 'message' => "above maximum {$schema['maximum']}"];
            }
        }

        if (is_array($value) && array_is_list($value)) {
            if (isset($schema['minItems']) && count($value) < (int) $schema['minItems']) {
                $issues[] = ['path' => $path, 'message' => "fewer than minItems {$schema['minItems']} (at least {$schema['minItems']})"];
            }
            if (isset($schema['maxItems']) && count($value) > (int) $schema['maxItems']) {
                $issues[] = ['path' => $path, 'message' => "more than maxItems {$schema['maxItems']}"];
            }
            if (isset($schema['items']) && is_array($schema['items'])) {
                foreach ($value as $i => $v) {
                    self::check($schema['items'], $v, "{$path}/{$i}", $issues);
                }
            }
        }

        if (self::isJsonObject($value)) {
            foreach ((array) ($schema['required'] ?? []) as $key) {
                if (!array_key_exists((string) $key, $value)) {
                    $issues[] = ['path' => $path, 'message' => "missing required property \"{$key}\""];
                }
            }
            $props = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            foreach ($value as $key => $v) {
                $esc = str_replace(['~', '/'], ['~0', '~1'], (string) $key);
                if (array_key_exists((string) $key, $props)) {
                    self::check($props[(string) $key], $v, "{$path}/{$esc}", $issues);
                } elseif (($schema['additionalProperties'] ?? null) === false) {
                    $issues[] = ['path' => "{$path}/{$esc}", 'message' => 'unexpected property'];
                } elseif (is_array($schema['additionalProperties'] ?? null)) {
                    self::check($schema['additionalProperties'], $v, "{$path}/{$esc}", $issues);
                }
            }
        }
    }

    /** One JSON-Schema type name against a decoded value. */
    private static function typeMatches(mixed $value, string $want): bool
    {
        return match ($want) {
            'null'    => $value === null,
            'boolean' => is_bool($value),
            'string'  => is_string($value),
            'number'  => (is_int($value) || is_float($value)),
            'integer' => (is_int($value) || is_float($value)) && self::isIntegral($value),
            'array'   => is_array($value) && ($value === [] || array_is_list($value)),
            'object'  => self::isJsonObject($value),
            default   => false,
        };
    }

    /** An empty PHP array counts as an empty object too (json_decode assoc). */
    private static function isJsonObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    private static function isIntegral(int|float $value): bool
    {
        return is_int($value) || floor($value) === $value;
    }

    /**
     * JS SameValueZero-ish equality: strict, except that an int and a float
     * carrying the same number are equal (JSON has one number type).
     */
    private static function sameValue(mixed $a, mixed $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))
            && !is_bool($a) && !is_bool($b)
        ) {
            return (float) $a === (float) $b;
        }
        return false;
    }

    /** Human name for a decoded JSON value's type, for issue messages. */
    public static function typeName(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return 'boolean';
        }
        if (is_int($value) || is_float($value)) {
            return 'number';
        }
        if (is_string($value)) {
            return 'string';
        }
        if (is_array($value)) {
            return array_is_list($value) ? 'array' : 'object';
        }
        return gettype($value);
    }
}
