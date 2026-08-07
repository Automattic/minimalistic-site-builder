<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockFixers;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\CollectImagesStep;
use Automattic\SiteBuild\Steps\CoverContrastStep;

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
    print_built_projects(STDERR);
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

// The Llm is only used to rewrite prompts the image safety filter rejects;
// without LLM credentials the step still runs, minus that repair.
try {
    $llm = make_llm();
} catch (\Throwable $e) {
    fwrite(STDERR, "  (no LLM available — safety-filtered prompts won't be repaired: {$e->getMessage()})\n");
    $llm = null;
}

$start = microtime(true);
make_generate_images_step($llm)->run($project);
printf("  done in %.1fs\n", microtime(true) - $start);

// With the real pixels on disk, verify cover text against the dimmed images.
(new CoverContrastStep(BlockFixers::default()))->run($project);

echo "Output: {$project->themePath('assets')}\n";
