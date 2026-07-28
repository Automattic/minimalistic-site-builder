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
 * Repairs omissions in the structure templates depend on (version 3, required
 * color, font-family, and font-size slugs) and records every fallback.
 */
final class ThemeJsonStep implements ConcurrentStep
{
    use LlmOptions;

    private const REQUIRED_COLORS = ['base', 'contrast', 'primary', 'secondary', 'accent'];
    private const REQUIRED_FONTS = ['heading', 'body'];
    /**
     * Documented fallback type scale for missing generated presets.
     *
     * @var list<array{slug: string, name: string, size: string}>
     */
    private const FONT_SIZE_PROFILE = [
        ['slug' => 'caption', 'name' => 'Caption', 'size' => '0.875rem'],
        ['slug' => 'body', 'name' => 'Body', 'size' => '1.125rem'],
        ['slug' => 'lead', 'name' => 'Lead', 'size' => '1.375rem'],
        ['slug' => 'heading', 'name' => 'Heading', 'size' => '1.75rem'],
        ['slug' => 'section-title', 'name' => 'Section Title', 'size' => 'clamp(2.25rem, 3vw, 3rem)'],
        ['slug' => 'display', 'name' => 'Display', 'size' => 'clamp(3rem, 7vw, 6rem)'],
    ];
    /**
     * Neutral, WCAG-safe fallback palette used only when generated roles cannot
     * supply a usable color.
     *
     * @var list<array{slug: string, name: string, color: string}>
     */
    private const DEFAULT_PALETTE = [
        ['slug' => 'base', 'name' => 'Base', 'color' => '#FAF8F4'],
        ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#1F2421'],
        ['slug' => 'primary', 'name' => 'Primary', 'color' => '#365C4D'],
        ['slug' => 'secondary', 'name' => 'Secondary', 'color' => '#5B514A'],
        ['slug' => 'accent', 'name' => 'Accent', 'color' => '#9C3D2E'],
    ];
    /**
     * Real Google families with web-safe fallbacks for an unusable generated
     * font-family profile.
     *
     * @var list<array{slug: string, name: string, fontFamily: string}>
     */
    private const DEFAULT_FONT_FAMILIES = [
        [
            'slug' => 'heading',
            'name' => 'Heading',
            'fontFamily' => '"Fraunces", Georgia, "Times New Roman", serif',
        ],
        [
            'slug' => 'body',
            'name' => 'Body',
            'fontFamily' => '"Source Sans 3", "Helvetica Neue", Arial, sans-serif',
        ],
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
    /**
     * Mechanical preset-to-role wiring shared by every generated theme.
     *
     * @var array<string,mixed>
     */
    private const SCAFFOLD = [
        'styles' => [
            'color' => [
                'background' => 'var:preset|color|base',
                'text' => 'var:preset|color|contrast',
            ],
            'typography' => [
                'fontFamily' => 'var:preset|font-family|body',
                'fontSize' => 'var:preset|font-size|body',
                'lineHeight' => '1.6',
            ],
            'elements' => [
                'h1' => [
                    'typography' => [
                        'fontFamily' => 'var:preset|font-family|heading',
                        'fontSize' => 'var:preset|font-size|display',
                    ],
                    'color' => ['text' => 'var:preset|color|primary'],
                ],
                'h2' => [
                    'typography' => [
                        'fontFamily' => 'var:preset|font-family|heading',
                        'fontSize' => 'var:preset|font-size|section-title',
                    ],
                    'color' => ['text' => 'var:preset|color|primary'],
                ],
                'h3' => [
                    'typography' => [
                        'fontFamily' => 'var:preset|font-family|heading',
                        'fontSize' => 'var:preset|font-size|heading',
                    ],
                    'color' => ['text' => 'var:preset|color|primary'],
                ],
                'h4' => [
                    'typography' => [
                        'fontFamily' => 'var:preset|font-family|heading',
                        'fontSize' => 'var:preset|font-size|heading',
                    ],
                ],
                'h5' => [
                    'typography' => [
                        'fontFamily' => 'var:preset|font-family|heading',
                        'fontSize' => 'var:preset|font-size|heading',
                    ],
                ],
                'h6' => [
                    'typography' => [
                        'fontFamily' => 'var:preset|font-family|heading',
                        'fontSize' => 'var:preset|font-size|heading',
                    ],
                ],
                'caption' => [
                    'typography' => ['fontSize' => 'var:preset|font-size|caption'],
                    'color' => ['text' => 'var:preset|color|secondary'],
                ],
            ],
            'blocks' => [
                'core/quote' => [
                    'typography' => [
                        'fontFamily' => 'var:preset|font-family|body',
                        'fontSize' => 'var:preset|font-size|lead',
                    ],
                    'color' => ['text' => 'var:preset|color|contrast'],
                ],
                'core/pullquote' => [
                    'typography' => [
                        'fontFamily' => 'var:preset|font-family|heading',
                        'fontSize' => 'var:preset|font-size|heading',
                    ],
                    'color' => ['text' => 'var:preset|color|primary'],
                ],
                'core/table' => [
                    'typography' => [
                        'fontFamily' => 'var:preset|font-family|body',
                        'fontSize' => 'var:preset|font-size|body',
                    ],
                    'color' => ['text' => 'var:preset|color|contrast'],
                ],
                'core/separator' => [
                    'color' => ['text' => 'var:preset|color|secondary'],
                ],
                'core/list' => [
                    'typography' => [
                        'fontFamily' => 'var:preset|font-family|body',
                        'fontSize' => 'var:preset|font-size|body',
                    ],
                    'color' => ['text' => 'var:preset|color|contrast'],
                ],
                'core/image' => [
                    'typography' => [
                        'fontFamily' => 'var:preset|font-family|body',
                        'fontSize' => 'var:preset|font-size|caption',
                    ],
                    'color' => ['text' => 'var:preset|color|secondary'],
                ],
                'core/site-title' => [
                    'typography' => [
                        'fontFamily' => 'var:preset|font-family|heading',
                        'fontSize' => 'var:preset|font-size|heading',
                    ],
                    'color' => ['text' => 'var:preset|color|primary'],
                ],
                'core/navigation' => [
                    'typography' => [
                        'fontFamily' => 'var:preset|font-family|body',
                        'fontSize' => 'var:preset|font-size|caption',
                    ],
                    'color' => ['text' => 'var:preset|color|contrast'],
                ],
            ],
        ],
    ];
    private const REQ = 'theme-json';
    private const LOG_FILE = 'theme-json.log';

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
            writes: ['theme/theme.json', 'warnings.json', 'logs/theme-json.log'],
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
        $repairWarnings = [];
        $theme = $results[self::REQ] ?? null;
        if (!is_array($theme) || ($theme !== [] && array_is_list($theme))) {
            $theme = [];
            $rootWarning = 'theme/theme.json: missing or unusable model output at document root; '
                . 'substituted an empty theme as repair input; delivered with complete documented defaults';
            $repairWarnings[] = $rootWarning;
            $project->addWarnings(self::REQ, [$rootWarning]);
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

        $theme = self::applyScaffold($theme);
        $theme = self::fillColors($theme, $project, $repairWarnings);
        $theme = self::fillFonts($theme, $project, $repairWarnings);
        $theme = self::fillFontSizes($theme, $project, $repairWarnings);

        $project->writeJson('theme/theme.json', $theme);

        if ($repairWarnings !== []) {
            $project->writeText(
                'logs/' . self::LOG_FILE,
                'theme-json delivered with ' . count($repairWarnings)
                    . " deterministic repair(s); actionable warnings:\n- "
                    . implode("\n- ", $repairWarnings) . "\n",
            );
            fwrite(
                STDERR,
                '  [theme-json] warning: ' . count($repairWarnings)
                    . ' repair(s) recorded in warnings.json; see logs/' . self::LOG_FILE . "\n",
            );
        }
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
     * Fill model omissions with the frozen mechanical preset-to-role wiring.
     *
     * Pure — unit-testable.
     *
     * @param array<mixed> $theme
     * @return array<mixed>
     */
    public static function applyScaffold(array $theme): array
    {
        return self::mergeScaffoldDefaults(self::SCAFFOLD, $theme);
    }

    /**
     * Recursively fill associative-map omissions while preserving every
     * model-authored leaf. Non-empty lists and scalars are model leaves; an
     * empty array is also PHP's representation of a decoded empty JSON object,
     * so it receives the scaffold map.
     *
     * Pure — unit-testable.
     *
     * @param array<mixed> $scaffold
     * @param array<mixed> $model
     * @return array<mixed>
     */
    public static function mergeScaffoldDefaults(array $scaffold, array $model): array
    {
        foreach ($scaffold as $key => $scaffoldValue) {
            if (!array_key_exists($key, $model)) {
                $model[$key] = $scaffoldValue;
                continue;
            }

            $modelValue = $model[$key];
            $modelIsMap = is_array($modelValue)
                && ($modelValue === [] || !array_is_list($modelValue));
            $scaffoldIsMap = is_array($scaffoldValue)
                && ($scaffoldValue === [] || !array_is_list($scaffoldValue));
            if ($modelIsMap && $scaffoldIsMap) {
                $model[$key] = self::mergeScaffoldDefaults($scaffoldValue, $modelValue);
            }
        }

        return $model;
    }

    /**
     * Fill missing or unusable required color roles without replacing usable
     * model presets. Model fallbacks are read from the original palette only,
     * so a repaired role cannot silently become another role's model source.
     *
     * @param array<mixed> $theme
     * @param list<string>|null $repairWarnings
     * @return array<mixed>
     */
    public static function fillColors(
        array $theme,
        Project $project,
        ?array &$repairWarnings = null,
    ): array
    {
        $colorSettings = $theme['settings']['color'] ?? null;
        $palettePresent = is_array($colorSettings) && array_key_exists('palette', $colorSettings);
        $palette = self::normalizePresetRows(
            $palettePresent ? $colorSettings['palette'] : [],
            $palettePresent,
            'settings.color.palette',
            'color',
            $project,
            $repairWarnings,
        );

        $originalColors = [];
        foreach ($palette as $preset) {
            if (!isset($originalColors[$preset['slug']])) {
                $originalColors[$preset['slug']] = $preset['color'];
            }
        }

        $defaults = [];
        foreach (self::DEFAULT_PALETTE as $preset) {
            $defaults[$preset['slug']] = $preset;
        }
        $modelFallbackRoles = [
            'primary' => 'contrast',
            'secondary' => 'contrast',
            'accent' => 'primary',
        ];
        $warnings = [];

        foreach (self::REQUIRED_COLORS as $needed) {
            if (in_array($needed, array_column($palette, 'slug'), true)) {
                continue;
            }

            $sourceRole = $modelFallbackRoles[$needed] ?? null;
            if ($sourceRole !== null && isset($originalColors[$sourceRole])) {
                $value = $originalColors[$sourceRole];
                $source = "original model settings.color.palette[slug={$sourceRole}].color";
            } else {
                $value = $defaults[$needed]['color'];
                $source = "DEFAULT_PALETTE[slug={$needed}].color";
            }

            $replacement = $defaults[$needed];
            $replacement['color'] = $value;
            $palette[] = $replacement;
            $encoded = json_encode(
                $value,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
            );
            $warnings[] = "theme/theme.json: missing or unusable settings.color.palette[slug={$needed}].color; "
                . "substituted {$encoded} from {$source}; delivered with repaired preset";
        }

        $theme['settings']['color']['palette'] = $palette;
        $project->addWarnings(self::REQ, $warnings);
        if ($repairWarnings !== null) {
            array_push($repairWarnings, ...$warnings);
        }
        return $theme;
    }

    /**
     * Fill missing or unusable required font roles. A model fallback comes
     * only from the other role in the original generated profile.
     *
     * @param array<mixed> $theme
     * @param list<string>|null $repairWarnings
     * @return array<mixed>
     */
    public static function fillFonts(
        array $theme,
        Project $project,
        ?array &$repairWarnings = null,
    ): array
    {
        $typography = $theme['settings']['typography'] ?? null;
        $familiesPresent = is_array($typography) && array_key_exists('fontFamilies', $typography);
        $families = self::normalizePresetRows(
            $familiesPresent ? $typography['fontFamilies'] : [],
            $familiesPresent,
            'settings.typography.fontFamilies',
            'fontFamily',
            $project,
            $repairWarnings,
        );

        $originalFamilies = [];
        foreach ($families as $preset) {
            if (!isset($originalFamilies[$preset['slug']])) {
                $originalFamilies[$preset['slug']] = $preset['fontFamily'];
            }
        }

        $defaults = [];
        foreach (self::DEFAULT_FONT_FAMILIES as $preset) {
            $defaults[$preset['slug']] = $preset;
        }
        $otherRole = ['heading' => 'body', 'body' => 'heading'];
        $warnings = [];

        foreach (self::REQUIRED_FONTS as $needed) {
            if (in_array($needed, array_column($families, 'slug'), true)) {
                continue;
            }

            $sourceRole = $otherRole[$needed];
            if (isset($originalFamilies[$sourceRole])) {
                $value = $originalFamilies[$sourceRole];
                $source = "original model settings.typography.fontFamilies[slug={$sourceRole}].fontFamily";
            } else {
                $value = $defaults[$needed]['fontFamily'];
                $source = "DEFAULT_FONT_FAMILIES[slug={$needed}].fontFamily";
            }

            $replacement = $defaults[$needed];
            $replacement['fontFamily'] = $value;
            $families[] = $replacement;
            $encoded = json_encode(
                $value,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
            );
            $warnings[] = "theme/theme.json: missing or unusable settings.typography.fontFamilies[slug={$needed}].fontFamily; "
                . "substituted {$encoded} from {$source}; delivered with repaired preset";
        }

        $theme['settings']['typography']['fontFamilies'] = $families;
        $project->addWarnings(self::REQ, $warnings);
        if ($repairWarnings !== null) {
            array_push($repairWarnings, ...$warnings);
        }
        return $theme;
    }

    /**
     * Fill each missing or unusable required font-size preset from the frozen
     * profile while preserving model-authored sizes and unrelated presets.
     *
     * @param array<mixed> $theme
     * @param list<string>|null $repairWarnings
     * @return array<mixed>
     */
    public static function fillFontSizes(
        array $theme,
        Project $project,
        ?array &$repairWarnings = null,
    ): array
    {
        $typography = $theme['settings']['typography'] ?? null;
        $fontSizesPresent = is_array($typography) && array_key_exists('fontSizes', $typography);
        $fontSizes = self::normalizePresetRows(
            $fontSizesPresent ? $typography['fontSizes'] : [],
            $fontSizesPresent,
            'settings.typography.fontSizes',
            'size',
            $project,
            $repairWarnings,
        );

        $warnings = [];
        foreach (self::FONT_SIZE_PROFILE as $fallback) {
            $needed = $fallback['slug'];
            if (in_array($needed, array_column($fontSizes, 'slug'), true)) {
                continue;
            }

            $fontSizes[] = $fallback;
            $encoded = json_encode(
                $fallback['size'],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
            );
            $warnings[] = "theme/theme.json: missing or unusable settings.typography.fontSizes[slug={$needed}].size; "
                . "substituted {$encoded} from FONT_SIZE_PROFILE[slug={$needed}].size; "
                . 'delivered with repaired preset';
        }

        $theme['settings']['typography']['fontSizes'] = $fontSizes;
        $project->addWarnings(self::REQ, $warnings);
        if ($repairWarnings !== null) {
            array_push($repairWarnings, ...$warnings);
        }
        return $theme;
    }

    /**
     * Keep only structurally usable generated presets. This is deliberately
     * bounded to reviewed preset-list signatures; surrounding theme structure
     * and all I/O/programming failures remain outside the repair boundary.
     *
     * @param list<string>|null $repairWarnings
     * @return list<array<mixed>>
     */
    private static function normalizePresetRows(
        mixed $container,
        bool $present,
        string $path,
        string $valueKey,
        Project $project,
        ?array &$repairWarnings,
    ): array {
        if (!$present) {
            return [];
        }

        $warnings = [];
        if (!is_array($container) || !array_is_list($container)) {
            $type = is_array($container) ? 'associative array' : get_debug_type($container);
            $encoded = json_encode(
                $container,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_INVALID_UTF8_SUBSTITUTE
                    | JSON_PARTIAL_OUTPUT_ON_ERROR,
            );
            $warnings[] = "theme/theme.json: invalid {$path} container; authored type={$type}, value={$encoded}; "
                . 'discarded invalid preset container; delivered defaults/remaining usable presets';
            $project->addWarnings(self::REQ, $warnings);
            if ($repairWarnings !== null) {
                array_push($repairWarnings, ...$warnings);
            }
            return [];
        }

        $usable = [];
        foreach ($container as $index => $preset) {
            $slugUsable = is_array($preset)
                && is_string($preset['slug'] ?? null)
                && trim($preset['slug']) !== '';
            $valueUsable = is_array($preset)
                && is_string($preset[$valueKey] ?? null)
                && trim($preset[$valueKey]) !== '';
            $nameUsable = is_array($preset)
                && is_string($preset['name'] ?? null)
                && trim($preset['name']) !== '';
            if ($slugUsable && $valueUsable && !$nameUsable) {
                $slug = trim($preset['slug']);
                $name = ucwords(str_replace(['-', '_'], ' ', $slug));
                $preset['name'] = $name;
                $usable[] = $preset;
                $encodedValue = json_encode(
                    $preset[$valueKey],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
                );
                $encodedName = json_encode(
                    $name,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
                );
                $warnings[] = "theme/theme.json: missing or unusable {$path}[index={$index}].name; "
                    . "kept authored {$valueKey}={$encodedValue} and metadata; "
                    . "synthesized name={$encodedName} from slug={$slug}; delivered with repaired preset";
                continue;
            }

            $valid = is_array($preset)
                && $slugUsable
                && $nameUsable
                && $valueUsable;
            if ($valid) {
                $usable[] = $preset;
                continue;
            }

            $type = get_debug_type($preset);
            $encoded = json_encode(
                $preset,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_INVALID_UTF8_SUBSTITUTE
                    | JSON_PARTIAL_OUTPUT_ON_ERROR,
            );
            $warnings[] = "theme/theme.json: invalid {$path}[index={$index}]; "
                . "authored type={$type}, value={$encoded}; discarded invalid preset; "
                . 'delivered defaults/remaining usable presets';
        }

        $project->addWarnings(self::REQ, $warnings);
        if ($repairWarnings !== null) {
            array_push($repairWarnings, ...$warnings);
        }
        return $usable;
    }
}
