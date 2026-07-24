<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Json;

final class JsonNull extends JsonValue
{
    public function toNative(): mixed
    {
        return null;
    }
}
