<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\AboveFoldContract;
use Automattic\SiteBuild\BandColor;
use Automattic\SiteBuild\CardStyle;
use Automattic\SiteBuild\ColorEconomy;
use Automattic\SiteBuild\ConceptSeeds;
use Automattic\SiteBuild\HeadingEmphasis;
use Automattic\SiteBuild\ShapeMarkup;
use Automattic\SiteBuild\CtaStyle;
use Automattic\SiteBuild\Depth;
use Automattic\SiteBuild\Device;
use Automattic\SiteBuild\DirectionExecutability;
use Automattic\SiteBuild\Env;
use Automattic\SiteBuild\FontCatalog;
use Automattic\SiteBuild\FontMonoculture;
use Automattic\SiteBuild\FontShortlist;
use Automattic\SiteBuild\Surface;
use Automattic\SiteBuild\TypeTreatment;
use Automattic\SiteBuild\GeneratedJsonException;
use Automattic\SiteBuild\GroundKey;
use Automattic\SiteBuild\GroundTint;
use Automattic\SiteBuild\HeroBlueprint;
use Automattic\SiteBuild\HeroComposition;
use Automattic\SiteBuild\ImageTreatment;
use Automattic\SiteBuild\ImageCrop;
use Automattic\SiteBuild\ItemPattern;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\Measure;
use Automattic\SiteBuild\Motion;
use Automattic\SiteBuild\LlmOptions;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\TypeScale;
use Automattic\SiteBuild\BoundedChoice;
use Automattic\SiteBuild\Warnings;

/**
 * Step (LLM): commit to ONE distinctive creative concept for the site BEFORE any
 * theme, palette, or layout is chosen.
 *
 * Input:  meta.json (the user prompt) + siteSpec.json (factual info).
 * Output: designDirection.json — the chosen direction as structured data:
 *         title + vivid description plus the explicit fields downstream steps
 *         execute instead of re-interpreting (palette hexes, type pairing and
 *         treatment, image grade, canvas/measure layout commitments, the
 *         repeated-item idiom, and a separately consumed structured front-page
 *         hero blueprint).
 *
 * Two calls. First, a cheap seed call (small model, hot sampling) brainstorms
 * THREE concept seeds — each an object: an evocative title plus one vivid
 * sentence committing the seed's visual world (palette family, typography
 * character, imagery treatment, mood), plus ground/register/accent
 * coordinates — with divergence across the set enforced in the prompt and
 * checked after. One seed is picked uniformly at random, then the
 * main call expands ONLY that seed into the full direction. The random pick
 * over a divergent seed spread is the pipeline's variety injection — repeated
 * builds of one brief land on different concepts — while the expensive
 * model's tokens are spent on a single direction. The DESIGN_DIRECTION_CHOICE
 * env var forces seed N (1-based) for reproducible evals, and a failed seed
 * call degrades to a built-in "invent one bold concept" seed instead of
 * aborting the build.
 *
 * This is the single source of design intent. The theme-json, page-plan and
 * section steps all read it (via DesignDirectionStep::readFor, which renders
 * the structured fields into the injected brief), so two sites diverge in
 * concept — not just in hex values.
 */
final class DesignDirectionStep implements Step
{
    use LlmOptions;

    /**
     * Injected into downstream prompts when no designDirection.json exists yet
     * (a step run in isolation). Keeps the creative intent alive instead of
     * silently reverting to defaults.
     */
    private const FALLBACK = '(No explicit design direction was provided. Make bold, '
        . 'specific, non-generic design choices that fit the brand, and consciously avoid '
        . 'default treatments like a centered hero, all-sans-serif typography, and an '
        . 'unmotivated page background. No hue is off-limits — a reflexive warm off-white '
        . 'is the most common default of all.)';

    /**
     * Injected as the {{seed}} when the seed call fails or returns nothing
     * usable — the expansion call must still commit to something bold.
     */
    private const SEED_FALLBACK = '(No concept seed was chosen. Invent ONE distinctive, '
        . 'topic-grounded concept yourself and commit to it.)';

    /** Where the chosen direction is persisted, and read back from by readFor(). */
    private const FILE = 'designDirection.json';

    /** Successful deterministic repair evidence (kept out of warnings.json). */
    private const REPORT_FILE = 'design-direction.txt';

    /** Palette roles a direction commits to — the same slugs theme.json requires. */
    public const PALETTE_ROLES = ['base', 'contrast', 'primary', 'secondary', 'accent', 'band'];

    /**
     * The corner languages a direction may commit to. `sharp` is the default
     * and wires square corners; `soft`/`round` make downstream repair wire a
     * corner radius onto contained core/image media and buttons.
     */
    public const SHAPES = ['sharp', 'soft', 'round'];

    /** Env var forcing seed N (1-based) — the reproducible-evals escape hatch. */
    public const CHOICE_ENV = 'DESIGN_DIRECTION_CHOICE';

    /** Exact operator/evaluation override for the code-owned hero catalog. */
    public const HERO_RECIPE_ENV = 'HERO_RECIPE';

    /**
     * Card constructions a direction may commit to. The section prompt's card
     * anatomy documents one markup recipe per value; `flush` is the default so
     * the dated inset-media card only appears when a direction opts into it.
     */
    public const CARD_STYLES = CardStyle::ALL;

    /** Render-time treatments tying delivered photos to the committed palette. */
    public const IMAGE_TREATMENTS = ImageTreatment::ALL;

    /** Repeated-item idioms the planner may assign to list-like sections. */
    public const ITEM_PATTERNS = ItemPattern::ALL;

    /** Site-wide image proportion system for crop-role class hooks. */
    public const IMAGE_CROPS = ImageCrop::ALL;

    /**
     * How the page's bands follow one another. The page plan already assigns a
     * layout archetype and background per section, but with no site-level
     * intent to assign them against: across 1,924 audited planned sections it
     * spent 77% of its archetype budget on one value and 87% of its background
     * budget on another, which is the uniform band stack you can see on a
     * finished page. This is the commitment those per-section picks answer to.
     */
    public const RHYTHMS = ['stacked', 'alternating', 'offset', 'interrupted', 'banded', 'gallery'];

    /** The write-side rhythm default; see normalizeRhythm() for why not `stacked`. */
    public const DEFAULT_RHYTHM = 'alternating';

    /**
     * How tightly the page packs vertically. Sections already carry a
     * `vertical_density`, chosen per section with nothing to be consistent
     * with — 85% of audited sections came back `standard`. One site-level
     * commitment gives that per-section choice something to express.
     */
    public const DENSITIES = ['expansive', 'airy', 'measured', 'dense', 'packed'];

    /**
     * Site-level horizontal intent for text below page-opening heroes. The
     * page planner turns this bias into one explicit placement per section;
     * section authors move the readable column without widening its measure.
     */
    public const TEXT_PLACEMENTS = ['left-column', 'centered', 'split', 'asymmetric-thirds'];

    public function __construct(
        private Llm $llm,
        private PromptRenderer $renderer,
        private ?string $model = null,
        private ?float $temperature = null,
        private ?string $seedModel = null,
    ) {}

    public function id(): string
    {
        return 'design-direction';
    }

    public function label(): string
    {
        return 'Choose design direction';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: ['meta.json', 'siteSpec.json'],
            writes: ['designDirection.json', 'warnings.json'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $meta = $project->readJson('meta.json');
        $prompt = (string) ($meta['prompt'] ?? '');
        if (trim($prompt) === '') {
            throw new \RuntimeException('meta.json has no "prompt"');
        }
        $constraints = HeroComposition::validateConstraints($meta['design_constraints'] ?? []);
        if (HeroComposition::compatible($constraints) === []) {
            throw new \InvalidArgumentException(
                'meta.json design_constraints leave no compatible hero recipe'
            );
        }
        self::validateOperatorRecipe($constraints);
        AboveFoldContract::validateHeaderArchetypePreflight(
            Env::get(AboveFoldContract::HEADER_ARCHETYPE_ENV),
            $constraints,
            self::definitiveRequestedPageCount($meta),
        );

        $spec = $project->readText('siteSpec.json');
        $specData = $project->readJson('siteSpec.json');
        // Loaded once: the expansion prompt samples its font shortlist from
        // it, and the monoculture floor below substitutes against it.
        $fontCatalog = FontCatalog::load();

        $warnings = [];
        [
            'text'          => $seed,
            'ground'        => $seedGround,
            'tint'          => $seedTint,
            'register'      => $seedRegister,
            'type_register' => $seedTypeRegister,
            'color_economy' => $seedColorEconomy,
        ] = $this->chooseSeed($prompt, $spec, $warnings);
        $recipe = self::selectHeroRecipe(
            $meta,
            (string) ($specData['slug'] ?? $project->slug()),
            $seed,
            $warnings,
        );
        // The recipe is code-owned and seeded, and so are its media axes
        // (BIGR-912). The prompt below tells the model to preserve the defaults
        // it is handed, so handing every site the same aspect and weight would
        // make the merged contained-split recipe draw one composition forever.
        $blueprintDefaults = array_merge(
            HeroBlueprint::defaultFor($recipe, $constraints),
            HeroComposition::selectMediaAxes(
                (string) ($specData['slug'] ?? $project->slug()),
                $seed,
                $recipe,
            ),
        );
        $heroComposition = $this->renderer->render('hero-composition.md', [
            'recipe' => $recipe,
            'blueprint_defaults' => json_encode(
                $blueprintDefaults,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
            'composition_recipe' => $this->renderer->render(
                HeroComposition::recipeTemplate($recipe),
                [],
            ),
        ]);

        $rendered = $this->renderer->render('design-direction.md', [
            'user_prompt' => $prompt,
            'site_spec'   => $spec,
            'seed'        => $seed,
            // Empty when a degraded seed committed no light/dark coordinate.
            'ground_key'  => $seedGround === ''
                ? 'not committed by the seed — choose one and say which'
                : $seedGround,
            // Empty when the seed round degraded and committed no ground; the
            // prompt then asks for the field without naming a family, and
            // normalize() enforces the direction's own answer instead.
            'ground_tint' => $seedTint === '' ? 'not committed by the seed — choose one and say which' : $seedTint,
            // Same degradation as the ground: a seed that named no tradition
            // asks the expansion to pick one rather than pretending it did.
            'register' => $seedRegister === ''
                ? 'not committed by the seed — read the tradition off the seed sentence'
                : $seedRegister,
            'type_register' => $seedTypeRegister === ''
                ? 'not committed by the seed — read the letterform tradition off the seed sentence'
                : $seedTypeRegister,
            'color_economy' => $seedColorEconomy === ''
                ? 'not committed by the seed — choose the most restrained economy that serves the concept'
                : $seedColorEconomy,
            // A rotating per-site shortlist of real families in the committed
            // tradition. Naming the tradition alone lands every build on its
            // one famous face (BIGR-920); empty for a degraded seed.
            'type_candidates' => FontShortlist::promptParagraph(
                $seedTypeRegister,
                (string) ($specData['slug'] ?? $project->slug()),
                $fontCatalog,
            ),
            'hero_composition' => $heroComposition,
        ]);
        try {
            $payload = $this->llm->completeJson($rendered, $this->withOptions(['log_label' => $this->id()]));
        } catch (GeneratedJsonException $e) {
            // A syntactically unusable generated direction is content drift,
            // not an operational failure. Plain RuntimeExceptions still
            // propagate so transport and programming failures stay visible.
            $payload = [];
            $warnings[] = 'designDirection.json: generated JSON remained unusable after its repair attempt ('
                . $e->getMessage() . '); deterministic seed-derived direction delivered for field direction; '
                . 'disposition fallback';
        }

        $repairs = [];
        $direction = self::normalize(
            $payload['direction'] ?? null,
            $recipe,
            $seed,
            $repairs,
            $warnings,
            $seedTint,
            $seedGround,
            $seedColorEconomy,
            $seedRegister,
            $seedTypeRegister,
        );
        if ($direction === null) {
            // A build without a committed direction still works — every
            // downstream step tolerates empty fields — so deliver the
            // deterministic fallback (built on the chosen seed when one
            // exists) rather than abort. Recorded durably: the site loses
            // the concept-variety this step exists to inject.
            $direction = self::fallbackDirection(
                $seed,
                $recipe,
                (string) ($constraints['hero_canvas'] ?? 'full-bleed'),
            );
            $warnings[] = 'designDirection.json: model returned no usable design direction; field direction '
                . 'authored unusable generated value delivered deterministic assigned-recipe direction; '
                . 'disposition fallback';
        }

        if (isset($constraints['hero_canvas']) && $direction['canvas'] !== $constraints['hero_canvas']) {
            $repairs[] = 'designDirection.json: field canvas authored '
                . self::describe($direction['canvas']) . ' delivered '
                . self::describe($constraints['hero_canvas'])
                . '; disposition repaired to caller-owned design_constraints.hero_canvas';
            $direction['canvas'] = $constraints['hero_canvas'];
        }

        if ($repairs !== []) {
            Narrator::write('  [design-direction] repaired ' . count($repairs)
                . " generated direction field(s) (reported separately from durable warnings).\n");
        }
        // Naming the reflex faces in the prompt moved us off them and straight
        // onto the next tier — prose is a suggestion the model may decline.
        // This is the floor under it, and it runs here rather than in
        // normalize() because it needs the shipped catalog off disk.
        $direction = self::substituteMonocultureFonts(
            $direction,
            (string) ($specData['slug'] ?? $project->slug()),
            $fontCatalog,
            $warnings,
        );

        // Last, with every field final: does the narrative promise decoration
        // no step can execute? The prose is handed to every downstream design
        // and section prompt as the authoritative brief, so a promise outside
        // the bounded vocabulary is never refused and never delivered — the
        // page just ships plainer than its own direction (BIGR-884). Nothing
        // here can be repaired deterministically (rewriting prose needs a
        // model), so this is rung 4: record it and continue.
        array_push($warnings, ...DirectionExecutability::problems($direction));

        // Narrated HERE, after every source has contributed: font substitution
        // and the executability walk both add warnings, and announcing the
        // count before them printed no line at all for a build whose only
        // durable warning came from one of the two.
        if ($warnings !== []) {
            Narrator::write('  [design-direction] warning: delivered through ' . count($warnings)
                . " generated-content degradation(s) (recorded in warnings.json)\n");
        }

        $report = [
            "Assigned hero recipe: {$recipe}",
            'Successful deterministic repairs: ' . count($repairs),
        ];
        foreach ($repairs as $repair) {
            $report[] = '- ' . $repair;
        }
        $report[] = 'Durable degradations: ' . count($warnings);
        foreach ($warnings as $warning) {
            $report[] = '- ' . $warning;
        }
        $project->writeText('logs/' . self::REPORT_FILE, implode("\n", $report) . "\n");

        $project->addWarnings($this->id(), $warnings);
        $project->writeJson(self::FILE, $direction);
    }

    /**
     * The deterministic direction delivered when the model's payload is
     * unusable: the chosen seed's one-sentence commitment as the narrative
     * (or the generic "be bold" brief when even seeding failed), no palette
     * or type commitments (downstream steps then decide inline), full-bleed
     * canvas, default motion profile. Pure — unit-testable.
     *
     * @return array<string,mixed>
     */
    public static function fallbackDirection(
        string $seed,
        string $recipe,
        string $canvas = 'full-bleed',
    ): array
    {
        HeroComposition::assertKnown($recipe);
        if (!in_array($canvas, HeroComposition::CANVASES, true)) {
            throw new \InvalidArgumentException('fallback direction canvas must be full-bleed or framed');
        }
        $seed = trim($seed);
        $description = $seed !== '' && $seed !== self::SEED_FALLBACK
            ? $seed
            : 'Make bold, specific, non-generic design choices that fit the brand, and consciously '
                . 'avoid default treatments like a centered hero, all-sans-serif typography, and an '
                . 'unmotivated page background. No hue is off-limits — a reflexive warm off-white '
                . 'is the most common default of all.';
        return [
            'title'            => '',
            'description'      => $description,
            'palette'          => [],
            'ground_key'       => '',
            'ground_tint'      => '',
            'color_economy'    => ColorEconomy::DEFAULT,
            'type'             => [
                'heading' => self::emptyTypeSlot(),
                'body'    => self::emptyTypeSlot(),
                'accent'  => self::emptyTypeSlot(),
            ],
            'type_scale'       => TypeScale::DEFAULT,
            'image_grade'      => '',
            'image_treatment'  => ImageTreatment::DEFAULT,
            'image_crop'       => ImageCrop::DEFAULT,
            'canvas'           => $canvas,
            'measure'          => Measure::DEFAULT,
            'type_treatment'   => TypeTreatment::DEFAULT,
            'card_style'       => 'flush',
            'item_pattern'     => ItemPattern::DEFAULT,
            'depth'            => Depth::DEFAULT,
            'cta_style'        => CtaStyle::DEFAULT,
            'shape'            => 'sharp',
            'surface'          => Surface::DEFAULT,
            'device'           => Device::DEFAULT,
            'heading_emphasis' => HeadingEmphasis::DEFAULT,
            'rhythm'           => self::DEFAULT_RHYTHM,
            'density'          => 'measured',
            'text_placement'    => 'left-column',
            'motion'           => Motion::DEFAULT_PROFILE,
            'motion_note'      => [],
            'concept_seed'     => $seed,
            'register'         => '',
            'hero_blueprint'   => HeroBlueprint::defaultFor($recipe),
        ];
    }

    /**
     * Brainstorm three concept titles on the cheap model and pick ONE, rendered
     * as the text block the expansion prompt consumes.
     *
     * Precedence: the DESIGN_DIRECTION_CHOICE env var forces seed N (1-based;
     * out of range — including a failed seed call — fails loud, because a
     * forced eval must not silently drift, so it indexes the round as the
     * model wrote it); otherwise a uniform random pick over the DISTINCT
     * seeds, since a world the model described twice would otherwise be twice
     * as likely to win (see ConceptSeeds).
     * Without a forced choice, any seed failure (transport error, no usable
     * seeds) degrades to SEED_FALLBACK — seeding must never abort a build.
     * The step's hot temperature is applied here too: the seed spread is now
     * the pipeline's variety source, and the small models still support
     * sampling.
     *
     * @param list<string> $warnings
     * @return array{text:string,ground:string,tint:string,register:string,type_register:string,color_economy:string}
     */
    private function chooseSeed(string $brief, string $spec, array &$warnings = []): array
    {
        $forced = Env::get(self::CHOICE_ENV);
        $isForced = $forced !== null && $forced !== '';

        $seeds = [];
        try {
            $rendered = $this->renderer->render(
                'design-direction-seeds.md',
                ConceptSeeds::seedPromptVars($brief, $spec),
            );
            $opts = ['log_label' => 'design-direction-seeds'];
            if ($this->seedModel !== null) {
                $opts['model'] = $this->seedModel;
            }
            if ($this->temperature !== null) {
                $opts['temperature'] = $this->temperature;
            }
            $payload = $this->llm->completeJson($rendered, $opts);
            $locked = ConceptSeeds::lockedFromBrief($brief);
            foreach (is_array($payload['seeds'] ?? null) ? $payload['seeds'] : [] as $raw) {
                $seed = ConceptSeeds::normalize($raw, $locked);
                if ($seed !== null) {
                    $seeds[] = $seed;
                }
            }
        } catch (\Throwable $e) {
            if ($isForced) {
                throw $e;
            }
            // Fall through to the fallback seed below.
        }

        if ($isForced) {
            $n = (int) $forced;
            if ($n < 1 || $n > count($seeds)) {
                throw new \RuntimeException(sprintf(
                    'design-direction: %s=%s is out of range (1..%d)',
                    self::CHOICE_ENV,
                    $forced,
                    count($seeds),
                ));
            }
            return self::chosen($seeds[$n - 1]);
        }

        if ($seeds === []) {
            return [
                'text' => self::SEED_FALLBACK,
                'ground' => '',
                'tint' => '',
                'register' => '',
                'type_register' => '',
                'color_economy' => '',
            ];
        }
        $pool = ConceptSeeds::distinct($seeds, $warnings);
        $triples = [];
        foreach ($pool as $seed) {
            $key = ConceptSeeds::axisKey($seed);
            if ($key !== null) {
                $triples[$key] = true;
            }
        }
        // distinct() already records a collapsed round (one world, kept
        // whole). A second row that restates the shared axis is the same
        // event, and "open brief" is a claim this step never checked.
        if (count($triples) > 1) {
            $sharedGround = ConceptSeeds::sharedGround($pool);
            if ($sharedGround !== null) {
                $warnings[] = 'design-direction: every concept seed is ' . $sharedGround
                    . '-grounded; picked from it anyway; disposition tolerated';
            }
            // The tint is not in the dedup key, so a round of three distinct
            // worlds can still lean one way — the audited cohort's cream
            // skew (BIGR-922). Visible in the report, never blocking.
            $sharedTint = ConceptSeeds::sharedTint($pool);
            if ($sharedTint !== null) {
                $warnings[] = 'design-direction: every concept seed is ' . $sharedTint
                    . '-tinted; picked from the one-family round anyway; disposition tolerated';
            }
        }
        return self::chosen($pool[random_int(0, count($pool) - 1)]);
    }

    /**
     * The chosen seed as the facts the expansion needs: the sentence the
     * prompts consume, the ground family the palette check enforces, and the
     * two traditions the expansion must stay inside.
     *
     * `register` and `type_register` used to stop here — they were dedup
     * coordinates only, so a seed labelled `brutalist` reached the expansion as
     * a sentence and nothing more, and the expansion re-decided the world from
     * prose. Passing them through is what makes the vocabularies levers rather
     * than bookkeeping.
     *
     * @param array{text:string,ground:?string,register:?string,accent:?string,tint:?string,type_register:?string,color_economy:?string} $seed
     * @return array{text:string,ground:string,tint:string,register:string,type_register:string,color_economy:string}
     */
    private static function chosen(array $seed): array
    {
        return [
            'text'          => $seed['text'],
            'ground'        => $seed['ground'] ?? '',
            'tint'          => $seed['tint'] ?? '',
            'register'      => $seed['register'] ?? '',
            'type_register' => $seed['type_register'] ?? '',
            'color_economy' => $seed['color_economy'] ?? '',
        ];
    }

    /**
     * One raw seed as the text the prompts consume ("Title — one vivid
     * sentence"), dropping the coordinates only this step's pick uses. The
     * seeds prompt is shared with the homepage-design tournament, which wants
     * the sentence and nothing else. Returns null when nothing usable is
     * present. Pure — unit-testable.
     *
     * @param mixed $raw
     */
    public static function normalizeSeed($raw): ?string
    {
        $seed = ConceptSeeds::normalize($raw);
        return $seed === null ? null : $seed['text'];
    }

    /**
     * Resolve one authoritative recipe from caller controls and the pure
     * compatibility-filtered selector. HERO_RECIPE is exact/fatal; the batch
     * assignment in meta.json is fallible and remaps with a durable warning.
     *
     * @param array<mixed> $meta
     * @param list<string> $warnings
     */
    public static function selectHeroRecipe(
        array $meta,
        string $stableIdentifier,
        string $conceptSeed,
        array &$warnings = [],
    ): string {
        $callerConstraints = HeroComposition::validateConstraints($meta['design_constraints'] ?? []);
        if (HeroComposition::compatible($callerConstraints) === []) {
            throw new \InvalidArgumentException('design_constraints leave no compatible hero recipe');
        }
        $constraints = $callerConstraints;
        $pool = HeroComposition::compatible($constraints);

        $forced = trim((string) (Env::get(self::HERO_RECIPE_ENV) ?? ''));
        if ($forced !== '') {
            HeroComposition::assertKnown($forced);
            if (!HeroComposition::isCompatible($forced, $callerConstraints)) {
                throw new \InvalidArgumentException(
                    self::HERO_RECIPE_ENV . "='{$forced}' is incompatible with caller-owned design_constraints"
                );
            }
            return $forced;
        }

        if (!array_key_exists('hero_assignment', $meta)) {
            return HeroComposition::select($stableIdentifier, $conceptSeed, $constraints);
        }
        $assignment = $meta['hero_assignment'];
        if (is_array($assignment)) {
            $source = is_string($assignment['source'] ?? null)
                ? strtolower(trim($assignment['source']))
                : '';
            $requested = is_string($assignment['requested_recipe'] ?? null)
                ? trim($assignment['requested_recipe'])
                : '';
            if ($source === 'batch'
                && $requested !== ''
                && in_array($requested, HeroComposition::RECIPES, true)
                && in_array($requested, $pool, true)) {
                return $requested;
            }

            $delivered = HeroComposition::select($stableIdentifier, $conceptSeed, $constraints);
            $reason = $source !== 'batch'
                ? 'assignment source was not the supported fallible batch channel'
                : ($requested === ''
                ? 'batch assignment had no usable requested_recipe'
                : (!in_array($requested, HeroComposition::RECIPES, true)
                    ? 'batch requested an unknown recipe'
                    : 'batch request was outside the caller design_constraints pool'));
            $warnings[] = "file='meta.json'; path=\"hero_assignment.requested_recipe\"; authored="
                . self::describe($assignment['requested_recipe'] ?? null)
                . '; delivered=' . self::describe($delivered)
                . "; disposition=remapped by stable compatible-pool selector because {$reason}";
            return $delivered;
        }

        $delivered = HeroComposition::select($stableIdentifier, $conceptSeed, $constraints);
        $warnings[] = "file='meta.json'; path=\"hero_assignment\"; authored="
            . self::describe($assignment)
            . '; delivered=' . self::describe($delivered)
            . '; disposition=malformed fallible batch assignment was remapped by the stable compatible-pool selector';
        return $delivered;
    }

    /** Validate the exact operator override before the seed LLM call. */
    private static function validateOperatorRecipe(array $constraints): void
    {
        $forced = trim((string) (Env::get(self::HERO_RECIPE_ENV) ?? ''));
        if ($forced === '') {
            return;
        }
        HeroComposition::assertKnown($forced);
        if (!HeroComposition::isCompatible($forced, $constraints)) {
            throw new \InvalidArgumentException(
                self::HERO_RECIPE_ENV . "='{$forced}' is incompatible with caller-owned design_constraints"
            );
        }
    }

    /**
     * Count a caller-fixed page tree for header preflight. A model-owned or
     * malformed tree stays unknown; SiteSpecStep will normalize it later.
     *
     * @param array<mixed> $meta
     */
    private static function definitiveRequestedPageCount(array $meta): ?int
    {
        if (($meta['multi_page'] ?? false) !== true) {
            return 1;
        }
        $requested = $meta['pages'] ?? null;
        if (!is_array($requested) || !array_is_list($requested) || $requested === []) {
            return null;
        }
        $countEntry = static function (mixed $entry) use (&$countEntry): ?int {
            if (is_string($entry)) {
                return trim($entry) === '' ? null : 1;
            }
            if (!is_array($entry)) {
                return null;
            }
            $title = trim((string) ($entry['title'] ?? ''));
            if ($title === '') {
                return null;
            }
            $children = $entry['children'] ?? [];
            if (!is_array($children) || !array_is_list($children)) {
                return null;
            }
            $count = 1;
            foreach ($children as $child) {
                $childCount = $countEntry($child);
                if ($childCount === null) {
                    return null;
                }
                $count += $childCount;
            }
            return $count;
        };

        $count = 0;
        foreach ($requested as $entry) {
            $entryCount = $countEntry($entry);
            if ($entryCount === null) {
                return null;
            }
            $count += $entryCount;
        }
        return $count;
    }

    /**
     * Validate and coerce the raw direction into the persisted structure.
     * Returns null when it is unusable (no description). Everything else
     * degrades gracefully: invalid palette hexes and unknown roles are
     * dropped, missing fields become empty strings — downstream renders only
     * what is present. Pure — unit-testable.
     *
     * @param mixed        $raw
     * @param list<string> $repairs
     * @param list<string> $warnings
     * @return ?array<string,mixed>
     */
    public static function normalize(
        mixed $raw,
        string $assignedRecipe = 'cinematic-safe-zone',
        string $conceptSeed = '',
        array &$repairs = [],
        array &$warnings = [],
        string $conceptTint = '',
        string $conceptGround = '',
        string $conceptColorEconomy = '',
        string $conceptRegister = '',
        string $conceptTypeRegister = '',
    ): ?array {
        if (!is_array($raw)) {
            return null;
        }
        $description = trim((string) ($raw['description'] ?? ''));
        if ($description === '') {
            return null;
        }

        $palette = [];
        $rawPalette = is_array($raw['palette'] ?? null) ? $raw['palette'] : [];
        foreach (self::PALETTE_ROLES as $role) {
            $hex = strtoupper(trim((string) ($rawPalette[$role] ?? '')));
            if (preg_match('/^#[0-9A-F]{6}$/', $hex) === 1) {
                $palette[$role] = $hex;
            } elseif (array_key_exists($role, $rawPalette)) {
                $repairs[] = 'designDirection.json: field palette.' . $role
                    . ' authored ' . self::describe($rawPalette[$role])
                    . ' delivered removed; disposition dropped invalid generated color';
            }
        }

        // The seed's light/dark coordinate is authoritative over the
        // expansion's restatement. Move only palette.base across the shared
        // luminance boundary; the later palette floor repairs contrast pairs.
        // A degraded seed may leave the coordinate to the expansion instead.
        $groundKey = BoundedChoice::explicit($conceptGround, GroundKey::ALL)
            ?? BoundedChoice::explicit($raw['ground_key'] ?? null, GroundKey::ALL);
        if ($groundKey !== null && isset($palette['base'])) {
            $authored = $palette['base'];
            if (GroundKey::classify($authored) !== $groundKey) {
                $moved = GroundKey::move($authored, $groundKey);
                if ($moved !== null) {
                    $palette['base'] = $moved;
                    $repairs[] = 'designDirection.json: field palette.base authored '
                        . self::describe($authored) . ' delivered ' . self::describe($moved)
                        . '; disposition moved onto the committed "' . $groundKey . '" ground';
                }
            }
        }

        // The tint is the orthogonal hue coordinate. Apply it after the
        // luminance repair so retint() preserves the committed light/dark key
        // as it rotates the ground into the seed's family.
        $groundTint = BoundedChoice::explicit($conceptTint, GroundTint::ALL)
            ?? BoundedChoice::explicit($raw['ground_tint'] ?? null, GroundTint::ALL);
        $colorEconomy = ColorEconomy::explicit($conceptColorEconomy)
            ?? ColorEconomy::normalize($raw['color_economy'] ?? null, $warnings);
        if ($groundTint !== null && isset($palette['base'])) {
            $authored = $palette['base'];
            if (GroundTint::classify($authored) !== $groundTint) {
                $moved = GroundTint::retint($authored, $groundTint);
                if ($moved !== null) {
                    $palette['base'] = $moved;
                    $repairs[] = 'designDirection.json: field palette.base authored '
                        . self::describe($authored) . ' delivered ' . self::describe($moved)
                        . '; disposition moved onto the committed "' . $groundTint . '" ground';
                } else {
                    $warnings[] = "file='designDirection.json'; path=\"palette.base\"; authored="
                        . self::describe($authored) . '; delivered=' . self::describe($authored)
                        . '; disposition=committed ground_tint "' . $groundTint
                        . '" cannot be rendered at this luminance; retained authored value';
                }
            }
        }

        if (isset($palette['base'])) {
            $authoredBand = $palette['band'] ?? null;
            if (!is_string($authoredBand) || !BandColor::valid($palette['base'], $authoredBand)) {
                $band = BandColor::fromBase($palette['base']);
                if ($band !== null) {
                    $palette['band'] = $band;
                    $repairs[] = 'designDirection.json: field palette.band authored '
                        . self::describe($authoredBand) . ' delivered ' . self::describe($band)
                        . '; disposition derived a same-family surface 10 lightness points from base '
                        . 'without crossing the page light/dark key';
                }
            }
        }

        $type = is_array($raw['type'] ?? null) ? $raw['type'] : [];
        $typeScale = BoundedChoice::normalize(
            $raw['type_scale'] ?? null,
            TypeScale::ALL,
            TypeScale::DEFAULT,
            'type_scale',
            $warnings,
            'invalid modular scale replaced by deterministic classic fallback',
        );

        HeroComposition::assertKnown($assignedRecipe);

        $blueprint = HeroBlueprint::normalize(
            $raw['hero_blueprint'] ?? null,
            $assignedRecipe,
            $repairs,
            $warnings,
        );

        $canvasRaw = strtolower(trim((string) ($raw['canvas'] ?? '')));
        $canvas = $canvasRaw === 'framed' ? 'framed' : 'full-bleed';
        if ($canvasRaw !== '' && $canvasRaw !== $canvas) {
            $repairs[] = 'designDirection.json: field canvas authored '
                . self::describe($raw['canvas']) . ' delivered "full-bleed"; disposition repaired invalid value';
        }

        $measure = BoundedChoice::normalize(
            $raw['measure'] ?? null,
            Measure::ALL,
            Measure::DEFAULT,
            'measure',
            $warnings,
            'invalid layout measure replaced by deterministic standard fallback',
        );
        $typeTreatment = BoundedChoice::normalize(
            $raw['type_treatment'] ?? null,
            TypeTreatment::ALL,
            TypeTreatment::DEFAULT,
            'type_treatment',
            $warnings,
            'invalid heading treatment replaced by deterministic sentence fallback',
        );

        $cardStyle = self::normalizeCardStyle($raw['card_style'] ?? null, $warnings);
        $imageTreatment = self::normalizeImageTreatment($raw['image_treatment'] ?? null, $warnings);
        $itemPattern = ItemPattern::normalize($raw['item_pattern'] ?? null, $warnings);
        $imageCrop = self::normalizeImageCrop($raw['image_crop'] ?? null, $warnings);
        $depth = self::normalizeDepth($raw['depth'] ?? null, $warnings);
        $ctaStyle = BoundedChoice::normalize(
            $raw['cta_style'] ?? null,
            CtaStyle::ALL,
            CtaStyle::DEFAULT,
            'cta_style',
            $warnings,
            'invalid CTA construction replaced by deterministic solid fallback',
        );
        $surface = self::normalizeSurface($raw['surface'] ?? null, $warnings);
        $device = self::rationHairlineDevice(
            self::normalizeDevice($raw['device'] ?? null, $warnings),
            $conceptRegister,
            $conceptTypeRegister,
            $warnings,
        );
        $headingEmphasis = self::normalizeHeadingEmphasis($raw['heading_emphasis'] ?? null, $warnings);
        $rhythm = self::normalizeRhythm($raw['rhythm'] ?? null, $warnings);
        $density = self::normalizeDensity($raw['density'] ?? null, $warnings);
        $textPlacement = self::normalizeTextPlacement($raw['text_placement'] ?? null, $warnings);

        $motion = self::motionProfile($raw['motion'] ?? null);
        $rawMotion = is_string($raw['motion'] ?? null)
            ? strtolower(trim($raw['motion']))
            : '';
        if ($rawMotion !== '' && $rawMotion !== $motion) {
            $repairs[] = 'designDirection.json: field motion authored '
                . self::describe($raw['motion']) . ' delivered ' . self::describe($motion)
                . '; disposition repaired invalid profile';
        }

        // motion_note names kit classes; it is not art direction to interpret.
        // Every token must be a class exactly, so a phrase the kit cannot ship
        // drops whole instead of turning on whichever class its letters
        // happen to contain. Persist the list; format() renders the sentence.
        $rawMotionNote = $raw['motion_note'] ?? null;
        $authoredNote = self::describeNote($rawMotionNote);
        $validated = Motion::validateNote($rawMotionNote, $motion);
        $motionNote = $validated['classes'];
        $notePresent = $rawMotionNote !== null && $rawMotionNote !== '' && $rawMotionNote !== [];
        $alreadyCanonical = is_array($rawMotionNote)
            && array_is_list($rawMotionNote)
            && $rawMotionNote === $motionNote;
        if (
            array_key_exists('motion_note', $raw)
            && $rawMotionNote !== null
            && !is_string($rawMotionNote)
            && !is_array($rawMotionNote)
        ) {
            $warnings[] = "file='designDirection.json'; path=\"motion_note\"; authored="
                . Warnings::value($rawMotionNote)
                . '; delivered=' . Warnings::value($motionNote)
                . '; disposition=motion note was neither a class list nor a string and was removed';
        } elseif ($notePresent && $validated['classes'] === []) {
            $warnings[] = 'designDirection.json: field motion_note authored '
                . Warnings::value($authoredNote)
                . '; delivered=' . Warnings::value($motionNote)
                . '; disposition named no motion-kit class the '
                . $motion . ' profile ships (' . implode('; ', $validated['dropped']) . ')';
        } elseif ($validated['dropped'] !== []) {
            $warnings[] = 'designDirection.json: field motion_note authored '
                . Warnings::value($authoredNote)
                . '; delivered=' . Warnings::value($motionNote)
                . '; disposition dropped ' . implode('; ', $validated['dropped']);
        } elseif ($notePresent && !$alreadyCanonical) {
            $repairs[] = 'designDirection.json: field motion_note authored '
                . self::describe($rawMotionNote) . ' delivered ' . self::describe($motionNote)
                . '; disposition rendered the committed classes as the note list';
        }

        // The corner language is a fixed list; anything unrecognized falls
        // back to sharp — an accidental radius reads as template styling, so
        // rounding only ships on an explicit commitment.
        $rawShape = $raw['shape'] ?? null;
        $explicitShape = self::explicitShape($rawShape);
        $shape = $explicitShape ?? 'sharp';
        if (
            array_key_exists('shape', $raw)
            && $explicitShape === null
        ) {
            $warnings[] = "file='designDirection.json'; path=\"shape\"; authored="
                . Warnings::value($rawShape)
                . '; delivered="sharp"; disposition=invalid corner language replaced by deterministic sharp fallback';
        }

        return [
            'title'            => trim((string) ($raw['title'] ?? '')),
            'description'      => $description,
            'palette'          => $palette,
            'ground_key'       => $groundKey ?? '',
            'ground_tint'      => $groundTint ?? '',
            'color_economy'    => $colorEconomy,
            'type'             => [
                'heading' => self::normalizeTypeSlot($type['heading'] ?? null, 'heading', $warnings),
                'body'    => self::normalizeTypeSlot($type['body'] ?? null, 'body', $warnings),
                'accent'  => self::normalizeTypeSlot($type['accent'] ?? null, 'accent', $warnings),
            ],
            'type_scale'       => $typeScale,
            'image_grade'      => trim((string) ($raw['image_grade'] ?? '')),
            'image_treatment'  => $imageTreatment,
            'image_crop'       => $imageCrop,
            // Anything that isn't an explicit "framed" commitment is full-bleed:
            // an accidental frame reads as a rendering bug, not a design choice.
            'canvas'           => $canvas,
            'measure'          => $measure,
            'type_treatment'   => $typeTreatment,
            // Anything outside the bounded card constructions delivers the
            // flush default — inset media must be an explicit opt-in, never
            // the accidental look every site gets.
            'card_style'       => $cardStyle,
            'item_pattern'     => $itemPattern,
            'depth'            => $depth,
            'cta_style'        => $ctaStyle,
            'shape'            => $shape,
            'surface'          => $surface,
            'device'           => $device,
            // One clause per heading set apart by tone, face or highlighter;
            // the model marks it with the `emph` hook and a kit paints it.
            'heading_emphasis' => $headingEmphasis,
            // The page-level commitments the per-section plan answers to. See
            // RHYTHMS / DENSITIES for why the rhythm default is not `stacked`.
            'rhythm'           => $rhythm,
            'density'          => $density,
            'text_placement'   => $textPlacement,
            // The motion profile is a fixed list (the kit ships exactly these);
            // anything unrecognized falls back to the default so every build
            // commits to ONE profile the downstream steps can gate on.
            'motion'           => $motion,
            'motion_note'      => $motionNote,
            'concept_seed'     => $conceptSeed,
            // The seed's design tradition, kept as a bounded token so
            // build-owned gates (AboveFoldContract's header pool) can read
            // it. The persisted value survives re-normalization: a second
            // pass without the seed axis reads it back from the artifact.
            'register'         => BoundedChoice::explicit($conceptRegister, ConceptSeeds::knownRegisters())
                ?? BoundedChoice::explicit($raw['register'] ?? null, ConceptSeeds::knownRegisters())
                ?? '',
            'hero_blueprint'   => $blueprint,
        ];
    }

    /**
     * Normalize the one machine-readable card construction contract shared by
     * direction generation and every downstream adapter. Missing/null/blank is
     * the documented flush default. Any non-empty unsupported commitment loses
     * authored intent, so its fallback is durable-warning material.
     *
     * @param list<string> $warnings
     */
    public static function normalizeCardStyle(mixed $authored, array &$warnings = []): string
    {
        return BoundedChoice::normalize(
            $authored,
            self::CARD_STYLES,
            'flush',
            'card_style',
            $warnings,
            'unsupported generated card treatment replaced by default',
        );
    }

    /**
     * Normalize the build-owned render-time image treatment. Natural is the
     * honest fallback: an accidental filter changes every delivered photo.
     *
     * @param list<string> $warnings
     */
    public static function normalizeImageTreatment(mixed $authored, array &$warnings = []): string
    {
        return BoundedChoice::normalize(
            $authored,
            ImageTreatment::ALL,
            ImageTreatment::DEFAULT,
            'image_treatment',
            $warnings,
            'unsupported render-time image treatment replaced by natural',
        );
    }

    /**
     * Normalize the build-owned image proportion contract. Mixed preserves the
     * established per-role ratios and is the safe behavior for a pre-field
     * direction; invalid authored intent is durable-warning material.
     *
     * @param list<string> $warnings
     */
    public static function normalizeImageCrop(mixed $authored, array &$warnings = []): string
    {
        return BoundedChoice::normalize(
            $authored,
            ImageCrop::ALL,
            ImageCrop::DEFAULT,
            'image_crop',
            $warnings,
            'unsupported image proportion system replaced by mixed',
        );
    }

    /**
     * Normalize the build-owned elevation contract. Flat is a fully authored
     * visual choice as well as the safe behavior for a pre-field direction;
     * an invalid non-empty model value remains durable-warning material.
     *
     * @param list<string> $warnings
     */
    public static function normalizeDepth(mixed $authored, array &$warnings = []): string
    {
        return BoundedChoice::normalize(
            $authored,
            Depth::ALL,
            Depth::DEFAULT,
            'depth',
            $warnings,
            'unsupported elevation treatment replaced by flat',
        );
    }

    /**
     * Move any type slot off a monoculture face, keeping its category.
     *
     * `axes` is cleared alongside the swap: an `opsz` range was committed for
     * the family the model named, and carrying it onto a different face would
     * promise an optical-size axis the replacement may not have. Weights need
     * no such care — FontCatalog::faces() already resolves to the nearest
     * weight the delivered family actually ships.
     *
     * Pure given the catalog — unit-testable.
     *
     * @param array<string,mixed> $direction
     * @param list<string> $warnings
     * @return array<string,mixed>
     */
    public static function substituteMonocultureFonts(
        array $direction,
        string $seed,
        FontCatalog $catalog,
        array &$warnings = [],
    ): array {
        foreach (['heading', 'body', 'accent'] as $slot) {
            $family = $direction['type'][$slot]['family'] ?? null;
            if (!is_string($family) || trim($family) === '') {
                continue;
            }
            $replacement = FontMonoculture::substitute(trim($family), $seed, $catalog, $slot);
            if ($replacement === null) {
                continue;
            }
            $direction['type'][$slot]['family'] = $replacement;
            $direction['type'][$slot]['axes'] = [];
            $warnings[] = 'designDirection.json: type.' . $slot . '.family authored value '
                . Warnings::value($family) . '; delivered ' . Warnings::value($replacement)
                . '; disposition substituted a monoculture face for one outside it, category preserved';
        }
        return $direction;
    }

    /**
     * The band rhythm, defaulting to `alternating`.
     *
     * The default is deliberately not `stacked`. `stacked` describes what the
     * page plan already does unprompted; making it the fallback would mean a
     * direction that forgot the field silently re-elects the uniform stack this
     * commitment exists to break.
     *
     * @param list<string> $warnings
     */
    public static function normalizeRhythm(mixed $authored, array &$warnings = []): string
    {
        return BoundedChoice::normalize(
            $authored,
            self::RHYTHMS,
            self::DEFAULT_RHYTHM,
            'rhythm',
            $warnings,
            'unsupported generated band rhythm replaced by default',
        );
    }

    /**
     * @param list<string> $warnings
     */
    public static function normalizeDensity(mixed $authored, array &$warnings = []): string
    {
        return BoundedChoice::normalize(
            $authored,
            self::DENSITIES,
            'measured',
            'density',
            $warnings,
            'unsupported generated page density replaced by default',
        );
    }

    /**
     * @param list<string> $warnings
     */
    public static function normalizeTextPlacement(mixed $authored, array &$warnings = []): string
    {
        return BoundedChoice::normalize(
            $authored,
            self::TEXT_PLACEMENTS,
            'left-column',
            'text_placement',
            $warnings,
            'unsupported horizontal text placement replaced by left-column',
        );
    }

    /**
     * @param list<string> $warnings
     */
    public static function normalizeHeadingEmphasis(mixed $authored, array &$warnings = []): string
    {
        return BoundedChoice::normalize(
            $authored,
            HeadingEmphasis::ALL,
            HeadingEmphasis::DEFAULT,
            'heading_emphasis',
            $warnings,
            'unsupported heading emphasis replaced by none',
        );
    }

    public static function normalizeSurface(mixed $authored, array &$warnings = []): string
    {
        return BoundedChoice::normalize(
            $authored,
            Surface::ALL,
            Surface::DEFAULT,
            'surface',
            $warnings,
            'unsupported texture replaced by none',
        );
    }

    /**
     * @param list<string> $warnings
     */
    /**
     * Seed registers whose world is a printed page, where a ruled band is a
     * native mark rather than a reflex.
     *
     * @var list<string>
     */
    public const PRINTED_REGISTERS = ['editorial', 'archival', 'heritage'];

    /**
     * Letterform traditions that carry the same printed-page argument.
     *
     * @var list<string>
     */
    public const PRINTED_TYPE_REGISTERS = ['didone', 'transitional'];

    /**
     * Withhold the hairline device from concepts with no printed-page argument.
     *
     * The prompt says "leave none unless the concept needs that one mark",
     * and 51 of 53 audited directions committed `hairline-rule` anyway, on
     * neon festivals and SaaS landings alike (BIGR-978). The seed's register
     * and letterform tradition are the committed facts a designer would cite
     * for a ruled band, so the device stays only when one of them is a
     * printed tradition. A seed round that committed no tradition leaves
     * nothing to judge against, and the authored device stands.
     *
     * @param list<string> $warnings
     */
    public static function rationHairlineDevice(
        string $device,
        string $register,
        string $typeRegister,
        array &$warnings = [],
    ): string {
        if ($device !== 'hairline-rule') {
            return $device;
        }
        $register = strtolower(trim($register));
        $typeRegister = strtolower(trim($typeRegister));
        if ($register === '' && $typeRegister === '') {
            return $device;
        }
        if (
            in_array($register, self::PRINTED_REGISTERS, true)
            || in_array($typeRegister, self::PRINTED_TYPE_REGISTERS, true)
        ) {
            return $device;
        }
        $warnings[] = 'designDirection.json: field device authored "hairline-rule"; delivered "none"; '
            . 'disposition the hairline device is a printed-page mark and this concept commits register "'
            . ($register === '' ? 'none' : $register) . '" and letterform tradition "'
            . ($typeRegister === '' ? 'none' : $typeRegister)
            . '", neither a printed tradition, so the one-band rule is withheld';
        return Device::DEFAULT;
    }

    public static function normalizeDevice(mixed $authored, array &$warnings = []): string
    {
        return BoundedChoice::normalize(
            $authored,
            Device::ALL,
            Device::DEFAULT,
            'device',
            $warnings,
            'unbuildable motif replaced by none',
        );
    }

    /**
     * @param mixed $raw
     * @param list<string> $warnings
     * @return array{family:string,weights:list<int>,italic:bool,axes:array<string,array{min:float,max:float}>,character:string}
     */
    private static function normalizeTypeSlot($raw, string $slot, array &$warnings): array
    {
        $empty = self::emptyTypeSlot();
        if ($raw === null) {
            return $empty;
        }
        if (!is_array($raw)) {
            $warnings[] = 'designDirection.json: type.' . $slot . ' authored value '
                . Warnings::value($raw) . '; delivered ' . Warnings::value($empty)
                . '; disposition malformed prose typography replaced by the structured empty contract';
            return $empty;
        }

        $rawFamily = $raw['family'] ?? null;
        $family = is_string($rawFamily) ? trim($rawFamily) : '';
        if (array_key_exists('family', $raw) && !is_string($rawFamily)) {
            $warnings[] = 'designDirection.json: type.' . $slot . '.family authored value '
                . Warnings::value($rawFamily) . '; delivered ""; disposition non-string family name removed';
        } elseif (
            $family !== ''
            && preg_match("/^\\p{L}[\\p{L}\\p{N} .&'_-]{0,99}$/u", $family) !== 1
        ) {
            $warnings[] = 'designDirection.json: type.' . $slot . '.family authored value '
                . Warnings::value($rawFamily) . '; delivered ""; disposition invalid family name removed';
            $family = '';
        }

        $rawWeights = $raw['weights'] ?? [];
        $rawAxes = $raw['axes'] ?? [];
        if ($family === '') {
            if ($rawWeights !== []) {
                $warnings[] = 'designDirection.json: type.' . $slot . '.weights authored value '
                    . Warnings::value($rawWeights)
                    . '; delivered []; disposition requirements removed because no deliverable family remained';
            }
            if (array_key_exists('italic', $raw) && $raw['italic'] !== false) {
                $warnings[] = 'designDirection.json: type.' . $slot . '.italic authored value '
                    . Warnings::value($raw['italic'])
                    . '; delivered false; disposition requirement removed because no deliverable family remained';
            }
            if (is_array($rawAxes)) {
                foreach ($rawAxes as $tag => $range) {
                    $warnings[] = 'designDirection.json: type.' . $slot . '.axes.' . (string) $tag
                        . ' authored value ' . Warnings::value($range)
                        . '; delivered removed; disposition axis removed because no deliverable family remained';
                }
            } elseif ($rawAxes !== []) {
                $warnings[] = 'designDirection.json: type.' . $slot . '.axes authored value '
                    . Warnings::value($rawAxes)
                    . '; delivered {}; disposition axes removed because no deliverable family remained';
            }
            if (array_key_exists('character', $raw) && $raw['character'] !== '') {
                $warnings[] = 'designDirection.json: type.' . $slot . '.character authored value '
                    . Warnings::value($raw['character'])
                    . '; delivered ""; disposition rationale removed because no deliverable family remained';
            }
            return $empty;
        }

        $weights = [];
        $invalidWeights = false;
        if (is_array($rawWeights) && array_is_list($rawWeights)) {
            foreach ($rawWeights as $weight) {
                if (
                    (is_int($weight) || (is_string($weight) && ctype_digit($weight)))
                    && (int) $weight >= 100
                    && (int) $weight <= 900
                    && (int) $weight % 100 === 0
                ) {
                    $weights[] = (int) $weight;
                } else {
                    $invalidWeights = true;
                }
            }
        } elseif ($rawWeights !== []) {
            $invalidWeights = true;
        }
        $weights = array_values(array_unique($weights));
        sort($weights);
        if ($family !== '' && $weights === []) {
            $weights = [400];
        }
        if ($invalidWeights) {
            $warnings[] = 'designDirection.json: type.' . $slot . '.weights authored value '
                . Warnings::value($rawWeights) . '; delivered ' . Warnings::value($weights)
                . '; disposition invalid weights removed';
        }

        $italic = is_bool($raw['italic'] ?? null) ? $raw['italic'] : false;
        if (array_key_exists('italic', $raw) && !is_bool($raw['italic'])) {
            $warnings[] = 'designDirection.json: type.' . $slot . '.italic authored value '
                . Warnings::value($raw['italic']) . '; delivered false; disposition non-boolean value removed';
        }

        $axes = [];
        if (is_array($rawAxes)) {
            foreach ($rawAxes as $tag => $range) {
                $path = 'designDirection.json: type.' . $slot . '.axes.' . (string) $tag;
                if ($tag !== 'opsz') {
                    $warnings[] = $path . ' authored value ' . Warnings::value($range)
                        . '; delivered removed; disposition axis is not supported by the deterministic CSS2 contract';
                    continue;
                }
                $min = is_array($range) ? ($range['min'] ?? null) : null;
                $max = is_array($range) ? ($range['max'] ?? null) : null;
                if (
                    !is_int($min) && !is_float($min)
                    || !is_int($max) && !is_float($max)
                    || !is_finite((float) $min)
                    || !is_finite((float) $max)
                    || (float) $min <= 0
                    || (float) $max < (float) $min
                    || (float) $max > 1000
                ) {
                    $warnings[] = $path . ' authored value ' . Warnings::value($range)
                        . '; delivered removed; disposition invalid optical-size range';
                    continue;
                }
                $axes['opsz'] = ['min' => (float) $min, 'max' => (float) $max];
            }
        } elseif ($rawAxes !== []) {
            $warnings[] = 'designDirection.json: type.' . $slot . '.axes authored value '
                . Warnings::value($rawAxes) . '; delivered {}; disposition non-object axes removed';
        }

        $character = is_string($raw['character'] ?? null) ? trim($raw['character']) : '';
        if (array_key_exists('character', $raw) && !is_string($raw['character'])) {
            $warnings[] = 'designDirection.json: type.' . $slot . '.character authored value '
                . Warnings::value($raw['character'])
                . '; delivered ""; disposition non-string typography rationale removed';
        }

        return [
            'family'    => $family,
            'weights'   => $weights,
            'italic'    => $italic,
            'axes'      => $axes,
            'character' => $character,
        ];
    }

    /** @return array{family:string,weights:list<int>,italic:bool,axes:array<string,array{min:float,max:float}>,character:string} */
    private static function emptyTypeSlot(): array
    {
        return ['family' => '', 'weights' => [], 'italic' => false, 'axes' => [], 'character' => ''];
    }

    /**
     * Render one direction as the markdown brief downstream prompts consume:
     * the title + vivid narrative first, then the explicit fields as a fact
     * list the executing steps implement instead of re-interpreting. Empty
     * fields are omitted. Pure — unit-testable.
     *
     * @param array<string,mixed> $direction
     */
    public static function format(array $direction): string
    {
        $title = trim((string) ($direction['title'] ?? ''));
        $description = trim((string) ($direction['description'] ?? ''));
        $head = $title === '' ? $description : "# {$title}\n\n{$description}";

        $facts = [];

        $palette = is_array($direction['palette'] ?? null) ? $direction['palette'] : [];
        $swatches = [];
        foreach ($palette as $role => $hex) {
            $swatches[] = "{$role} {$hex}";
        }
        if ($swatches !== []) {
            $facts[] = '- **Palette**: ' . implode(' · ', $swatches);
        }

        $groundKey = BoundedChoice::explicit($direction['ground_key'] ?? null, GroundKey::ALL);
        if ($groundKey !== null) {
            $facts[] = '- **Ground key**: ' . $groundKey
                . ' — keep the page background on this side of the light/dark luminance split.';
        }

        // Stated alongside the hexes because downstream steps re-pick colors
        // from this block; without it they only see a base hex and read the
        // family back out of it, which is how a committed ground drifts.
        $groundTint = BoundedChoice::explicit($direction['ground_tint'] ?? null, GroundTint::ALL);
        if ($groundTint !== null) {
            $facts[] = '- **Ground tint**: ' . $groundTint
                . ' — the page background belongs to this family; do not re-hue it.';
        }

        $colorEconomy = ColorEconomy::explicit($direction['color_economy'] ?? null);
        if ($colorEconomy !== null) {
            $facts[] = '- **Color economy**: ' . $colorEconomy . ' ('
                . ColorEconomy::meaning($colorEconomy) . ').';
        }

        $type = is_array($direction['type'] ?? null) ? $direction['type'] : [];
        $pair = [];
        foreach (['heading', 'body', 'accent'] as $slot) {
            $typeSlot = is_array($type[$slot] ?? null) ? $type[$slot] : [];
            $family = is_string($typeSlot['family'] ?? null) ? trim($typeSlot['family']) : '';
            if ($family === '') {
                continue;
            }
            $details = ["{$slot} — {$family}"];
            $weights = is_array($typeSlot['weights'] ?? null) ? $typeSlot['weights'] : [];
            if ($weights !== []) {
                $details[] = 'weights ' . implode('/', array_map('intval', $weights));
            }
            if (($typeSlot['italic'] ?? false) === true) {
                $details[] = 'true italics';
            }
            $axes = is_array($typeSlot['axes'] ?? null) ? $typeSlot['axes'] : [];
            foreach ($axes as $tag => $range) {
                if (is_array($range) && isset($range['min'], $range['max'])) {
                    $details[] = $tag . ' ' . self::formatNumber((float) $range['min'])
                        . '..' . self::formatNumber((float) $range['max']);
                }
            }
            $character = is_string($typeSlot['character'] ?? null) ? trim($typeSlot['character']) : '';
            if ($character !== '') {
                $details[] = $character;
            }
            $pair[] = implode('; ', $details);
        }
        if ($pair !== []) {
            $facts[] = '- **Type**: ' . implode('; ', $pair);
        }

        $typeScale = TypeScale::explicit($direction['type_scale'] ?? null);
        if ($typeScale !== null) {
            $facts[] = '- **Type scale**: ' . $typeScale . ' — '
                . TypeScale::meaning($typeScale) . '. The build owns the six preset values.';
        }

        $typeTreatment = TypeTreatment::explicit($direction['type_treatment'] ?? null);
        if ($typeTreatment !== null) {
            $facts[] = '- **Type treatment**: ' . $typeTreatment . ' — '
                . TypeTreatment::meaning($typeTreatment)
                . '. The build owns heading textTransform and letterSpacing; preserve its lineHeight.';
        }

        // Render the canvas commitment with its executable meaning, so the
        // section/header prompts act on it instead of re-interpreting a bare
        // keyword. Directions persisted before the field existed carry none.
        $canvas = trim((string) ($direction['canvas'] ?? ''));
        if ($canvas === 'framed') {
            $facts[] = '- **Canvas**: framed — the page keeps a visible mat of page background around every band BELOW the hero; cap those bands at `"align":"wide"`, never `"align":"full"`. The page-opening hero is exempt: it always runs edge-to-edge with `"align":"full"`, and the mat begins with the following section.';
        } elseif ($canvas !== '') {
            $facts[] = '- **Canvas**: full-bleed — heroes, image bands and color bands may run edge-to-edge with `"align":"full"`.';
        }

        $measure = Measure::explicit($direction['measure'] ?? null);
        if ($measure !== null) {
            $measureFact = '- **Measure**: ' . $measure . ' — ' . Measure::meaning($measure) . '.';
            if ($canvas === 'framed') {
                $measureFact .= ' The committed wideSize is the visible frame edge below the full-bleed hero.';
            }
            $facts[] = $measureFact;
        }

        // Render the card commitment with its executable meaning: the section
        // prompt's card anatomy executes exactly the named construction, and
        // defaults to flush when a direction predates the field.
        $rawCardStyle = $direction['card_style'] ?? null;
        $cardStyle = is_string($rawCardStyle) ? strtolower(trim($rawCardStyle)) : '';
        if (in_array($cardStyle, self::CARD_STYLES, true)) {
            $meaning = match ($cardStyle) {
                'flush'      => 'card media bleeds to the card edges and padding wraps only the text — use the `flush` construction from the card anatomy',
                'framed'     => 'card media sits inset behind padding on all sides — use the `framed` construction from the card anatomy, with concentric corner radii',
                'overlap'    => 'the text panel rides up over the media\'s bottom edge — use the `overlap` construction from the card anatomy',
                'borderless' => 'cards have no box at all; media above a plain text stack — use the `borderless` construction from the card anatomy',
            };
            $facts[] = "- **Card treatment**: {$cardStyle} — {$meaning}.";
        }

        $itemPattern = ItemPattern::explicit($direction['item_pattern'] ?? null);
        if ($itemPattern !== null) {
            $meaning = match ($itemPattern) {
                'card'        => 'list-like sections repeat discrete bounded cards',
                'rule-row'    => 'list-like sections use compact name/detail rows joined by a purposeful hairline',
                'spec-table'  => 'list-like sections align compact label/value pairs for comparison',
                'tag-cluster' => 'list-like sections wrap short categorical labels as compact inline chips',
            };
            $facts[] = "- **Item pattern**: {$itemPattern} — {$meaning}.";
        }

        $ctaStyle = CtaStyle::explicit($direction['cta_style'] ?? null);
        if ($ctaStyle !== null) {
            $facts[] = '- **CTA style**: ' . $ctaStyle . ' — ' . CtaStyle::meaning($ctaStyle)
                . '. The build owns button fill, border, padding, interaction construction, and any arrow glyph;'
                . ' do not restyle those per button.';
        }

        // The page plan reads these two and assigns its per-section archetype,
        // background and density against them. Stated as executable meaning
        // rather than a bare keyword for the same reason as canvas: a keyword
        // alone gets re-interpreted, and the audited result was one archetype
        // on one background for three-quarters of every page.
        $rhythm = self::explicitRhythm($direction);
        if ($rhythm !== null) {
            $facts[] = '- **Rhythm**: ' . $rhythm . ' — ' . match ($rhythm) {
                'stacked'     => 'bands follow one another in one steady column; carry the page on type scale and spacing, not on changes of shape',
                // Deliberately says nothing about backgrounds. Alternating the
                // page's surfaces is the "stripes" pattern the page plan already
                // rejects, and this is the DEFAULT rhythm — a background clause
                // here would contradict that rule on every build.
                'alternating' => 'consecutive bands carry visibly different compositions; vary the layout archetype down the page rather than repeating one and varying only its contents',
                'offset'      => 'bands break the centre line: unequal splits and staggered starts, so the eye never settles on one axis',
                'interrupted' => 'a mostly steady stack broken by full-bleed bands at deliberate intervals — plan at least one edge-to-edge image or colour band per page',
                'banded'      => 'the page is paced by its surfaces: spend the page\'s contrast and tinted bands here rather than carrying it on layout change',
                'gallery'     => 'imagery leads and text supports; favour grids and image bands over text-led sections',
                default       => 'the committed band rhythm',
            } . '.';
        }

        $density = BoundedChoice::explicit($direction['density'] ?? null, self::DENSITIES);
        if ($density !== null) {
            // A bias, not an override: spacious pauses stay accents under every
            // density, so these clauses must not read as "spacious everywhere"
            // — the page plan caps them and would demote the excess anyway.
            $facts[] = '- **Density**: ' . $density . ' — ' . match ($density) {
                'expansive' => 'monumental vertical breathing room; spend every allowed spacious pause, keep the rest standard, and let emptiness carry the page',
                'airy'      => 'generous vertical breathing room; spend the page\'s spacious pauses and prefer standard over compact elsewhere',
                'measured'  => 'an even, unhurried rhythm; standard throughout, with a spacious pause only where the composition needs one',
                'dense'     => 'tightly packed; prefer compact wherever the content supports it and let content carry the page',
                'packed'    => 'maximally compressed; compact everywhere the content permits, no spacious pauses, and the content itself paces the page',
                default     => 'the committed page density',
            } . '. The build derives the section-padding ramp, component spacing, and page gutter from this commitment.';
        }

        $textPlacement = BoundedChoice::explicit(
            $direction['text_placement'] ?? null,
            self::TEXT_PLACEMENTS,
        );
        if ($textPlacement !== null) {
            $facts[] = '- **Text placement**: ' . $textPlacement . ' — ' . match ($textPlacement) {
                'left-column' => 'below page-opening heroes, place readable copy on the wide band\'s leading column rather than auto-centering every stack',
                'centered' => 'below page-opening heroes, center the readable copy column as a composition while keeping wrapped paragraphs start-aligned',
                'split' => 'below page-opening heroes, make copy one side of an intentional two-zone composition and alternate the occupied side where the page flow supports it',
                'asymmetric-thirds' => 'below page-opening heroes, offset readable copy into the second or third zone of wide bands instead of repeating the leading edge',
                default => 'the committed horizontal intent',
            } . '. The page plan assigns each section its own placement against this intent; move the column, never widen its readable measure.';
        }

        $depth = Depth::explicit($direction['depth'] ?? null);
        if ($depth !== null) {
            $facts[] = '- **Depth**: ' . $depth . ' — ' . match ($depth) {
                'flat'        => 'cards, contained images, contained covers, and media-text surfaces stay deliberately shadowless',
                'ring'        => 'the build gives cards and contained media one 1px hairline ring and no lift',
                'soft'        => 'the build gives cards and contained media one restrained, diffuse lift',
                'hard-offset' => 'the build gives cards and contained media one crisp poster-like offset plate',
                'inset'       => 'the build presses cards and contained media into their surfaces with an inset edge and shade',
                'glow'        => 'the build gives cards and contained media one primary-colored luminous halo',
            } . '. Full-bleed media stays unelevated; do not add another shadow.';
        }

        // Render the shape commitment with its executable meaning. The build
        // wires contained media (core/image, core/cover, the media half of
        // core/media-text) and button radii itself; this line keeps prompts
        // from re-interpreting a bare keyword. Directions persisted before
        // the field existed carry none.
        $shape = self::explicitShape($direction['shape'] ?? null);
        if ($shape !== null) {
            $scale = ShapeMarkup::RADIUS_SCALE[$shape];
            $facts[] = match ($shape) {
                'sharp' => '- **Shape**: sharp — the build keeps contained media (`core/image`, `core/cover`, the media half of `core/media-text`) and buttons square. Full-bleed media stays square.',
                'soft'  => '- **Shape**: soft — the build wires a subtle corner radius onto contained media (`core/image`, `core/cover`, the media half of `core/media-text`) and a modest radius onto buttons. Full-bleed media stays square.',
                'round' => '- **Shape**: round — the build wires a decisive corner radius onto contained media (`core/image`, `core/cover`, the media half of `core/media-text`) and pill-shaped buttons. Full-bleed media stays square.',
            } . ' The same commitment is one radius scale the build executes: marked card shells'
                . ' (`card-style--flush`, `card-style--framed`, `card-style--overlap` groups) ' . $scale['card']
                . ', panels ' . $scale['panel'] . ', pill controls ' . $scale['pill']
                . '. Never author a competing radius on those surfaces.';
        }

        $surface = Surface::explicit($direction['surface'] ?? null);
        if ($surface !== null && $surface !== 'none') {
            $surfaceMeaning = match ($surface) {
                'paper'    => 'a paper tooth overlay on the page',
                'concrete' => 'a concrete grit overlay on the page',
                'film'     => 'a film grain overlay on the page',
                'fabric'   => 'a fabric weave overlay on the page',
                default    => 'the committed surface overlay',
            };
            $facts[] = "- **Surface**: {$surface} — {$surfaceMeaning}.";
        }

        $headingEmphasis = HeadingEmphasis::explicit($direction['heading_emphasis'] ?? null);
        if ($headingEmphasis !== null && $headingEmphasis !== 'none') {
            $facts[] = "- **Heading emphasis**: {$headingEmphasis} — " . HeadingEmphasis::meaning($headingEmphasis)
                . '. Mark at most ONE clause per heading, only in the hero H1 and in section headings, never in'
                . ' paragraphs, navigation or buttons; never author a colour, face or background on the span.';
        }

        $device = Device::explicit($direction['device'] ?? null);
        $deviceClass = Device::className($device);
        if ($device !== null && $device !== 'none' && $deviceClass !== null) {
            $deviceMeaning = match ($device) {
                'hairline-rule'  => 'a 1px rule in the current text color on ONE non-hero band',
                'stamp'          => 'a rotated stamp mark on ONE non-hero band',
                default          => 'the committed one-band CSS device',
            };
            $facts[] = "- **Device**: {$deviceClass} — {$deviceMeaning}. Never the hero. Never two bands.";
        }

        // Render the motion commitment with its executable meaning: the
        // section prompts gate their motion-class placement on this line.
        $motion = strtolower(trim((string) ($direction['motion'] ?? '')));
        if (in_array($motion, Motion::PROFILES, true)) {
            $meaning = match ($motion) {
                'none'    => 'the site is completely static; use NO motion classes',
                'minimal' => 'hover micro-interactions only; `hover-lift`/`hover-reveal` are the ONLY motion classes allowed',
                default   => [
                    'calm'      => 'soft fades and gentle settling',
                    'energetic' => 'quick vertical arrivals with a crisp settle',
                    'dramatic'  => 'long vertical masks and a cinematic hero focus pull',
                ][$motion] . ' — place motion classes sparingly, per their budget rules',
            };
            $note = self::formatMotionNote($direction['motion_note'] ?? null);
            $facts[] = "- **Motion**: {$motion} — {$meaning}." . ($note !== '' ? " Motion note: {$note}" : '');
        }

        $imageCrop = ImageCrop::explicit($direction['image_crop'] ?? null);
        if ($imageCrop !== null) {
            $facts[] = '- **Image crop**: ' . $imageCrop . ' — ' . match ($imageCrop) {
                'landscape' => 'the build makes ordinary cards 3:2, dominant cards and thumbs 4:3, and feature media 16:9',
                'portrait'  => 'the build makes ordinary cards and feature media 4:5, dominant cards 2:3, and thumbs 3:4',
                'square'    => 'the build makes every card, thumbnail, and feature-media crop 1:1',
                'panoramic' => 'the build makes ordinary cards and thumbs 16:9, dominant cards 3:2, and feature media 21:9',
                'mixed'     => 'the build keeps the established per-role system: ordinary cards 3:2, dominant cards 4:5, and thumbs 1:1',
            } . '. Full-bleed media remains wide; use the documented crop role classes and do not author an aspect ratio.';
        }

        $imageGrade = trim((string) ($direction['image_grade'] ?? ''));
        if ($imageGrade !== '') {
            $facts[] = "- **Image grade (all imagery)**: {$imageGrade}";
        }

        $imageTreatment = ImageTreatment::explicit($direction['image_treatment'] ?? null);
        if ($imageTreatment !== null) {
            $facts[] = '- **Image treatment**: ' . $imageTreatment . ' — ' . match ($imageTreatment) {
                'natural' => 'the build leaves delivered image pixels untreated',
                'duotone' => 'the build maps content-image shadows and highlights onto the delivered contrast/base palette pair',
                'tinted-overlay' => 'the build places one low-opacity primary-color tint above Cover and card media pixels but below their copy',
                'high-key-bw' => 'the build forces content imagery into a bright, low-contrast grayscale treatment',
            } . '. Do not author a local duotone, filter, blend mode, or image overlay.';
        }

        return $head . ($facts === [] ? '' : "\n\n" . implode("\n", $facts));
    }

    /** A motion note as one readable string, whether authored as a list or a line. */
    private static function describeNote(mixed $raw): string
    {
        if (is_array($raw)) {
            $parts = array_filter($raw, 'is_string');
            return implode(', ', array_map('trim', $parts));
        }
        return is_string($raw) ? trim($raw) : '';
    }

    /**
     * Prompt sentence for a persisted class list. A leftover string (hand-written
     * fixtures, predating this contract) is passed through unchanged.
     */
    private static function formatMotionNote(mixed $raw): string
    {
        if (is_array($raw)) {
            $classes = [];
            foreach ($raw as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $classes[] = trim($item);
                }
            }
            return Motion::formatNote($classes);
        }
        return is_string($raw) ? trim($raw) : '';
    }

    private static function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    /**
     * The committed design direction as a text block to inject into a downstream
     * prompt: the narrative plus the structured fields rendered as a fact list.
     * Returns a fallback that keeps the "be bold, avoid defaults" intent when no
     * direction was persisted, so steps run in isolation still work.
     */
    public static function readFor(Project $project): string
    {
        return $project->exists(self::FILE)
            ? self::format($project->readJson(self::FILE))
            : self::FALLBACK;
    }

    /**
     * Read the separately scoped, normalized front-page blueprint. This is a
     * required build artifact contract: silently inventing a default here
     * would let downstream units author a recipe that was never assigned.
     *
     * @return array<string,mixed>
     */
    public static function heroBlueprintFor(Project $project): array
    {
        if (!$project->exists(self::FILE)) {
            throw new \RuntimeException('designDirection.json is missing the required persisted hero blueprint');
        }
        $direction = $project->readJson(self::FILE);
        $blueprint = $direction['hero_blueprint'] ?? null;
        if (!is_array($blueprint)) {
            throw new \RuntimeException('designDirection.json has no structured hero_blueprint');
        }
        $recipe = trim((string) ($blueprint['recipe'] ?? ''));
        if (!in_array($recipe, HeroComposition::RECIPES, true)) {
            throw new \RuntimeException("designDirection.json has unknown hero_blueprint recipe '{$recipe}'");
        }
        $repairs = [];
        $warnings = [];
        $normalized = HeroBlueprint::normalize($blueprint, $recipe, $repairs, $warnings);
        if ($normalized !== $blueprint) {
            throw new \RuntimeException(
                'designDirection.json hero_blueprint is not normalized (the producing step must persist its fixed point)'
            );
        }
        return $blueprint;
    }

    /**
     * Render the focused blueprint for the few front-page-only consumers.
     * This intentionally does not call format()/readFor(), preventing the
     * recipe from leaking into ordinary section or footer prompts.
     *
     * @param array<string,mixed> $blueprint
     */
    public static function formatHeroBlueprint(array $blueprint): string
    {
        HeroBlueprint::recipe($blueprint);
        return "## Front-page hero blueprint (front page only)\n\n```json\n"
            . json_encode(
                $blueprint,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            )
            . "\n```";
    }

    public static function formatHeroBlueprintFor(Project $project): string
    {
        return self::formatHeroBlueprint(self::heroBlueprintFor($project));
    }

    public static function heroRecipeFor(Project $project): string
    {
        return HeroBlueprint::recipe(self::heroBlueprintFor($project));
    }

    /**
     * The whole committed direction, or none. The accessors below answer one
     * field each; callers needing a whole block read through this.
     *
     * The full graph cannot reach these callers without a direction —
     * StepGraph::validate() rejects a composition that drops the writer. The
     * tolerance is for steps run on their own, where the file is simply not
     * there: unit tests, and any host that runs a span of the graph rather
     * than the whole of it.
     *
     * @return array<mixed>
     */
    public static function dataFor(Project $project): array
    {
        return $project->exists(self::FILE) ? $project->readJson(self::FILE) : [];
    }

    /**
     * The authoritative site-wide card construction, including the documented
     * flush default. Adapter callers pass warnings through to their own durable
     * step boundary so an invalid non-empty persisted value is never hidden.
     *
     * @param list<string> $warnings
     */
    public static function cardStyleFor(Project $project, array &$warnings = []): string
    {
        $direction = self::dataFor($project);
        return self::normalizeCardStyle($direction['card_style'] ?? null, $warnings);
    }

    /**
     * The authoritative repeated-item idiom, with the card default for a
     * missing or pre-field direction. Callers persist any invalid-value
     * warning at their own step boundary.
     *
     * @param list<string> $warnings
     */
    public static function itemPatternFor(Project $project, array &$warnings = []): string
    {
        $direction = self::dataFor($project);
        return ItemPattern::normalize($direction['item_pattern'] ?? null, $warnings);
    }

    /**
     * The committed direction's image grade — the one-sentence photographic
     * treatment shared by ALL of the site's imagery, consumed verbatim by
     * GenerateImagesStep. Returns '' when no direction (or no grade) was
     * persisted: an absent grade just means the image prompts carry no grade
     * clause.
     */
    public static function imageGradeFor(Project $project): string
    {
        if (!$project->exists(self::FILE)) {
            return '';
        }
        return trim((string) ($project->readJson(self::FILE)['image_grade'] ?? ''));
    }

    /** Explicit committed render-time image treatment, or null for pre-field artifacts. */
    public static function imageTreatmentFor(Project $project): ?string
    {
        if (!$project->exists(self::FILE)) {
            return null;
        }
        return ImageTreatment::explicit($project->readJson(self::FILE)['image_treatment'] ?? null);
    }

    /** Explicit committed image crop, or null for a pre-field/garbled artifact. */
    public static function imageCropFor(Project $project): ?string
    {
        if (!$project->exists(self::FILE)) {
            return null;
        }
        return ImageCrop::explicit($project->readJson(self::FILE)['image_crop'] ?? null);
    }

    /**
     * The committed direction's canvas ("full-bleed" or "framed"), or '' when
     * no direction was persisted. A framed canvas keeps a mat of page
     * background around every band, so an overlay header can never float over
     * the hero image; AboveFoldContract resolves that relation once.
     */
    /**
     * The committed design tradition ('' for a pre-field artifact or a
     * degraded seed). Read by the above-fold header pool.
     */
    public static function registerFor(Project $project): string
    {
        if (!$project->exists(self::FILE)) {
            return '';
        }
        return BoundedChoice::explicit(
            $project->readJson(self::FILE)['register'] ?? null,
            ConceptSeeds::knownRegisters(),
        ) ?? '';
    }

    public static function canvasFor(Project $project): string
    {
        if (!$project->exists(self::FILE)) {
            return '';
        }
        return strtolower(trim((string) ($project->readJson(self::FILE)['canvas'] ?? '')));
    }

    /** The explicit content/wide layout commitment, or null for a pre-field artifact. */
    public static function measureFor(Project $project): ?string
    {
        if (!$project->exists(self::FILE)) {
            return null;
        }
        return Measure::explicit($project->readJson(self::FILE)['measure'] ?? null);
    }

    /**
     * The committed motion profile, gating the motion-sanity strip and the
     * finalize-theme kit wiring. Fails closed: no direction, or one that
     * predates/garbled the field, means `none` — a step run in isolation must
     * not surprise-animate a site whose direction never committed to motion.
     */
    public static function motionProfileFor(Project $project): string
    {
        if (!$project->exists(self::FILE)) {
            return 'none';
        }
        $motion = strtolower(trim((string) ($project->readJson(self::FILE)['motion'] ?? '')));
        return in_array($motion, Motion::PROFILES, true) ? $motion : 'none';
    }

    /**
     * The explicit committed corner language ("sharp", "soft" or "round"),
     * or null when no direction was persisted or its shape field is absent or
     * garbled. The producing step normalizes every generated direction onto a
     * valid value; null lets isolated downstream steps preserve pre-field
     * artifacts instead of silently rewriting them as sharp.
     */
    public static function shapeFor(Project $project): ?string
    {
        if (!$project->exists(self::FILE)) {
            return null;
        }
        return self::explicitShape($project->readJson(self::FILE)['shape'] ?? null);
    }

    /** Explicit committed depth, or null for a pre-field/garbled artifact. */
    public static function depthFor(Project $project): ?string
    {
        if (!$project->exists(self::FILE)) {
            return null;
        }
        return Depth::explicit($project->readJson(self::FILE)['depth'] ?? null);
    }

    /**
     * The committed band rhythm in one direction's data, or null when the
     * field is absent or not a committed value. This is the one reader of
     * the persisted field; the gate and the prompts both go through it.
     *
     * @param array<mixed> $direction
     */
    public static function explicitRhythm(array $direction): ?string
    {
        return BoundedChoice::explicit($direction['rhythm'] ?? null, self::RHYTHMS);
    }

    /**
     * The persisted band rhythm, or the write-side default when the file is
     * absent or the value is not committed. A prompt that states the stagger
     * rule names this value, so the prompt and the build agree.
     */
    public static function rhythmFor(Project $project): string
    {
        return self::explicitRhythm(self::dataFor($project)) ?? self::DEFAULT_RHYTHM;
    }

    /** The persisted page density, measured when absent or not a committed value. */
    public static function densityFor(Project $project): string
    {
        if (!$project->exists(self::FILE)) {
            return 'measured';
        }
        return BoundedChoice::explicit(
            $project->readJson(self::FILE)['density'] ?? null,
            self::DENSITIES,
        ) ?? 'measured';
    }

    /** The palette's hue budget; missing commitments use the restrained default. */
    public static function colorEconomyFor(Project $project): string
    {
        if (!$project->exists(self::FILE)) {
            return ColorEconomy::DEFAULT;
        }
        return ColorEconomy::explicit(
            $project->readJson(self::FILE)['color_economy'] ?? null,
        ) ?? ColorEconomy::DEFAULT;
    }

    /** The explicit modular type-scale commitment, or null when absent/garbled. */
    public static function typeScaleFor(Project $project): ?string
    {
        if (!$project->exists(self::FILE)) {
            return null;
        }
        return TypeScale::explicit($project->readJson(self::FILE)['type_scale'] ?? null);
    }

    /** The explicit site-wide CTA construction, or null for a pre-field artifact. */
    public static function ctaStyleFor(Project $project): ?string
    {
        if (!$project->exists(self::FILE)) {
            return null;
        }
        return CtaStyle::explicit($project->readJson(self::FILE)['cta_style'] ?? null);
    }

    /** The explicit heading case/tracking commitment, or null for a pre-field artifact. */
    public static function typeTreatmentFor(Project $project): ?string
    {
        if (!$project->exists(self::FILE)) {
            return null;
        }
        return TypeTreatment::explicit($project->readJson(self::FILE)['type_treatment'] ?? null);
    }

    /**
     * The committed page surface, or `none` when no direction was persisted
     * or the field is absent.
     */
    /** The committed heading emphasis, or `none`. */
    public static function headingEmphasisFor(Project $project): string
    {
        if (!$project->exists(self::FILE)) {
            return HeadingEmphasis::DEFAULT;
        }
        return self::normalizeHeadingEmphasis($project->readJson(self::FILE)['heading_emphasis'] ?? null);
    }

    public static function surfaceFor(Project $project): string
    {
        if (!$project->exists(self::FILE)) {
            return Surface::DEFAULT;
        }
        return self::normalizeSurface($project->readJson(self::FILE)['surface'] ?? null);
    }

    /** The committed one-band CSS device, or `none`. */
    public static function deviceFor(Project $project): string
    {
        if (!$project->exists(self::FILE)) {
            return Device::DEFAULT;
        }
        return self::normalizeDevice($project->readJson(self::FILE)['device'] ?? null);
    }

    /** Parse only an explicit valid corner-language commitment. */
    private static function explicitShape(mixed $raw): ?string
    {
        return BoundedChoice::explicit($raw, self::SHAPES);
    }


    /** Coerce a raw motion value onto the fixed profile list. */
    private static function motionProfile(mixed $raw): string
    {
        $motion = strtolower(trim((string) (is_string($raw) ? $raw : '')));
        return in_array($motion, Motion::PROFILES, true) ? $motion : Motion::DEFAULT_PROFILE;
    }

    private static function describe(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $encoded === false ? get_debug_type($value) : $encoded;
    }
}
