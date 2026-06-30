<?php
declare(strict_types=1);

/**
 * Step (LLM): generate the block theme's theme.json.
 *
 * Input:  meta.json (user prompt) + siteSpec.json (factual info). The model
 *         makes the design decisions (palette, typography, spacing) inline —
 *         there is no separate design document.
 * Output: theme/theme.json — palette, typography, spacing, layout, element styles.
 *
 * Validates the structure the templates depend on (version 3, the five color
 * slugs, the two font slugs) and fails loud if the model drifts from it.
 */
final class ThemeJsonStep implements ConcurrentStep
{
    use ModelOption;

    private const REQUIRED_COLORS = ['base', 'contrast', 'primary', 'secondary', 'accent'];
    private const REQUIRED_FONTS = ['heading', 'body'];
    private const REQ = 'theme-json';

    public function __construct(
        private Llm $llm,
        private PromptRenderer $renderer,
        private ?string $model = null,
    ) {}

    public function id(): string
    {
        return 'theme-json';
    }

    public function label(): string
    {
        return 'Generate theme.json';
    }

    public function requests(Project $project): array
    {
        $meta = $project->readJson('meta.json');
        $rendered = $this->renderer->render('theme-json.md', [
            'user_prompt'      => (string) ($meta['prompt'] ?? ''),
            'site_spec'        => $project->readText('siteSpec.json'),
            'design_direction' => DesignDirectionStep::readFor($project),
        ]);

        return [self::REQ => $this->withModel(['prompt' => $rendered])];
    }

    /** How many times to re-ask the model to fix a contrast-failing palette. */
    private const MAX_CONTRAST_RETRIES = 2;

    public function consume(Project $project, array $results): void
    {
        $theme = $results[self::REQ] ?? null;
        if (!is_array($theme)) {
            throw new RuntimeException('theme-json: missing model output');
        }
        $theme = self::finalize($theme);

        // Validator V1 — WCAG contrast, computed at token-generation time (not
        // trusted to the model). Tokens are locked here and every section trusts
        // them, so a failing palette must be fixed BEFORE any layout is composed.
        // We re-ask the model with the exact failing pairs fed back, up to a
        // bound; the result is recorded either way as an audit trail of the gate.
        $violations = ContrastValidator::validate($theme);
        $attempts = 0;
        while ($violations !== [] && $attempts < self::MAX_CONTRAST_RETRIES) {
            $attempts++;
            $theme = self::finalize($this->regenerateForContrast($project, $theme, $violations));
            $violations = ContrastValidator::validate($theme);
        }

        $project->writeJson('logs/contrast-check.json', [
            'passed'     => $violations === [],
            'attempts'   => $attempts,
            'violations' => $violations,
        ]);
        if ($violations !== []) {
            // After exhausting retries we keep the best palette and proceed, but
            // make the failure loud so it isn't silently shipped.
            fwrite(STDERR, "  [V1] contrast still failing after {$attempts} retr"
                . ($attempts === 1 ? 'y' : 'ies') . ":\n    - "
                . implode("\n    - ", $violations) . "\n");
        }

        $project->writeJson('theme/theme.json', $theme);
    }

    /** Force the schema fields and validate the contract templates rely on. */
    private static function finalize(array $theme): array
    {
        $theme['$schema'] = 'https://schemas.wp.org/trunk/theme.json';
        $theme['version'] = 3;
        self::assertColors($theme);
        self::assertFonts($theme);
        return $theme;
    }

    /**
     * Re-ask the model to fix a contrast-failing palette: the original prompt
     * plus the current (failing) palette and the exact violations, instructing it
     * to regenerate the full theme.json adjusting ONLY the palette lightness so
     * every pair clears AA while keeping the committed design intent.
     *
     * @param array<mixed> $theme
     * @param string[]     $violations
     * @return array<mixed>
     */
    private function regenerateForContrast(Project $project, array $theme, array $violations): array
    {
        $basePrompt = $this->requests($project)[self::REQ]['prompt'];
        $palette = json_encode(
            $theme['settings']['color']['palette'] ?? [],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
        $correction = "\n\n---\nCONTRAST FIX REQUIRED. The palette you produced FAILS computed WCAG-AA contrast:\n- "
            . implode("\n- ", $violations)
            . "\n\nCurrent (failing) palette:\n{$palette}\n\n"
            . "Regenerate the COMPLETE theme.json. Keep the committed design intent (the same hues / mood), "
            . "but adjust the palette's LIGHTNESS so EVERY pair above clears 4.5:1 (aim 7:1) — push darks deeper "
            . "and lights lighter; no two text/background slugs may be near-matches. Output ONLY the theme.json JSON.";

        $fixed = $this->llm->completeJson(
            $basePrompt . $correction,
            $this->withModel(['log_label' => $this->id() . '-contrast-fix'])
        );
        return is_array($fixed) ? $fixed : $theme;
    }

    public function run(Project $project): void
    {
        $this->consume($project, $this->llm->completeJsonBatch($this->requests($project)));
    }

    /** @param array<mixed> $theme */
    private static function assertColors(array $theme): void
    {
        $palette = $theme['settings']['color']['palette'] ?? null;
        if (!is_array($palette)) {
            throw new RuntimeException('theme.json missing settings.color.palette');
        }
        $slugs = array_column($palette, 'slug');
        foreach (self::REQUIRED_COLORS as $needed) {
            if (!in_array($needed, $slugs, true)) {
                throw new RuntimeException("theme.json palette missing slug: {$needed}");
            }
        }
    }

    /** @param array<mixed> $theme */
    private static function assertFonts(array $theme): void
    {
        $families = $theme['settings']['typography']['fontFamilies'] ?? null;
        if (!is_array($families)) {
            throw new RuntimeException('theme.json missing settings.typography.fontFamilies');
        }
        $slugs = array_column($families, 'slug');
        foreach (self::REQUIRED_FONTS as $needed) {
            if (!in_array($needed, $slugs, true)) {
                throw new RuntimeException("theme.json fontFamilies missing slug: {$needed}");
            }
        }
    }
}
