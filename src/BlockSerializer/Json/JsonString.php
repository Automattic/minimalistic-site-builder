<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Json;

final class JsonString extends JsonValue
{
    public function __construct(public readonly string $value) {}

    public function toNative(): string
    {
        return $this->value;
    }
}
