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
     * @param bool $authoredClassOnSavedRoot whether the class belonged to the
     *        block's sole saved root rather than an owned descendant
     * @param bool $authoredClassIsSafeRootTextAlignment whether that root has
     *        one unambiguous text-alignment class and no conflicting inline
     *        alignment declaration or all-property reset
     * @param ?string $deliveredBlockPath path of the semantic match in final
     *        markup; null means the authored block was not uniquely located
     * @param ?string $authoredElementPath stable element-only path for an
     *        owned-descendant occurrence; null identifies the saved root
     */
    public function __construct(
        public readonly string $blockPath,
        public readonly string $blockName,
        public readonly string $authoredClass,
        public readonly array $deliveredClasses,
        public readonly bool $authoredClassOnSavedRoot = false,
        public readonly bool $authoredClassIsSafeRootTextAlignment = false,
        public readonly ?string $deliveredBlockPath = null,
        public readonly ?string $authoredElementPath = null,
    ) {}
}
