<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\Steps\ApplyIdentityStep;
use Automattic\SiteBuild\Steps\AssembleLandingPageStep;
use Automattic\SiteBuild\Steps\CollectImagesStep;
use Automattic\SiteBuild\Steps\DesignDirectionStep;
use Automattic\SiteBuild\Steps\FinalizeThemeStep;
use Automattic\SiteBuild\Steps\FixBlocksStep;
use Automattic\SiteBuild\Steps\FontsPhpStep;
use Automattic\SiteBuild\Steps\PageStylesStep;
use Automattic\SiteBuild\Steps\RefinePromptStep;
use Automattic\SiteBuild\Steps\ScaffoldThemeStep;
use Automattic\SiteBuild\Steps\SectionPlanStep;
use Automattic\SiteBuild\Steps\SectionsStep;
use Automattic\SiteBuild\Steps\SiteSpecStep;
use Automattic\SiteBuild\Steps\ThemeJsonStep;

/**
 * Consumer-facing entry point for the default site-creation pipeline.
 *
 * Construct with an Llm transport, prompts dir, output root, and BlockFixer;
 * then createProject() and pipeline()->runThrough(). Replaces the procedural
 * build_pipeline() bootstrap so embedding hosts inject their own dependencies.
 *
 * @param array<string,string> $models step id => model id overrides
 */
final class SiteBuilder
{
    /** @param array<string,string> $models */
    public function __construct(
        private Llm $llm,
        private string $promptsDir,
        private string $outputRoot,
        private BlockFixer $blockFixer,
        private array $models = [],
    ) {}

    /**
     * Assemble the full site-creation pipeline in order. Fresh Pipeline each
     * call. Model map is package defaults (step_models()) overlaid with the
     * constructor overrides; temperatures come from step_temperatures().
     */
    public function pipeline(): Pipeline
    {
        $renderer = new PromptRenderer($this->promptsDir);
        $models = array_merge(step_models(), $this->models);
        $temps = step_temperatures();

        return new Pipeline([
            new ScaffoldThemeStep(),
            // Cheap, fast first pass on a small model: expand short/vague prompts and
            // normalize the brief before any expensive step reads it. Rewrites the
            // `prompt` in meta.json (original kept as `original_prompt`), so every
            // step below benefits with no further wiring.
            new RefinePromptStep($this->llm, $renderer, $models['refine-prompt'], $temps['refine-prompt']),
            new SiteSpecStep($this->llm, $renderer, $models['site-spec'], $temps['site-spec']),
            new ApplyIdentityStep(),
            // Commit to ONE creative concept BEFORE theme.json / the section plan, so
            // both derive from a strong, specific direction instead of converging on
            // safe defaults. Writes designDirection.json, read by the steps below.
            // Tradeoff: this is an extra serial LLM round-trip on the critical path
            // (the concurrent group now depends on its output) — a deliberate cost
            // we pay for design variety; tune via LLM_MODEL_DESIGN_DIRECTION.
            new DesignDirectionStep($this->llm, $renderer, $models['design-direction'], $temps['design-direction'], $models['design-direction-seeds']),
            // theme.json and the section plan both derive from the prompt + siteSpec +
            // the design direction, so run them concurrently. Design decisions are
            // made inline, steered by designDirection.json.
            new ConcurrentGroup($this->llm, [
                new ThemeJsonStep($this->llm, $renderer, $models['theme-json'], $temps['theme-json']),
                new SectionPlanStep($this->llm, $renderer, $models['section-plan'], $temps['section-plan']),
            ]),
            // Generate the header, footer, and every section part in one concurrent
            // batch, then stitch them into the page deterministically.
            new SectionsStep($this->llm, $renderer, $models['sections'], $temps['sections']),
            new AssembleLandingPageStep(),
            // Collect image placeholders BEFORE fix-blocks: the block re-serializer
            // strips the alt from wp:cover background images (core cover save()
            // resets it to ""), which would lose every hero's AI_IMAGE spec.
            new CollectImagesStep(),
            new FixBlocksStep($this->blockFixer),
            // AFTER fix-blocks: reads the final (re-serialized) markup for which
            // layout utility classes survived, and appends their CSS to style.css —
            // a file the fixer never touches, so nothing here can be stripped.
            new PageStylesStep($this->llm, $renderer, $models['page-styles'], $temps['page-styles']),
            // Also after fix-blocks: writes fonts.php from the design direction,
            // validated against a deterministic scan of the final theme.json +
            // markup (every family/weight/italic the build uses MUST be requested;
            // scan-built fallback otherwise).
            new FontsPhpStep($this->llm, $renderer, $models['fonts-php'], $temps['fonts-php']),
            // Sole owner of functions.php: the deterministic loader that enqueues
            // style.css and require_once's the generated fonts.php.
            new FinalizeThemeStep(),
        ]);
    }

    public function store(): ProjectStore
    {
        return new ProjectStore($this->outputRoot);
    }

    /**
     * Create a project directory and seed meta.json. Null slug → free random
     * adjective-noun name (same as bin/build.php). Explicit slug is used as-is
     * (re-runs can target the same folder). Merges over any pre-seeded meta.
     */
    public function createProject(string $prompt, ?string $slug = null): Project
    {
        $store = $this->store();
        if ($slug === null) {
            $slug = $store->freeSlug(ProjectStore::randomSlug());
        }
        $project = $store->create($slug);

        $meta = $project->exists('meta.json') ? $project->readJson('meta.json') : [];
        $project->writeJson('meta.json', array_merge($meta, [
            'prompt'           => $prompt,
            'provisional_slug' => $project->slug(),
            'created_at'       => gmdate('c'),
        ]));

        return $project;
    }
}
