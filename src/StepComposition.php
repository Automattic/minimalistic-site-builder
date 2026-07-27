<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\Steps\ApplyIdentityStep;
use Automattic\SiteBuild\Steps\AssemblePagesStep;
use Automattic\SiteBuild\Steps\CollectImagesStep;
use Automattic\SiteBuild\Steps\ContrastFixStep;
use Automattic\SiteBuild\Steps\CustomMotionStep;
use Automattic\SiteBuild\Steps\DesignDirectionStep;
use Automattic\SiteBuild\Steps\FinalizeThemeStep;
use Automattic\SiteBuild\Steps\FixBlocksStep;
use Automattic\SiteBuild\Steps\FontsPhpStep;
use Automattic\SiteBuild\Steps\MotionSanityStep;
use Automattic\SiteBuild\Steps\NormalizeLayoutStep;
use Automattic\SiteBuild\Steps\PagePlanStep;
use Automattic\SiteBuild\Steps\PageStylesStep;
use Automattic\SiteBuild\Steps\RefinePromptStep;
use Automattic\SiteBuild\Steps\ScaffoldPluginStep;
use Automattic\SiteBuild\Steps\ScaffoldThemeStep;
use Automattic\SiteBuild\Steps\SectionRhythmStep;
use Automattic\SiteBuild\Steps\SectionsStep;
use Automattic\SiteBuild\Steps\SiteSpecStep;
use Automattic\SiteBuild\Steps\ThemeJsonStep;
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
     * The package default / CLI composition. Models and temperatures are package
     * defaults overlaid with the overrides passed here (same merge SiteBuilder used).
     *
     * @param array<string, string> $models       step id => model id overrides
     * @param array<string, ?float> $temperatures step id => temperature overrides
     */
    public static function default(
        Llm $llm,
        PromptRenderer $renderer,
        array $models = [],
        array $temperatures = [],
        ?BlockFixer $blockFixer = null,
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
            // Tradeoff: this is three serial LLM round-trips on the critical path
            // (seeds, judge, expansion — the concurrent group depends on the
            // output) — a deliberate cost we pay for design variety and a
            // defensible pick; tune via LLM_MODEL_DESIGN_DIRECTION and
            // LLM_MODEL_DESIGN_DIRECTION_JUDGE.
            new DesignDirectionStep($llm, $renderer, $models['design-direction'], $temps['design-direction'], $models['design-direction-seeds'], $models['design-direction-judge']),
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
            // Also after fix-blocks: writes fonts.php from the design direction,
            // validated against a deterministic scan of the final theme.json +
            // markup (every family/weight/italic the build uses MUST be requested;
            // scan-built fallback otherwise).
            new FontsPhpStep($llm, $renderer, $models['fonts-php'], $temps['fonts-php']),
            // Sole owner of functions.php: the deterministic loader that enqueues
            // style.css and require_once's the generated fonts.php.
            new FinalizeThemeStep(),
            // Last chance to catch contract drift introduced by serialization or
            // later append-only steps before the project is reported as complete.
            new ValidateThemeStep(),
        ]);
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
