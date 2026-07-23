<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Parser;

interface DocumentNode
{
    public function sourceStart(): int;

    public function sourceEnd(): int;

    public function rawSource(): string;
}
