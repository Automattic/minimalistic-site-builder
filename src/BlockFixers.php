<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Selects the bundled block-fixer implementation for production consumers. */
final class BlockFixers
{
    /**
     * Build the configured fixer.
     *
     * BLOCK_FIXER is intentionally strict: a typo must fail before a build
     * starts instead of silently changing the runtime implementation.
     */
    public static function default(): BlockFixer
    {
        $configured = Env::get('BLOCK_FIXER', 'php');
        $name = strtolower(trim($configured ?? 'php'));

        return match ($name) {
            '', 'php' => new PhpBlockFixer(),
            'node' => NodeBlockFixer::default(),
            default => throw new \RuntimeException(
                "Invalid BLOCK_FIXER '{$configured}'. Expected 'node' or 'php'."
            ),
        };
    }
}
