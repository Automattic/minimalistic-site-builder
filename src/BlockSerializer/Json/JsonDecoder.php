<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Json;

/**
 * Small recursive-descent JSON.parse compatibility decoder.
 *
 * By default duplicate object keys follow JSON.parse exactly: the last
 * declaration wins wholesale. Block comment attributes opt into
 * $mergeDuplicateObjectKeys instead, because a model that emits
 * {"style":{...}},"style":{...}} plainly meant one object — deep-merging
 * object values (last wins on non-object conflicts) preserves every member
 * the last-wins collapse would silently drop, and the merged key paths stay
 * reviewable through mergedDuplicateKeyPaths().
 */
final class JsonDecoder
{
    private int $offset = 0;
    private int $length;

    /** @var list<string> dotted key path of the object member being parsed */
    private array $keyPath = [];

    /** @var list<string> */
    private array $mergedKeyPaths = [];

    public function __construct(
        private string $source,
        private bool $mergeDuplicateObjectKeys = false,
    ) {
        $this->length = strlen($source);
    }

    /**
     * Dotted key paths (array indices contribute no segment) whose duplicate
     * declarations were merged by the last decode() call. Empty unless
     * $mergeDuplicateObjectKeys was requested and duplicates existed.
     *
     * @return list<string>
     */
    public function mergedDuplicateKeyPaths(): array
    {
        return array_values(array_unique($this->mergedKeyPaths));
    }

    public function decode(): JsonValue
    {
        $this->whitespace();
        $value = $this->value();
        $this->whitespace();
        if ($this->offset !== $this->length) {
            $this->fail('Unexpected trailing input');
        }
        return $value;
    }

    private function value(): JsonValue
    {
        if ($this->offset >= $this->length) {
            $this->fail('Unexpected end of JSON input');
        }
        return match ($this->source[$this->offset]) {
            '{' => $this->object(),
            '[' => $this->array(),
            '"' => new JsonString($this->string()),
            't' => $this->literal('true', new JsonBoolean(true)),
            'f' => $this->literal('false', new JsonBoolean(false)),
            'n' => $this->literal('null', new JsonNull()),
            default => $this->number(),
        };
    }

    private function object(): JsonObject
    {
        $this->offset++;
        $object = new JsonObject();
        $this->whitespace();
        if ($this->consume('}')) {
            return $object;
        }
        while (true) {
            if ($this->offset >= $this->length || $this->source[$this->offset] !== '"') {
                $this->fail('Expected a quoted object key');
            }
            $key = $this->string();
            $this->whitespace();
            if (!$this->consume(':')) {
                $this->fail("Expected ':' after object key");
            }
            $this->whitespace();
            $this->keyPath[] = $key;
            $value = $this->value();
            array_pop($this->keyPath);
            if ($this->mergeDuplicateObjectKeys && $object->has($key)) {
                $this->mergedKeyPaths[] = implode('.', [...$this->keyPath, $key]);
                $value = self::mergeValues($object->get($key), $value);
            }
            $object->set($key, $value);
            $this->whitespace();
            if ($this->consume('}')) {
                return $object;
            }
            if (!$this->consume(',')) {
                $this->fail("Expected ',' or '}' in object");
            }
            $this->whitespace();
        }
    }

    /**
     * Deterministic duplicate-declaration merge: two objects merge member by
     * member (recursively), anything else resolves exactly as JSON.parse
     * would — the later declaration wins.
     */
    private static function mergeValues(?JsonValue $existing, JsonValue $incoming): JsonValue
    {
        if (!$existing instanceof JsonObject || !$incoming instanceof JsonObject) {
            return $incoming;
        }
        foreach ($incoming->entries() as $entry) {
            $existing->set(
                $entry['key'],
                $existing->has($entry['key'])
                    ? self::mergeValues($existing->get($entry['key']), $entry['value'])
                    : $entry['value']
            );
        }
        return $existing;
    }

    private function array(): JsonArray
    {
        $this->offset++;
        $array = new JsonArray();
        $this->whitespace();
        if ($this->consume(']')) {
            return $array;
        }
        while (true) {
            $array->push($this->value());
            $this->whitespace();
            if ($this->consume(']')) {
                return $array;
            }
            if (!$this->consume(',')) {
                $this->fail("Expected ',' or ']' in array");
            }
            $this->whitespace();
        }
    }

    private function string(): string
    {
        $start = $this->offset;
        $this->offset++;
        while ($this->offset < $this->length) {
            $byte = ord($this->source[$this->offset]);
            if ($byte === 0x22) {
                $this->offset++;
                $token = substr($this->source, $start, $this->offset - $start);
                try {
                    return JsStringCodec::decode($token);
                } catch (\InvalidArgumentException $error) {
                    $this->fail('Invalid JSON string: ' . $error->getMessage(), $start);
                }
            }
            if ($byte < 0x20) {
                $this->fail('Unescaped control character in JSON string');
            }
            if ($byte === 0x5c) {
                $this->offset++;
                if ($this->offset >= $this->length) {
                    $this->fail('Unterminated JSON escape');
                }
                if ($this->source[$this->offset] === 'u') {
                    $hex = substr($this->source, $this->offset + 1, 4);
                    if (strlen($hex) !== 4 || preg_match('/^[0-9a-fA-F]{4}$/D', $hex) !== 1) {
                        $this->fail('Invalid Unicode escape');
                    }
                    $this->offset += 5;
                    continue;
                }
            }
            $this->offset++;
        }
        $this->fail('Unterminated JSON string', $start);
    }

    private function number(): JsonNumber
    {
        if (preg_match(
            '/-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/A',
            substr($this->source, $this->offset),
            $match
        ) !== 1) {
            $this->fail('Unexpected token');
        }
        $this->offset += strlen($match[0]);
        return JsonNumber::fromLexeme($match[0]);
    }

    private function literal(string $literal, JsonValue $value): JsonValue
    {
        if (substr($this->source, $this->offset, strlen($literal)) !== $literal) {
            $this->fail('Unexpected token');
        }
        $this->offset += strlen($literal);
        return $value;
    }

    private function whitespace(): void
    {
        while ($this->offset < $this->length
            && str_contains(" \t\r\n", $this->source[$this->offset])) {
            $this->offset++;
        }
    }

    private function consume(string $byte): bool
    {
        if ($this->offset < $this->length && $this->source[$this->offset] === $byte) {
            $this->offset++;
            return true;
        }
        return false;
    }

    private function fail(string $message, ?int $offset = null): never
    {
        $at = $offset ?? $this->offset;
        throw new \InvalidArgumentException("{$message} at byte {$at}");
    }
}
