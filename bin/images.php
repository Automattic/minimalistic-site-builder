<?php
declare(strict_types=1);

/**
 * Generate (or regenerate) the AI images for an already-built project.
 *
 *   php bin/images.php <slug>
 *
 * Generates any pending images recorded in images.json via the WPCOM AI proxy
 * and wires the resulting assets into the theme. Useful to add images to a build
 * made without --with-images. Already-completed images are left as-is.
 *
 * images.json is written by the collect-images pipeline step (which runs before
 * fix-blocks, while the AI_IMAGE alts are still intact). We do NOT re-collect
 * from the on-disk markup here, because by now fix-blocks has stripped the alt
 * from cover backgrounds — collecting again would silently drop those images.
 * Only if images.json is missing entirely do we attempt a best-effort collect.
 */

require_once __DIR__ . '/../src/bootstrap.php';

$slug = $argv[1] ?? null;
if ($slug === null || trim($slug) === '') {
    fwrite(STDERR, "Usage: php bin/images.php <slug>\n");
    fwrite(STDERR, "Available projects:\n");
    foreach (glob(repo_path('projects/*/theme/style.css')) ?: [] as $f) {
        fwrite(STDERR, '  - ' . basename(dirname(dirname($f))) . "\n");
    }
    exit(1);
}

$store = new ProjectStore(repo_path('projects'));
$project = $store->open($slug);

echo "Generating images for '{$project->slug()}'\n";

// Use the durable record from the pipeline; only collect if it's absent.
if (!$project->exists('images.json')) {
    (new CollectImagesStep())->run($project);
}
$specs = $project->readJson('images.json');
$pending = array_filter($specs, static fn ($img) => ($img['status'] ?? 'pending') !== 'completed');
printf("  %d placeholder(s), %d to generate\n", count($specs), count($pending));

$start = microtime(true);
(new GenerateImagesStep(make_image_client()))->run($project);
printf("  done in %.1fs\n", microtime(true) - $start);
echo "Output: {$project->themePath('assets')}\n";
