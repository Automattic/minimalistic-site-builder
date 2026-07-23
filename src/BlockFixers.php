<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Constructs the bundled pure-PHP block fixer. */
final class BlockFixers
{
    public static function default(): BlockFixer
    {
        return new PhpBlockFixer();
    }
}
