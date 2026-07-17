#!/usr/bin/env php
<?php
declare(strict_types=1);

use Automattic\SiteBuild\PhpBlockFixer;

require_once __DIR__ . '/../src/bootstrap.php';

/** @return list<string> */
function differential_theme_files(string $theme): array
{
    $files = [];
    foreach (['parts', 'templates'] as $subdirectory) {
        foreach (glob($theme . '/' . $subdirectory . '/*.html') ?: [] as $file) {
            $files[] = $subdirectory . '/' . basename($file);
        }
    }
    sort($files, SORT_STRING);
    return $files;
}

function differential_copy_theme_markup(string $source, string $destination): void
{
    if (!is_dir($source)) {
        throw new RuntimeException("Source directory does not exist: {$source}");
    }
    if (!is_dir($destination) && !mkdir($destination, 0775, true) && !is_dir($destination)) {
        throw new RuntimeException("Could not create temporary directory: {$destination}");
    }
    foreach (['parts', 'templates'] as $subdirectory) {
        $targetDirectory = $destination . '/' . $subdirectory;
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            throw new RuntimeException("Could not create temporary {$subdirectory} directory");
        }
        foreach (glob($source . '/' . $subdirectory . '/*.html') ?: [] as $file) {
            $target = $targetDirectory . '/' . basename($file);
            if (!copy($file, $target)) {
                throw new RuntimeException("Could not copy file: {$subdirectory}/" . basename($file));
            }
        }
    }
}

function differential_remove_tree(string $directory): void
{
    $prefix = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'block-fixer-diff-';
    if (!str_starts_with($directory, $prefix) || !is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($directory);
}

function differential_error_message(Throwable $error, string $theme): string
{
    $message = str_replace($theme, '<theme>', $error->getMessage());
    $message = preg_replace('/\s+/', ' ', $message) ?? $message;
    $message = trim($message);
    if ($message === '') {
        $message = 'No error message';
    }
    return get_debug_type($error) . ': ' . $message;
}

/** @param list<string> $themes @return array{exit:int,stdout:string,stderr:string} */
function differential_node_oracle(array $themes): array
{
    $node = getenv('NODE_BIN');
    $node = is_string($node) && $node !== '' ? $node : 'node';
    $process = proc_open(
        array_merge([$node, __DIR__ . '/block-fixer/oracle.js'], $themes),
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the Node fixed-point oracle');
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

$projectsRoot = __DIR__ . '/../projects';
$requireProjects = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--require-projects') {
        $requireProjects = true;
    } elseif (!str_starts_with($argument, '--')) {
        $projectsRoot = $argument;
    } else {
        fwrite(STDERR, "Unknown option: {$argument}\n");
        exit(2);
    }
}
$projects = [];
if (is_dir($projectsRoot)) {
    foreach (glob(rtrim($projectsRoot, '/') . '/*', GLOB_ONLYDIR) ?: [] as $project) {
        if (differential_theme_files($project . '/theme') !== []) {
            $projects[] = $project;
        }
    }
}
sort($projects, SORT_STRING);

if ($projects === []) {
    fwrite(STDOUT, "SKIP block-fixer project differential: zero non-empty projects found in {$projectsRoot}\n");
    exit($requireProjects ? 1 : 0);
}
if (!class_exists(PhpBlockFixer::class)) {
    fwrite(STDERR, "PhpBlockFixer is not available; complete the PHP implementation before differential testing.\n");
    exit(2);
}

$temporary = sys_get_temp_dir() . '/block-fixer-diff-' . bin2hex(random_bytes(8));
$projectCount = 0;
$fileCount = 0;
$mismatches = [];
$phpErrors = [];
try {
    $copies = [];
    foreach ($projects as $project) {
        $slug = basename($project);
        $nodeTheme = $temporary . '/' . $slug . '/node';
        $phpTheme = $temporary . '/' . $slug . '/php';
        differential_copy_theme_markup($project . '/theme', $nodeTheme);
        differential_copy_theme_markup($project . '/theme', $phpTheme);
        $copies[] = ['slug' => $slug, 'node' => $nodeTheme, 'php' => $phpTheme];
        $projectCount++;
        $fileCount += count(differential_theme_files($nodeTheme));
    }
    $oracle = differential_node_oracle(array_column($copies, 'node'));
    if ($oracle['exit'] !== 0) {
        throw new RuntimeException('Node oracle failed: ' . trim($oracle['stderr']));
    }
    foreach ($copies as $copy) {
        $slug = $copy['slug'];
        $nodeTheme = $copy['node'];
        $phpTheme = $copy['php'];
        try {
            (new PhpBlockFixer())->fix($phpTheme);
        } catch (Throwable $error) {
            $phpErrors[] = [
                'slug' => $slug,
                'message' => differential_error_message($error, $phpTheme),
            ];
        }
        foreach (differential_theme_files($nodeTheme) as $relative) {
            $nodeBytes = (string) file_get_contents($nodeTheme . '/' . $relative);
            $phpBytes = (string) file_get_contents($phpTheme . '/' . $relative);
            if ($nodeBytes !== $phpBytes) {
                $mismatches[] = $slug . '/' . $relative;
            }
        }
    }
} finally {
    differential_remove_tree($temporary);
}

fwrite(
    STDOUT,
    "block-fixer differential: {$projectCount} project(s), {$fileCount} file(s), "
    . count($mismatches) . ' mismatch(es), '
    . count($phpErrors) . " PHP fail-closed project(s).\n",
);
foreach ($mismatches as $mismatch) {
    fwrite(STDOUT, "  MISMATCH {$mismatch}\n");
}
foreach ($phpErrors as $error) {
    fwrite(STDOUT, "  FAIL-CLOSED {$error['slug']}: {$error['message']}\n");
}
exit($mismatches === [] && $phpErrors === [] ? 0 : 1);
