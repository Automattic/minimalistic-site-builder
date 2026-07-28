<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Repairs WordPress block-validation issues in generated markup (attribute/order
 * mismatches that trigger "unexpected or invalid content"). Consumers may supply
 * their own implementation; BlockFixers selects the bundled implementation.
 * Implement ReportingBlockFixer when callers need typed per-file failures.
 */
interface BlockFixer
{
    /**
     * Re-serialize block templates under $themeDir. First line = console summary;
     * further lines = log detail.
     */
    public function fix(string $themeDir): string;
}
