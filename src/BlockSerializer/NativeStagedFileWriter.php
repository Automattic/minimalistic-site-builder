<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer;

/** Same-directory staging plus atomic rename; no partial target writes. */
final class NativeStagedFileWriter implements StagedFileWriter
{
    public function stage(string $target, string $content): string
    {
        $temporary = @tempnam(dirname($target), '.block-fixer-');
        if ($temporary === false) {
            throw new \RuntimeException("Could not create staged file beside {$target}");
        }
        // tempnam silently falls back to the system tmp dir when the requested
        // directory is not writable; rename() from there may cross filesystems
        // and degrade to a non-atomic copy. Refuse rather than stage unsafely.
        if (realpath(dirname($temporary)) !== realpath(dirname($target))) {
            @unlink($temporary);
            throw new \RuntimeException("Could not create staged file beside {$target}");
        }
        // tempnam creates 0600 files and rename keeps that mode; match the
        // target so replaced theme files stay as readable as the originals.
        $mode = is_file($target) ? (fileperms($target) & 0777) : (0666 & ~umask());
        @chmod($temporary, $mode);
        $handle = @fopen($temporary, 'wb');
        if ($handle === false) {
            @unlink($temporary);
            throw new \RuntimeException("Could not open staged file for {$target}");
        }
        try {
            $length = strlen($content);
            $written = 0;
            while ($written < $length) {
                $count = fwrite($handle, substr($content, $written));
                if ($count === false || $count === 0) {
                    throw new \RuntimeException("Could not write complete staged file for {$target}");
                }
                $written += $count;
            }
            if (!fflush($handle)) {
                throw new \RuntimeException("Could not flush staged file for {$target}");
            }
        } catch (\Throwable $error) {
            fclose($handle);
            @unlink($temporary);
            throw $error;
        }
        fclose($handle);
        return $temporary;
    }

    public function replace(string $staged, string $target): void
    {
        if (!@rename($staged, $target)) {
            throw new \RuntimeException("Could not atomically replace {$target}");
        }
    }

    public function discard(string $staged): void
    {
        if (is_file($staged)) {
            @unlink($staged);
        }
    }
}
