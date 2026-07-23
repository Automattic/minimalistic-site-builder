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

            $current = $original;
            $repairs = [];
            $converged = false;
            for ($pass = 1; $pass <= self::MAX_PASSES; $pass++) {
                try {
                    $result = $this->transformer->transform($current);
                } catch (\Throwable $error) {
                    throw new \RuntimeException(
                        "Block transformation failed for {$relative} on pass {$pass}: {$error->getMessage()}",
                        0,
                        $error,
                    );
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
            if (!$converged) {
                throw new \RuntimeException(
                    "Block transformation did not converge within " . self::MAX_PASSES . " passes for {$relative}"
                );
            }

            $changed = $current !== $original;
            $fileRepairs = $changed ? array_values($repairs) : [];
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
