<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\AboveFoldContract;
use Automattic\SiteBuild\Env;
use Automattic\SiteBuild\GeneratedJsonException;
use Automattic\SiteBuild\HeroBlueprint;
use Automattic\SiteBuild\HeroComposition;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\Motion;
use Automattic\SiteBuild\LlmOptions;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\Warnings;

/**
 * Step (LLM): commit to ONE distinctive creative concept for the site BEFORE any
 * theme, palette, or layout is chosen.
 *
 * Input:  meta.json (the user prompt) + siteSpec.json (factual info).
 * Output: designDirection.json — the chosen direction as structured data:
 *         title + vivid description plus the explicit fields downstream steps
 *         execute instead of re-interpreting (palette hexes, type pairing,
 *         image grade, signature device placement, and a separately consumed
 *         structured front-page hero blueprint).
 *
 * Two calls. First, a cheap seed call (small model, hot sampling) brainstorms
 * THREE concept seeds — each one string: an evocative title plus one vivid
 * sentence committing the seed's visual world (palette family, typography
 * character, imagery treatment, mood) — with divergence across the set
 * enforced in the prompt. One seed is picked uniformly at random, then the
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
        . 'default treatments like a centered hero, all-sans-serif typography, and a '
        . 'blue/teal palette.)';

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
    public const PALETTE_ROLES = ['base', 'contrast', 'primary', 'secondary', 'accent'];

    /** Env var forcing seed N (1-based) — the reproducible-evals escape hatch. */
    public const CHOICE_ENV = 'DESIGN_DIRECTION_CHOICE';

    /** Exact operator/evaluation override for the code-owned hero catalog. */
    public const HERO_RECIPE_ENV = 'HERO_RECIPE';

    /** Slots in which the one global signature device may appear. */
    public const SIGNATURE_DEVICE_SLOTS = ['header', 'hero', 'body', 'closing', 'footer'];

    /**
     * Card constructions a direction may commit to. The section prompt's card
     * anatomy documents one markup recipe per value; `flush` is the default so
     * the dated inset-media card only appears when a direction opts into it.
     */
    public const CARD_STYLES = ['flush', 'framed', 'overlap', 'borderless'];

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

        $seed = $this->chooseSeed($prompt, $spec);
        $warnings = [];
        $recipe = self::selectHeroRecipe(
            $meta,
            (string) ($specData['slug'] ?? $project->slug()),
            $seed,
            $warnings,
        );
        $blueprintDefaults = HeroBlueprint::defaultFor($recipe, $constraints);
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
                . 'avoid default treatments like a centered hero, all-sans-serif typography, and a '
                . 'blue/teal palette.';
        return [
            'title'            => '',
            'description'      => $description,
            'palette'          => [],
            'type'             => [
                'heading' => self::emptyTypeSlot(),
                'body'    => self::emptyTypeSlot(),
            ],
            'image_grade'      => '',
            'canvas'           => $canvas,
            'card_style'       => 'flush',
            'motion'           => Motion::DEFAULT_PROFILE,
            'motion_note'      => '',
            'signature_device' => '',
            'signature_device_slots' => [],
            'concept_seed'     => $seed,
            'hero_blueprint'   => HeroBlueprint::defaultFor($recipe),
        ];
    }

    /**
     * Brainstorm three concept titles on the cheap model and pick ONE, rendered
     * as the text block the expansion prompt consumes.
     *
     * Precedence: the DESIGN_DIRECTION_CHOICE env var forces seed N (1-based;
     * out of range — including a failed seed call — fails loud, because a
     * forced eval must not silently drift); otherwise a uniform random pick.
     * Without a forced choice, any seed failure (transport error, no usable
     * seeds) degrades to SEED_FALLBACK — seeding must never abort a build.
     * The step's hot temperature is applied here too: the seed spread is now
     * the pipeline's variety source, and the small models still support
     * sampling.
     */
    private function chooseSeed(string $brief, string $spec): string
    {
        $forced = Env::get(self::CHOICE_ENV);
        $isForced = $forced !== null && $forced !== '';

        $seeds = [];
        try {
            $rendered = $this->renderer->render('design-direction-seeds.md', [
                'user_prompt' => $brief,
                'site_spec'   => $spec,
            ]);
            $opts = ['log_label' => 'design-direction-seeds'];
            if ($this->seedModel !== null) {
                $opts['model'] = $this->seedModel;
            }
            if ($this->temperature !== null) {
                $opts['temperature'] = $this->temperature;
            }
            $payload = $this->llm->completeJson($rendered, $opts);
            foreach (is_array($payload['seeds'] ?? null) ? $payload['seeds'] : [] as $raw) {
                $seed = self::normalizeSeed($raw);
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
            return $seeds[$n - 1];
        }

        if ($seeds === []) {
            return self::SEED_FALLBACK;
        }
        return $seeds[random_int(0, count($seeds) - 1)];
    }

    /**
     * Validate and coerce one raw seed ("Title — one vivid sentence"). The
     * prompt asks for bare strings; an object carrying a `title` key is
     * tolerated. Returns null when nothing non-empty is present. Pure —
     * unit-testable.
     *
     * @param mixed $raw
     */
    public static function normalizeSeed($raw): ?string
    {
        if (is_array($raw)) {
            $raw = $raw['title'] ?? null;
        }
        if (!is_string($raw)) {
            return null;
        }
        $seed = trim($raw);
        return $seed === '' ? null : $seed;
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

        $type = is_array($raw['type'] ?? null) ? $raw['type'] : [];

        HeroComposition::assertKnown($assignedRecipe);

        $signatureDevice = trim((string) ($raw['signature_device'] ?? ''));
        $signatureSlots = self::normalizeSignatureDeviceSlots(
            $raw['signature_device_slots'] ?? null,
            $signatureDevice,
            $repairs,
            $warnings,
        );
        $blueprint = HeroBlueprint::normalize(
            $raw['hero_blueprint'] ?? null,
            $assignedRecipe,
            [
                'signature_device' => $signatureDevice,
                'signature_device_slots' => $signatureSlots,
            ],
            $repairs,
            $warnings,
        );

        $canvasRaw = strtolower(trim((string) ($raw['canvas'] ?? '')));
        $canvas = $canvasRaw === 'framed' ? 'framed' : 'full-bleed';
        if ($canvasRaw !== '' && $canvasRaw !== $canvas) {
            $repairs[] = 'designDirection.json: field canvas authored '
                . self::describe($raw['canvas']) . ' delivered "full-bleed"; disposition repaired invalid value';
        }

        $cardRaw = strtolower(trim((string) ($raw['card_style'] ?? '')));
        $cardStyle = in_array($cardRaw, self::CARD_STYLES, true) ? $cardRaw : 'flush';
        if ($cardRaw !== '' && $cardRaw !== $cardStyle) {
            $repairs[] = 'designDirection.json: field card_style authored '
                . self::describe($raw['card_style']) . ' delivered "flush"; disposition repaired invalid value';
        }

        $motion = self::motionProfile($raw['motion'] ?? null);
        $rawMotion = is_string($raw['motion'] ?? null)
            ? strtolower(trim($raw['motion']))
            : '';
        if ($rawMotion !== '' && $rawMotion !== $motion) {
            $repairs[] = 'designDirection.json: field motion authored '
                . self::describe($raw['motion']) . ' delivered ' . self::describe($motion)
                . '; disposition repaired invalid profile';
        }

        return [
            'title'            => trim((string) ($raw['title'] ?? '')),
            'description'      => $description,
            'palette'          => $palette,
            'type'             => [
                'heading' => self::normalizeTypeSlot($type['heading'] ?? null, 'heading', $warnings),
                'body'    => self::normalizeTypeSlot($type['body'] ?? null, 'body', $warnings),
            ],
            'image_grade'      => trim((string) ($raw['image_grade'] ?? '')),
            // Anything that isn't an explicit "framed" commitment is full-bleed:
            // an accidental frame reads as a rendering bug, not a design choice.
            'canvas'           => $canvas,
            // Anything outside the bounded card constructions delivers the
            // flush default — inset media must be an explicit opt-in, never
            // the accidental look every site gets.
            'card_style'       => $cardStyle,
            // The motion profile is a fixed list (the kit ships exactly these);
            // anything unrecognized falls back to the default so every build
            // commits to ONE profile the downstream steps can gate on.
            'motion'           => $motion,
            'motion_note'      => trim((string) ($raw['motion_note'] ?? '')),
            'signature_device' => $signatureDevice,
            'signature_device_slots' => $signatureSlots,
            'concept_seed'     => $conceptSeed,
            'hero_blueprint'   => $blueprint,
        ];
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

        $type = is_array($direction['type'] ?? null) ? $direction['type'] : [];
        $pair = [];
        foreach (['heading', 'body'] as $slot) {
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

        // Render the canvas commitment with its executable meaning, so the
        // section/header prompts act on it instead of re-interpreting a bare
        // keyword. Directions persisted before the field existed carry none.
        $canvas = trim((string) ($direction['canvas'] ?? ''));
        if ($canvas === 'framed') {
            $facts[] = '- **Canvas**: framed — the page keeps a visible mat of page background around every band BELOW the hero; cap those bands at `"align":"wide"`, never `"align":"full"`. The page-opening hero is exempt: it always runs edge-to-edge with `"align":"full"`, and the mat begins with the following section.';
        } elseif ($canvas !== '') {
            $facts[] = '- **Canvas**: full-bleed — heroes, image bands and color bands may run edge-to-edge with `"align":"full"`.';
        }

        // Render the card commitment with its executable meaning: the section
        // prompt's card anatomy executes exactly the named construction, and
        // defaults to flush when a direction predates the field.
        $cardStyle = strtolower(trim((string) ($direction['card_style'] ?? '')));
        if (in_array($cardStyle, self::CARD_STYLES, true)) {
            $meaning = match ($cardStyle) {
                'flush'      => 'card media bleeds to the card edges and padding wraps only the text — use the `flush` construction from the card anatomy',
                'framed'     => 'card media sits inset behind padding on all sides — use the `framed` construction from the card anatomy, with concentric corner radii',
                'overlap'    => 'the text panel rides up over the media\'s bottom edge — use the `overlap` construction from the card anatomy',
                'borderless' => 'cards have no box at all; media above a plain text stack — use the `borderless` construction from the card anatomy',
            };
            $facts[] = "- **Card treatment**: {$cardStyle} — {$meaning}.";
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
                    'energetic' => 'quick diagonal arrivals with spring overshoot',
                    'dramatic'  => 'long directional masks and a cinematic hero focus pull',
                ][$motion] . ' — place motion classes sparingly, per their budget rules',
            };
            $note = trim((string) ($direction['motion_note'] ?? ''));
            $facts[] = "- **Motion**: {$motion} — {$meaning}." . ($note !== '' ? " Motion note: {$note}" : '');
        }

        foreach ([
            'signature_device' => 'Signature device',
            'image_grade'      => 'Image grade (all imagery)',
        ] as $key => $label) {
            $value = trim((string) ($direction[$key] ?? ''));
            if ($value !== '') {
                $facts[] = "- **{$label}**: {$value}";
            }
        }

        $signatureDevice = trim((string) ($direction['signature_device'] ?? ''));
        if ($signatureDevice !== '') {
            $slots = is_array($direction['signature_device_slots'] ?? null)
                ? array_values(array_map('strval', $direction['signature_device_slots']))
                : [];
            $facts[] = '- **Signature device placement slots**: '
                . ($slots === [] ? 'none — do not place the device' : implode(', ', $slots));
        }

        return $head . ($facts === [] ? '' : "\n\n" . implode("\n", $facts));
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
        $normalized = HeroBlueprint::normalize($blueprint, $recipe, [
            'signature_device' => $direction['signature_device'] ?? '',
            'signature_device_slots' => $direction['signature_device_slots'] ?? [],
        ], $repairs, $warnings);
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

    /**
     * The committed direction's canvas ("full-bleed" or "framed"), or '' when
     * no direction was persisted. A framed canvas keeps a mat of page
     * background around every band, so an overlay header can never float over
     * the hero image; AboveFoldContract resolves that relation once.
     */
    public static function canvasFor(Project $project): string
    {
        if (!$project->exists(self::FILE)) {
            return '';
        }
        return strtolower(trim((string) ($project->readJson(self::FILE)['canvas'] ?? '')));
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
     * Normalize the explicit placement budget without parsing motif prose.
     * Any invalid list for a real device degrades as a whole to [] so a
     * partially trusted list cannot accidentally duplicate the global motif.
     *
     * @param mixed        $raw
     * @param list<string> $repairs
     * @param list<string> $warnings
     * @return list<string>
     */
    private static function normalizeSignatureDeviceSlots(
        mixed $raw,
        string $signatureDevice,
        array &$repairs,
        array &$warnings,
    ): array {
        if ($signatureDevice === '') {
            if ($raw !== null && $raw !== []) {
                $repairs[] = 'designDirection.json: field signature_device_slots authored '
                    . self::describe($raw)
                    . ' delivered []; disposition cleared because signature_device is empty';
            }
            return [];
        }

        if (!is_array($raw) || !array_is_list($raw)) {
            $warnings[] = 'designDirection.json: field signature_device_slots authored '
                . self::describe($raw)
                . ' delivered []; disposition removed invalid/missing placement budget without inferring from prose';
            return [];
        }

        $slots = [];
        $valid = count($raw) <= 2;
        foreach ($raw as $slot) {
            if (!is_string($slot)) {
                $valid = false;
                continue;
            }
            $normalized = strtolower(trim($slot));
            if (!in_array($normalized, self::SIGNATURE_DEVICE_SLOTS, true)
                || in_array($normalized, $slots, true)) {
                $valid = false;
                continue;
            }
            $slots[] = $normalized;
            if ($slot !== $normalized) {
                $repairs[] = 'designDirection.json: field signature_device_slots authored '
                    . self::describe($slot) . ' delivered ' . self::describe($normalized)
                    . '; disposition canonicalized';
            }
        }

        if (!$valid) {
            $warnings[] = 'designDirection.json: field signature_device_slots authored '
                . self::describe($raw)
                . ' delivered []; disposition removed invalid placement budget without inferring from prose';
            return [];
        }
        return $slots;
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
