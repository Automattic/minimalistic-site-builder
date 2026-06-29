<?php
declare(strict_types=1);

/**
 * Step (LLM): commit to ONE distinctive creative concept for the site BEFORE any
 * theme, palette, or layout is chosen.
 *
 * Input:  meta.json (the user prompt) + siteSpec.json (factual info).
 * Output: designDirection.md — a short freeform design brief (markdown).
 *
 * This is the single source of design intent. The theme-json, section-plan and
 * section steps all read it (via DesignDirectionStep::readFor) and let it drive
 * their choices, so two sites diverge in concept — not just in hex values. It is
 * the deliberate counter to designs converging on safe, generic defaults.
 *
 * The brief is freeform prose, not JSON: nothing downstream parses it — it's
 * produced by a model and consumed by a model (spliced into the design prompts),
 * so structure would only add escaping friction without buying anything.
 */
final class DesignDirectionStep implements Step
{
    /**
     * Injected into downstream prompts when no designDirection.md exists yet
     * (a step run in isolation, or older projects). Keeps the creative intent
     * alive instead of silently reverting to defaults.
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
        $brief = trim($this->llm->complete($rendered, $this->llmOpts(['log_label' => $this->id()])));
        if ($brief === '') {
            throw new RuntimeException('design-direction: model returned an empty brief');
        }

        $project->writeText(self::FILE, $brief . "\n");
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

    /**
     * Merge the per-step model override into a set of LLM options. Shared shape
     * across every LLM step.
     *
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    private function llmOpts(array $opts = []): array
    {
        if ($this->model !== null) {
            $opts['model'] = $this->model;
        }
        return $opts;
    }
}
