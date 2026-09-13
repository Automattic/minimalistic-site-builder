<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Patterns;

use Automattic\SiteBuild\Project;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Load a fixture directory into a project so the composition can run on it.
 *
 * The stages before the export are still being extracted, so a fixture stands
 * in for their output. That makes the loader part of the contract rather than
 * a convenience: what it leaves behind is what the build reads.
 */
final class FixtureLoader
{
    /**
     * Copy the fixture over the project, replacing what it supplies.
     *
     * Each top-level directory in the fixture is removed from the project
     * first. Copying without that leaves whatever a previous fixture wrote and
     * this one does not: a one-page fixture loaded into a project that last
     * held four pages produced a four-page bundle, and every check downstream
     * passed, because the stale pages were themselves valid. Silently building
     * from inputs nobody supplied is worse than failing.
     */
    public static function load(string $fixtureDir, Project $project): void
    {
        foreach (scandir($fixtureDir) ?: [] as $name) {
            if ($name !== '.' && $name !== '..' && is_dir($fixtureDir . '/' . $name)) {
                self::remove($project->path($name));
            }
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($fixtureDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($entries as $entry) {
            $target = $project->path(substr($entry->getPathname(), strlen($fixtureDir) + 1));

            if ($entry->isDir()) {
                self::ensureDir($target);
                continue;
            }

            self::ensureDir(dirname($target));
            copy($entry->getPathname(), $target);
        }
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
    }

    private static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($dir);
    }
}
