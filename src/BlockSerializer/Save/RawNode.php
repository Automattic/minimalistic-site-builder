<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Save;

final class RawNode implements SaveNode
{
    public function __construct(public readonly string $html)
    {
    }
}
