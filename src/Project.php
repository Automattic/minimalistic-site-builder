<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\BlockSerializer\NativeStagedFileWriter;

/**
 * A single project on disk: projects/<slug>/. All artifacts (meta.json,
 * siteSpec.json, theme/...) live under its root. Files are the
 * source of truth passed between steps.
 */
final class Project
{
    public function __construct(public readonly string $root) {}

    public function slug(): string
    {
        return basename($this->root);
    }

    /**
     * Absolute path to a file relative to the project root.
     *
     * Project paths are assembled from step output, and step output is
     * model-authored: a `..` segment resolves outside the project and the
     * write lands wherever the path points. Containment belongs here because
     * every other path builder on this class delegates here, so one check
     * covers them all. It is a backstop, not the primary control — steps
     * slugify the names they derive from model output (see
     * BundleFontsStep::faceFilename), which also catches the separators and
     * spaces a project-relative path is right to allow.
     */
    public function path(string $rel = ''): string
    {
        $rel = ltrim($rel, '/');
        if (str_contains($rel, "\0") || in_array('..', explode('/', $rel), true)) {
            throw new \RuntimeException("Project path must be a canonical path inside the project: {$rel}");
        }
        return $rel === '' ? $this->root : $this->root . '/' . $rel;
    }

    /** Absolute path under the theme directory. */
    public function themePath(string $rel = ''): string
    {
        return $this->path('theme' . ($rel === '' ? '' : '/' . ltrim($rel, '/')));
    }

    /** Absolute path under the companion content plugin's directory. */
    public function pluginPath(string $rel = ''): string
    {
        return $this->path('plugin' . ($rel === '' ? '' : '/' . ltrim($rel, '/')));
    }

    /**
     * Absolute paths of every generated block-markup file: the theme's parts
     * and templates plus the content plugin's page files. Markup-scanning
     * consumers (page-styles, fonts-php, validator warnings) read the site
     * through this one lens so content living in the plugin is never
     * invisible to them.
     *
     * @return string[]
     */
    public function markupFiles(): array
    {
        $files = [];
        foreach ([$this->themePath('parts'), $this->themePath('templates'), $this->pluginPath('pages')] as $dir) {
            foreach (glob($dir . '/*.html') ?: [] as $file) {
                $files[] = $file;
            }
        }
        return $files;
    }

    /**
     * Absolute path under the project's logs/ directory, creating it on demand.
     * Steps route their verbose output here (one file per step) so the console
     * stays a concise summary and the full detail is kept for inspection.
     */
    public function logPath(string $rel = ''): string
    {
        $dir = $this->path('logs');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create logs directory: {$dir}");
        }
        return $this->path('logs' . ($rel === '' ? '' : '/' . ltrim($rel, '/')));
    }

    /**
     * Callers use this to decide whether to skip work — GenerateImagesStep to
     * drop a junk manifest entry, ThemeValidator to record "not on disk" as a
     * warning rather than a failure. An uncontained path is unreachable, which
     * is what those callers are asking; answering it must not abort their step.
     */
    public function exists(string $rel): bool
    {
        try {
            return file_exists($this->path($rel));
        } catch (\RuntimeException) {
            return false;
        }
    }

    public function writeText(string $rel, string $content): void
    {
        $full = $this->path($rel);
        $dir = dirname($full);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create directory: {$dir}");
        }
        if (file_put_contents($full, $content) === false) {
            throw new \RuntimeException("Could not write file: {$full}");
        }
    }

    public function readText(string $rel): string
    {
        $full = $this->path($rel);
        if (!is_file($full)) {
            throw new \RuntimeException("Missing file: {$full}");
        }
        $content = @file_get_contents($full);
        if ($content === false) {
            throw new \RuntimeException("Could not read file: {$full}");
        }
        return $content;
    }

    /** @param array<mixed> $data */
    public function writeJson(string $rel, array $data): void
    {
        $this->writeText(
            $rel,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
        );
    }

    /**
     * Replace a JSON artifact atomically through a complete same-directory
     * staging file. Use this for resumable progress manifests: interruption
     * leaves either the previous valid JSON or the complete new JSON, never a
     * truncated target.
     *
     * @param array<mixed> $data
     */
    public function writeJsonAtomic(string $rel, array $data): void
    {
        $full = $this->path($rel);
        $dir = dirname($full);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create directory: {$dir}");
        }

        $content = json_encode(
            $data,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_THROW_ON_ERROR
        ) . "\n";
        $writer = new NativeStagedFileWriter();
        $staged = $writer->stage($full, $content);
        try {
            $writer->replace($staged, $full);
        } catch (\Throwable $error) {
            $writer->discard($staged);
            throw $error;
        }
    }

    /**
     * Record non-fatal problems in warnings.json at the project root: the
     * durable, machine-readable place for every defect the build chose to
     * deliver through rather than fail on, grouped by the reporting step's id.
     * A later repair pass (BIGR-722) consumes this file to fix each problem.
     * Repeated calls append; duplicate messages within a step are dropped.
     * Projects are built once, so there is no cross-run cleanup: any future
     * resume/rebuild feature must clear stale entries or this file drifts
     * from the theme's actual state.
     *
     * @param list<string> $messages
     */
    public function addWarnings(string $stepId, array $messages): void
    {
        if ($messages === []) {
            return;
        }
        $warnings = $this->exists('warnings.json') ? $this->readJson('warnings.json') : [];
        $warnings[$stepId] = array_values(array_unique(array_merge($warnings[$stepId] ?? [], $messages)));
        $this->writeJson('warnings.json', $warnings);
    }

    /** @return array<mixed> */
    public function readJson(string $rel): array
    {
        $data = json_decode($this->readText($rel), true);
        if (!is_array($data)) {
            throw new \RuntimeException("File is not valid JSON: {$this->path($rel)}");
        }
        return $data;
    }
}
