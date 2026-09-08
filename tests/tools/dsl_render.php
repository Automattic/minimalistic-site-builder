<?php
declare(strict_types=1);

/**
 * Compile a DSL section to Gutenberg through the pipeline's own registry,
 * and check the result is already the normalizer's fixed point.
 *
 * Usage: php tests/tools/dsl_render.php <file.dsl> [...] [--out=<dir>]
 */

require __DIR__ . '/../../autoload.php';
require __DIR__ . '/SpecRenderer.php';
require __DIR__ . '/Dsl.php';

use Automattic\SiteBuild\BlockSerializer\Registry\BlockRegistry;
use Automattic\SiteBuild\BlockSerializer\Serializer;
use Automattic\SiteBuild\Tools\Dsl;
use Automattic\SiteBuild\Tools\SpecRenderer;

// Not getopt(): it stops at the first non-option argument, so a --out after
// the file list would be silently dropped.
$outDir = null;
$files = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--out=')) {
        $outDir = rtrim(substr($arg, 6), '/');
    } elseif (!str_starts_with($arg, '--')) {
        $files[] = $arg;
    }
}
if ($files === []) {
    fwrite(STDERR, "usage: dsl_render.php <file.dsl> [...] [--out=<dir>]\n");
    exit(2);
}
if ($outDir !== null && !is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}

$registry = new BlockRegistry();
$renderer = new SpecRenderer($registry);
$serializer = new Serializer($registry);

printf("%-28s %-7s %-7s %-7s %s\n", 'file', 'blocks', 'dsl', 'html', 'fixed');
$failed = 0;
foreach ($files as $file) {
    $dsl = (string) file_get_contents($file);
    try {
        $html = $renderer->document(Dsl::parse($dsl));
    } catch (\Throwable $e) {
        printf("%-28s FAILED: %s\n", basename($file), $e->getMessage());
        $failed++;
        continue;
    }
    $normalized = $serializer->transform($html)->html;
    $fixed = $normalized === $html;
    $failed += $fixed ? 0 : 1;
    printf(
        "%-28s %-7d %-7d %-7d %s\n",
        basename($file),
        substr_count($html, '<!-- wp:'),
        strlen($dsl),
        strlen($html),
        $fixed ? 'yes' : 'NO'
    );
    if ($outDir !== null) {
        file_put_contents("$outDir/" . basename($file, '.dsl') . '.html', $normalized);
    }
}
exit($failed === 0 ? 0 : 1);
