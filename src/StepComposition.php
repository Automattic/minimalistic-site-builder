<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\Steps\ApplyIdentityStep;
use Automattic\SiteBuild\Steps\AssemblePagesStep;
use Automattic\SiteBuild\Steps\AssignImageSourcesStep;
use Automattic\SiteBuild\Steps\BundleFontsStep;
use Automattic\SiteBuild\Steps\CollectImagesStep;
use Automattic\SiteBuild\Steps\ContrastFixStep;
use Automattic\SiteBuild\Steps\CustomMotionStep;
use Automattic\SiteBuild\Steps\DesignDirectionStep;
use Automattic\SiteBuild\Steps\DesignPreviewStep;
use Automattic\SiteBuild\Steps\FinalizeThemeStep;
use Automattic\SiteBuild\Steps\FixBlocksStep;
use Automattic\SiteBuild\Steps\FixPagesStep;
use Automattic\SiteBuild\Steps\FontsPhpStep;
use Automattic\SiteBuild\Steps\HeaderHeroStep;
use Automattic\SiteBuild\Steps\InnerPagesDesignStep;
use Automattic\SiteBuild\Steps\InspirationStep;
use Automattic\SiteBuild\Steps\MotionSanityStep;
use Automattic\SiteBuild\Steps\NormalizeLayoutStep;
use Automattic\SiteBuild\Steps\PagePlanStep;
use Automattic\SiteBuild\Steps\PageStylesStep;
use Automattic\SiteBuild\Steps\RefinePromptStep;
use Automattic\SiteBuild\Steps\ScaffoldPluginStep;
use Automattic\SiteBuild\Steps\ScaffoldThemeStep;
use Automattic\SiteBuild\Steps\SectionCopyDedupeStep;
use Automattic\SiteBuild\Steps\SectionLayoutStep;
use Automattic\SiteBuild\Steps\SectionRhythmStep;
use Automattic\SiteBuild\Steps\SectionsStep;
use Automattic\SiteBuild\Steps\SiteSpecStep;
use Automattic\SiteBuild\Steps\SpliceHomeDesignStep;
use Automattic\SiteBuild\Steps\ThemeJsonStep;
use Automattic\SiteBuild\Steps\TransformSiteStep;
use Automattic\SiteBuild\Steps\ValidateThemeStep;

/**
 * Ordered Step list with assembly-time validation. Hosts start from default()
 * (the CLI full graph) and withSeeds/without/insertAfter/replace.
 *
 * Mutations return a new instance. Validation runs on every construction via
 * StepGraph (default seed: meta.json). Configure new seeds before inserting or
 * replacing a step that reads them so every intermediate composition is valid.
 */
final class StepComposition
{
    /** Artifacts produced before the runtime fallback enters the legacy tail. */
    private const LEGACY_TAIL_SEEDS = [
        'meta.json',
        'theme/style.css',
        'theme/readme.txt',
        'theme/assets/motion/*',
        'theme/assets/header/*',
        'plugin/site-content.php',
        'siteSpec.json',
        'designDirection.json',
        'warnings.json',
    ];

    /**
     * @param Step[]       $steps
     * @param list<string> $seeds
     */
    private function __construct(
        private array $steps,
        private array $seeds = StepGraph::DEFAULT_SEEDS,
    ) {
        StepGraph::validate($this->steps, $this->seeds);
    }

    /**
     * The package default / CLI composition. HTML-first unless the explicit
     * SITE_BUILD_LEGACY=1 escape hatch selects the unchanged legacy graph.
     *
     * @param array<string, string> $models       step id => model id overrides
     * @param array<string, ?float> $temperatures step id => temperature overrides
     * @param bool                  $inspiration  governs only steps constructed by this
     *        composition: false omits InspirationStep and disables its direction/preview
     *        consumers. A separately constructed GenerateImagesStep needs its own
     *        `useInspiration: false`; do not use without('inspiration') for this mode.
     */
    public static function default(
        Llm $llm,
        PromptRenderer $renderer,
        array $models = [],
        array $temperatures = [],
        ?BlockFixer $blockFixer = null,
        ?FontFetcher $fontFetcher = null,
        ?UrlAnalyzer $urlAnalyzer = null,
        bool $inspiration = true,
    ): self {
        if (Env::get('SITE_BUILD_LEGACY') === '1') {
            return self::legacy($llm, $renderer, $models, $temperatures, $blockFixer, $fontFetcher);
        }

        $blockFixer ??= BlockFixers::default();
        $models = array_merge(StepDefaults::models(), $models);
        $temps = array_merge(StepDefaults::temperatures(), $temperatures);

        return new self([
            new ScaffoldThemeStep(),
            new ScaffoldPluginStep(),
            new RefinePromptStep($llm, $renderer, $models['refine-prompt'], $temps['refine-prompt']),
            // Reference URLs in the brief become design briefs before anything
            // reads the prompt for design intent. No URLs means no cost.
            ...($inspiration ? [new InspirationStep($urlAnalyzer)] : []),
            new SiteSpecStep($llm, $renderer, $models['site-spec'], $temps['site-spec']),
            new ApplyIdentityStep(),
            new DesignDirectionStep(
                $llm,
                $renderer,
                $models['design-direction'],
                $temps['design-direction'],
                $models['design-direction-seeds'],
                useInspiration: $inspiration,
            ),
            new DesignPreviewStep(
                $llm,
                $renderer,
                $models['design-preview'] ?? null,
                $temps['design-preview'] ?? null,
                useInspiration: $inspiration,
            ),
            new ThemeJsonStep(
                llm: $llm,
                renderer: $renderer,
                model: $models['theme-json'],
                temperature: $temps['theme-json'],
                htmlFirst: true,
            ),
            new InnerPagesDesignStep(
                $llm,
                $renderer,
                $models['inner-pages-design'] ?? null,
                $temps['inner-pages-design'] ?? null,
                new PagePlanStep(
                    $llm,
                    $renderer,
                    $models['page-plan'],
                    $temps['page-plan'],
                ),
            ),
            new SpliceHomeDesignStep(),
            // Right before transform-site: the design <img> tags need a real
            // theme asset path or the transformer drops them, and the path has
            // to be the one collect-images/generate-images will use later.
            new AssignImageSourcesStep(),
            new TransformSiteStep(
                llm: $llm,
                renderer: $renderer,
                model: $models['transform-site'] ?? null,
                temperature: $temps['transform-site'] ?? null,
                pagePlanStep: new PagePlanStep(
                    $llm,
                    $renderer,
                    $models['page-plan'],
                    $temps['page-plan'],
                ),
                sectionsStep: new SectionsStep(
                    $llm,
                    $renderer,
                    $models['sections'],
                    $temps['sections'],
                ),
            ),
            new SectionRhythmStep(),
            new SectionLayoutStep(),
            new CollectImagesStep(htmlFirst: true),
            new NormalizeLayoutStep(htmlFirst: true),
            new HeaderHeroStep(),
            new ContrastFixStep(htmlFirst: true),
            new MotionSanityStep(htmlFirst: true),
            new FixBlocksStep($blockFixer, htmlFirst: true),
            new AssemblePagesStep(),
            // Re-run the block fixer over the assembled pages: fix-blocks only
            // saw the isolated section parts, so document-scope issues that
            // emerge after concatenation ship unrepaired without this pass.
            new FixPagesStep($blockFixer),
            new PageStylesStep(
                llm: $llm,
                renderer: $renderer,
                model: $models['page-styles'],
                temperature: $temps['page-styles'],
                htmlFirst: true,
            ),
            new CustomMotionStep($llm, $renderer, $models['custom-motion'], $temps['custom-motion']),
            new FontsPhpStep(),
            new FinalizeThemeStep(),
            new ValidateThemeStep(htmlFirst: true),
        ]);
    }

    /**
     * Full pre-HTML-first composition. Keep ordering and constructor options
     * identical for the SITE_BUILD_LEGACY=1 escape hatch.
     *
     * @param array<string, string> $models       step id => model id overrides
     * @param array<string, ?float> $temperatures step id => temperature overrides
     */
    public static function legacy(
        Llm $llm,
        PromptRenderer $renderer,
        array $models = [],
        array $temperatures = [],
        ?BlockFixer $blockFixer = null,
        ?FontFetcher $fontFetcher = null,
    ): self {
        $blockFixer ??= BlockFixers::default();
        $models = array_merge(StepDefaults::models(), $models);
        $temps = array_merge(StepDefaults::temperatures(), $temperatures);

        return new self([
            new ScaffoldThemeStep(),
            // The companion content plugin is pure static code — scaffold it up
            // front next to the theme; apply-identity fills its header and
            // per-site symbol prefixes later.
            new ScaffoldPluginStep(),
            // Cheap, fast first pass on a small model: expand short/vague prompts and
            // normalize the brief before any expensive step reads it. Rewrites the
            // `prompt` in meta.json (original kept as `original_prompt`), so every
            // step below benefits with no further wiring.
            new RefinePromptStep($llm, $renderer, $models['refine-prompt'], $temps['refine-prompt']),
            new SiteSpecStep($llm, $renderer, $models['site-spec'], $temps['site-spec']),
            new ApplyIdentityStep(),
            // Commit to ONE creative concept BEFORE theme.json / the section plan, so
            // both derive from a strong, specific direction instead of converging on
            // safe defaults. Writes designDirection.json, read by the steps below.
            // Tradeoff: this is an extra serial LLM round-trip on the critical path
            // (the concurrent group now depends on its output) — a deliberate cost
            // we pay for design variety; tune via LLM_MODEL_DESIGN_DIRECTION.
            new DesignDirectionStep(
                $llm,
                $renderer,
                $models['design-direction'],
                $temps['design-direction'],
                $models['design-direction-seeds'],
                useInspiration: false,
            ),
            // theme.json and the page plan both derive from the prompt + siteSpec +
            // the design direction, so run them concurrently. Design decisions are
            // made inline, steered by designDirection.json.
            new ConcurrentGroup($llm, [
                new ThemeJsonStep($llm, $renderer, $models['theme-json'], $temps['theme-json']),
                new PagePlanStep($llm, $renderer, $models['page-plan'], $temps['page-plan']),
            ]),
            // Generate the header, footer, and every page's section parts in one
            // concurrent batch, then resolve each page's vertical seams while the
            // parts are still separate files ordered by the plan; the assemble
            // step later composes them deterministically.
            new SectionsStep($llm, $renderer, $models['sections'], $temps['sections']),
            new SectionRhythmStep(),
            // Concurrently authored sections independently derive the same
            // short identity line (kicker/tagline/quote) from one brief; this
            // deterministic pass removes the later repeats page-wide plus the
            // closing-section/footer seam, while the parts are still separate
            // ordered files (BIGR-783). Hero copy stays with HeaderHeroStep.
            new SectionCopyDedupeStep(),
            // Collect image placeholders BEFORE fix-blocks: the block re-serializer
            // strips the alt from wp:cover background images (core cover save()
            // resets it to ""), which would lose every hero's AI_IMAGE spec.
            new CollectImagesStep(),
            // Attribute repair + layout/rhythm normalization BEFORE the
            // contrast/motion policy passes: LayoutFixer can activate
            // previously-inert attributes (unparseable JSON, HTML-only
            // declarations), and those must exist when the policies run or
            // repaired markup would bypass them unchecked.
            new NormalizeLayoutStep(),
            // Deterministic markup finalizer for the delivery-phase
            // aboveFold.json contract shared by header, hero, openings, and
            // the following seam. It runs after section rhythm/layout, writes
            // the final phase atomically with any objective repairs, and stays
            // before contrast/fix-blocks so later serialization can mirror its
            // attributes into saved HTML.
            new HeaderHeroStep(),
            // Deterministic WCAG contrast lint + repair. BEFORE fix-blocks:
            // repairs rewrite only the block-comment JSON attributes, and the
            // fix-blocks re-serialization below regenerates the saved HTML
            // from those attributes, keeping markup and attributes in sync.
            new ContrastFixStep(),
            // Deterministic backstop for the motion-class budget the section
            // prompts are asked to respect (independent concurrent calls can't
            // coordinate it). Also BEFORE fix-blocks: it edits block-comment
            // JSON attributes and the re-serialization syncs the HTML.
            new MotionSanityStep(),
            new FixBlocksStep($blockFixer),
            // AFTER fix-blocks, so the markup inlined into the content plugin is
            // the final re-serialized form: writes plugin/pages/* + the manifest,
            // the theme's page/index templates, and drops the transient parts.
            new AssemblePagesStep(),
            // AFTER fix-blocks: reads the final (re-serialized) markup for which
            // layout utility classes survived, and appends their CSS to style.css —
            // a file the fixer never touches, so nothing here can be stripped.
            new PageStylesStep($llm, $renderer, $models['page-styles'], $temps['page-styles']),
            // Escape hatch: one scoped CSS-generation call, made ONLY when the
            // user explicitly requested a specific animation (site-spec captured
            // it verbatim) AND a section tagged its target. Default path: no-op,
            // zero LLM calls.
            new CustomMotionStep($llm, $renderer, $models['custom-motion'], $temps['custom-motion']),
            // Also after fix-blocks: ships each Google family the scan selected as
            // theme assets declared in theme.json, so visitors are never sent to
            // fonts.googleapis.com. A family the catalog does not know, or whose
            // faces fail to download, degrades to the link path below.
            new BundleFontsStep($fontFetcher),
            // Hotlinks exactly the families BundleFontsStep left unbundled.
            // Deterministic: builds fonts.php from the committed typography floor
            // plus final theme/markup usage — usage may add variants, never remove
            // direction-selected ones. It takes no model — see BIGR-750.
            new FontsPhpStep(),
            // Sole owner of functions.php: the deterministic loader that enqueues
            // style.css and require_once's the generated fonts.php.
            new FinalizeThemeStep(),
            // Last chance to catch contract drift introduced by serialization or
            // later append-only steps before the project is reported as complete.
            new ValidateThemeStep(),
        ]);
    }

    /**
     * Legacy graph after the shared design-direction prefix. Its seeds are the
     * common artifacts already produced by the primary pipeline.
     *
     * @param array<string, string> $models       step id => model id overrides
     * @param array<string, ?float> $temperatures step id => temperature overrides
     */
    public static function legacyTail(
        Llm $llm,
        PromptRenderer $renderer,
        array $models = [],
        array $temperatures = [],
        ?BlockFixer $blockFixer = null,
        ?FontFetcher $fontFetcher = null,
    ): self {
        $steps = self::legacy($llm, $renderer, $models, $temperatures, $blockFixer, $fontFetcher)->steps();
        foreach ($steps as $index => $step) {
            if ($step->id() === 'design-direction') {
                return new self(array_slice($steps, $index + 1), self::LEGACY_TAIL_SEEDS);
            }
        }

        throw new \LogicException('StepComposition::legacyTail: design-direction boundary missing');
    }

    /** @return Step[] */
    public function steps(): array
    {
        return $this->steps;
    }

    /** @return list<string> */
    public function seeds(): array
    {
        return $this->seeds;
    }

    public function without(string ...$ids): self
    {
        $drop = array_fill_keys($ids, true);
        $next = array_values(array_filter(
            $this->steps,
            static fn (Step $s) => !isset($drop[$s->id()]),
        ));
        return new self($next, $this->seeds);
    }

    public function insertAfter(string $afterId, Step $step): self
    {
        $next = [];
        $found = false;
        foreach ($this->steps as $s) {
            $next[] = $s;
            if ($s->id() === $afterId) {
                $next[] = $step;
                $found = true;
            }
        }
        if (!$found) {
            throw new \InvalidArgumentException("StepComposition::insertAfter: unknown id '{$afterId}'");
        }
        return new self($next, $this->seeds);
    }

    public function replace(string $id, Step $step): self
    {
        $next = [];
        $found = false;
        foreach ($this->steps as $s) {
            if ($s->id() === $id) {
                $next[] = $step;
                $found = true;
            } else {
                $next[] = $s;
            }
        }
        if (!$found) {
            throw new \InvalidArgumentException("StepComposition::replace: unknown id '{$id}'");
        }
        return new self($next, $this->seeds);
    }

    /**
     * Replace the paths available before the first step runs.
     *
     * Call this before insertAfter() or replace() when the new step reads a seed
     * that is not already available. Every mutation validates immediately.
     */
    public function withSeeds(string ...$seeds): self
    {
        return new self($this->steps, array_values($seeds));
    }
}
