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
 * Validates the structure the templates depend on (version 3, the five required
 * color slugs, the two required font slugs) and fails loud if the model drifts
 * from it. The required slugs are a MINIMUM contract, not an exact one: the
 * model may add expressive extras (a surface/muted color, a third display/mono
 * font) for variety, so the checks below are subset checks — extras pass, only
 * a missing required slug fails.
 */
final class ThemeJsonStep implements ConcurrentStep
{
    use ModelOption;

    // The guaranteed-present slugs downstream parts/header/footer reference. The
    // model may add more (extra palette tints, a third font); these are just the
    // floor every theme must clear.
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

    public function consume(Project $project, array $results): void
    {
        $theme = $results[self::REQ] ?? null;
        if (!is_array($theme)) {
            throw new RuntimeException('theme-json: missing model output');
        }

        // Force the schema fields and validate the contract templates rely on.
        $theme['$schema'] = 'https://schemas.wp.org/trunk/theme.json';
        $theme['version'] = 3;

        self::assertColors($theme);
        self::assertFonts($theme);

        $project->writeJson('theme/theme.json', $theme);
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
