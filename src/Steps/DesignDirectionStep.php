<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Env;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\Motion;
use Automattic\SiteBuild\LlmOptions;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

/**
 * Step (LLM): commit to ONE distinctive creative concept for the site BEFORE any
 * theme, palette, or layout is chosen.
 *
 * Input:  meta.json (the user prompt) + siteSpec.json (factual info).
 * Output: designDirection.json — the chosen direction as structured data:
 *         title + vivid description plus the explicit fields downstream steps
 *         execute instead of re-interpreting (palette hexes, type pairing,
 *         image grade, signature device, hero composition).
 *
 * Two calls. First, a cheap seed call (small model, hot sampling) brainstorms
 * FOUR concept seeds — each one string: an evocative title plus one vivid
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

    /** Palette roles a direction commits to — the same slugs theme.json requires. */
    public const PALETTE_ROLES = ['base', 'contrast', 'primary', 'secondary', 'accent'];

    /** Env var forcing seed N (1-based) — the reproducible-evals escape hatch. */
    public const CHOICE_ENV = 'DESIGN_DIRECTION_CHOICE';

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
        $spec = $project->readText('siteSpec.json');

        $seed = $this->chooseSeed($prompt, $spec);

        $rendered = $this->renderer->render('design-direction.md', [
            'user_prompt' => $prompt,
            'site_spec'   => $spec,
            'seed'        => $seed,
        ]);
        $payload = $this->llm->completeJson($rendered, $this->withOptions(['log_label' => $this->id()]));

        $direction = self::normalize($payload['direction'] ?? null);
        if ($direction === null) {
            // A build without a committed direction still works — every
            // downstream step tolerates empty fields — so deliver the
            // deterministic fallback (built on the chosen seed when one
            // exists) rather than abort. Recorded durably: the site loses
            // the concept-variety this step exists to inject.
            $direction = self::fallbackDirection($seed);
            $project->addWarnings($this->id(), [
                'model returned no usable design direction; deterministic fallback direction delivered',
            ]);
            echo "  [design-direction] warning: no usable direction from the model; fallback delivered (recorded in warnings.json)\n";
        }

        $project->writeJson(self::FILE, $direction);
    }

    /**
     * The deterministic direction delivered when the model's payload is
     * unusable: the chosen seed's one-sentence commitment as the narrative
     * (or the generic "be bold" brief when even seeding failed), no palette
     * or type commitments (downstream steps then decide inline), full-bleed
     * canvas, default motion profile. Pure — unit-testable.
     *
     * @return array{title:string,description:string,palette:array<string,string>,type:array{heading:string,body:string},image_grade:string,canvas:string,motion:string,motion_note:string,signature_device:string,hero_composition:string}
     */
    public static function fallbackDirection(string $seed = ''): array
    {
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
            'type'             => ['heading' => '', 'body' => ''],
            'image_grade'      => '',
            'canvas'           => 'full-bleed',
            'motion'           => Motion::DEFAULT_PROFILE,
            'motion_note'      => '',
            'signature_device' => '',
            'hero_composition' => '',
        ];
    }

    /**
     * Brainstorm four concept titles on the cheap model and pick ONE, rendered
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
     * Validate and coerce the raw direction into the persisted structure.
     * Returns null when it is unusable (no description). Everything else
     * degrades gracefully: invalid palette hexes and unknown roles are
     * dropped, missing fields become empty strings — downstream renders only
     * what is present. Pure — unit-testable.
     *
     * @param mixed $raw
     * @return ?array{title:string,description:string,palette:array<string,string>,type:array{heading:string,body:string},image_grade:string,canvas:string,motion:string,motion_note:string,signature_device:string,hero_composition:string}
     */
    public static function normalize($raw): ?array
    {
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
            }
        }

        $type = is_array($raw['type'] ?? null) ? $raw['type'] : [];

        return [
            'title'            => trim((string) ($raw['title'] ?? '')),
            'description'      => $description,
            'palette'          => $palette,
            'type'             => [
                'heading' => trim((string) ($type['heading'] ?? '')),
                'body'    => trim((string) ($type['body'] ?? '')),
            ],
            'image_grade'      => trim((string) ($raw['image_grade'] ?? '')),
            // Anything that isn't an explicit "framed" commitment is full-bleed:
            // an accidental frame reads as a rendering bug, not a design choice.
            'canvas'           => strtolower(trim((string) ($raw['canvas'] ?? ''))) === 'framed' ? 'framed' : 'full-bleed',
            // The motion profile is a fixed list (the kit ships exactly these);
            // anything unrecognized falls back to the default so every build
            // commits to ONE profile the downstream steps can gate on.
            'motion'           => self::motionProfile($raw['motion'] ?? null),
            'motion_note'      => trim((string) ($raw['motion_note'] ?? '')),
            'signature_device' => trim((string) ($raw['signature_device'] ?? '')),
            'hero_composition' => trim((string) ($raw['hero_composition'] ?? '')),
        ];
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
            $family = trim((string) ($type[$slot] ?? ''));
            if ($family !== '') {
                $pair[] = "{$slot} — {$family}";
            }
        }
        if ($pair !== []) {
            $facts[] = '- **Type**: ' . implode('; ', $pair);
        }

        // Render the canvas commitment with its executable meaning, so the
        // section/header prompts act on it instead of re-interpreting a bare
        // keyword. Directions persisted before the field existed carry none.
        $canvas = trim((string) ($direction['canvas'] ?? ''));
        if ($canvas === 'framed') {
            $facts[] = '- **Canvas**: framed — the page keeps a visible mat of page background around every band; cap bands at `"align":"wide"`, never `"align":"full"`.';
        } elseif ($canvas !== '') {
            $facts[] = '- **Canvas**: full-bleed — heroes, image bands and color bands may run edge-to-edge with `"align":"full"`.';
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
            'hero_composition' => 'Hero composition',
            'image_grade'      => 'Image grade (all imagery)',
        ] as $key => $label) {
            $value = trim((string) ($direction[$key] ?? ''));
            if ($value !== '') {
                $facts[] = "- **{$label}**: {$value}";
            }
        }

        return $head . ($facts === [] ? '' : "\n\n" . implode("\n", $facts));
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
     * the hero image — SectionsStep gates the header archetype pool on this.
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

    /** Coerce a raw motion value onto the fixed profile list. */
    private static function motionProfile(mixed $raw): string
    {
        $motion = strtolower(trim((string) (is_string($raw) ? $raw : '')));
        return in_array($motion, Motion::PROFILES, true) ? $motion : Motion::DEFAULT_PROFILE;
    }
}
