<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer;

use Automattic\SiteBuild\BlockSerializer\Json\JsonObject;

/** The recreated in-memory block state consumed by the save serializer. */
final class NormalizedBlock
{
    /**
     * @param array<string,mixed> $attributes
     * @param list<Repair> $repairs
     */
    public function __construct(
        public readonly string $name,
        public readonly JsonObject $typedAttributes,
        public readonly array $attributes,
        public readonly array $repairs = [],
    ) {}
}
