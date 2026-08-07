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
use Automattic\SiteBuild\Units\GeneratedMarkup;

/** Pure-PHP, fixed-point Gutenberg compatibility fixer. */
final class PhpBlockFixer implements ReportingBlockFixer
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

            // Per-file isolation: an unsupported block, an unreviewed
            // deprecation signature, or non-convergence abandons THIS file's
            // transformation and delivers its pre-fixer bytes untouched — a
            // typed 'failed' report row the step turns into a durable warning
            // — instead of discarding the whole already-paid-for build.
            $current = $original;
            $repairs = [];
            $converged = false;
            $failure = null;
            for ($pass = 1; $pass <= self::MAX_PASSES; $pass++) {
                try {
                    $result = $this->transformer->transform($current);
                } catch (\Throwable $error) {
                    $failure = "block transformation failed on pass {$pass}: {$error->getMessage()}";
                    break;
                }
                $repairs = array_merge($repairs, $result->repairs);
                if ($result->html === $current) {
                    $converged = true;
                    break;
                }
                $current = $result->html;
            }
            if ($failure === null && !$converged) {
                $failure = 'block transformation did not converge within ' . self::MAX_PASSES . ' passes';
            }
            if ($failure !== null) {
                $reports[] = new FileReport($relative, 'failed', error: $failure);
                continue;
            }

            // core/group's pinned save() intentionally omits style.background.
            // The generated stage tile is a narrower code-owned extension:
            // after the generic serializer reaches its own fixed point,
            // restore its saved wrapper only when the complete trusted attrs
            // contract and marker survived. Keeping this outside the fixed-
            // point loop preserves the generic serializer's frozen behavior
            // while making every PhpBlockFixer caller deliver the same paint.
            $stageRepairPaths = [];
            $current = GeneratedMarkup::resyncStageTextureSavedHtml($current, $stageRepairPaths);
            foreach ($stageRepairPaths as $blockPath) {
                $repairs[] = new Repair('stage-texture-saved-html-resync', $blockPath);
            }

            $changed = $current !== $original;
            $fileRepairs = $changed ? Repair::dedupe($repairs) : [];
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
        foreach (['templates', 'parts', 'pages'] as $directory) {
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
