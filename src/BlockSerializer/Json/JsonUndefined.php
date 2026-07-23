<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Json;

/**
 * JavaScript's non-JSON undefined sentinel.
 *
 * It cannot be produced by JSON.parse(), but later schema/default processing
 * needs JSON.stringify's object-omission and array-null behavior.
 */
final class JsonUndefined extends JsonValue
{
    public function toNative(): mixed
    {
        return null;
    }
}
