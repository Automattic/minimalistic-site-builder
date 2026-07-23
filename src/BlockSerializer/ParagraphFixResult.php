<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer;

final class ParagraphFixResult
{
    /**
     * @param list<int> $repairedParagraphOrdinals Zero-based paragraph-block
     *        ordinals, in delimiter/source order, that contained a repair.
     */
    public function __construct(
        public readonly string $html,
        public readonly int $count,
        public readonly array $repairedParagraphOrdinals = [],
    ) {}
}
