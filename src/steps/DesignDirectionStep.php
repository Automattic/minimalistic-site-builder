<?php
declare(strict_types=1);

/**
 * Step (LLM): commit to ONE distinctive creative concept for the site BEFORE any
 * theme, palette, or layout is chosen.
 *
 * Input:  meta.json (the user prompt) + siteSpec.json (factual info) + the
 *         cross-build direction history (projects/.direction-history.json).
 * Output: designDirection.json — the chosen direction as structured data:
 *         title + vivid description plus the explicit fields downstream steps
 *         execute instead of re-interpreting (palette hexes, type pairing,
 *         image grade, signature device, hero composition).
 *
 * The model is asked for FOUR distinct visual directions in one call (at a hot
 * sampling temperature, with the recently used directions injected as "do NOT
 * repeat these"); a cheap judge call then picks the candidate that best fits
 * the brief AND is most distinct from that history. Generating a spread and
 * selecting from it — rather than asking for a single "best" concept — is the
 * deliberate injection of variety: it widens the creative range and stops
 * repeated builds of the same brief from converging on the model's one safe
 * favourite. If the judge fails, selection falls back to a uniform random
 * pick, and the DESIGN_DIRECTION_CHOICE env var forces candidate N (1-based)
 * for reproducible evals.
 *
 * This is the single source of design intent. The theme-json, section-plan and
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

    /** Where the chosen direction is persisted, and read back from by readFor(). */
    private const FILE = 'designDirection.json';

    /** Palette roles a direction commits to — the same slugs theme.json requires. */
    public const PALETTE_ROLES = ['base', 'contrast', 'primary', 'secondary', 'accent'];

    /** Recent history entries injected into the generation + judge prompts. */
    private const HISTORY_PROMPT_LIMIT = 8;

    /** Env var forcing candidate N (1-based) — the reproducible-evals escape hatch. */
    public const CHOICE_ENV = 'DESIGN_DIRECTION_CHOICE';

    public function __construct(
        private Llm $llm,
        private PromptRenderer $renderer,
        private ?string $model = null,
        private ?float $temperature = null,
        private ?string $judgeModel = null,
    ) {}

    public function id(): string
    {
        return 'design-direction';
    }

    public function label(): string
    {
        return 'Choose design direction';
    }

    public function run(Project $project): void
    {
        $meta = $project->readJson('meta.json');
        $prompt = (string) ($meta['prompt'] ?? '');
        if (trim($prompt) === '') {
            throw new RuntimeException('meta.json has no "prompt"');
        }

        $history = DirectionHistory::forProject($project);
        $recent = DirectionHistory::renderForPrompt($history->recent(self::HISTORY_PROMPT_LIMIT));

        $rendered = $this->renderer->render('design-direction.md', [
            'user_prompt'       => $prompt,
            'site_spec'         => $project->readText('siteSpec.json'),
            'recent_directions' => $recent,
        ]);
        $payload = $this->llm->completeJson($rendered, $this->withOptions(['log_label' => $this->id()]));

        // Keep only well-formed directions (a description is what downstream
        // needs; everything else degrades gracefully — see normalize()).
        $directions = [];
        foreach (is_array($payload['directions'] ?? null) ? $payload['directions'] : [] as $raw) {
            $direction = self::normalize($raw);
            if ($direction !== null) {
                $directions[] = $direction;
            }
        }
        if ($directions === []) {
            throw new RuntimeException('design-direction: model returned no usable directions');
        }

        $chosen = $this->choose($prompt, $directions, $recent);

        $project->writeJson(self::FILE, $chosen);
        $history->append($chosen);
    }

    /**
     * Pick ONE direction from the candidates.
     *
     * Precedence: the DESIGN_DIRECTION_CHOICE env var forces candidate N
     * (1-based; out of range fails loud — a forced eval must not silently
     * drift); a single candidate is taken as-is; otherwise a cheap judge call
     * scores fit-to-brief and distinctiveness-from-history and picks. Any
     * judge failure (transport error, malformed verdict) falls back to a
     * uniform random pick — selection must never abort a build.
     *
     * @param array<int,array<string,mixed>> $directions
     * @return array<string,mixed>
     */
    private function choose(string $brief, array $directions, string $recentDirections): array
    {
        $forced = Env::get(self::CHOICE_ENV);
        if ($forced !== null && $forced !== '') {
            $n = (int) $forced;
            if ($n < 1 || $n > count($directions)) {
                throw new RuntimeException(sprintf(
                    'design-direction: %s=%s is out of range (1..%d)',
                    self::CHOICE_ENV,
                    $forced,
                    count($directions),
                ));
            }
            return $directions[$n - 1];
        }

        if (count($directions) === 1) {
            return $directions[0];
        }

        try {
            $judgePrompt = $this->renderer->render('direction-judge.md', [
                'user_prompt'       => $brief,
                'recent_directions' => $recentDirections,
                'candidates'        => self::renderCandidates($directions),
            ]);
            $opts = ['log_label' => 'direction-judge'];
            if ($this->judgeModel !== null) {
                $opts['model'] = $this->judgeModel;
            }
            $verdict = $this->llm->completeJson($judgePrompt, $opts);
            $n = (int) ($verdict['choice'] ?? 0);
            if ($n >= 1 && $n <= count($directions)) {
                return $directions[$n - 1];
            }
        } catch (Throwable) {
            // Fall through to the random pick below.
        }

        return $directions[random_int(0, count($directions) - 1)];
    }

    /**
     * Validate and coerce one raw candidate into the persisted structure.
     * Returns null when the candidate is unusable (no description). Everything
     * else degrades gracefully: invalid palette hexes and unknown roles are
     * dropped, missing fields become empty strings — downstream renders only
     * what is present. Pure — unit-testable.
     *
     * @param mixed $raw
     * @return ?array{title:string,description:string,palette:array<string,string>,type:array{heading:string,body:string},image_grade:string,signature_device:string,hero_composition:string}
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
     * Render the candidate list for the judge prompt: numbered, each with its
     * full narrative + fact block, so the judge compares whole concepts. Pure
     * — unit-testable.
     *
     * @param array<int,array<string,mixed>> $directions
     */
    public static function renderCandidates(array $directions): string
    {
        $blocks = [];
        foreach ($directions as $i => $direction) {
            $blocks[] = '### Candidate ' . ($i + 1) . "\n\n" . self::format($direction);
        }
        return implode("\n\n", $blocks);
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
}
