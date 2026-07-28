<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\BlockSerializer\FixerReport;

/**
 * A block fixer that exposes per-file outcomes in addition to its human log.
 *
 * Steps use this contract to isolate a generated-content failure to the exact
 * file that failed without scraping the formatted report. BlockFixer remains
 * the lightweight extension point for simple third-party implementations.
 */
interface ReportingBlockFixer extends BlockFixer
{
    public function fixReport(string $themeDir): FixerReport;
}
