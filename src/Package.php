<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Resolves paths to assets the package OWNS (prompt templates, bundled scripts),
 * anchored to this file's location so they resolve correctly whether the package
 * is run from its own repo or vendored inside another project. This is distinct
 * from the consumer-supplied output root where projects/themes are written.
 */
final class Package
{
    public static function root(): string
    {
        return dirname(__DIR__);
    }

    public static function promptsDir(): string
    {
        return self::root() . '/prompts';
    }

    /** The static motion kit (motion.css, motion.js, profiles/) shipped into themes. */
    public static function motionDir(): string
    {
        return self::root() . '/assets/motion';
    }
}
