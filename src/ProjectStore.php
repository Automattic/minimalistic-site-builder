<?php
declare(strict_types=1);

/**
 * Creates and locates projects under a base directory (default: projects/).
 */
final class ProjectStore
{
    public function __construct(private string $baseDir)
    {
        if (!is_dir($this->baseDir) && !mkdir($this->baseDir, 0775, true) && !is_dir($this->baseDir)) {
            throw new RuntimeException("Could not create base directory: {$this->baseDir}");
        }
    }

    public function create(string $slug): Project
    {
        $slug = self::slugify($slug);
        $root = $this->baseDir . '/' . $slug;
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            throw new RuntimeException("Could not create project directory: {$root}");
        }
        return new Project($root);
    }

    public function open(string $slug): Project
    {
        $root = $this->baseDir . '/' . self::slugify($slug);
        if (!is_dir($root)) {
            throw new RuntimeException("Project does not exist: {$root}");
        }
        return new Project($root);
    }

    /**
     * First unused slug for a new project: the given base if its folder is free,
     * otherwise base2, base3, … — so callers never overwrite an existing project.
     * The base is slugified first, matching create()/open().
     */
    public function freeSlug(string $base): string
    {
        $base = self::slugify($base);
        $slug = $base;
        $n = 2;
        while (is_dir($this->baseDir . '/' . $slug)) {
            $slug = $base . $n;
            $n++;
        }
        return $slug;
    }

    /**
     * Filesystem- and URL-safe slug: lowercase, alnum + single hyphens,
     * trimmed, capped. Always returns a non-empty string.
     */
    public static function slugify(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');
        if (strlen($s) > 60) {
            $s = rtrim(substr($s, 0, 60), '-');
        }
        return $s === '' ? 'site' : $s;
    }
}
