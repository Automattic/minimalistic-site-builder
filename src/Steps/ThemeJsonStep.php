<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\GeneratedJsonException;
use Automattic\SiteBuild\GeneratedJsonFallbackStep;
use Automattic\SiteBuild\CssChecks;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\LlmOptions;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\Warnings;

/**
 * Step (LLM): generate the block theme's theme.json.
 *
 * Input:  meta.json (user prompt) + siteSpec.json (factual info) +
 *         designDirection.json (the committed creative and typography floor).
 *         The model translates that direction into theme.json tokens.
 * Output: theme/theme.json — palette, typography, spacing, layout, element styles.
 *
 * Validates the structure the templates depend on (version 3, the five color
 * slugs, the two font slugs) and repairs drift deterministically: missing
 * slugs are filled from the design direction's committed values, then neutral
 * defaults, with every fill recorded in warnings.json — a missing slug never
 * aborts the build.
 */
final class ThemeJsonStep implements GeneratedJsonFallbackStep
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
     * The type scale the scaffold wires roles to. Every slug here is
     * referenced by SCAFFOLD, so a missing one would leave a dangling
     * var:preset|font-size|… — PresetReferences does scan theme.json's own
     * strings and would report it, but as a build-time problem rather than a
     * rendered site. Filling them keeps the scaffold's references valid.
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
     * Build-supplied wiring the model no longer writes. It maps presets to
     * roles and makes zero aesthetic choices — every value is a var:preset
     * token whose actual color, family and size the model chose, so sites stay
     * visually distinct. No borders, radii, shadows or decorative treatment.
     * (The direction-committed shape wiring in repairShapeWiring() is the one
     * deliberate exception, and it executes an explicit design commitment
     * rather than making a choice here.)
     *
     * Context-free block/caption text colors are deliberately absent:
     * ContrastFixStep evaluates rendered backgrounds but cannot see
     * theme-level block defaults. button, link and heading are also absent:
     * ContrastFixStep reads those paths and rewrites failing colors, so they
     * stay model-authored.
     *
     * @var array<mixed>
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
                ],
                'h2' => [
                    'typography' => [
                        'fontFamily' => 'var:preset|font-family|heading',
                        'fontSize' => 'var:preset|font-size|section-title',
                    ],
                ],
                'h3' => [
                    'typography' => [
                        'fontFamily' => 'var:preset|font-family|heading',
                        'fontSize' => 'var:preset|font-size|heading',
                    ],
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
                ],
            ],
            'blocks' => [
                'core/quote' => [
                    'typography' => [
                        'fontFamily' => 'var:preset|font-family|body',
                        'fontSize' => 'var:preset|font-size|lead',
                    ],
                ],
                'core/pullquote' => [
                    'typography' => [
                        'fontFamily' => 'var:preset|font-family|heading',
                        'fontSize' => 'var:preset|font-size|heading',
                    ],
                ],
                'core/table' => [
                    'typography' => [
                        'fontFamily' => 'var:preset|font-family|body',
                        'fontSize' => 'var:preset|font-size|body',
                    ],
                ],
                'core/list' => [
                    'typography' => [
                        'fontFamily' => 'var:preset|font-family|body',
                        'fontSize' => 'var:preset|font-size|body',
                    ],
                ],
                'core/image' => [
                    'typography' => [
                        'fontFamily' => 'var:preset|font-family|body',
                        'fontSize' => 'var:preset|font-size|caption',
                    ],
                ],
                'core/site-title' => [
                    'typography' => [
                        'fontFamily' => 'var:preset|font-family|heading',
                        'fontSize' => 'var:preset|font-size|heading',
                    ],
                ],
                'core/navigation' => [
                    'typography' => [
                        'fontFamily' => 'var:preset|font-family|body',
                        'fontSize' => 'var:preset|font-size|caption',
                    ],
                ],
            ],
        ],
    ];
    /**
     * The image-corner radius each committed corner language wires onto
     * core/image (WordPress applies the block's border support to the inner
     * img, so contained figures, card crops and gallery items all pick it
     * up). Covers and media-text halves have no structured theme.json path to
     * their media surface; their committed radius ships in the build-owned
     * shape kit instead (ShapeMarkup::kitCss(), enqueued by FinalizeThemeStep).
     * `sharp` removes the declaration instead of writing a redundant zero.
     *
     * @var array<string,string>
     */
    private const IMAGE_SHAPE_RADII = [
        'soft'  => '0.5rem',
        'round' => '1.25rem',
    ];

    /** @var array<string,string> */
    private const BUTTON_SHAPE_RADII = [
        'sharp' => '0',
        'soft'  => '0.5rem',
        'round' => '9999px',
    ];

    private const REQ = 'theme-json';
    private const SHAPE_REPORT_FILE = 'theme-json-shape.txt';

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
            writes: ['theme/theme.json', 'logs/' . self::SHAPE_REPORT_FILE, 'warnings.json'],
            concurrent: false,
        );
    }

    public function requests(Project $project): array
    {
        $meta = $project->readJson('meta.json');
        $heroBlueprint = DesignDirectionStep::heroBlueprintFor($project);
        $rendered = $this->renderer->render('theme-json.md', [
            'user_prompt'      => (string) ($meta['prompt'] ?? ''),
            'site_spec'        => $project->readText('siteSpec.json'),
            'design_direction' => DesignDirectionStep::readFor($project),
            'hero_sizing_context' => DesignDirectionStep::formatHeroBlueprint($heroBlueprint),
        ]);

        return [self::REQ => $this->withOptions(['prompt' => $rendered])];
    }

    public function consume(Project $project, array $results): void
    {
        $theme = $results[self::REQ] ?? null;
        if (!is_array($theme)) {
            throw new \RuntimeException('theme-json: missing model output');
        }
        $this->writeTheme($project, $theme);
    }

    public function consumeGeneratedJsonFailure(
        Project $project,
        array $results,
        array $failures,
    ): void {
        if (isset($results[self::REQ]) || !isset($failures[self::REQ])) {
            throw new \RuntimeException('theme-json: inconsistent generated JSON failure routing');
        }
        $this->writeTheme($project, [], [
            'theme/theme.json: generated JSON remained unusable after its repair attempt ('
                . $failures[self::REQ] . '); deterministic base theme delivered',
        ]);
    }

    /** @param array<mixed> $theme @param list<string> $warnings */
    private function writeTheme(Project $project, array $theme, array $warnings = []): void
    {
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
        if (array_key_exists('styles', $theme)
            && (!is_array($theme['styles'])
                || ($theme['styles'] !== [] && array_is_list($theme['styles'])))) {
            $warnings[] = 'theme/theme.json styles: authored '
                . Warnings::value($theme['styles'])
                . '; delivered build-supplied styles object'
                . '; disposition replaced malformed shape before normalization';
            $theme['styles'] = [];
        }
        if (!is_array($theme['styles'] ?? null)) {
            $theme['styles'] = [];
        }
        if (array_key_exists('spacing', $theme['styles'])
            && (!is_array($theme['styles']['spacing'])
                || ($theme['styles']['spacing'] !== []
                    && array_is_list($theme['styles']['spacing'])))) {
            $warnings[] = 'theme/theme.json styles.spacing: authored '
                . Warnings::value($theme['styles']['spacing'])
                . '; delivered {"blockGap":"var:preset|spacing|md"}'
                . '; disposition replaced malformed shape before normalization';
            $theme['styles']['spacing'] = [];
        }
        if (!is_array($theme['styles']['spacing'] ?? null)) {
            $theme['styles']['spacing'] = [];
        }
        $theme['styles']['spacing']['blockGap'] ??= 'var:preset|spacing|md';

        // Missing required slugs are filled deterministically instead of
        // aborting the build: the direction's committed hexes first, neutral
        // readable defaults otherwise. Every fill is recorded durably.
        $direction = DesignDirectionStep::dataFor($project);
        $preferred = is_array($direction['palette'] ?? null) ? $direction['palette'] : [];
        [$theme, $colorWarnings] = self::repairColors($theme, $preferred);
        $preferredType = is_array($direction['type'] ?? null) ? $direction['type'] : [];
        [$theme, $fontWarnings] = self::repairFonts($theme, $preferredType);
        [$theme, $sizeWarnings] = self::repairFontSizes($theme);

        // Last: the scaffold references the preset slugs repaired above. The
        // committed shape is then authoritative over model-authored radii.
        [$theme, $scaffoldWarnings] = self::repairScaffold($theme);
        [$theme, $shapeRepairs, $shapeWarnings] = self::repairShapeWiring(
            $theme,
            DesignDirectionStep::shapeFor($project) ?? '',
        );
        [$theme, $groupPaddingWarnings] = self::repairGroupBlockPadding($theme);
        $warnings = array_merge(
            $warnings,
            $colorWarnings,
            $fontWarnings,
            $sizeWarnings,
            $scaffoldWarnings,
            $groupPaddingWarnings,
            $shapeWarnings,
        );

        $shapeReport = ['Successful deterministic shape repairs: ' . count($shapeRepairs)];
        foreach ($shapeRepairs as $repair) {
            $shapeReport[] = '- ' . $repair;
        }
        $project->writeText('logs/' . self::SHAPE_REPORT_FILE, implode("\n", $shapeReport) . "\n");
        if ($shapeRepairs !== []) {
            Narrator::write('  [theme-json] repaired ' . count($shapeRepairs)
                . " conflicting shape declaration(s); see logs/" . self::SHAPE_REPORT_FILE . "\n");
        }

        if ($warnings !== []) {
            $project->addWarnings($this->id(), $warnings);
            echo '  [theme-json] warning: ' . count($warnings)
                . " generated theme defect(s) repaired with defaults (recorded in warnings.json)\n";
        }

        $project->writeJson('theme/theme.json', $theme);
    }

    public function run(Project $project): void
    {
        try {
            $this->consume($project, $this->llm->completeJsonBatch($this->requests($project)));
        } catch (GeneratedJsonException $e) {
            // JsonBatchRecovery distinguishes malformed/refused/truncated
            // generated content from transport and sender-contract failures.
            $this->consumeGeneratedJsonFailure($project, $e->partialResults, $e->failures);
        }
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
     * Remove vertical padding from the global core/group block style.
     *
     * Group is a recursive layout primitive: WordPress expands this path to a
     * selector matching every .wp-block-group, including structural wrappers
     * nested inside headers, sections and cards. Section-scale padding there
     * therefore compounds at every nesting level. Vertical rhythm belongs to
     * explicit block instances (SectionRhythmStep, header/footer roots and
     * authored components), while a model-authored horizontal treatment may
     * still survive here.
     *
     * Scalar padding applies to all four sides. Preserve its horizontal intent
     * by rewriting it to left/right longhands while dropping the unsafe
     * vertical default. Empty containers are pruned so theme.json retains the
     * object shapes WordPress expects. Pure and idempotent — unit-testable.
     *
     * @param array<mixed> $theme
     * @return array<mixed>
     */
    public static function normalizeGroupBlockPadding(array $theme): array
    {
        return self::repairGroupBlockPadding($theme)[0];
    }

    /**
     * normalizeGroupBlockPadding() plus a durable warning row per removed or
     * rewritten model-authored declaration, in the same grammar as every
     * sibling repair in writeTheme(). Pure — unit-testable.
     *
     * @param array<mixed> $theme
     * @return array{0:array<mixed>,1:list<string>} theme, warnings
     */
    public static function repairGroupBlockPadding(array $theme): array
    {
        $warnings = [];
        $padding = $theme['styles']['blocks']['core/group']['spacing']['padding'] ?? null;
        $pathExists = isset($theme['styles']['blocks']['core/group']['spacing'])
            && is_array($theme['styles']['blocks']['core/group']['spacing'])
            && array_key_exists('padding', $theme['styles']['blocks']['core/group']['spacing']);
        if (!$pathExists) {
            return [$theme, $warnings];
        }

        if (is_string($padding) || is_int($padding) || is_float($padding)) {
            $delivered = ['left' => $padding, 'right' => $padding];
            $theme['styles']['blocks']['core/group']['spacing']['padding'] = $delivered;
            $warnings[] = 'theme/theme.json styles.blocks.core/group.spacing.padding: authored '
                . Warnings::value($padding) . '; delivered ' . Warnings::value($delivered)
                . '; disposition rewrote the four-side shorthand to horizontal longhands because its vertical'
                . ' default compounds inside every nested Group';
            return [$theme, $warnings];
        }
        if (!is_array($padding) || ($padding !== [] && array_is_list($padding))) {
            return [$theme, $warnings];
        }

        foreach (['top', 'bottom'] as $side) {
            if (!array_key_exists($side, $padding)) {
                continue;
            }
            $warnings[] = "theme/theme.json styles.blocks.core/group.spacing.padding.{$side}: authored "
                . Warnings::value($padding[$side])
                . '; delivered removed'
                . '; disposition removed recursive vertical Group padding that compounds inside every nested Group';
        }
        unset(
            $theme['styles']['blocks']['core/group']['spacing']['padding']['top'],
            $theme['styles']['blocks']['core/group']['spacing']['padding']['bottom'],
        );
        if ($theme['styles']['blocks']['core/group']['spacing']['padding'] === []) {
            unset($theme['styles']['blocks']['core/group']['spacing']['padding']);
        }
        if ($theme['styles']['blocks']['core/group']['spacing'] === []) {
            unset($theme['styles']['blocks']['core/group']['spacing']);
        }
        if ($theme['styles']['blocks']['core/group'] === []) {
            unset($theme['styles']['blocks']['core/group']);
        }
        if ($theme['styles']['blocks'] === []) {
            unset($theme['styles']['blocks']);
        }

        return [$theme, $warnings];
    }

    /**
     * Ensure every required palette slug exists, filling gaps from the design
     * direction's committed hexes and then the neutral fallbacks. Malformed
     * entries are removed at the smallest unit and recorded before a required
     * slug is replaced. Pure — unit-testable.
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
        $entries = [];
        $nonObjects = 0;
        foreach ($palette as $entry) {
            if (!is_array($entry)) {
                $nonObjects++;
                continue;
            }
            $slug = is_string($entry['slug'] ?? null) ? trim($entry['slug']) : '';
            if ($slug === '') {
                $warnings[] = 'theme.json palette: entry with missing or invalid slug '
                    . Warnings::value($entry['slug'] ?? null) . ' removed';
                continue;
            }
            $color = $entry['color'] ?? null;
            if (!is_string($color) || preg_match('/^#[0-9A-F]{3}(?:[0-9A-F]{3})?$/i', trim($color)) !== 1) {
                $warnings[] = "theme.json palette slug '{$slug}': invalid color "
                    . Warnings::value($color) . '; malformed entry removed';
                continue;
            }
            $entry['slug'] = $slug;
            $entry['color'] = trim($color);
            $entries[] = $entry;
        }
        if ($nonObjects > 0) {
            $warnings[] = "theme.json palette: removed {$nonObjects} malformed (non-object) entr"
                . ($nonObjects === 1 ? 'y' : 'ies');
        }
        $palette = $entries;
        $slugs = array_column($palette, 'slug');
        foreach (self::REQUIRED_COLORS as $needed) {
            if (in_array($needed, $slugs, true)) {
                continue;
            }
            $rawPreferred = $preferredHexes[$needed] ?? null;
            $preferred = is_string($rawPreferred) ? strtoupper(trim($rawPreferred)) : '';
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
    public static function repairFonts(array $theme, array $preferredType = []): array
    {
        $warnings = [];
        $preferred = [];
        foreach (self::REQUIRED_FONTS as $slot) {
            $typeSlot = is_array($preferredType[$slot] ?? null) ? $preferredType[$slot] : [];
            $family = is_string($typeSlot['family'] ?? null) ? trim($typeSlot['family']) : '';
            if (
                $family !== ''
                && preg_match("/^\\p{L}[\\p{L}\\p{N} .&'_-]{0,99}$/u", $family) === 1
            ) {
                $preferred[$slot] = $family;
            } elseif ($family !== '') {
                $warnings[] = 'designDirection.json: type.' . $slot . '.family authored value '
                    . Warnings::value($family)
                    . '; delivered removed; disposition invalid family name could not be applied to theme.json';
            }
        }

        $families = $theme['settings']['typography']['fontFamilies'] ?? null;
        if (!is_array($families)) {
            $warnings[] = 'theme.json missing settings.typography.fontFamilies; rebuilt with system stacks';
            $families = [];
        }
        $entries = [];
        $nonObjects = 0;
        foreach ($families as $entry) {
            if (!is_array($entry)) {
                $nonObjects++;
                continue;
            }
            $slug = is_string($entry['slug'] ?? null) ? trim($entry['slug']) : '';
            if ($slug === '') {
                $warnings[] = 'theme.json fontFamilies: entry with missing or invalid slug '
                    . Warnings::value($entry['slug'] ?? null) . ' removed';
                continue;
            }
            $family = $entry['fontFamily'] ?? null;
            if (!is_string($family) || trim($family) === '') {
                $warnings[] = "theme.json fontFamilies slug '{$slug}': invalid fontFamily "
                    . Warnings::value($family) . '; malformed entry removed';
                continue;
            }
            $entry['slug'] = $slug;
            $entry['fontFamily'] = trim($family);
            if (isset($preferred[$slug])) {
                $entry['fontFamily'] = self::replacePrimaryFamily($entry['fontFamily'], $preferred[$slug]);
            }
            $entries[] = $entry;
        }
        if ($nonObjects > 0) {
            $warnings[] = "theme.json fontFamilies: removed {$nonObjects} malformed (non-object) entr"
                . ($nonObjects === 1 ? 'y' : 'ies');
        }
        $families = $entries;
        $slugs = array_column($families, 'slug');
        foreach (self::REQUIRED_FONTS as $needed) {
            if (in_array($needed, $slugs, true)) {
                continue;
            }
            $stack = isset($preferred[$needed])
                ? self::replacePrimaryFamily(self::FALLBACK_FONTS[$needed], $preferred[$needed])
                : self::FALLBACK_FONTS[$needed];
            $families[] = ['slug' => $needed, 'name' => ucfirst($needed), 'fontFamily' => $stack];
            $warnings[] = isset($preferred[$needed])
                ? "theme.json fontFamilies missing slug '{$needed}'; filled from designDirection.json with {$stack}"
                : "theme.json fontFamilies missing slug '{$needed}'; filled with the system stack";
        }
        $theme['settings']['typography']['fontFamilies'] = $families;
        return [$theme, $warnings];
    }

    private static function replacePrimaryFamily(string $stack, string $family): string
    {
        $parts = explode(',', $stack, 2);
        $fallback = isset($parts[1]) && trim($parts[1]) !== ''
            ? ', ' . trim($parts[1])
            : ', system-ui, sans-serif';
        return '"' . $family . '"' . $fallback;
    }

    /**
     * Ensure every font-size slug the scaffold references exists. A preset
     * that is usable but unnamed keeps its authored size — only the name is
     * synthesized from the slug. Pure — unit-testable.
     *
     * @param array<mixed> $theme
     * @return array{0:array<mixed>,1:list<string>} theme, warnings
     */
    public static function repairFontSizes(array $theme): array
    {
        $warnings = [];
        $sizes = $theme['settings']['typography']['fontSizes'] ?? null;
        if (!is_array($sizes) || ($sizes !== [] && !array_is_list($sizes))) {
            if ($sizes !== null) {
                $warnings[] = 'theme.json settings.typography.fontSizes: invalid container '
                    . Warnings::value($sizes) . '; rebuilt from the default scale';
            }
            $sizes = [];
        }

        $entries = [];
        $seen = [];
        foreach ($sizes as $entry) {
            if (!is_array($entry)) {
                $warnings[] = 'theme.json fontSizes: removed malformed (non-object) entry '
                    . Warnings::value($entry);
                continue;
            }
            $slug = is_string($entry['slug'] ?? null) ? trim($entry['slug']) : '';
            if ($slug === '') {
                $warnings[] = 'theme.json fontSizes: entry with missing or invalid slug '
                    . Warnings::value($entry['slug'] ?? null) . ' removed';
                continue;
            }
            $size = $entry['size'] ?? null;
            if (!is_string($size) || !self::isSafeFontSize($size)) {
                $warnings[] = "theme.json fontSizes slug '{$slug}': invalid size "
                    . Warnings::value($size) . '; malformed entry removed';
                continue;
            }
            $entry['slug'] = $slug;
            $entry['size'] = trim($size);
            if (isset($seen[$slug])) {
                $warnings[] = "theme.json fontSizes duplicate slug '{$slug}': authored size "
                    . Warnings::value($entry['size']) . '; delivered first authored size '
                    . Warnings::value($seen[$slug]) . '; disposition removed duplicate';
                continue;
            }
            // Only the name is missing — keep the authored size rather than
            // discarding a usable preset over a cosmetic field.
            if (!is_string($entry['name'] ?? null) || trim($entry['name']) === '') {
                $entry['name'] = ucwords(str_replace(['-', '_'], ' ', $slug));
                $warnings[] = "theme.json fontSizes slug '{$slug}': missing name; "
                    . "kept authored size {$entry['size']}, synthesized name '{$entry['name']}'";
            }
            $seen[$slug] = $entry['size'];
            $entries[] = $entry;
        }

        $slugs = array_column($entries, 'slug');
        foreach (self::FONT_SIZE_PROFILE as $fallback) {
            if (in_array($fallback['slug'], $slugs, true)) {
                continue;
            }
            $entries[] = $fallback;
            $warnings[] = "theme.json fontSizes missing slug '{$fallback['slug']}'; "
                . "filled with {$fallback['size']}";
        }

        $theme['settings']['typography']['fontSizes'] = $entries;
        return [$theme, $warnings];
    }

    /**
     * The prompt's bounded font-size grammar: a CSS length/percentage, or a
     * composition of the safe sizing functions it asks the model to use.
     */
    private static function isSafeFontSize(string $size): bool
    {
        $size = trim($size);
        if ($size === '' || strlen($size) > 160) {
            return false;
        }
        $number = '(?:\d+(?:\.\d+)?|\.\d+)';
        $unit = '(?:px|r?em|vw|vh|vmin|vmax|%|ch|ex|cap|ic|lh|rlh|pt|pc|in|cm|mm|q)';
        $length = '(?:0|' . $number . $unit . ')';
        $variable = 'var\(\s*--[A-Za-z_][A-Za-z0-9_-]*(?:\s*,\s*' . $length . ')?\s*\)';
        $calculation = 'calc\(\s*' . $length . '(?:\s*[+-]\s*' . $length . ')+\s*\)';
        $component = '(?:' . $length . '|' . $variable . '|' . $calculation . ')';

        return preg_match('/^' . $component . '$/i', $size) === 1
            || preg_match(
                '/^clamp\(\s*' . $component . '\s*,\s*' . $component . '\s*,\s*' . $component . '\s*\)$/i',
                $size,
            ) === 1
            || preg_match('/^(?:min|max)\(\s*' . $component . '(?:\s*,\s*' . $component . ')+\s*\)$/i', $size) === 1;
    }

    /**
     * Fill the build-supplied wiring, letting any well-shaped model-authored
     * leaf win.
     * Pure — unit-testable.
     *
     * @param array<mixed> $theme
     * @return array<mixed>
     */
    public static function applyScaffold(array $theme): array
    {
        return self::repairScaffold($theme)[0];
    }

    /**
     * Fill scaffold omissions and repair wrong-shaped scaffold-owned nodes.
     *
     * @param array<mixed> $theme
     * @return array{0:array<mixed>,1:list<string>} theme, warnings
     */
    public static function repairScaffold(array $theme): array
    {
        [$theme, $warnings] = self::removeUnverifiedContextColors($theme);
        $shapeWarnings = [];
        $theme = self::mergeScaffoldDefaultsAtPath(self::SCAFFOLD, $theme, '', $shapeWarnings);
        return [$theme, array_merge($warnings, $shapeWarnings)];
    }

    /**
     * Execute the design direction's committed corner language as authoritative
     * build wiring for contained images and buttons. A conflicting authored
     * radius is repaired and recorded in the step report; unrelated style
     * siblings survive. Fully resolved conflicts do not enter warnings.json.
     * `sharp` removes the image radius and gives buttons a zero radius, `soft`
     * gives both a subtle radius, and `round` gives contained images a decisive
     * radius with pill buttons. Cover and media-text corners are owned by the
     * build-owned shape kit stylesheet (FinalizeThemeStep), so authored radii
     * on those blocks are repaired here without an authoritative base leaf;
     * FixBlocksStep adds a local zero-radius override to alignfull core/image
     * blocks so full-bleed media also stays square. A direction persisted
     * before the shape field existed remains a complete no-op.
     *
     * Pure — unit-testable.
     *
     * @param array<mixed> $theme
     * @return array{0:array<mixed>,1:list<string>,2:list<string>}
     *         theme, successful repair notes, durable warnings
     */
    public static function repairShapeWiring(array $theme, string $shape): array
    {
        $buttonRadius = self::BUTTON_SHAPE_RADII[$shape] ?? null;
        if ($buttonRadius === null) {
            return [$theme, [], []];
        }

        $repairs = [];
        $warnings = [];
        $theme = self::repairCompetingShapeOverrides(
            $theme,
            $shape,
            $repairs,
            $warnings,
        );
        if ($shape === 'sharp') {
            [$theme] = self::removeCommittedShapeValueAtPath(
                $theme,
                ['styles', 'blocks', 'core/image', 'border', 'radius'],
                0,
                'styles.blocks.core/image.border.radius',
                $shape,
                $repairs,
            );
        }
        $styles = [];
        $imageRadius = self::IMAGE_SHAPE_RADII[$shape] ?? null;
        if ($imageRadius !== null) {
            $styles['blocks'] = [
                'core/image' => ['border' => ['radius' => $imageRadius]],
            ];
        }
        $styles['elements'] = [
            'button' => ['border' => ['radius' => $buttonRadius]],
        ];

        $theme = self::enforceCommittedShapeAtPath(
            ['styles' => $styles],
            $theme,
            '',
            $shape,
            $repairs,
        );
        return [$theme, $repairs, $warnings];
    }

    /**
     * Remove every structured or custom-CSS radius that WordPress emits after
     * an authoritative base image/button rule. This walks pseudo/responsive
     * states, block-style variations, nested element styles, and variation
     * inner-block styles using the same recursive shapes as the global-styles
     * engine. The two authoritative base leaves themselves are preserved for
     * repairShapeWiring() to enforce below.
     *
     * @param array<mixed> $theme
     * @param list<string> $repairs
     * @param list<string> $warnings
     * @return array<mixed>
     */
    private static function repairCompetingShapeOverrides(
        array $theme,
        string $shape,
        array &$repairs,
        array &$warnings,
    ): array {
        $styles = $theme['styles'] ?? null;
        if (!is_array($styles) || ($styles !== [] && array_is_list($styles))) {
            return $theme;
        }
        $theme['styles'] = self::repairShapeStyleNode(
            $styles,
            'styles',
            null,
            false,
            $shape,
            $repairs,
            $warnings,
        );
        return $theme;
    }

    /**
     * @param array<mixed> $node
     * @param 'image'|'button'|'cover'|'media-text'|null $target
     * @param list<string> $repairs
     * @param list<string> $warnings
     * @return array<mixed>
     */
    private static function repairShapeStyleNode(
        array $node,
        string $path,
        ?string $target,
        bool $authoritativeBase,
        string $shape,
        array &$repairs,
        array &$warnings,
    ): array {
        if (is_string($node['css'] ?? null)) {
            $authoredCss = $node['css'];
            $scopedDeclarationList = $path !== 'styles';
            $selectorOwned = static fn (string $selector): bool =>
                CssChecks::selectorTargetsShape($selector)
                || ($target !== null && self::selectorTargetsImplicitStyleRoot($selector));
            $authoredDeclarations = CssChecks::shapeAffectingDeclarations(
                $authoredCss,
                $selectorOwned,
                $scopedDeclarationList,
                $target !== null,
            );
            if ($authoredDeclarations !== []) {
                $unsafe = array_filter(
                    $authoredDeclarations,
                    static fn (array $declaration): bool => !$declaration['structurallySafe'],
                );
                if ($unsafe !== []) {
                    unset($node['css']);
                    $warnings[] = "theme/theme.json {$path}.css: authored "
                        . Warnings::value($authoredCss)
                        . '; delivered removed; disposition structurally malformed custom CSS contained '
                        . 'an image/button corner override that could not be isolated safely';
                } else {
                    $ownedStarts = array_fill_keys(array_column($authoredDeclarations, 'start'), true);
                    [$deliveredCss, $dropped] = CssChecks::dropDeclarations(
                        $authoredCss,
                        static fn (array $declaration): bool => isset($ownedStarts[$declaration['start']]),
                        $scopedDeclarationList,
                    );
                    $residual = CssChecks::shapeAffectingDeclarations(
                        $deliveredCss,
                        $selectorOwned,
                        $scopedDeclarationList,
                        $target !== null,
                    );
                    if ($residual !== [] || count($dropped) !== count($authoredDeclarations)) {
                        unset($node['css']);
                        $warnings[] = "theme/theme.json {$path}.css: authored "
                            . Warnings::value($authoredCss)
                            . '; delivered removed; disposition custom CSS contained an image/button '
                            . 'corner override that could not be isolated safely';
                    } else {
                        if (trim($deliveredCss) === '') {
                            unset($node['css']);
                        } else {
                            $node['css'] = $deliveredCss;
                        }
                        foreach ($dropped as $declaration) {
                            $message = "theme/theme.json {$path}.css: authored declaration "
                                . Warnings::value(trim($declaration['raw']))
                                . '; delivered removed; disposition removed custom-CSS corner override '
                                . 'that bypasses the committed shape scope';
                            $repairs[] = $message . " for authoritative {$shape} "
                                . ($target ?? 'image/button selector') . ' styling';
                        }
                    }
                }
            }
        }

        if ($target !== null
            && !$authoritativeBase
            && is_array($node['border'] ?? null)
            && (($node['border'] ?? []) === [] || !array_is_list($node['border']))
            && array_key_exists('radius', $node['border'])
        ) {
            $repairs[] = "theme/theme.json {$path}.border.radius: authored "
                . Warnings::value($node['border']['radius'])
                . '; delivered removed'
                . "; disposition removed conflicting radius to enforce committed {$shape} shape";
            unset($node['border']['radius']);
            if ($node['border'] === []) {
                unset($node['border']);
            }
        }

        $blocks = $node['blocks'] ?? null;
        if (is_array($blocks) && ($blocks === [] || !array_is_list($blocks))) {
            foreach ($blocks as $block => $child) {
                if (!is_string($block)
                    || !is_array($child)
                    || ($child !== [] && array_is_list($child))
                ) {
                    continue;
                }
                $childPath = $path . '.blocks.' . $block;
                $childTarget = match ($block) {
                    'core/image' => 'image',
                    'core/button' => 'button',
                    'core/cover' => 'cover',
                    'core/media-text' => 'media-text',
                    default => null,
                };
                $blocks[$block] = self::repairShapeStyleNode(
                    $child,
                    $childPath,
                    $childTarget,
                    $childPath === 'styles.blocks.core/image',
                    $shape,
                    $repairs,
                    $warnings,
                );
            }
            $node['blocks'] = $blocks;
        }

        $elements = $node['elements'] ?? null;
        if (is_array($elements) && ($elements === [] || !array_is_list($elements))) {
            foreach ($elements as $element => $child) {
                if (!is_string($element)
                    || !is_array($child)
                    || ($child !== [] && array_is_list($child))
                ) {
                    continue;
                }
                $childPath = $path . '.elements.' . $element;
                // Element selectors own their own rendered surface. A caption
                // nested under core/image, for example, is not image-corner
                // geometry and must not inherit the parent target.
                $childTarget = $element === 'button' ? 'button' : null;
                $elements[$element] = self::repairShapeStyleNode(
                    $child,
                    $childPath,
                    $childTarget,
                    $childPath === 'styles.elements.button',
                    $shape,
                    $repairs,
                    $warnings,
                );
            }
            $node['elements'] = $elements;
        }

        $variations = $node['variations'] ?? null;
        if (is_array($variations) && ($variations === [] || !array_is_list($variations))) {
            foreach ($variations as $variation => $child) {
                if (!is_string($variation)
                    || !is_array($child)
                    || ($child !== [] && array_is_list($child))
                ) {
                    continue;
                }
                $variations[$variation] = self::repairShapeStyleNode(
                    $child,
                    $path . '.variations.' . $variation,
                    $target,
                    false,
                    $shape,
                    $repairs,
                    $warnings,
                );
            }
            $node['variations'] = $variations;
        }

        foreach ($node as $state => $child) {
            if (!is_string($state)
                || (!str_starts_with($state, ':')
                    && !str_starts_with($state, '@')
                    && !in_array($state, ['mobile', 'tablet', 'desktop'], true))
                || !is_array($child)
                || ($child !== [] && array_is_list($child))
            ) {
                continue;
            }
            $node[$state] = self::repairShapeStyleNode(
                $child,
                $path . '.' . $state,
                $target,
                false,
                $shape,
                $repairs,
                $warnings,
            );
        }

        return $node;
    }

    private static function selectorTargetsImplicitStyleRoot(string $selector): bool
    {
        return CssChecks::selectorTargetsSubject($selector, '&');
    }

    /**
     * Remove one build-owned shape leaf, pruning only containers made empty by
     * that removal. The boolean reports whether the requested leaf existed.
     *
     * @param array<mixed> $model
     * @param list<string> $path
     * @param list<string> $repairs
     * @return array{0:array<mixed>,1:bool}
     */
    private static function removeCommittedShapeValueAtPath(
        array $model,
        array $path,
        int $offset,
        string $label,
        string $shape,
        array &$repairs,
    ): array {
        $key = $path[$offset] ?? null;
        if (!is_string($key) || !array_key_exists($key, $model)) {
            return [$model, false];
        }

        if ($offset === count($path) - 1) {
            $repairs[] = "theme/theme.json {$label}: authored "
                . Warnings::value($model[$key])
                . '; delivered removed'
                . "; disposition removed conflicting radius to enforce committed {$shape} shape";
            unset($model[$key]);
            return [$model, true];
        }

        $child = $model[$key];
        if (!is_array($child) || ($child !== [] && array_is_list($child))) {
            return [$model, false];
        }
        [$child, $removed] = self::removeCommittedShapeValueAtPath(
            $child,
            $path,
            $offset + 1,
            $label,
            $shape,
            $repairs,
        );
        if (!$removed) {
            return [$model, false];
        }
        if ($child === []) {
            unset($model[$key]);
        } else {
            $model[$key] = $child;
        }
        return [$model, true];
    }

    /**
     * Recursively install build-owned shape leaves, replacing conflicts rather
     * than letting model-authored radii override the committed direction.
     *
     * @param array<mixed> $commitment
     * @param array<mixed> $model
     * @param list<string> $repairs
     * @return array<mixed>
     */
    private static function enforceCommittedShapeAtPath(
        array $commitment,
        array $model,
        string $path,
        string $shape,
        array &$repairs,
    ): array {
        foreach ($commitment as $key => $committedValue) {
            $currentPath = $path === '' ? (string) $key : $path . '.' . $key;
            if (!array_key_exists($key, $model)) {
                $model[$key] = $committedValue;
                continue;
            }

            $modelValue = $model[$key];
            $committedIsMap = is_array($committedValue)
                && ($committedValue === [] || !array_is_list($committedValue));
            $modelIsMap = is_array($modelValue)
                && ($modelValue === [] || !array_is_list($modelValue));
            if ($committedIsMap && $modelIsMap) {
                $model[$key] = self::enforceCommittedShapeAtPath(
                    $committedValue,
                    $modelValue,
                    $currentPath,
                    $shape,
                    $repairs,
                );
                continue;
            }
            if ($modelValue === $committedValue) {
                continue;
            }

            $repairs[] = "theme/theme.json {$currentPath}: authored "
                . Warnings::value($modelValue) . '; delivered '
                . Warnings::value($committedValue)
                . ($committedIsMap
                    ? "; disposition replaced malformed container to enforce committed {$shape} shape"
                    : "; disposition replaced conflicting radius to enforce committed {$shape} shape");
            $model[$key] = $committedValue;
        }

        return $model;
    }

    /**
     * Remove theme-level text colors the rendered-background contrast pass
     * cannot see. The declaration is the smallest isolatable unit; sibling
     * color properties and typography survive.
     *
     * @param array<mixed> $theme
     * @return array{0:array<mixed>,1:list<string>} theme, warnings
     */
    public static function removeUnverifiedContextColors(array $theme): array
    {
        $warnings = [];
        foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'caption'] as $element) {
            self::removeContextTextColor(
                $theme,
                ['styles', 'elements', $element, 'color'],
                "styles.elements.{$element}.color",
                $warnings,
            );
        }
        $blocks = $theme['styles']['blocks'] ?? null;
        if (is_array($blocks) && ($blocks === [] || !array_is_list($blocks))) {
            foreach (array_keys($blocks) as $block) {
                if (!is_string($block)) {
                    continue;
                }
                self::removeContextTextColor(
                    $theme,
                    ['styles', 'blocks', $block, 'color'],
                    "styles.blocks.{$block}.color",
                    $warnings,
                );
            }
        }
        return [$theme, $warnings];
    }

    /**
     * @param array<mixed> $theme
     * @param list<string> $path
     * @param list<string> $warnings
     */
    private static function removeContextTextColor(
        array &$theme,
        array $path,
        string $label,
        array &$warnings,
    ): void {
        $leaf = array_pop($path);
        if (!is_string($leaf)) {
            return;
        }
        $parent =& $theme;
        foreach ($path as $key) {
            if (!is_array($parent) || !array_key_exists($key, $parent)) {
                return;
            }
            $parent =& $parent[$key];
        }
        if (!is_array($parent) || !array_key_exists($leaf, $parent)) {
            return;
        }
        if (!is_array($parent[$leaf])
            || ($parent[$leaf] !== [] && array_is_list($parent[$leaf]))) {
            $warnings[] = "theme/theme.json {$label}: authored "
                . Warnings::value($parent[$leaf])
                . '; delivered removed'
                . '; disposition removed malformed context-free color container';
            unset($parent[$leaf]);
            return;
        }
        if (!array_key_exists('text', $parent[$leaf])) {
            return;
        }
        $warnings[] = "theme/theme.json {$label}.text: authored "
            . Warnings::value($parent[$leaf]['text'])
            . '; delivered removed'
            . '; disposition removed context-free text color invisible to contrast repair';
        unset($parent[$leaf]['text']);
        if ($parent[$leaf] === []) {
            unset($parent[$leaf]);
        }
    }

    /**
     * Recursively fill associative-map omissions while preserving every
     * well-shaped model-authored leaf. An empty array is also PHP's
     * representation of a decoded empty JSON object, so it receives the
     * scaffold map.
     *
     * Pure — unit-testable.
     *
     * @param array<mixed> $scaffold
     * @param array<mixed> $model
     * @return array<mixed>
     */
    public static function mergeScaffoldDefaults(array $scaffold, array $model): array
    {
        $warnings = [];
        return self::mergeScaffoldDefaultsAtPath($scaffold, $model, '', $warnings);
    }

    /**
     * @param array<mixed> $scaffold
     * @param array<mixed> $model
     * @param list<string> $warnings
     * @return array<mixed>
     */
    private static function mergeScaffoldDefaultsAtPath(
        array $scaffold,
        array $model,
        string $path,
        array &$warnings,
    ): array {
        foreach ($scaffold as $key => $scaffoldValue) {
            $currentPath = $path === '' ? (string) $key : $path . '.' . $key;
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
                $model[$key] = self::mergeScaffoldDefaultsAtPath(
                    $scaffoldValue,
                    $modelValue,
                    $currentPath,
                    $warnings,
                );
                continue;
            }
            if (($scaffoldIsMap && !$modelIsMap)
                || (!is_array($scaffoldValue)
                    && get_debug_type($modelValue) !== get_debug_type($scaffoldValue))) {
                $warnings[] = "theme/theme.json {$currentPath}: authored "
                    . Warnings::value($modelValue) . '; delivered '
                    . Warnings::value($scaffoldValue)
                    . '; disposition replaced malformed shape with scaffold default';
                $model[$key] = $scaffoldValue;
            }
        }

        return $model;
    }
}
