<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Shared plumbing for the per-request transcript loggers (LlmLogger,
 * ImageLogger): the target directory and enabled state, the run-order file
 * numbering, and the guarded write path (best-effort mkdir,
 * `NN-<label>[-failed].log` naming, collision-free paths). Trait static
 * properties get a separate copy per using class, so each logger keeps its own
 * directory and numbering. The using class supplies the file contents (its
 * format() method, via writeTranscript()'s $render callback) and a
 * SLUG_FALLBACK constant used when a label slugs to nothing.
 */
trait TranscriptLogger
{
    /** Target dir for logs, set per run from the active project; null = no-op. */
    private static ?string $dir = null;
    private static bool $disabled = false;

    /** Count of requests logged this run, used to prefix files in call order. */
    private static int $seq = 0;

    /** Where logs are written, or null when no project context is set. */
    public static function dir(): ?string
    {
        return self::$dir;
    }

    /** Point logging at the active project's log dir (null disables it). */
    public static function setDir(?string $dir): void
    {
        self::$dir = $dir;
        self::$seq = 0; // new run → restart the call-order numbering
    }

    /** Turn logging on/off (off is handy for tests). */
    public static function setEnabled(bool $enabled): void
    {
        self::$disabled = !$enabled;
    }

    /**
     * Write one transcript file. Returns the written path, or null when
     * logging is off, there is no directory, or the write failed. Never throws.
     *
     * @param callable(): string $render produces the file contents; only called
     *                                   once the guards have passed
     */
    private static function writeTranscript(string $label, ?string $error, callable $render): ?string
    {
        if (self::$disabled) {
            return null;
        }
        $dir = self::$dir;
        if ($dir === null) {
            // No active project context — nowhere to log (and never the repo root).
            return null;
        }
        try {
            if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
                return null;
            }
            // Prefix the filename with the request's position this run (01, 02, …)
            // so the directory listing reflects the order requests were made, and
            // tag failures so they stand out in the listing.
            $prefix = sprintf('%02d', ++self::$seq);
            $name = $prefix . '-' . $label . ($error !== null ? '-failed' : '');
            $path = self::uniquePath($dir, $name);
            $written = @file_put_contents($path, $render());
            return $written === false ? null : $path;
        } catch (\Throwable $e) {
            // Best-effort: a logging failure must never break a build.
            return null;
        }
    }

    /**
     * Next free path for a label: `<label>.log`, then `<label>-02.log`,
     * `<label>-03.log`, … so two same-named requests never collide. Pure apart
     * from the filesystem existence checks — unit-testable.
     */
    public static function uniquePath(string $dir, string $label): string
    {
        $base = self::slug($label);
        $path = "{$dir}/{$base}.log";
        if (!file_exists($path)) {
            return $path;
        }
        for ($n = 2; ; $n++) {
            $candidate = sprintf('%s/%s-%02d.log', $dir, $base, $n);
            if (!file_exists($candidate)) {
                return $candidate;
            }
        }
    }

    /** Make a label safe as a filename: lowercase, only [a-z0-9._-]. Pure. */
    public static function slug(string $label): string
    {
        $label = strtolower(trim($label));
        $label = preg_replace('/[^a-z0-9._-]+/', '-', $label) ?? '';
        $label = trim($label, '-');
        return $label === '' ? self::SLUG_FALLBACK : $label;
    }
}
