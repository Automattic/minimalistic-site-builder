<?php
declare(strict_types=1);

/**
 * Step (LLM): commit to ONE distinctive creative concept for the site BEFORE any
 * theme, palette, or layout is chosen.
 *
 * Input:  meta.json (the user prompt) + siteSpec.json (factual info).
 * Output: designDirection.md — a short freeform design brief (markdown).
 *
 * The model is asked for FOUR distinct visual directions in one call; this step
 * then picks ONE of them at random. Generating a spread and sampling from it —
 * rather than asking for a single "best" concept — is the deliberate injection of
 * variety: it widens the creative range and stops repeated builds of the same
 * brief from converging on the model's one safe favourite.
 *
 * This is the single source of design intent. The theme-json, section-plan and
 * section steps all read it (via DesignDirectionStep::readFor) and let it drive
 * their choices, so two sites diverge in concept — not just in hex values.
 *
 * Only the chosen direction is persisted, as freeform prose: nothing downstream
 * parses it — it's produced by a model and consumed by a model (spliced into the
 * design prompts), so structure would only add escaping friction downstream.
 */
final class DesignDirectionStep implements Step
{
    use ModelOption;

    /**
     * Injected into downstream prompts when no designDirection.md exists yet
     * (a step run in isolation). Keeps the creative intent alive instead of
     * silently reverting to defaults.
     */
    private const FALLBACK = '(No explicit design direction was provided. Make bold, '
        . 'specific, non-generic design choices that fit the brand, and consciously avoid '
        . 'default treatments like a centered hero, all-sans-serif typography, and a '
        . 'blue/teal palette.)';

    /** Where the brief is written, and read back from by readFor(). */
    private const FILE = 'designDirection.md';

    public function __construct(
        private Llm $llm,
        private PromptRenderer $renderer,
        private ?string $model = null,
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

        $rendered = $this->renderer->render('design-direction.md', [
            'user_prompt' => $prompt,
            'site_spec'   => $project->readText('siteSpec.json'),
        ]);
        $payload = $this->llm->completeJson($rendered, $this->withModel(['log_label' => $this->id()]));

        // Keep only well-formed directions (a description is what downstream needs;
        // the title is a nice-to-have label).
        $directions = array_values(array_filter(
            is_array($payload['directions'] ?? null) ? $payload['directions'] : [],
            static fn ($d): bool => is_array($d) && trim((string) ($d['description'] ?? '')) !== '',
        ));
        if ($directions === []) {
            throw new RuntimeException('design-direction: model returned no usable directions');
        }

        // Sample ONE direction at random — the deliberate variety injection.
        $chosen = $directions[random_int(0, count($directions) - 1)];

        $project->writeText(self::FILE, self::format($chosen) . "\n");
    }

    /**
     * Render one chosen direction as the freeform markdown brief downstream reads.
     *
     * @param array<string,mixed> $direction
     */
    private static function format(array $direction): string
    {
        $title = trim((string) ($direction['title'] ?? ''));
        $description = trim((string) $direction['description']);
        return $title === '' ? $description : "# {$title}\n\n{$description}";
    }

    /**
     * The committed design direction as a text block to inject into a downstream
     * prompt. Returns the brief verbatim when present, else a fallback that keeps
     * the "be bold, avoid defaults" intent so steps run in isolation still work.
     */
    public static function readFor(Project $project): string
    {
        return $project->exists(self::FILE)
            ? trim($project->readText(self::FILE))
            : self::FALLBACK;
    }
}
