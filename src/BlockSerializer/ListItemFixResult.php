<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer;

/** Outcome of ListItemFixer::fix — repaired HTML plus which lists changed. */
final class ListItemFixResult
{
    /**
     * @param int $count total raw <li> elements wrapped
     * @param list<int> $repairedListOrdinals ordinals (in wp:list source
     *        order) of the list blocks whose items were wrapped
     */
    public function __construct(
        public readonly string $html,
        public readonly int $count,
        public readonly array $repairedListOrdinals = [],
    ) {}
}
