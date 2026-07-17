<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Json;

/** An IEEE-754 JavaScript Number, including a preserved negative-zero bit. */
final class JsonNumber extends JsonValue
{
    private bool $negativeZero;

    public function __construct(public readonly float $value, ?bool $negativeZero = null)
    {
        $this->negativeZero = $negativeZero ?? self::nativeNegativeZero($value);
    }

    public static function fromLexeme(string $lexeme): self
    {
        $value = (float) $lexeme;
        $negativeZero = $value == 0.0 && str_starts_with($lexeme, '-');
        return new self($value, $negativeZero);
    }

    public function isNegativeZero(): bool
    {
        return $this->negativeZero;
    }

    public function isFinite(): bool
    {
        return is_finite($this->value);
    }

    public function toNative(): float
    {
        return $this->negativeZero ? -0.0 : $this->value;
    }

    private static function nativeNegativeZero(float $value): bool
    {
        if ($value != 0.0) {
            return false;
        }
        $encoded = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
        return is_string($encoded) && str_starts_with($encoded, '-');
    }
}
