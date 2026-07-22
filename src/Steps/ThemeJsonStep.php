<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\ConcurrentStep;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\LlmOptions;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

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
    /**
     * One bounded spacing vocabulary for every generated site.
     *
     * sm/md are component-level gaps. lg/xl/xxl are the compact, standard,
     * and spacious section-padding choices. Their fluid ranges prevent the
     * largest token from becoming fixed 128px padding on mobile or growing
     * beyond 112px on wide screens.
     *
     * @var list<array{slug: string, name: string, size: string}>
     */
    private const SPACING_PROFILE = [
        ['slug' => 'sm', 'name' => 'Small', 'size' => 'clamp(0.75rem, 1vw, 1rem)'],
        ['slug' => 'md', 'name' => 'Medium', 'size' => 'clamp(1.5rem, 2vw, 2rem)'],
        ['slug' => 'lg', 'name' => 'Compact', 'size' => 'clamp(3rem, 4vw, 4rem)'],
        ['slug' => 'xl', 'name' => 'Standard', 'size' => 'clamp(4rem, 6vw, 6rem)'],
        ['slug' => 'xxl', 'name' => 'Spacious', 'size' => 'clamp(5rem, 7vw, 7rem)'],
    ];
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

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: ['meta.json', 'siteSpec.json', 'designDirection.json'],
            writes: ['theme/theme.json'],
            concurrent: false,
        );
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
        $theme = self::disableCoreDefaultPresets($theme);
        $theme = self::normalizeSpacingSettings($theme);
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
     * Disable WordPress core's default presets so the slugs declared in
     * theme.json are the only presets that exist at runtime.
     *
     * Without these flags, markup referencing a core slug ("fontSize":"large",
     * "textColor":"white", a core gradient or duotone) renders with values
     * from outside the designed scale, and PresetReferences' declared-slug
     * model diverges from runtime. Shadows deliberately stay enabled: the
     * scaffold CSS and the page-styles prompt use core shadow presets, and
     * PresetReferences honors settings.shadow.defaultPresets when a theme
     * opts out. (defaultSpacingSizes is part of the canonical spacing stanza
     * in normalizeSpacingSettings.)
     *
     * Pure — unit-testable.
     *
     * @param array<mixed> $theme
     * @return array<mixed>
     */
    public static function disableCoreDefaultPresets(array $theme): array
    {
        if (!isset($theme['settings']) || !is_array($theme['settings'])) {
            $theme['settings'] = [];
        }
        $flags = [
            'color'      => ['defaultPalette', 'defaultGradients', 'defaultDuotone'],
            'typography' => ['defaultFontSizes'],
        ];
        foreach ($flags as $section => $names) {
            if (!isset($theme['settings'][$section]) || !is_array($theme['settings'][$section])) {
                $theme['settings'][$section] = [];
            }
            foreach ($names as $name) {
                $theme['settings'][$section][$name] = false;
            }
        }
        return $theme;
    }

    /**
     * Install the canonical responsive spacing scale and enable block gaps.
     *
     * The model still owns all visual choices outside settings.spacing. Any
     * additional valid spacing settings (such as units) are preserved, while
     * a missing or malformed spacing stanza is repaired rather than allowed
     * to produce missing CSS custom properties downstream. Core default
     * spacing sizes are disabled here (not in disableCoreDefaultPresets) so
     * ThemeValidator::spacingWarnings' drift comparison covers the flag.
     *
     * Pure — unit-testable.
     *
     * @param array<mixed> $theme
     * @return array<mixed>
     */
    public static function normalizeSpacingSettings(array $theme): array
    {
        if (!isset($theme['settings']) || !is_array($theme['settings'])) {
            $theme['settings'] = [];
        }
        if (!isset($theme['settings']['spacing']) || !is_array($theme['settings']['spacing'])) {
            $theme['settings']['spacing'] = [];
        }

        $theme['settings']['spacing']['blockGap'] = true;
        $theme['settings']['spacing']['defaultSpacingSizes'] = false;
        $theme['settings']['spacing']['spacingSizes'] = self::SPACING_PROFILE;

        return $theme;
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
