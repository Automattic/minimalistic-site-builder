<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockSerializer\FixerReport;
use Automattic\SiteBuild\PhpBlockFixer;

/** @return array<string,mixed> */
function php_block_fixer_golden_json(string $path): array
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("Could not read golden metadata: {$path}");
    }
    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException("Golden metadata is not an object: {$path}");
    }
    return $decoded;
}

/** @return list<string> */
function php_block_fixer_golden_files(string $root): array
{
    if (!is_dir($root)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $files[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
        }
    }
    sort($files, SORT_STRING);
    return $files;
}

function php_block_fixer_golden_copy_tree(string $source, string $target): void
{
    if (!mkdir($target, 0775, true) && !is_dir($target)) {
        throw new RuntimeException("Could not create golden theme: {$target}");
    }
    foreach (php_block_fixer_golden_files($source) as $relative) {
        $destination = $target . '/' . $relative;
        if (!is_dir(dirname($destination))
            && !mkdir(dirname($destination), 0775, true)
            && !is_dir(dirname($destination))) {
            throw new RuntimeException("Could not create golden fixture directory: {$destination}");
        }
        if (!copy($source . '/' . $relative, $destination)) {
            throw new RuntimeException("Could not copy golden fixture: {$relative}");
        }
    }
}

function php_block_fixer_golden_remove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            php_block_fixer_golden_remove($path . '/' . $name);
        }
    }
    @rmdir($path);
}

/** @return list<array{file:string,blockPath:string,code:string}> */
function php_block_fixer_golden_repairs(FixerReport $report): array
{
    $rows = [];
    foreach ($report->files as $file) {
        foreach ($file->repairs as $repair) {
            $rows[] = [
                'file' => $file->path,
                'blockPath' => $repair->blockPath,
                'code' => $repair->code,
            ];
        }
    }
    return $rows;
}

$phpBlockFixerGoldenRoot = dirname(__DIR__) . '/fixtures/block-fixer/cases';
$phpBlockFixerGoldenCases = glob($phpBlockFixerGoldenRoot . '/*', GLOB_ONLYDIR) ?: [];
sort($phpBlockFixerGoldenCases, SORT_STRING);

foreach ($phpBlockFixerGoldenCases as $phpBlockFixerGoldenCase) {
    $phpBlockFixerGoldenName = basename($phpBlockFixerGoldenCase);
    test("PhpBlockFixer matches the pinned fixed-point golden: {$phpBlockFixerGoldenName}", function () use (
        $phpBlockFixerGoldenCase,
        $phpBlockFixerGoldenName,
    ): void {
        $input = $phpBlockFixerGoldenCase . '/input';
        $expected = $phpBlockFixerGoldenCase . '/expected';
        $expectedFiles = php_block_fixer_golden_files($expected);
        assert_true($expectedFiles !== [], "{$phpBlockFixerGoldenName} must contain fixed-point bytes");
        assert_eq(
            php_block_fixer_golden_files($input),
            $expectedFiles,
            "{$phpBlockFixerGoldenName} input and expected file inventories differ"
        );

        $expectedReport = php_block_fixer_golden_json($phpBlockFixerGoldenCase . '/report.json');
        $expectedRepairs = php_block_fixer_golden_json($phpBlockFixerGoldenCase . '/repairs.json');
        assert_eq(1, $expectedRepairs['schemaVersion'] ?? null);
        assert_eq(true, $expectedRepairs['reviewed'] ?? null, "{$phpBlockFixerGoldenName} repairs are not reviewed");

        $temporary = sys_get_temp_dir() . '/php-block-fixer-golden-' . bin2hex(random_bytes(8));
        $theme = $temporary . '/theme';
        php_block_fixer_golden_copy_tree($input, $theme);

        try {
            $fixer = new PhpBlockFixer();
            $first = $fixer->fixReport($theme);

            foreach ($expectedFiles as $relative) {
                $expectedBytes = file_get_contents($expected . '/' . $relative);
                $actualBytes = file_get_contents($theme . '/' . $relative);
                assert_true($expectedBytes !== false && $actualBytes !== false, "could not read {$relative}");
                assert_eq(
                    $expectedBytes,
                    $actualBytes,
                    "{$phpBlockFixerGoldenName}:{$relative} differs from the pinned Node fixed point"
                );
            }
            assert_eq($expectedFiles, php_block_fixer_golden_files($theme), 'the fixer changed the file inventory');
            assert_eq(
                $expectedReport,
                $first->normalized(),
                "{$phpBlockFixerGoldenName} normalized report differs"
            );
            assert_eq(
                $expectedRepairs['k'] ?? null,
                $first->repairCount(),
                "{$phpBlockFixerGoldenName} repair count K differs"
            );
            assert_eq(
                $expectedRepairs['repairs'] ?? null,
                php_block_fixer_golden_repairs($first),
                "{$phpBlockFixerGoldenName} reviewed repair rows differ"
            );

            $fixedPointBytes = [];
            foreach ($expectedFiles as $relative) {
                $fixedPointBytes[$relative] = file_get_contents($theme . '/' . $relative);
            }
            $second = $fixer->fixReport($theme);
            foreach ($fixedPointBytes as $relative => $bytes) {
                assert_eq(
                    $bytes,
                    file_get_contents($theme . '/' . $relative),
                    "{$phpBlockFixerGoldenName}:{$relative} changed on the second invocation"
                );
            }

            $secondExpected = $expectedRepairs['secondInvocation'] ?? null;
            assert_true(is_array($secondExpected), "{$phpBlockFixerGoldenName} lacks second-invocation expectations");
            assert_eq(0, $secondExpected['k'] ?? null, 'reviewed second-invocation K must be zero');
            assert_eq([], $secondExpected['repairs'] ?? null, 'reviewed second-invocation repair rows must be empty');
            assert_eq(0, $second->normalized()['totals']['N'] ?? null, 'second invocation must change no files');
            assert_eq(0, $second->normalized()['totals']['D'] ?? null, 'second invocation must report no drops');
            assert_eq($secondExpected['k'] ?? null, $second->repairCount(), 'second-invocation K differs');
            assert_eq(
                $secondExpected['repairs'] ?? null,
                php_block_fixer_golden_repairs($second),
                'second invocation emits unexpected repair rows'
            );
        } finally {
            php_block_fixer_golden_remove($temporary);
        }
    });
}
