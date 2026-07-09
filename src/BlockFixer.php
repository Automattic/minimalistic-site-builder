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
    /** Re-serialize every block template under $themeDir; return a one-line summary. */
    public function fix(string $themeDir): string;
}
