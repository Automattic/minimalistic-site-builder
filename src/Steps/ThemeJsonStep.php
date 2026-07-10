<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\ConcurrentStep;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\LlmOptions;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Step;

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
    use LlmOptions;

    private const REQUIRED_COLORS = ['base', 'contrast', 'primary', 'secondary', 'accent'];
    private const REQUIRED_FONTS = ['heading', 'body'];
    private const REQ = 'theme-json';

    public function __construct(
        private Llm $llm,
        private PromptRenderer $renderer,
        private ?string $model = null,
        private ?float $temperature = null,
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

        return [self::REQ => $this->withOptions(['prompt' => $rendered])];
    }

    public function consume(Project $project, array $results): void
    {
        $theme = $results[self::REQ] ?? null;
        if (!is_array($theme)) {
            throw new \RuntimeException('theme-json: missing model output');
        }

        // Force the schema fields and validate the contract templates rely on.
        $theme['$schema'] = 'https://schemas.wp.org/trunk/theme.json';
        $theme['version'] = 3;
        $theme = self::normalizeRootPadding($theme);

        // blockGap must be non-null: when it is null WordPress emits NO blockGap
        // layout CSS on the frontend while the editor still emulates it, so any
        // per-block "blockGap" the parts set (e.g. the branded-lockup header's
        // zero-gap title/tagline stack) renders editor-only and the frontend
        // falls back to browser default margins.
        $theme['settings']['spacing']['blockGap'] ??= true;
        $theme['styles']['spacing']['blockGap'] ??= 'var:preset|spacing|md';

        self::assertColors($theme);
        self::assertFonts($theme);

        $project->writeJson('theme/theme.json', $theme);
    }

    public function run(Project $project): void
    {
        $this->consume($project, $this->llm->completeJsonBatch($this->requests($project)));
    }

    /**
     * Normalize the root padding stanza the model reliably copies from
     * published themes but never gets quite right:
     *
     * - A theme that sets root left/right padding MUST also opt into
     *   root-padding-aware alignments: without the flag WordPress puts the
     *   padding on <body>, where no block can escape it, so every align:full
     *   hero/footer renders inset by a page-background gutter.
     * - Root top/bottom padding is forced to 0: with the flag it lands on
     *   .wp-site-blocks as dead space above the hero and below the footer,
     *   and the vertical rhythm belongs to the header/sections/footer, which
     *   all bring their own padding.
     *
     * Pure — unit-testable.
     *
     * @param array<mixed> $theme
     * @return array<mixed>
     */
    public static function normalizeRootPadding(array $theme): array
    {
        $padding = $theme['styles']['spacing']['padding'] ?? null;
        if (!is_array($padding)) {
            return $theme;
        }
        $theme['styles']['spacing']['padding']['top'] = '0';
        $theme['styles']['spacing']['padding']['bottom'] = '0';
        foreach (['left', 'right'] as $side) {
            $value = trim((string) ($padding[$side] ?? ''));
            if ($value !== '' && preg_match('/^0(?:[a-z%]+)?$/i', $value) !== 1) {
                $theme['settings']['useRootPaddingAwareAlignments'] = true;
                return $theme;
            }
        }
        return $theme;
    }

    /** @param array<mixed> $theme */
    private static function assertColors(array $theme): void
    {
        $palette = $theme['settings']['color']['palette'] ?? null;
        if (!is_array($palette)) {
            throw new \RuntimeException('theme.json missing settings.color.palette');
        }
        $slugs = array_column($palette, 'slug');
        foreach (self::REQUIRED_COLORS as $needed) {
            if (!in_array($needed, $slugs, true)) {
                throw new \RuntimeException("theme.json palette missing slug: {$needed}");
            }
        }
    }

    /** @param array<mixed> $theme */
    private static function assertFonts(array $theme): void
    {
        $families = $theme['settings']['typography']['fontFamilies'] ?? null;
        if (!is_array($families)) {
            throw new \RuntimeException('theme.json missing settings.typography.fontFamilies');
        }
        $slugs = array_column($families, 'slug');
        foreach (self::REQUIRED_FONTS as $needed) {
            if (!in_array($needed, $slugs, true)) {
                throw new \RuntimeException("theme.json fontFamilies missing slug: {$needed}");
            }
        }
    }
}
