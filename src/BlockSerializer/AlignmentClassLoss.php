<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer;

/**
 * One visitor-visible alignment class present on an authored block but absent
 * from the same block in the final delivered markup.
 */
final class AlignmentClassLoss
{
    /**
     * @param list<string> $deliveredClasses other same-family alignment classes
     *        found on the final block; empty means the authored class was removed
     */
    public function __construct(
        public readonly string $blockPath,
        public readonly string $blockName,
        public readonly string $authoredClass,
        public readonly array $deliveredClasses,
    ) {}
}
