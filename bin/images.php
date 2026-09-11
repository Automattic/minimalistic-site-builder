<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\StepComposition;
use Automattic\SiteBuild\Steps\CollectImagesStep;
use Automattic\SiteBuild\TransformArtifacts;

/**
 * Generate (or regenerate) the AI images for an already-built project.
 *
 *   php bin/images.php <slug> [--all]
 *
 * Generates eligible pending images recorded in images.json via the WPCOM AI proxy
 * and delivers local placeholders for interior non-hero images. Useful to add images to a build
 * made without --with-images. Already-completed images are left as-is.
 *
 * --all ignores the initial-image policy and generates every pending image,
 * including the ones an earlier run left as placeholders. This is the only way
 * back to a fully imaged build, so evals and demos are not stuck with the
 * spending policy an initial build applied.
 *
 * images.json is written by the collect-images pipeline step (which runs before
 * fix-blocks, while the AI_IMAGE alts are still intact). We do NOT re-collect
 * from the on-disk markup here, because by now fix-blocks has stripped the alt
 * from cover backgrounds — collecting again would silently drop those images.
 * Only if images.json is missing entirely do we attempt a best-effort collect.
 */

require_once __DIR__ . '/../src/bootstrap.php';

$args = array_slice($argv, 1);
$generateAll = in_array('--all', $args, true);
$slug = null;
foreach ($args as $arg) {
    if (!str_starts_with($arg, '--')) {
        $slug = $arg;
        break;
    }
}
if ($slug === null || trim($slug) === '') {
    fwrite(STDERR, "Usage: php bin/images.php <slug> [--all]\n");
    print_built_projects(STDERR);
    exit(1);
}

$store = new ProjectStore(repo_path('projects'));
$project = $store->open($slug);

echo "Generating images for '{$project->slug()}'\n";

// This entry point did not build the project, so the env selector says nothing
// about which graph did — meta.json's record does, and the transform report
// (written only by the HTML-first pipeline) is the fallback for a project built
// before that record existed. Both the collector and postImages' closing
// re-validation apply rules that differ per graph, so they read one answer.
$meta = $project->exists('meta.json') ? $project->readJson('meta.json') : [];
$recordedGraph = $meta['graph'] ?? null;
$htmlFirst = StepComposition::resumeHtmlFirst(
    is_string($recordedGraph) ? $recordedGraph : null,
    null,
) ?? $project->exists(TransformArtifacts::REPORT);

// Use the durable record from the pipeline; only collect if it's absent.
if (!$project->exists('images.json')) {
    // HTML-first is what tells the collector to read prose alts as image subjects.
    (new CollectImagesStep(htmlFirst: $htmlFirst))->run($project);
}
// generate-images applies the policy and narrates what it decided; counting
// here would only duplicate it with a second copy of the same rules.
printf("  %d image(s) recorded%s\n", count($project->readJson('images.json')), $generateAll ? ', generating all' : '');

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
$imagesStep = make_generate_images_step($llm, generateAllImages: $generateAll);
foreach (StepComposition::postImages($imagesStep, htmlFirst: $htmlFirst) as $step) {
    $step->run($project);
}
printf("  done in %.1fs\n", microtime(true) - $start);

echo "Output: {$project->themePath('assets')}\n";
