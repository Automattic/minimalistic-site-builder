<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Json;

final class JsonBoolean extends JsonValue
{
    public function __construct(public readonly bool $value) {}

    public function toNative(): bool
    {
        return $this->value;
    }
}
