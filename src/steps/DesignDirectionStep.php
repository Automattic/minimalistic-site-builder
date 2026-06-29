<?php
declare(strict_types=1);

/**
 * Step (LLM): commit to ONE distinctive creative concept for the site BEFORE any
 * theme, palette, or layout is chosen.
 *
 * Input:  meta.json (the user prompt) + siteSpec.json (factual info).
 * Output: designDirection.json — { archetype, mood[], era_reference,
 *         color_strategy, type_strategy, shape_language, signature_move, avoid }.
 *
 * This is the single source of design intent. The theme-json, section-plan and
 * section steps all read it (via DesignDirectionStep::readFor) and let it drive
 * their choices, so two sites diverge in concept — not just in hex values. It is
 * the deliberate counter to designs converging on safe, generic defaults.
 */
final class DesignDirectionStep implements Step
{
    /** Fields the direction must always carry so downstream prompts can rely on them. */
    private const FIELDS = [
        'archetype', 'era_reference', 'color_strategy',
        'type_strategy', 'shape_language', 'signature_move', 'avoid',
    ];

    /**
     * Injected into downstream prompts when no designDirection.json exists yet
     * (a step run in isolation, or older projects). Keeps the creative intent
     * alive instead of silently reverting to defaults.
     */
    private const FALLBACK = '(No explicit design direction was provided. Make bold, '
        . 'specific, non-generic design choices that fit the brand, and consciously avoid '
        . 'default treatments like a centered hero, all-sans-serif typography, and a '
        . 'blue/teal palette.)';

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
        $direction = $this->llm->completeJson($rendered, $this->llmOpts(['log_label' => $this->id()]));

        $project->writeJson('designDirection.json', self::normalize($direction));
    }

    /**
     * The committed design direction as a text block to inject into a downstream
     * prompt. Returns the JSON verbatim when present, else a fallback that keeps
     * the "be bold, avoid defaults" intent so steps run in isolation still work.
     */
    public static function readFor(Project $project): string
    {
        return $project->exists('designDirection.json')
            ? $project->readText('designDirection.json')
            : self::FALLBACK;
    }

    /**
     * Require an archetype and default the remaining fields benignly so the
     * downstream prompts can rely on every key existing. Pure — unit-testable.
     *
     * @param array<mixed> $direction
     * @return array<mixed>
     */
    public static function normalize(array $direction): array
    {
        // Check emptiness before slugify (slugify falls back to "site" for "").
        if (trim((string) ($direction['archetype'] ?? '')) === '') {
            throw new RuntimeException('design direction has no "archetype"');
        }
        $direction['archetype'] = ProjectStore::slugify((string) $direction['archetype']);

        // Mood is a list of adjectives; tolerate a string by wrapping it.
        $mood = $direction['mood'] ?? [];
        if (is_string($mood)) {
            $mood = $mood === '' ? [] : [$mood];
        }
        $direction['mood'] = is_array($mood) ? array_values($mood) : [];

        foreach (self::FIELDS as $key) {
            if ($key === 'archetype') {
                continue;
            }
            $direction[$key] = trim((string) ($direction[$key] ?? ''));
        }

        return $direction;
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
