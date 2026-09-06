<?php
declare(strict_types=1);

/**
 * Re-render saved block specs without calling the model, and check each
 * against the pipeline's normalizer. Use it after changing SpecRenderer.
 *
 * Usage: php tests/tools/blocktree_replay.php <file.json|directory> [...]
 */

require __DIR__ . '/../../autoload.php';
require __DIR__ . '/SpecRenderer.php';

use Automattic\SiteBuild\BlockSerializer\Registry\BlockRegistry;
use Automattic\SiteBuild\BlockSerializer\Serializer;
use Automattic\SiteBuild\Tools\SpecRenderer;

$files = [];
foreach (array_slice($argv, 1) as $arg) {
    if (is_file($arg)) { $files[] = $arg; continue; }
    foreach (glob(rtrim($arg, '/') . '/*.json') ?: [] as $f) { $files[] = $f; }
}
sort($files);

$registry = new BlockRegistry();
$renderer = new SpecRenderer($registry);
$serializer = new Serializer($registry);
$fixed = 0; $failed = [];

foreach ($files as $file) {
    try {
        $html = $renderer->document(SpecRenderer::fromJson(file_get_contents($file)));
    } catch (\Throwable $e) {
        $failed[$file] = 'excepción: ' . $e->getMessage();
        continue;
    }
    $result = $serializer->transform($html);
    if ($result->html === $html && $result->repairs === []) {
        $fixed++;
        continue;
    }
    $before = explode("\n", $html);
    $after = explode("\n", $result->html);
    $changed = 0;
    foreach ($before as $i => $line) {
        if (($after[$i] ?? null) !== $line) { $changed++; }
    }
    $failed[$file] = sprintf('%d repairs, %d líneas cambiadas', count($result->repairs), $changed);
}

printf("%d specs: %d punto fijo, %d con diferencia\n", count($files), $fixed, count($failed));
foreach ($failed as $f => $why) { printf("  FALLA %-40s %s\n", basename($f), $why); }
exit($failed === [] ? 0 : 1);
