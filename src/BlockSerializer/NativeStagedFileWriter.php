<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer;

/** Same-directory staging plus atomic rename; no partial target writes. */
final class NativeStagedFileWriter implements StagedFileWriter
{
    public function stage(string $target, string $content): string
    {
        $temporary = tempnam(dirname($target), '.block-fixer-');
        if ($temporary === false) {
            throw new \RuntimeException("Could not create staged file beside {$target}");
        }
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
