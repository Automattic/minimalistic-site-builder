<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Repairs WordPress block-validation issues in generated markup (attribute/order
 * mismatches that trigger "unexpected or invalid content"). Consumers may supply
 * their own implementation; the package ships NodeBlockFixer as the default.
 */
interface BlockFixer
{
    /**
     * Re-serialize block templates under $themeDir. First line = console summary;
     * further lines = log detail.
     */
    public function fix(string $themeDir): string;
}
