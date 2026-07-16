<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Consumer-facing entry point for the default site-creation pipeline.
 *
 * Construct with an Llm transport, prompts dir, output root, and BlockFixer;
 * then createProject() and pipeline()->runThrough(). Replaces the procedural
 * build_pipeline() bootstrap so embedding hosts inject their own dependencies.
 *
 * The default step list lives in StepComposition::default() so hosts can
 * extend or slim it without forking this facade. Pipeline construction
 * validates the graph via StepGraph (seed: meta.json from createProject()).
 *
 * Run at most one build per process: LlmLogger is process-global and
 * Pipeline::runThrough() points it at the current project's logs/.
 *
 * @param array<string,string>  $models       step id => model id overrides
 * @param array<string,?float>  $temperatures step id => temperature overrides
 *        (null = don't send temperature; API default applies)
 */
final class SiteBuilder
{
    /**
     * @param array<string,string> $models
     * @param array<string,?float> $temperatures
     */
    public function __construct(
        private Llm $llm,
        private string $promptsDir,
        private string $outputRoot,
        private BlockFixer $blockFixer,
        private array $models = [],
        private array $temperatures = [],
    ) {}

    /**
     * Assemble the full site-creation pipeline in order. Fresh Pipeline each
     * call. Pass a custom StepComposition to use a host-tuned graph.
     */
    public function pipeline(?StepComposition $composition = null): Pipeline
    {
        $composition ??= StepComposition::default(
            llm: $this->llm,
            renderer: new PromptRenderer($this->promptsDir),
            models: $this->models,
            temperatures: $this->temperatures,
            blockFixer: $this->blockFixer,
        );

        return new Pipeline($composition->steps());
    }

    public function store(): ProjectStore
    {
        return new ProjectStore($this->outputRoot);
    }

    /**
     * Create a project directory and seed meta.json. Null slug → free random
     * adjective-noun name (claimed atomically). Explicit slug is used as-is
     * (re-runs can target the same folder). Merges over any pre-seeded meta.
     */
    public function createProject(string $prompt, ?string $slug = null): Project
    {
        $store = $this->store();
        $project = $slug === null
            ? $store->claimNew(ProjectStore::randomSlug())
            : $store->create($slug);

        $meta = $project->exists('meta.json') ? $project->readJson('meta.json') : [];
        $project->writeJson('meta.json', array_merge($meta, [
            'prompt'           => $prompt,
            'provisional_slug' => $project->slug(),
            'created_at'       => gmdate('c'),
        ]));

        return $project;
    }
}
