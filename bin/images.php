<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\StepComposition;
use Automattic\SiteBuild\Steps\CollectImagesStep;
use Automattic\SiteBuild\TransformArtifacts;

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
    // The transform report is only written by the HTML-first pipeline, and it
    // is what tells the collector to read prose alts as image subjects.
    (new CollectImagesStep(htmlFirst: $project->exists(TransformArtifacts::REPORT)))->run($project);
}
$specs = $project->readJson('images.json');
$pending = array_filter($specs, static fn ($img) => ($img['status'] ?? 'pending') !== 'completed');
printf("  %d placeholder(s), %d to generate\n", count($specs), count($pending));

// The Llm is only used to rewrite prompts the image safety filter rejects;
// without LLM credentials the step still runs, minus that repair.
try {
    $llm = resolve_llm();
} catch (\Throwable $e) {
    fwrite(STDERR, "  (no LLM available — safety-filtered prompts won't be repaired: {$e->getMessage()})\n");
    $llm = null;
}

// The whole post-image phase, not just the generation: everything downstream
// of the real pixels — the cover-contrast recheck and the theme's preview card
// — has to run here too, or a project that got its images this way keeps the
// placeholders the pipeline left behind.
$start = microtime(true);
foreach (StepComposition::postImages(make_generate_images_step($llm)) as $step) {
    $step->run($project);
}
printf("  done in %.1fs\n", microtime(true) - $start);

echo "Output: {$project->themePath('assets')}\n";
