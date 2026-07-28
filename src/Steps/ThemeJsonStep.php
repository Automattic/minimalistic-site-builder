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
 * slugs, the two font slugs) and repairs drift deterministically: missing
 * slugs are filled from the design direction's committed values, then neutral
 * defaults, with every fill recorded in warnings.json — a missing slug never
 * aborts the build.
 */
final class ThemeJsonStep implements ConcurrentStep
{
    use LlmOptions;

    private const REQUIRED_COLORS = ['base', 'contrast', 'primary', 'secondary', 'accent'];
    private const REQUIRED_FONTS = ['heading', 'body'];

    /**
     * Readable neutral defaults for palette slugs the model omitted, used only
     * when the design direction committed no hex for the role either. base and
     * contrast bound the readability math; the roles all fall back to
     * near-black so any repaired pairing stays WCAG-safe on base.
     *
     * @var array<string,string>
     */
    private const FALLBACK_COLORS = [
        'base'      => '#FFFFFF',
        'contrast'  => '#111111',
        'primary'   => '#111111',
        'secondary' => '#444444',
        'accent'    => '#111111',
    ];

    /**
     * System stacks for font slugs the model omitted: render everywhere, and
     * FontsPhpStep's GENERIC list recognizes system-ui, so no Google Fonts
     * request is ever minted for them.
     *
     * @var array<string,string>
     */
    private const FALLBACK_FONTS = [
        'heading' => 'system-ui, sans-serif',
        'body'    => 'system-ui, sans-serif',
    ];
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
            writes: ['theme/theme.json', 'warnings.json'],
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

        // A default vertical rhythm between sibling blocks: without it, per-block
        // "blockGap" the parts set (e.g. the branded-lockup header's zero-gap
        // title/tagline stack) renders editor-only and the frontend falls back
        // to browser default margins. (settings.spacing.blockGap is already
        // forced non-null by normalizeSpacingSettings above.)
        if (!is_array($theme['styles'] ?? null)) {
            $theme['styles'] = [];
        }
        if (!is_array($theme['styles']['spacing'] ?? null)) {
            $theme['styles']['spacing'] = [];
        }
        $theme['styles']['spacing']['blockGap'] ??= 'var:preset|spacing|md';

        // Missing required slugs are filled deterministically instead of
        // aborting the build: the direction's committed hexes first, neutral
        // readable defaults otherwise. Every fill is recorded durably.
        $direction = $project->exists('designDirection.json')
            ? $project->readJson('designDirection.json')
            : [];
        $preferred = is_array($direction['palette'] ?? null) ? $direction['palette'] : [];
        [$theme, $warnings] = self::repairColors($theme, $preferred);
        [$theme, $fontWarnings] = self::repairFonts($theme);
        $warnings = array_merge($warnings, $fontWarnings);
        if ($warnings !== []) {
            $project->addWarnings($this->id(), $warnings);
            echo '  [theme-json] warning: ' . count($warnings)
                . " missing required slug(s) filled with defaults (recorded in warnings.json)\n";
        }

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

    /**
     * Ensure every required palette slug exists, filling gaps from the design
     * direction's committed hexes and then the neutral fallbacks. The model's
     * palette entries are never altered — only missing slugs are appended.
     * Pure — unit-testable.
     *
     * @param array<mixed>         $theme
     * @param array<string,mixed>  $preferredHexes role => "#RRGGBB" (direction palette)
     * @return array{0:array<mixed>,1:list<string>} theme, warnings
     */
    public static function repairColors(array $theme, array $preferredHexes = []): array
    {
        $warnings = [];
        $palette = $theme['settings']['color']['palette'] ?? null;
        if (!is_array($palette)) {
            $warnings[] = 'theme.json missing settings.color.palette; rebuilt with default colors';
            $palette = [];
        }
        $entries = array_values(array_filter($palette, 'is_array'));
        if (($malformed = count($palette) - count($entries)) > 0) {
            $warnings[] = "theme.json palette: removed {$malformed} malformed (non-object) entr"
                . ($malformed === 1 ? 'y' : 'ies');
        }
        $palette = $entries;
        $slugs = array_column($palette, 'slug');
        foreach (self::REQUIRED_COLORS as $needed) {
            if (in_array($needed, $slugs, true)) {
                continue;
            }
            $preferred = strtoupper(trim((string) ($preferredHexes[$needed] ?? '')));
            $hex = preg_match('/^#[0-9A-F]{6}$/', $preferred) === 1
                ? $preferred
                : self::FALLBACK_COLORS[$needed];
            $palette[] = ['slug' => $needed, 'color' => $hex, 'name' => ucfirst($needed)];
            $warnings[] = "theme.json palette missing slug '{$needed}'; filled with {$hex}";
        }
        $theme['settings']['color']['palette'] = $palette;
        return [$theme, $warnings];
    }

    /**
     * Ensure both required font-family slugs exist, appending system stacks
     * for the missing ones. Pure — unit-testable.
     *
     * @param array<mixed> $theme
     * @return array{0:array<mixed>,1:list<string>} theme, warnings
     */
    public static function repairFonts(array $theme): array
    {
        $warnings = [];
        $families = $theme['settings']['typography']['fontFamilies'] ?? null;
        if (!is_array($families)) {
            $warnings[] = 'theme.json missing settings.typography.fontFamilies; rebuilt with system stacks';
            $families = [];
        }
        $entries = array_values(array_filter($families, 'is_array'));
        if (($malformed = count($families) - count($entries)) > 0) {
            $warnings[] = "theme.json fontFamilies: removed {$malformed} malformed (non-object) entr"
                . ($malformed === 1 ? 'y' : 'ies');
        }
        $families = $entries;
        $slugs = array_column($families, 'slug');
        foreach (self::REQUIRED_FONTS as $needed) {
            if (in_array($needed, $slugs, true)) {
                continue;
            }
            $stack = self::FALLBACK_FONTS[$needed];
            $families[] = ['slug' => $needed, 'name' => ucfirst($needed), 'fontFamily' => $stack];
            $warnings[] = "theme.json fontFamilies missing slug '{$needed}'; filled with the system stack";
        }
        $theme['settings']['typography']['fontFamilies'] = $families;
        return [$theme, $warnings];
    }
}
