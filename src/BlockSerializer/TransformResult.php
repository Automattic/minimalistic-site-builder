<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer;

final class TransformResult
{
    /** @param list<Repair> $repairs */
    public function __construct(
        public readonly string $html,
        public readonly array $repairs = [],
    ) {}
}
