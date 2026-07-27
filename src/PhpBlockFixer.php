<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\BlockSerializer\DroppedContentDetector;
use Automattic\SiteBuild\BlockSerializer\FileReport;
use Automattic\SiteBuild\BlockSerializer\FixerReport;
use Automattic\SiteBuild\BlockSerializer\NativeStagedFileWriter;
use Automattic\SiteBuild\BlockSerializer\Repair;
use Automattic\SiteBuild\BlockSerializer\Serializer;
use Automattic\SiteBuild\BlockSerializer\StagedFileWriter;
use Automattic\SiteBuild\BlockSerializer\TemplateTransformer;

/** Pure-PHP, fixed-point Gutenberg compatibility fixer. */
final class PhpBlockFixer implements BlockFixer
{
    private const MAX_PASSES = 5;

    public function __construct(
        private ?TemplateTransformer $transformer = null,
        private ?DroppedContentDetector $drops = null,
        private ?StagedFileWriter $writer = null,
    ) {
        $this->transformer ??= new Serializer();
        $this->drops ??= new DroppedContentDetector();
        $this->writer ??= new NativeStagedFileWriter();
    }

    public function fix(string $themeDir): string
    {
        return $this->fixReport($themeDir)->format();
    }

    /** Run the public fixed-point transaction and retain its typed report. */
    public function fixReport(string $themeDir): FixerReport
    {
        $themeDir = rtrim($themeDir, DIRECTORY_SEPARATOR);
        if ($themeDir === '' || !file_exists($themeDir)) {
            throw new \RuntimeException("block-fixer theme directory does not exist: {$themeDir}");
        }
        if (!is_dir($themeDir)) {
            throw new \RuntimeException("block-fixer theme path is not a directory: {$themeDir}");
        }
        if (!is_readable($themeDir)) {
            throw new \RuntimeException("block-fixer theme directory is unreadable: {$themeDir}");
        }

        // Discovery and every transformation finish before the first write.
        $prepared = [];
        $reports = [];
        foreach ($this->discover($themeDir) as [$relative, $absolute]) {
            $original = @file_get_contents($absolute);
            if ($original === false) {
                throw new \RuntimeException("Could not read block-fixer input: {$absolute}");
            }
            if (!str_contains($original, '<!-- wp:')) {
                $reports[] = new FileReport($relative, 'skip');
                continue;
            }

            // One file the serializer cannot process must not cost the run
            // every other file. On failure the authored bytes stay on disk —
            // they are what the generator wrote and they still render, they
            // just are not canonicalized — and the reason is reported so it can
            // be fixed at the source instead of discovered by a dead build.
            $current = $original;
            $repairs = [];
            $converged = false;
            $failure = null;
            for ($pass = 1; $pass <= self::MAX_PASSES; $pass++) {
                try {
                    $result = $this->transformer->transform($current);
                } catch (\RuntimeException $error) {
                    // Same narrowing as the per-block fallback: only an
                    // "unsupported markup" failure degrades. A TypeError or
                    // LogicException is our bug and must still crash loudly
                    // rather than ship a theme of unprocessed files.
                    $failure = "pass {$pass}: {$error->getMessage()}";
                    break;
                }
                foreach ($result->repairs as $repair) {
                    $repairs[$repair->blockPath . "\0" . $repair->code] = $repair;
                }
                if ($result->html === $current) {
                    $converged = true;
                    break;
                }
                $current = $result->html;
            }
            if ($failure === null && !$converged) {
                $failure = 'did not converge within ' . self::MAX_PASSES . ' passes';
            }
            if ($failure !== null) {
                $reports[] = new FileReport($relative, 'failed', [], [], $failure);
                continue;
            }

            $changed = $current !== $original;
            // Repairs are normally only reported for a file whose bytes moved.
            // A block kept as authored is the exception: the file may be
            // byte-identical *because* the serializer gave up on part of it, and
            // reporting that as a clean `ok` is the one outcome this whole
            // fallback must never produce.
            $kept = array_values(array_filter(
                $repairs,
                static fn (Repair $repair): bool => str_starts_with($repair->code, 'block-kept-as-authored:'),
            ));
            $fileRepairs = $changed ? array_values($repairs) : $kept;
            $dropped = $changed ? $this->drops->detect($original, $current) : [];
            $reports[] = new FileReport(
                $relative,
                $changed ? 'fixed' : 'ok',
                $dropped,
                $fileRepairs,
            );
            if ($changed) {
                $prepared[] = [
                    'target' => $absolute,
                    'content' => $current,
                ];
            }
        }

        // Stage every complete replacement. Any staging failure leaves every
        // input untouched. Commit is a sequence of atomic replacements.
        $staged = [];
        try {
            foreach ($prepared as $file) {
                $temporary = $this->writer->stage($file['target'], $file['content']);
                $staged[] = ['temporary' => $temporary, 'target' => $file['target']];
            }
        } catch (\Throwable $error) {
            foreach ($staged as $file) {
                $this->writer->discard($file['temporary']);
            }
            throw new \RuntimeException('Could not stage block-fixer output: ' . $error->getMessage(), 0, $error);
        }

        foreach ($staged as $index => $file) {
            try {
                $this->writer->replace($file['temporary'], $file['target']);
            } catch (\Throwable $error) {
                // Earlier replacements are complete canonical bytes. Discard
                // this and all later staged files; retry is safe and converges.
                for ($remaining = $index; $remaining < count($staged); $remaining++) {
                    $this->writer->discard($staged[$remaining]['temporary']);
                }
                throw new \RuntimeException(
                    'Could not commit block-fixer output: ' . $error->getMessage(),
                    0,
                    $error,
                );
            }
        }

        return new FixerReport($reports, 1);
    }

    /** @return list<array{0:string,1:string}> */
    private function discover(string $themeDir): array
    {
        $files = [];
        foreach (['templates', 'parts'] as $directory) {
            $path = $themeDir . DIRECTORY_SEPARATOR . $directory;
            if (!is_dir($path)) {
                continue;
            }
            if (!is_readable($path)) {
                throw new \RuntimeException("Block-fixer input directory is unreadable: {$path}");
            }
            foreach (scandir($path) ?: [] as $name) {
                if ($name === '.' || $name === '..' || !str_ends_with($name, '.html')) {
                    continue;
                }
                $absolute = $path . DIRECTORY_SEPARATOR . $name;
                if (!is_file($absolute)) {
                    continue;
                }
                $relative = $directory . '/' . $name;
                $files[] = [$relative, $absolute];
            }
        }
        usort($files, static fn (array $left, array $right): int => strcmp($left[0], $right[0]));
        return $files;
    }
}
