<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Json;

/** Small recursive-descent JSON.parse compatibility decoder. */
final class JsonDecoder
{
    private int $offset = 0;
    private int $length;

    public function __construct(private string $source)
    {
        $this->length = strlen($source);
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
            $object->set($key, $this->value());
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
