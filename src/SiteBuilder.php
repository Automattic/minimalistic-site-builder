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
 * Production hosts should run at most one build per process: LlmLogger and
 * Narrator are process-global. The network-free matrix harness may run fresh
 * FakeLlm builds sequentially only because it resets both globals per cell.
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
        private ?FontFetcher $fontFetcher = null,
    ) {}

    /**
     * Assemble the full site-creation pipeline in order. Fresh runner each
     * call. Pass a custom StepComposition to use a host-tuned fixed graph.
     */
    public function pipeline(?StepComposition $composition = null): BuildPipeline
    {
        if ($composition !== null) {
            return new Pipeline($composition->steps(), $composition->seeds());
        }

        $renderer = new PromptRenderer($this->promptsDir);
        $composition = StepComposition::default(
            llm: $this->llm,
            renderer: $renderer,
            models: $this->models,
            temperatures: $this->temperatures,
            blockFixer: $this->blockFixer,
            fontFetcher: $this->fontFetcher,
        );
        $primary = new Pipeline($composition->steps(), $composition->seeds());

        // The blocks graph is the default and generates no design document, so
        // it has nothing to fall back from: only HTML-first gets the wrapper.
        if (!StepComposition::htmlFirstSelected()) {
            return $primary;
        }

        $blocksTail = StepComposition::blocksTail(
            llm: $this->llm,
            renderer: $renderer,
            models: $this->models,
            temperatures: $this->temperatures,
            blockFixer: $this->blockFixer,
            fontFetcher: $this->fontFetcher,
        );

        return new FallbackBuildPipeline(
            $primary,
            new Pipeline($blocksTail->steps(), $blocksTail->seeds()),
        );
    }

    public function store(): ProjectStore
    {
        return new ProjectStore($this->outputRoot);
    }

    /**
     * Create a project directory and seed meta.json. Null slug → free random
     * adjective-noun name (claimed atomically). Explicit slug is used as-is
     * (re-runs can target the same folder). Merges over any pre-seeded meta.
     *
     * $multiPage controls whether the site-spec step may retain inner pages.
     * Null keeps the ordinary generated-spec default (one landing page), but
     * preserves the full page tree when $siteSpec is supplied. Explicit false
     * always forces one page; explicit true allows inner pages. The resolved
     * value is recorded in meta.json as `multi_page` so the pipeline needs no
     * further wiring.
     *
     * $pages (multi-page builds only) fixes the page list instead of letting
     * the site-spec step invent one via LLM: entries are title strings or page
     * maps ({title, slug, purpose, children}), first entry = the homepage.
     * Recorded in meta.json as `pages`; [] records nothing, so a pre-seeded
     * `pages` (a host that already names its pages) survives the merge and
     * behaves exactly like the argument, including when siteSpec is supplied.
     *
     * $siteSpec lets an embedding host provide the factual site specification
     * it already owns. It is persisted as the `site_spec` input in meta.json;
     * SiteSpecStep deterministically normalizes it into siteSpec.json instead
     * of making its own LLM request. The prompt remains required because later
     * design and content steps consume both it and the normalized spec. See
     * Package::siteSpecSchemaPath() and Package::siteSpecExamplePath() for the
     * shipped consumer contract.
     *
     * $designConstraints is the optional caller-owned hero capability object;
     * it is validated before a project directory is claimed. $writingDirection
     * is the optional explicit logical direction and accepts only ltr|rtl.
     * $formPlaceholders declares that the host owns a real form backend, so
     * sections reserve a form's place with a JP_FORM placeholder the host
     * replaces after the build; without it they emit no form markup at all.
     *
     * @param array<int,string|array<string,mixed>> $pages
     * @param array<string,mixed>|null              $siteSpec
     * @param array<string,mixed>                   $designConstraints
     */
    public function createProject(
        string $prompt,
        ?string $slug = null,
        ?bool $multiPage = null,
        array $pages = [],
        ?array $siteSpec = null,
        array $designConstraints = [],
        ?string $writingDirection = null,
        bool $formPlaceholders = false,
        ?bool $htmlFirst = null,
    ): Project {
        if ($multiPage === false && $pages !== []) {
            throw new \InvalidArgumentException('A fixed page list requires multiPage to be true or omitted');
        }
        if ($siteSpec !== null) {
            try {
                json_encode($siteSpec, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \InvalidArgumentException('siteSpec must be JSON-serializable', previous: $e);
            }
        }
        $designConstraints = HeroComposition::validateConstraints($designConstraints);
        if (HeroComposition::compatible($designConstraints) === []) {
            throw new \InvalidArgumentException(
                'designConstraints leave no compatible hero recipe'
            );
        }
        $writingDirection = $writingDirection === null
            ? null
            : WritingDirection::validate($writingDirection);

        // A supplied spec is self-contained by default: unless the caller
        // explicitly forces one page, retain whatever page tree it carries.
        // Requested pages likewise imply a multi-page-capable build.
        $resolvedMultiPage = $multiPage ?? ($siteSpec !== null || $pages !== []);

        $store = $this->store();
        $project = $slug === null
            ? $store->claimNew(ProjectStore::randomSlug())
            : $store->create($slug);

        $seed = [
            'prompt'           => $prompt,
            'provisional_slug' => $project->slug(),
            'created_at'       => gmdate('c'),
            'multi_page'       => $resolvedMultiPage,
            // A --from resume reads this to run the graph that built the project.
            // Hosts driving a fixed composition must pass $htmlFirst; the default
            // reads the same selector pipeline() does.
            'graph'            => StepComposition::graphName($htmlFirst),
        ];
        if ($pages !== []) {
            $seed['pages'] = array_values($pages);
        }
        if ($siteSpec !== null) {
            $seed['site_spec'] = $siteSpec;
        }
        if ($designConstraints !== []) {
            $seed['design_constraints'] = $designConstraints;
        }
        if ($writingDirection !== null) {
            $seed['writing_direction'] = $writingDirection;
        }
        if ($formPlaceholders) {
            $seed['form_placeholders'] = true;
        }

        $meta = $project->exists('meta.json') ? $project->readJson('meta.json') : [];
        $project->writeJson('meta.json', array_merge($meta, $seed));

        return $project;
    }
}
