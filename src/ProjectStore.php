<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Creates and locates projects under a base directory (default: projects/).
 */
final class ProjectStore
{
    public function __construct(private string $baseDir)
    {
        if (!is_dir($this->baseDir) && !mkdir($this->baseDir, 0775, true) && !is_dir($this->baseDir)) {
            throw new \RuntimeException("Could not create base directory: {$this->baseDir}");
        }
    }

    public function create(string $slug): Project
    {
        $slug = self::slugify($slug);
        $root = $this->baseDir . '/' . $slug;
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            throw new \RuntimeException("Could not create project directory: {$root}");
        }
        return new Project($root);
    }

    /**
     * Atomically create a project at the first free slug for $base (base,
     * base2, …). mkdir is the claim so concurrent callers never share a dir.
     */
    public function claimNew(string $base): Project
    {
        $base = self::slugify($base);
        $slug = $base;
        $n = 2;
        while (true) {
            $root = $this->baseDir . '/' . $slug;
            if (@mkdir($root, 0775, true)) {
                return new Project($root);
            }
            if (!is_dir($root)) {
                throw new \RuntimeException("Could not create project directory: {$root}");
            }
            $slug = $base . $n;
            $n++;
        }
    }

    public function open(string $slug): Project
    {
        $root = $this->baseDir . '/' . self::slugify($slug);
        if (!is_dir($root)) {
            throw new \RuntimeException("Project does not exist: {$root}");
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
     * A short, arbitrary, human-friendly slug like "amber-otter" or
     * "brisk-harbor". Used when a project is created from a prompt without an
     * explicit slug, so the folder name stays short and memorable instead of
     * echoing the whole prompt. Collisions are possible, so callers should wrap
     * this in freeSlug() for the same repetition protection demo builds get.
     */
    public static function randomSlug(): string
    {
        $adjectives = [
            'amber', 'azure', 'brisk', 'calm', 'clever', 'coral', 'crisp',
            'dapper', 'dusky', 'eager', 'fleet', 'gentle', 'golden', 'jolly',
            'lucid', 'mellow', 'nimble', 'olive', 'plucky', 'quiet', 'rustic',
            'silver', 'sunny', 'swift', 'teal', 'vivid', 'warm', 'zesty',
        ];
        $nouns = [
            'otter', 'harbor', 'meadow', 'falcon', 'cedar', 'lantern', 'pebble',
            'willow', 'comet', 'ember', 'grove', 'heron', 'maple', 'brook',
            'canyon', 'dune', 'fjord', 'garden', 'island', 'lagoon', 'orchard',
            'ridge', 'summit', 'thicket', 'valley', 'harvest', 'beacon', 'anchor',
        ];
        return $adjectives[array_rand($adjectives)] . '-' . $nouns[array_rand($nouns)];
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
