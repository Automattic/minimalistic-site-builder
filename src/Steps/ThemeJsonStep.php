<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter;
use Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment;
use Automattic\SiteBuild\BlockSerializer\Html\HtmlNode;
use Automattic\SiteBuild\BlockSerializer\Html\Selector;
use Automattic\SiteBuild\CssTokenExtractor;
use Automattic\SiteBuild\FontCatalog;
use Automattic\SiteBuild\GeneratedJsonException;
use Automattic\SiteBuild\GeneratedJsonFallbackStep;
use Automattic\SiteBuild\ContrastMath;
use Automattic\SiteBuild\CssChecks;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\LlmOptions;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\Warnings;
use Throwable;

/**
 * Step (LLM): generate the block theme's theme.json.
 *
 * Input:  meta.json (user prompt) + siteSpec.json (factual info) +
 *         designDirection.json (the committed creative and typography floor).
 *         The model translates that direction into theme.json tokens.
 * Output: theme/theme.json — palette, typography, spacing, layout, element styles.
 *
 * Validates the structure the templates depend on (version 3, the five color
 * slugs, the heading/body font slugs, and an optional accent family) and
 * repairs drift deterministically: missing slugs are filled from the design
 * direction's committed values, then neutral defaults, and heading/body
 * families and palette hexes that disagree with the direction are written
 * back. When an accent family ships, captions pick it up even if a section
 * forgot fontFamily:accent. A fill, and a writeback a contrast floor
 * rejects, are recorded in warnings.json; an applied writeback is a receipt
 * in logs/theme-json-direction-bind.txt instead. A missing slug never
 * aborts the build.
 *
 * HTML-first composition mode declares and consumes design/site.css token
 * evidence. Legacy mode ignores any stale design artifact from an earlier run.
 */
final class ThemeJsonStep implements GeneratedJsonFallbackStep
{
    use LlmOptions;

    private const REQUIRED_COLORS = ['base', 'contrast', 'primary', 'secondary', 'accent'];

    /**
     * The per-slug contrast floors against `base` that prompts/theme-json.md
     * states as non-negotiable, mirrored here so the direction writeback can
     * tell a model hex that was moved to clear one from ordinary drift.
     */
    private const CONTRAST_FLOORS = [
        'contrast' => 7.0,
        'primary' => ContrastMath::NORMAL_TEXT,
        'secondary' => ContrastMath::NORMAL_TEXT,
        'accent' => ContrastMath::NORMAL_TEXT,
    ];
    private const REQUIRED_FONTS = ['heading', 'body'];
    private const OPTIONAL_FONTS = ['accent'];

    /** @var array{contentSize:string,wideSize:string} */
    private const FALLBACK_LAYOUT_WIDTHS = [
        'contentSize' => '800px',
        'wideSize' => '1280px',
    ];

    /**
     * Fixed desktop reference chosen for fluid design carriers in this slice.
     * Their resolved width will drift at other viewports until the layout
     * contract can carry a fluid expression instead of one theme.json length.
     */
    private const CONTENT_WIDTH_REFERENCE_VIEWPORT = 1366.0;
    private const CONTENT_WIDTH_ROOT_FONT_SIZE = 16.0;

    /** Element selectors whose box is the text itself, never a visual surface. */
    private const TEXT_SHADOW_ELEMENTS = [
        'caption', 'cite', 'heading', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'link',
    ];

    /** Block selectors whose root box is authored copy rather than a card/media surface. */
    private const TEXT_SHADOW_BLOCKS = [
        'core/heading', 'core/list', 'core/list-item', 'core/paragraph',
        'core/post-title', 'core/pullquote', 'core/quote', 'core/site-tagline',
        'core/site-title', 'core/verse',
    ];

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
        // The accent slot only ever donates its tail: replacePrimaryFamily
        // swaps the first entry for the direction's family, so whatever sits
        // here first is discarded. The tail is the neutral stack rather than
        // `cursive` because an accent face is whatever the direction picked —
        // Caveat is cursive, Bebas Neue is not — and guessing the wrong
        // generic is worse than degrading to the same stack as the siblings.
        'accent'  => 'system-ui, sans-serif',
    ];
    /**
     * The type scale the scaffold wires roles to. Every slug SCAFFOLD does
     * reference must exist here, or it would leave a dangling
     * var:preset|font-size|… — PresetReferences does scan theme.json's own
     * strings and would report it, but as a build-time problem rather than a
     * rendered site. `lead` is deliberately unreferenced by SCAFFOLD: it stays
     * in the scale as an editor choice, but nothing is wired to it, because
     * choosing which blocks are larger than body text is the design's call.
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
     * xs is the tight intra-component text rhythm (an eyebrow/heading/line
     * stack inside one card or list row — BIGR-777). sm/md are component-level
     * gaps. lg/xl/xxl are the compact, standard, and spacious section-padding
     * choices. Their fluid ranges prevent the largest token from becoming
     * fixed 128px padding on mobile or growing beyond 112px on wide screens.
     *
     * @var list<array{slug: string, name: string, size: string}>
     */
    private const SPACING_PROFILE = [
        ['slug' => 'xs', 'name' => 'Extra Small', 'size' => 'clamp(0.25rem, 0.5vw, 0.5rem)'],
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
                // No fontSize here. A size for a block the design left unstyled
                // is an aesthetic choice, not wiring: assigning `lead` rendered
                // quotes at 22px where the design's own render is 18px, because
                // the design authors no quote size and the quote inherits body.
                // Six of eight corpus designs author none either. Omitting the
                // key lets that inheritance stand; a design that does author a
                // quote size still wins through its own delivered CSS.
                'core/quote' => [
                    'typography' => [
                        'fontFamily' => 'var:preset|font-family|body',
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
     * The provisioned root inline gutter, from the canonical spacing scale.
     * md is a component-level inset (~1.5–2rem), matching the ~1rem-per-side
     * page gutter the designs author on their outermost container.
     */
    private const ROOT_GUTTER = 'var:preset|spacing|md';

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

    /**
     * Applied direction writebacks. warnings.json is the list of defects the
     * build delivered through (Project::addWarnings), which a later repair pass
     * consumes; a correction that succeeded is not one of those, and logging it
     * there hands the next pass work that is already done.
     */
    private const BIND_REPORT_FILE = 'theme-json-direction-bind.txt';

    public function __construct(
        private Llm $llm,
        private PromptRenderer $renderer,
        private ?string $model = null,
        private ?float $temperature = null,
        private bool $htmlFirst = false,
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
        $reads = ['meta.json', 'siteSpec.json', 'designDirection.json'];
        if ($this->htmlFirst) {
            $reads[] = 'design/site.css';
        }

        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: $reads,
            writes: [
                'theme/theme.json',
                'logs/' . self::SHAPE_REPORT_FILE,
                'logs/' . self::BIND_REPORT_FILE,
                'warnings.json',
            ],
            concurrent: false,
        );
    }

    public function requests(Project $project): array
    {
        $meta = $project->readJson('meta.json');
        $designDirection = DesignDirectionStep::readFor($project);
        if ($this->htmlFirst) {
            // --from can resume mid-graph on a project that never ran design-preview,
            // and readText() throws on a missing file. An empty stylesheet extracts to
            // empty tokens, which the sparse_tokens branch below already degrades.
            $designCss = $project->exists('design/site.css')
                ? $project->readText('design/site.css')
                : '';
            $tokens = CssTokenExtractor::extract($designCss);
            if ($tokens['palette'] !== [] && $tokens['fonts'] !== []) {
                $designDirection .= "\n\n"
                    . "DESIGN CSS TOKENS (authoritative evidence from design/site.css):\n"
                    . "Use these actual design colors, font stacks, and spacing values when naming "
                    . "the required theme.json slots. Usage count ranks palette importance. Do not "
                    . "invent replacements for representable extracted values.\n"
                    . 'Palette: ' . implode(', ', array_map(
                        static fn (array $entry): string => "{$entry['color']} ({$entry['count']} uses)",
                        $tokens['palette'],
                    )) . "\n"
                    . "Fonts:\n- " . implode("\n- ", $tokens['fonts']) . "\n"
                    . 'Spacing: ' . ($tokens['spacing'] === [] ? '(none)' : implode(', ', $tokens['spacing']));
            } else {
                $project->addWarnings($this->id(), [
                    'design/site.css at stylesheet root: sparse_tokens; authored '
                        . json_encode([
                            'palette_count' => count($tokens['palette']),
                            'font_count' => count($tokens['fonts']),
                            'spacing_count' => count($tokens['spacing']),
                        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                        . '; delivered design-direction values; disposition sparse token evidence omitted',
                ]);
            }
        }
        $heroBlueprint = DesignDirectionStep::heroBlueprintFor($project);
        $rendered = $this->renderer->render('theme-json.md', [
            'user_prompt'      => (string) ($meta['prompt'] ?? ''),
            'site_spec'        => $project->readText('siteSpec.json'),
            'design_direction' => $designDirection,
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
        [$theme, $layoutWarnings] = self::normalizeLayoutWidths(
            $theme,
            $this->htmlFirst && $project->exists('design/site.css')
                ? $project->readText('design/site.css')
                : null,
        );

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
        // After the shape repairs above so a malformed styles.spacing records
        // its warning before normalizeRootPadding's silent guard repairs it.
        $theme = self::normalizeRootPadding($theme);

        // Guarantee a root inline gutter so useRootPaddingAwareAlignments gives
        // constrained/wide content a side inset. SectionLayoutStep strips each
        // section's own inline left/right padding expecting this root gutter to
        // own the inline axis; without it constrained sections butt the edge.
        $theme = self::provisionRootGutter($theme);
        $theme = self::normalizeRootPadding($theme);

        // Missing required slugs are filled deterministically instead of
        // aborting the build: the direction's committed hexes first, neutral
        // readable defaults otherwise. Every fill is recorded durably.
        $direction = DesignDirectionStep::dataFor($project);
        $preferred = is_array($direction['palette'] ?? null) ? $direction['palette'] : [];
        [$theme, $colorWarnings, $colorRepairs] = self::repairColors($theme, $preferred);
        $preferredType = is_array($direction['type'] ?? null) ? $direction['type'] : [];
        [$theme, $fontWarnings, $fontRepairs] = self::repairFonts($theme, $preferredType);
        [$theme, $sizeWarnings] = self::repairFontSizes($theme);

        // Last: the scaffold references the preset slugs repaired above. The
        // committed shape is then authoritative over model-authored radii.
        [$theme, $scaffoldWarnings] = self::repairScaffold($theme);
        if ($this->htmlFirst) {
            $theme = self::removeGeneratedControlTypography($theme);
        }
        [$theme, $accentCaptionWarnings] = self::repairAccentCaption($theme);
        [$theme, $shapeRepairs, $shapeWarnings] = self::repairShapeWiring(
            $theme,
            DesignDirectionStep::shapeFor($project) ?? '',
        );
        [$theme, $groupPaddingWarnings] = self::repairGroupBlockPadding($theme);
        $warnings = array_merge(
            $warnings,
            $layoutWarnings,
            $colorWarnings,
            $fontWarnings,
            $accentCaptionWarnings,
            $sizeWarnings,
            $scaffoldWarnings,
            $groupPaddingWarnings,
            $shapeWarnings,
        );

        $bindRepairs = array_merge($colorRepairs, $fontRepairs);
        $bindReport = ['Successful design-direction writebacks: ' . count($bindRepairs)];
        foreach ($bindRepairs as $repair) {
            $bindReport[] = '- ' . $repair;
        }
        $project->writeText('logs/' . self::BIND_REPORT_FILE, implode("\n", $bindReport) . "\n");
        if ($bindRepairs !== []) {
            Narrator::write('  [theme-json] wrote ' . count($bindRepairs)
                . ' drifted value(s) back to the design direction; see logs/' . self::BIND_REPORT_FILE . "\n");
        }

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
     * Guarantee that emitted layout widths are usable CSS lengths. HTML-first
     * designs own these values through their root custom properties; otherwise
     * a positive unitless model number is deterministically interpreted as px.
     *
     * @param array<mixed> $theme
     * @return array{0:array<mixed>,1:list<string>}
     */
    public static function normalizeLayoutWidths(
        array $theme,
        ?string $designCss = null,
        ?string $designHtml = null,
    ): array
    {
        if (!isset($theme['settings']) || !is_array($theme['settings'])) {
            $theme['settings'] = [];
        }

        $warnings = [];
        $hadLayout = array_key_exists('layout', $theme['settings']);
        $authoredLayout = $theme['settings']['layout'] ?? [];
        if (!is_array($authoredLayout) || ($authoredLayout !== [] && array_is_list($authoredLayout))) {
            $warnings[] = 'theme/theme.json settings.layout: authored '
                . Warnings::value($authoredLayout)
                . '; delivered build-supplied layout object; disposition replaced malformed layout container';
            $authoredLayout = self::FALLBACK_LAYOUT_WIDTHS;
        }

        $designWidths = $designCss === null ? [] : self::designLayoutWidths($designCss);
        $contentDerivationFailed = false;
        $releaseRootGutter = false;
        if ($designCss !== null && $designHtml !== null) {
            $derivedContent = self::designContentWidth($designCss, $designHtml);
            if ($derivedContent === null) {
                $contentDerivationFailed = true;
            } else {
                $designWidths['contentSize'] = $derivedContent['width'];
                $releaseRootGutter = $derivedContent['releaseRootGutter'];
            }
        }
        $layout = $authoredLayout;
        foreach (self::FALLBACK_LAYOUT_WIDTHS as $key => $fallback) {
            if (isset($designWidths[$key])) {
                $layout[$key] = $designWidths[$key];
                continue;
            }
            if (!array_key_exists($key, $authoredLayout)) {
                continue;
            }

            $normalized = self::normalizeLayoutLength($authoredLayout[$key]);
            if ($normalized !== null) {
                $layout[$key] = $normalized;
                continue;
            }

            $layout[$key] = $fallback;
            $warnings[] = "theme/theme.json settings.layout.{$key}: authored "
                . Warnings::value($authoredLayout[$key])
                . '; delivered ' . Warnings::value($fallback)
                . '; disposition replaced invalid CSS length with build default';
        }
        if ($hadLayout || $layout !== []) {
            $theme['settings']['layout'] = $layout;
        }
        if ($releaseRootGutter) {
            // A viewport-fluid design has no inline inset to preserve. Leaving
            // the build-supplied md gutter in place would cap the realized box
            // at viewport - 2vw even though contentSize correctly says 1366px.
            // Keep the aware-alignment mode (align:full still depends on it),
            // but release only the root's physical inline padding.
            if (!is_array($theme['styles'] ?? null)) {
                $theme['styles'] = [];
            }
            if (!is_array($theme['styles']['spacing'] ?? null)) {
                $theme['styles']['spacing'] = [];
            }
            if (!is_array($theme['styles']['spacing']['padding'] ?? null)) {
                $theme['styles']['spacing']['padding'] = [];
            }
            $theme['styles']['spacing']['padding']['left'] = '0';
            $theme['styles']['spacing']['padding']['right'] = '0';
            $theme['settings']['useRootPaddingAwareAlignments'] = true;
        }
        if ($contentDerivationFailed) {
            $authored = $authoredLayout['contentSize'] ?? null;
            $delivered = $layout['contentSize'] ?? null;
            $warnings[] = 'design/home.html main content carrier: authored '
                . Warnings::value($authored)
                . '; delivered ' . Warnings::value($delivered)
                . '; disposition preserved normalized theme.json contentSize because the design carrier '
                . 'could not be resolved at the 1366px reference viewport';
        }

        return [$theme, $warnings];
    }

    /** @return array{contentSize?:string,wideSize?:string} */
    private static function designLayoutWidths(string $css): array
    {
        $stripped = preg_replace('~/\*.*?\*/~s', '', $css);
        if (!is_string($stripped)) {
            return [];
        }

        $widths = [];
        if (preg_match_all('/:root\s*\{([^{}]*)\}/i', $stripped, $roots) === false) {
            return [];
        }
        foreach ($roots[1] as $body) {
            foreach (explode(';', $body) as $declaration) {
                $colon = strpos($declaration, ':');
                if ($colon === false) {
                    continue;
                }
                $property = strtolower(trim(substr($declaration, 0, $colon)));
                $key = $property === '--wide-size' ? 'wideSize' : null;
                if ($key === null) {
                    continue;
                }
                $value = self::normalizeLayoutLength(substr($declaration, $colon + 1), false);
                if ($value !== null) {
                    $widths[$key] = $value;
                }
            }
        }

        return $widths;
    }

    /**
     * Resolve the design's repeated outer content carrier at the frozen
     * 1366px desktop viewport. The carrier is discovered from final home
     * markup rather than token names: a repeated shallow class wins, with
     * main itself as the fluid fallback. Its used content box then follows
     * the authored width/max-width/padding cascade.
     */
    /** @return array{width:string,releaseRootGutter:bool}|null */
    private static function designContentWidth(string $css, string $html): ?array
    {
        try {
            $fragment = HtmlFragment::parse($html);
            $main = $fragment->querySelector('main');
            if (!$main instanceof HtmlNode) {
                return null;
            }
            $carrier = self::designContentCarrier($main);
            $declarations = CssChecks::scanDeclarations($css);
            $customProperties = self::designCustomProperties($declarations);
            $width = self::resolvedCarrierContentWidth(
                $main,
                $carrier,
                $declarations,
                $customProperties,
            );
            if ($width === null || !is_finite($width) || $width <= 0) {
                return null;
            }
            $roundedWidth = round($width);
            return [
                'width' => self::formatLayoutNumber($roundedWidth) . 'px',
                'releaseRootGutter' => $roundedWidth === self::CONTENT_WIDTH_REFERENCE_VIEWPORT
                    && self::designHasViewportFlushSection(
                        $main,
                        $declarations,
                        $customProperties,
                    ),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A main-sized carrier alone does not prove the design is flush: some
     * designs put the common inset directly on every section. Preserve the
     * theme's single root gutter in that shape. Release it only when at least
     * one section's own resolved content box really reaches the viewport.
     *
     * @param list<array{property:string,value:string,context:string,ancestors:list<string>,kind:string,structurallySafe:bool}> $declarations
     * @param array<string,string> $customProperties
     */
    private static function designHasViewportFlushSection(
        HtmlNode $main,
        array $declarations,
        array $customProperties,
    ): bool {
        $sections = array_values(array_filter(
            $main->elementChildren(),
            static fn (HtmlNode $node): bool => $node->tagName() === 'section',
        ));
        if ($sections === []) {
            return false;
        }
        foreach ($sections as $section) {
            $width = self::resolvedNodeContentWidth(
                $section,
                self::CONTENT_WIDTH_REFERENCE_VIEWPORT,
                $declarations,
                $customProperties,
            );
            if ($width !== null && round($width) >= self::CONTENT_WIDTH_REFERENCE_VIEWPORT) {
                return true;
            }
        }
        return false;
    }

    /** Find the class carried shallowly by most direct main sections. */
    private static function designContentCarrier(HtmlNode $main): HtmlNode
    {
        $sections = array_values(array_filter(
            $main->elementChildren(),
            static fn (HtmlNode $node): bool => $node->tagName() === 'section',
        ));
        if (count($sections) < 2) {
            return $main;
        }

        /** @var array<string,array{coverage:int,depth:int,node:HtmlNode}> $classes */
        $classes = [];
        foreach ($sections as $section) {
            /** @var array<string,array{depth:int,node:HtmlNode}> $seen */
            $seen = [];
            $queue = [[$section, 0]];
            while ($queue !== []) {
                [$node, $depth] = array_shift($queue);
                if (!$node instanceof HtmlNode || $depth > 2) {
                    continue;
                }
                foreach (preg_split('/\s+/', trim($node->attribute('class') ?? '')) ?: [] as $class) {
                    if ($class === '' || preg_match('/\A-?[_a-zA-Z][_a-zA-Z0-9-]*\z/', $class) !== 1) {
                        continue;
                    }
                    if (!isset($seen[$class]) || $depth < $seen[$class]['depth']) {
                        $seen[$class] = ['depth' => $depth, 'node' => $node];
                    }
                }
                if ($depth < 2) {
                    foreach ($node->elementChildren() as $child) {
                        $queue[] = [$child, $depth + 1];
                    }
                }
            }
            foreach ($seen as $class => $entry) {
                if (!isset($classes[$class])) {
                    $classes[$class] = [
                        'coverage' => 0,
                        'depth' => 0,
                        'node' => $entry['node'],
                    ];
                }
                $classes[$class]['coverage']++;
                $classes[$class]['depth'] += $entry['depth'];
                if ($entry['depth'] < self::nodeDepthBelow($classes[$class]['node'], $main)) {
                    $classes[$class]['node'] = $entry['node'];
                }
            }
        }

        $minimumCoverage = max(2, (int) ceil(count($sections) * 2 / 3));
        $eligible = array_filter(
            $classes,
            static fn (array $entry): bool => $entry['coverage'] >= $minimumCoverage,
        );
        if ($eligible === []) {
            return $main;
        }
        uasort($eligible, static function (array $left, array $right): int {
            return ($right['coverage'] <=> $left['coverage'])
                ?: ($left['depth'] <=> $right['depth']);
        });
        $winner = reset($eligible);
        return is_array($winner) && $winner['node'] instanceof HtmlNode
            ? $winner['node']
            : $main;
    }

    private static function nodeDepthBelow(HtmlNode $node, HtmlNode $ancestor): int
    {
        $depth = 0;
        for ($cursor = $node; $cursor !== $ancestor; $cursor = $cursor->parent()) {
            if (!$cursor instanceof HtmlNode || ++$depth > 64) {
                return PHP_INT_MAX;
            }
        }
        return $depth;
    }

    /**
     * @param list<array{property:string,value:string,context:string,ancestors:list<string>,kind:string,structurallySafe:bool}> $declarations
     * @return array<string,string>
     */
    private static function designCustomProperties(array $declarations): array
    {
        $properties = [];
        foreach ($declarations as $declaration) {
            if ($declaration['kind'] !== 'style'
                || !$declaration['structurallySafe']
                || !str_starts_with($declaration['property'], '--')
                || !in_array(strtolower(trim($declaration['context'])), [':root', 'html'], true)
                || !self::referenceMediaApplies($declaration['ancestors'], [])
            ) {
                continue;
            }
            $properties[strtolower($declaration['property'])] = CssChecks::splitDeclarationPriority(
                $declaration['value'],
            )['value'];
        }
        return $properties;
    }

    /**
     * @param list<array{property:string,value:string,context:string,ancestors:list<string>,kind:string,structurallySafe:bool}> $declarations
     * @param array<string,string> $customProperties
     */
    private static function resolvedCarrierContentWidth(
        HtmlNode $main,
        HtmlNode $carrier,
        array $declarations,
        array $customProperties,
    ): ?float {
        $chain = [];
        for ($cursor = $carrier; $cursor instanceof HtmlNode; $cursor = $cursor->parent()) {
            array_unshift($chain, $cursor);
            if ($cursor === $main) {
                break;
            }
        }
        if ($chain === [] || $chain[0] !== $main) {
            return null;
        }

        $containingWidth = self::CONTENT_WIDTH_REFERENCE_VIEWPORT;
        foreach ($chain as $node) {
            $resolved = self::resolvedNodeContentWidth(
                $node,
                $containingWidth,
                $declarations,
                $customProperties,
            );
            if ($resolved === null) {
                return null;
            }
            $containingWidth = $resolved;
        }
        return $containingWidth;
    }

    /**
     * @param list<array{property:string,value:string,context:string,ancestors:list<string>,kind:string,structurallySafe:bool}> $declarations
     * @param array<string,string> $customProperties
     */
    private static function resolvedNodeContentWidth(
        HtmlNode $node,
        float $containingWidth,
        array $declarations,
        array $customProperties,
    ): ?float {
        $left = 0.0;
        $right = 0.0;
        $width = null;
        $maxWidth = null;
        $boxSizing = 'content-box';

        foreach ($declarations as $declaration) {
            if ($declaration['kind'] !== 'style'
                || !$declaration['structurallySafe']
                || !self::referenceMediaApplies($declaration['ancestors'], $customProperties)
                || !self::selectorMatchesNode($declaration['context'], $node)
            ) {
                continue;
            }
            $property = strtolower($declaration['property']);
            $value = CssChecks::splitDeclarationPriority($declaration['value'])['value'];
            if ($property === 'box-sizing') {
                if (in_array(strtolower($value), ['border-box', 'content-box'], true)) {
                    $boxSizing = strtolower($value);
                }
                continue;
            }
            if (in_array($property, ['width', 'inline-size'], true)) {
                if (strtolower(trim($value)) === 'auto') {
                    $width = null;
                    continue;
                }
                $width = self::resolveReferenceLength($value, $containingWidth, $customProperties);
                if ($width === null) {
                    return null;
                }
                continue;
            }
            if (in_array($property, ['max-width', 'max-inline-size'], true)) {
                if (strtolower(trim($value)) === 'none') {
                    $maxWidth = null;
                    continue;
                }
                $maxWidth = self::resolveReferenceLength($value, $containingWidth, $customProperties);
                if ($maxWidth === null) {
                    return null;
                }
                continue;
            }
            if ($property === 'padding') {
                $parts = CssValueSplitter::splitTopLevelWhitespace($value);
                $horizontal = match (count($parts)) {
                    1 => [$parts[0], $parts[0]],
                    2, 3 => [$parts[1], $parts[1]],
                    4 => [$parts[3], $parts[1]],
                    default => null,
                };
                if ($horizontal === null) {
                    return null;
                }
                $left = self::resolveReferenceLength($horizontal[0], $containingWidth, $customProperties);
                $right = self::resolveReferenceLength($horizontal[1], $containingWidth, $customProperties);
                if ($left === null || $right === null) {
                    return null;
                }
                continue;
            }
            if ($property === 'padding-inline') {
                $parts = CssValueSplitter::splitTopLevelWhitespace($value);
                if (count($parts) < 1 || count($parts) > 2) {
                    return null;
                }
                $left = self::resolveReferenceLength($parts[0], $containingWidth, $customProperties);
                $right = self::resolveReferenceLength($parts[1] ?? $parts[0], $containingWidth, $customProperties);
                if ($left === null || $right === null) {
                    return null;
                }
                continue;
            }
            if (in_array($property, ['padding-left', 'padding-inline-start'], true)) {
                $left = self::resolveReferenceLength($value, $containingWidth, $customProperties);
                if ($left === null) {
                    return null;
                }
                continue;
            }
            if (in_array($property, ['padding-right', 'padding-inline-end'], true)) {
                $right = self::resolveReferenceLength($value, $containingWidth, $customProperties);
                if ($right === null) {
                    return null;
                }
            }
        }

        // theme.json needs one stable CSS pixel value. Resolve each physical
        // edge the way the screenshot geometry gate observes it, then derive
        // the inner span between those integer edges (5vw at 1366px is 68px
        // per side, hence a 1230px fluid content box).
        $padding = round($left) + round($right);
        if ($width === null) {
            $borderBox = $containingWidth;
            if ($maxWidth !== null) {
                $borderBox = min($borderBox, $boxSizing === 'border-box' ? $maxWidth : $maxWidth + $padding);
            }
            return max(0.0, $borderBox - $padding);
        }
        if ($boxSizing === 'border-box') {
            $borderBox = $maxWidth === null ? $width : min($width, $maxWidth);
            return max(0.0, $borderBox - $padding);
        }
        return max(0.0, $maxWidth === null ? $width : min($width, $maxWidth));
    }

    private static function selectorMatchesNode(string $selectorList, HtmlNode $node): bool
    {
        foreach (CssValueSplitter::splitTopLevel($selectorList, [',']) as $selector) {
            try {
                if (Selector::compile($selector)->matches($node)) {
                    return true;
                }
            } catch (Throwable) {
                continue;
            }
        }
        return false;
    }

    /** @param list<string> $ancestors @param array<string,string> $customProperties */
    private static function referenceMediaApplies(array $ancestors, array $customProperties): bool
    {
        foreach ($ancestors as $ancestor) {
            $ancestor = trim($ancestor);
            if (!str_starts_with(strtolower($ancestor), '@media')) {
                if (str_starts_with($ancestor, '@')) {
                    return false;
                }
                continue;
            }
            if (preg_match_all('/\((min|max)-width\s*:\s*([^\)]+)\)/i', $ancestor, $matches, PREG_SET_ORDER) < 1) {
                return false;
            }
            foreach ($matches as $match) {
                $boundary = self::resolveReferenceLength(
                    $match[2],
                    self::CONTENT_WIDTH_REFERENCE_VIEWPORT,
                    $customProperties,
                );
                if ($boundary === null
                    || (strtolower($match[1]) === 'min' && self::CONTENT_WIDTH_REFERENCE_VIEWPORT < $boundary)
                    || (strtolower($match[1]) === 'max' && self::CONTENT_WIDTH_REFERENCE_VIEWPORT > $boundary)
                ) {
                    return false;
                }
            }
        }
        return true;
    }

    /** @param array<string,string> $customProperties */
    private static function resolveReferenceLength(
        string $value,
        float $containingWidth,
        array $customProperties,
        int $depth = 0,
    ): ?float {
        if ($depth > 16) {
            return null;
        }
        $value = trim(CssChecks::splitDeclarationPriority($value)['value']);
        if ($value === '0' || $value === '+0' || $value === '-0') {
            return 0.0;
        }
        if (preg_match('/\Avar\(\s*(--[-_a-zA-Z0-9]+)\s*(?:,\s*(.*))?\)\z/s', $value, $match) === 1) {
            $name = strtolower($match[1]);
            $replacement = $customProperties[$name] ?? ($match[2] ?? null);
            return is_string($replacement)
                ? self::resolveReferenceLength($replacement, $containingWidth, $customProperties, $depth + 1)
                : null;
        }
        if (preg_match('/\A([+-]?(?:\d+(?:\.\d+)?|\.\d+))(px|r?em|vw|vi|%)\z/i', $value, $match) === 1) {
            $number = (float) $match[1];
            return match (strtolower($match[2])) {
                'px' => $number,
                'em', 'rem' => $number * self::CONTENT_WIDTH_ROOT_FONT_SIZE,
                'vw', 'vi' => $number * self::CONTENT_WIDTH_REFERENCE_VIEWPORT / 100,
                '%' => $number * $containingWidth / 100,
                default => null,
            };
        }
        if (preg_match('/\A(clamp|min|max)\((.*)\)\z/is', $value, $match) === 1) {
            $parts = CssValueSplitter::splitTopLevel($match[2], [',']);
            $resolved = [];
            foreach ($parts as $part) {
                $length = self::resolveReferenceLength($part, $containingWidth, $customProperties, $depth + 1);
                if ($length === null) {
                    return null;
                }
                $resolved[] = $length;
            }
            return match (strtolower($match[1])) {
                'clamp' => count($resolved) === 3
                    ? min(max($resolved[1], $resolved[0]), $resolved[2])
                    : null,
                'min' => $resolved !== [] ? min($resolved) : null,
                'max' => $resolved !== [] ? max($resolved) : null,
                default => null,
            };
        }
        return null;
    }

    private static function normalizeLayoutLength(mixed $value, bool $unitlessToPx = true): ?string
    {
        if (is_int($value) || is_float($value)) {
            if (!$unitlessToPx || !is_finite((float) $value) || $value < 0) {
                return null;
            }
            return self::formatLayoutNumber((float) $value) . 'px';
        }
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/\A\+?(?:\d+(?:\.\d+)?|\.\d+)\z/', $value) === 1) {
            return $unitlessToPx ? self::formatLayoutNumber((float) $value) . 'px' : null;
        }

        $number = '\\+?(?:\\d+(?:\\.\\d+)?|\\.\\d+)';
        $unit = '(?:px|r?em|ch|ex|cap|ic|lh|rlh|vw|vh|vi|vb|vmin|vmax|svw|svh|lvw|lvh|dvw|dvh|cm|mm|q|in|pt|pc|%)';
        if (preg_match('/\A' . $number . $unit . '\z/i', $value) === 1) {
            return $value;
        }
        if (preg_match('/\A(?:calc|min|max|clamp)\(.+\)\z/is', $value) !== 1
            || preg_match('/' . $number . $unit . '/i', $value) !== 1
            || preg_match('/[;{}]/', $value) === 1
        ) {
            return null;
        }

        $depth = 0;
        foreach (str_split($value) as $character) {
            if ($character === '(') {
                $depth++;
            } elseif ($character === ')' && --$depth < 0) {
                return null;
            }
        }
        return $depth === 0 ? $value : null;
    }

    private static function formatLayoutNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }

    /**
     * Normalize the root padding stanza the model reliably copies from
     * published themes but never gets quite right:
     *
     * - Left/right root padding is the only viewport gutter constrained
     *   content gets on mobile, so a missing or zero side is synthesized to
     *   the md preset: without it every section that doesn't bring its own
     *   padding renders text flush against the 390px screen edge.
     * - A theme with root left/right padding MUST also opt into
     *   root-padding-aware alignments: without the flag WordPress puts the
     *   padding on <body>, where no block can escape it, so every align:full
     *   hero/footer renders inset by a page-background gutter.
     * - Root top/bottom padding is forced to 0: with the flag it lands on
     *   .wp-site-blocks as dead space above the hero and below the footer,
     *   and the vertical rhythm belongs to the header/sections/footer, which
     *   all bring their own padding.
     *
     * Pure and total — malformed styles/styles.spacing shapes are repaired
     * here too (silently; writeTheme's earlier shape repairs own the warning),
     * so no caller order can fatal.
     *
     * @param array<mixed> $theme
     * @return array<mixed>
     */
    public static function normalizeRootPadding(array $theme): array
    {
        if (!is_array($theme['styles'] ?? null)) {
            $theme['styles'] = [];
        }
        if (!is_array($theme['styles']['spacing'] ?? null)) {
            $theme['styles']['spacing'] = [];
        }
        $padding = $theme['styles']['spacing']['padding'] ?? null;
        if (!is_array($padding)) {
            $padding = [];
        }
        $normalized = ['top' => '0', 'bottom' => '0'];
        foreach (['left', 'right'] as $side) {
            $value = $padding[$side] ?? '';
            // Only scalar CSS-length candidates survive; arrays/objects/bools
            // would serialize as garbage in theme.json.
            $usable = (is_string($value) || is_int($value) || is_float($value))
                && trim((string) $value) !== ''
                && preg_match('/^0(?:[a-z%]+)?$/i', trim((string) $value)) !== 1;
            $normalized[$side] = $usable ? $value : 'var:preset|spacing|md';
        }
        $theme['styles']['spacing']['padding'] = $normalized;
        $theme['settings']['useRootPaddingAwareAlignments'] = true;
        return $theme;
    }

    /**
     * Ensure the theme carries a root inline gutter. Fills only an absent or
     * zero left/right side, so a real model-authored gutter is preserved and
     * sections (whose own inline padding SectionLayoutStep strips) never
     * double-pad. Vertical sides and the aware-alignment flag are settled by
     * normalizeRootPadding, which runs immediately after. Pure — unit-testable.
     *
     * @param array<mixed> $theme
     * @return array<mixed>
     */
    public static function provisionRootGutter(array $theme): array
    {
        if (!isset($theme['styles']['spacing']) || !is_array($theme['styles']['spacing'])) {
            $theme['styles']['spacing'] = [];
        }
        $padding = $theme['styles']['spacing']['padding'] ?? null;
        if (!is_array($padding)) {
            $padding = [];
        }
        foreach (['left', 'right'] as $side) {
            $value = is_string($padding[$side] ?? null) ? trim($padding[$side]) : '';
            if ($value === '' || preg_match('/^0(?:[a-z%]+)?$/i', $value) === 1) {
                $padding[$side] = self::ROOT_GUTTER;
            }
        }
        $theme['styles']['spacing']['padding'] = $padding;
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
     * A hex the model moved is only written back when the direction's own hex
     * stays readable on the delivered base: prompts/theme-json.md lets the
     * model nudge a hex to clear WCAG, and the direction sets no numeric floor,
     * so an unconditional writeback can silently reinstate an unreadable pair
     * that nothing downstream re-checks. A rejected writeback is a warning; an
     * applied one is a repair receipt, not a defect.
     *
     * @param array<mixed>         $theme
     * @param array<string,mixed>  $preferredHexes role => "#RRGGBB" (direction palette)
     * @return array{0:array<mixed>,1:list<string>,2:list<string>} theme, warnings, repairs
     */
    public static function repairColors(array $theme, array $preferredHexes = []): array
    {
        $warnings = [];
        $repairs = [];
        $palette = $theme['settings']['color']['palette'] ?? null;
        if (!is_array($palette)) {
            $warnings[] = 'theme.json missing settings.color.palette; rebuilt with default colors';
            $palette = [];
        }
        // The background every other slug is judged against, resolved before the
        // loop because `base` may sit after the entry being decided.
        $deliveredBase = self::deliveredBase($palette, $preferredHexes);
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
            $entry['color'] = trim((string) $color);
            $rawPreferred = $preferredHexes[$slug] ?? null;
            $preferred = is_string($rawPreferred) ? self::normalizeHex($rawPreferred) : null;
            $current = self::normalizeHex($entry['color']);
            if ($preferred !== null && $current !== null && $current !== $preferred) {
                $authored = $entry['color'];
                if (self::writebackWouldBlind($slug, $current, $preferred, $deliveredBase)) {
                    // The model's hex clears WCAG on the delivered base and the
                    // direction's does not. Keep the readable one and say so;
                    // ContrastFixStep only reports on the base/contrast pair and
                    // is skipped outright on the HTML-first path, so nothing
                    // downstream would catch this.
                    $warnings[] = "theme.json palette slug '{$slug}': authored {$authored}; delivered {$authored}"
                        . "; disposition kept the model hex because the design-direction hex {$preferred} scored "
                        . self::ratioLabel($preferred, $deliveredBase) . ':1 on base ' . $deliveredBase
                        . ', below the ' . self::CONTRAST_FLOORS[$slug] . ':1 floor for this slug, '
                        . 'which the model hex clears at ' . self::ratioLabel($current, $deliveredBase) . ':1'
                        . self::hueDriftNote($slug, $current, $preferred);
                } else {
                    $entry['color'] = $preferred;
                    $repairs[] = "palette slug '{$slug}': authored {$authored}; delivered {$preferred}"
                        . '; disposition wrote the design-direction hex back'
                        . self::hueDriftNote($slug, $authored, $preferred);
                }
            }
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
            $fromDirection = preg_match('/^#[0-9A-F]{6}$/', $preferred) === 1 ? $preferred : null;
            $neutral = self::FALLBACK_COLORS[$needed];
            if ($fromDirection === null || self::clearsFloor($needed, $fromDirection, $deliveredBase)) {
                $hex = $fromDirection ?? $neutral;
                $warnings[] = "theme.json palette missing slug '{$needed}'; filled with {$hex}";
            } else {
                // Same floor the writeback above enforces, applied to the one
                // other way a direction hex reaches the palette. There is no
                // model hex to keep here, so the choice is the direction's own
                // against the neutral default: take whichever actually reads on
                // the delivered base, and never make the slug less readable.
                $hex = (self::ratioOn($neutral, $deliveredBase) ?? 0.0)
                    > (self::ratioOn($fromDirection, $deliveredBase) ?? 0.0)
                    ? $neutral
                    : $fromDirection;
                $warnings[] = "theme.json palette missing slug '{$needed}': authored {$fromDirection}"
                    . "; delivered {$hex}; disposition the design-direction hex scored "
                    . self::ratioLabel($fromDirection, $deliveredBase) . ':1 on base ' . $deliveredBase
                    . ', below the ' . self::CONTRAST_FLOORS[$needed] . ':1 floor for this slug'
                    . self::hueDriftNote($needed, $fromDirection, $hex);
            }
            $palette[] = ['slug' => $needed, 'color' => $hex, 'name' => ucfirst($needed)];
        }
        $theme['settings']['color']['palette'] = $palette;
        return [$theme, $warnings, $repairs];
    }

    /**
     * Ensure both required font-family slugs exist, appending system stacks
     * for the missing ones. Pure — unit-testable.
     *
     * @param array<mixed> $theme
     * @return array{0:array<mixed>,1:list<string>,2:list<string>} theme, warnings, repairs
     */
    public static function repairFonts(array $theme, array $preferredType = []): array
    {
        $warnings = [];
        $repairs = [];
        $preferred = [];
        foreach (array_merge(self::REQUIRED_FONTS, self::OPTIONAL_FONTS) as $slot) {
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
            // An uncommitted accent ships a face nothing chose, and
            // repairAccentCaption would then put it on every caption. Guard
            // covers OPTIONAL_FONTS only — any other invented slug still ships.
            if (in_array($slug, self::OPTIONAL_FONTS, true) && !isset($preferred[$slug])) {
                $warnings[] = "theme.json fontFamilies slug '{$slug}': authored "
                    . Warnings::value(trim($family))
                    . '; delivered removed; disposition designDirection.json committed no type.'
                    . $slug . '.family, and the type pairing is the direction\'s call';
                continue;
            }
            $entry['slug'] = $slug;
            $entry['fontFamily'] = trim($family);
            if (isset($preferred[$slug])) {
                $previous = $entry['fontFamily'];
                $entry['fontFamily'] = self::replacePrimaryFamily($entry['fontFamily'], $preferred[$slug]);
                if (!self::samePrimaryFamily($previous, $preferred[$slug])) {
                    $repairs[] = "fontFamilies slug '{$slug}': authored {$previous}; delivered "
                        . $entry['fontFamily']
                        . '; disposition wrote the design-direction family back';
                }
            }
            $entries[] = $entry;
        }
        if ($nonObjects > 0) {
            $warnings[] = "theme.json fontFamilies: removed {$nonObjects} malformed (non-object) entr"
                . ($nonObjects === 1 ? 'y' : 'ies');
        }
        $families = $entries;
        // One pass over both slot kinds. A required slot is filled whether or
        // not the direction named a family; an optional one only when it did,
        // which is the single line of difference between them.
        foreach (array_merge(self::REQUIRED_FONTS, self::OPTIONAL_FONTS) as $needed) {
            $optional = in_array($needed, self::OPTIONAL_FONTS, true);
            if (in_array($needed, array_column($families, 'slug'), true)) {
                continue;
            }
            if ($optional && !isset($preferred[$needed])) {
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
        return [$theme, $warnings, $repairs];
    }

    /**
     * The base hex every other slug is judged against once the writeback has
     * run: the direction's base when it commits one, else the palette's own.
     */
    private static function deliveredBase(mixed $palette, array $preferredHexes): string
    {
        $fromDirection = is_string($preferredHexes['base'] ?? null)
            ? self::normalizeHex($preferredHexes['base'])
            : null;
        if ($fromDirection !== null) {
            return $fromDirection;
        }
        foreach (is_array($palette) ? $palette : [] as $entry) {
            if (!is_array($entry) || ($entry['slug'] ?? null) !== 'base') {
                continue;
            }
            $hex = is_string($entry['color'] ?? null) ? self::normalizeHex($entry['color']) : null;
            if ($hex !== null) {
                return $hex;
            }
        }
        return '#FFFFFF';
    }

    /**
     * True when the direction's hex would drop a slug the model had made
     * compliant below the floor prompts/theme-json.md:36-39 states for it.
     *
     * `base` is exempt: it is the reference background, and replacing it is a
     * design decision rather than a readability one. The floors below are the
     * prompt's own, and the ratio is symmetric, so `accent`'s requirement —
     * "base on accent >= 4.5:1" for button labels — is the same measurement.
     */
    private static function writebackWouldBlind(
        string $slug,
        string $authored,
        string $preferred,
        string $base,
    ): bool {
        return self::clearsFloor($slug, $authored, $base)
            && !self::clearsFloor($slug, $preferred, $base);
    }

    /**
     * Whether one hex meets the floor its slug carries on the delivered base.
     * A slug with no floor, and a hex we cannot measure, both pass: this gate
     * exists to catch a measured failure, not to reject unfamiliar input.
     */
    private static function clearsFloor(string $slug, string $hex, string $base): bool
    {
        $floor = self::CONTRAST_FLOORS[$slug] ?? null;
        if ($floor === null) {
            return true;
        }
        $ratio = self::ratioOn($hex, $base);
        return $ratio === null || $ratio >= $floor;
    }

    /**
     * The direction names its colors ("rosa goiaba"), so past 30 degrees of
     * hue the delivered hex reads as a different color than the name it
     * shipped under. Only secondary and accent carry a name worth reporting.
     */
    private static function hueDriftNote(string $slug, string $from, string $to): string
    {
        return in_array($slug, ['secondary', 'accent'], true)
            && self::hueDistance($from, $to) > 30.0
            ? '; hue distance exceeded 30 degrees'
            : '';
    }

    /** WCAG ratio of one hex against another, or null when either is unreadable. */
    private static function ratioOn(string $hex, string $base): ?float
    {
        $fg = ContrastMath::hexToRgb($hex);
        $bg = ContrastMath::hexToRgb($base);
        return $fg === null || $bg === null ? null : ContrastMath::ratio($fg, $bg);
    }

    private static function ratioLabel(string $hex, string $base): string
    {
        $ratio = self::ratioOn($hex, $base);
        return $ratio === null ? 'an unmeasurable' : number_format($ratio, 2);
    }

    /**
     * When an accent family shipped, captions and image credits use it so
     * the third face is visible even if a section forgot fontFamily:accent.
     *
     * @param array<mixed> $theme
     * @return array{0:array<mixed>,1:list<string>}
     */
    public static function repairAccentCaption(array $theme): array
    {
        $hasAccent = false;
        foreach ($theme['settings']['typography']['fontFamilies'] ?? [] as $entry) {
            if (is_array($entry) && ($entry['slug'] ?? '') === 'accent') {
                $family = is_string($entry['fontFamily'] ?? null) ? trim($entry['fontFamily']) : '';
                $hasAccent = $family !== '';
                break;
            }
        }
        if (!$hasAccent) {
            return [$theme, []];
        }
        $accent = 'var:preset|font-family|accent';
        $warnings = [];
        foreach ([['elements', 'caption'], ['blocks', 'core/image']] as [$group, $name]) {
            $node = &$theme;
            foreach (['styles', $group, $name, 'typography'] as $key) {
                if (!is_array($node[$key] ?? null)) {
                    $node[$key] = [];
                }
                $node = &$node[$key];
            }
            // The committed accent wins over a model choice here, but an
            // overridden valid value is a repair like any other: record it so
            // warnings.json still explains every face the build changed.
            $authored = $node['fontFamily'] ?? null;
            if ($authored !== null && $authored !== $accent) {
                $warnings[] = "theme/theme.json styles.{$group}.{$name}.typography.fontFamily: authored "
                    . Warnings::value($authored)
                    . "; delivered {$accent}"
                    . '; disposition the committed type.accent family owns caption typography';
            }
            $node['fontFamily'] = $accent;
            unset($node);
        }
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
     * FontCatalog owns the one parser for a stack's primary family, so a scan
     * and this writeback can never disagree about what a stack already names.
     */
    private static function samePrimaryFamily(string $stack, string $family): bool
    {
        return strcasecmp(FontCatalog::primaryFamily($stack) ?? '', $family) === 0;
    }

    /** @return ?string uppercase #RRGGBB */
    private static function normalizeHex(string $hex): ?string
    {
        $hex = strtoupper(trim($hex));
        if (preg_match('/^#[0-9A-F]{6}$/', $hex) === 1) {
            return $hex;
        }
        if (preg_match('/^#[0-9A-F]{3}$/', $hex) === 1) {
            return '#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3];
        }
        return null;
    }

    /** Circular hue distance in degrees, or 0 when either hex is unreadable. */
    private static function hueDistance(string $a, string $b): float
    {
        $ha = self::hueDegrees($a);
        $hb = self::hueDegrees($b);
        if ($ha === null || $hb === null) {
            return 0.0;
        }
        $delta = abs($ha - $hb);
        return min($delta, 360.0 - $delta);
    }

    private static function hueDegrees(string $hex): ?float
    {
        $rgb = ContrastMath::hexToRgb($hex);
        if ($rgb === null) {
            return null;
        }
        [$r, $g, $b] = [$rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255];
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $d = $max - $min;
        if ($d < 1e-6) {
            return 0.0;
        }
        $h = match ($max) {
            $r => fmod((($g - $b) / $d), 6),
            $g => (($b - $r) / $d) + 2,
            default => (($r - $g) / $d) + 4,
        };
        $h *= 60.0;
        return $h < 0 ? $h + 360.0 : $h;
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
        [$theme, $colorWarnings] = self::removeUnverifiedContextColors($theme);
        [$theme, $shadowWarnings] = self::repairTextTargetShadows($theme);
        $shapeWarnings = [];
        $theme = self::mergeScaffoldDefaultsAtPath(self::SCAFFOLD, $theme, '', $shapeWarnings);
        $theme = self::removeUnsupportedTextWrapProperties($theme);
        return [$theme, array_merge($colorWarnings, $shadowWarnings, $shapeWarnings)];
    }

    /**
     * theme.json v3 has no textWrap, textWrapStyle, or textWrapMode typography
     * leaves. Remove generated copies at any style depth, including custom CSS
     * strings; PageStylesStep owns the supported CSS policy for both generation
     * graphs (BIGR-869).
     *
     * @param array<mixed> $theme
     * @return array<mixed>
     */
    private static function removeUnsupportedTextWrapProperties(array $theme): array
    {
        if (!is_array($theme['styles'] ?? null)) {
            return $theme;
        }
        $remove = static function (array $node) use (&$remove): array {
            foreach ($node as $key => $value) {
                if ($key === 'css' && is_string($value)) {
                    [$node[$key]] = CssChecks::dropTextWrapDeclarations($value);
                    continue;
                }
                if (in_array($key, ['textWrap', 'textWrapStyle', 'textWrapMode'], true)) {
                    unset($node[$key]);
                    continue;
                }
                if (is_array($value)) {
                    $node[$key] = $remove($value);
                }
            }
            return $node;
        };
        $theme['styles'] = $remove($theme['styles']);
        return $theme;
    }

    /**
     * HTML-first builds deliver the design's authored CSS after theme.json.
     * Semantic navigation/button/link typography here would invent a competing
     * design-system default: where the design declares a value, carried CSS
     * owns it; where it declares none, both source and delivery must inherit.
     * The default blocks graph has no authored CSS carrier and never calls
     * this repair.
     *
     * Pure and idempotent — unit-testable through the mode-specific step.
     *
     * @param array<mixed> $theme
     * @return array<mixed>
     */
    private static function removeGeneratedControlTypography(array $theme): array
    {
        foreach ([
            ['blocks', 'core/navigation'],
            ['elements', 'button'],
            ['elements', 'link'],
        ] as [$family, $name]) {
            $node = $theme['styles'][$family][$name] ?? null;
            if (!is_array($node) || !array_key_exists('typography', $node)) {
                continue;
            }
            unset($node['typography']);
            if ($node === []) {
                unset($theme['styles'][$family][$name]);
            } else {
                $theme['styles'][$family][$name] = $node;
            }
        }

        return $theme;
    }

    /**
     * Remove generated shadows whose selector paints text rather than a
     * surface. A direct `shadow` is retained on buttons, groups/cards, media,
     * covers, navigation and the global canvas. `typography.textShadow` is
     * always text-directed, so it is removed from every root, block, element,
     * variation and pseudo-state style node.
     *
     * Every removed declaration gets its own actionable warning. Shadow
     * presets remain available in settings for safe surface use. Pure and
     * idempotent — unit-testable.
     *
     * @param array<mixed> $theme
     * @return array{0:array<mixed>,1:list<string>} theme, warnings
     */
    public static function repairTextTargetShadows(array $theme): array
    {
        $styles = $theme['styles'] ?? null;
        if (!is_array($styles) || ($styles !== [] && array_is_list($styles))) {
            return [$theme, []];
        }

        $warnings = [];
        self::repairTextShadowsAtStyleNode($styles, 'styles', false, $warnings);
        $theme['styles'] = $styles;
        return [$theme, $warnings];
    }

    /**
     * @param array<mixed> $node
     * @param list<string> $warnings
     */
    private static function repairTextShadowsAtStyleNode(
        array &$node,
        string $path,
        bool $boxShadowTargetsText,
        array &$warnings,
    ): bool {
        $changed = false;

        if ($boxShadowTargetsText
            && array_key_exists('shadow', $node)
            && self::shadowValueCanPaint($node['shadow'])) {
            $warnings[] = "theme/theme.json {$path}.shadow: authored "
                . Warnings::value($node['shadow'])
                . '; delivered removed'
                . '; disposition removed text-targeted box shadow; shadows are reserved for media, card,'
                . ' and cover surfaces';
            unset($node['shadow']);
            $changed = true;
        }

        $typography = $node['typography'] ?? null;
        if (is_array($typography)
            && ($typography === [] || !array_is_list($typography))
            && array_key_exists('textShadow', $typography)
            && self::shadowValueCanPaint($typography['textShadow'])) {
            $warnings[] = "theme/theme.json {$path}.typography.textShadow: authored "
                . Warnings::value($typography['textShadow'])
                . '; delivered removed'
                . '; disposition removed glyph shadow; shadow atmosphere is reserved for media, card,'
                . ' and cover surfaces';
            unset($typography['textShadow']);
            if ($typography === []) {
                unset($node['typography']);
            } else {
                $node['typography'] = $typography;
            }
            $changed = true;
        }

        $changed = self::repairTextShadowStyleMap(
            $node,
            'elements',
            $path,
            self::TEXT_SHADOW_ELEMENTS,
            $warnings,
        ) || $changed;
        $changed = self::repairTextShadowStyleMap(
            $node,
            'blocks',
            $path,
            self::TEXT_SHADOW_BLOCKS,
            $warnings,
        ) || $changed;

        $variations = $node['variations'] ?? null;
        if (is_array($variations) && ($variations === [] || !array_is_list($variations))) {
            $variationChanged = false;
            foreach (array_keys($variations) as $name) {
                if (!is_string($name)
                    || !is_array($variations[$name])
                    || ($variations[$name] !== [] && array_is_list($variations[$name]))) {
                    continue;
                }
                $childChanged = self::repairTextShadowsAtStyleNode(
                    $variations[$name],
                    "{$path}.variations.{$name}",
                    $boxShadowTargetsText,
                    $warnings,
                );
                if ($childChanged && $variations[$name] === []) {
                    unset($variations[$name]);
                }
                $variationChanged = $childChanged || $variationChanged;
            }
            if ($variationChanged) {
                if ($variations === []) {
                    unset($node['variations']);
                } else {
                    $node['variations'] = $variations;
                }
                $changed = true;
            }
        }

        foreach (array_keys($node) as $key) {
            if (!is_string($key)
                || !str_starts_with($key, ':')
                || !is_array($node[$key])
                || ($node[$key] !== [] && array_is_list($node[$key]))) {
                continue;
            }
            $childChanged = self::repairTextShadowsAtStyleNode(
                $node[$key],
                "{$path}.{$key}",
                $boxShadowTargetsText,
                $warnings,
            );
            if ($childChanged && $node[$key] === []) {
                unset($node[$key]);
            }
            $changed = $childChanged || $changed;
        }

        return $changed;
    }

    /**
     * Explicit resets and falsey malformed values cannot paint a shadow. They
     * are harmless, and `none` can intentionally suppress an inherited text
     * shadow, so retain their exact authored representation without warning.
     */
    private static function shadowValueCanPaint(mixed $value): bool
    {
        if (is_string($value)) {
            $withoutComments = preg_replace('~/\*.*?\*/~s', '', $value) ?? $value;
            $withoutImportant = preg_replace('/\s*!important\s*\z/i', '', $withoutComments)
                ?? $withoutComments;
            return !in_array(
                strtolower(trim($withoutImportant)),
                ['', 'none', 'initial', 'unset', 'revert', 'revert-layer'],
                true,
            );
        }
        if ($value === null || $value === false || $value === []) {
            return false;
        }
        if ((is_int($value) || is_float($value)) && (float) $value === 0.0) {
            return false;
        }
        return true;
    }

    /**
     * @param array<mixed> $node
     * @param list<string> $textTargets
     * @param list<string> $warnings
     */
    private static function repairTextShadowStyleMap(
        array &$node,
        string $mapKey,
        string $path,
        array $textTargets,
        array &$warnings,
    ): bool {
        $map = $node[$mapKey] ?? null;
        if (!is_array($map) || ($map !== [] && array_is_list($map))) {
            return false;
        }

        $changed = false;
        foreach (array_keys($map) as $name) {
            if (!is_string($name)
                || !is_array($map[$name])
                || ($map[$name] !== [] && array_is_list($map[$name]))) {
                continue;
            }
            $childChanged = self::repairTextShadowsAtStyleNode(
                $map[$name],
                "{$path}.{$mapKey}.{$name}",
                in_array($name, $textTargets, true),
                $warnings,
            );
            if ($childChanged && $map[$name] === []) {
                unset($map[$name]);
            }
            $changed = $childChanged || $changed;
        }
        if (!$changed) {
            return false;
        }
        if ($map === []) {
            unset($node[$mapKey]);
        } else {
            $node[$mapKey] = $map;
        }
        return true;
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
