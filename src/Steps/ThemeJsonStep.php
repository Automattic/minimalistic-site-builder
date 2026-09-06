<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter;
use Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment;
use Automattic\SiteBuild\BlockSerializer\Html\HtmlNode;
use Automattic\SiteBuild\BlockSerializer\Html\Selector;
use Automattic\SiteBuild\BoundedChoice;
use Automattic\SiteBuild\CssTokenExtractor;
use Automattic\SiteBuild\Depth;
use Automattic\SiteBuild\FontCatalog;
use Automattic\SiteBuild\GeneratedJsonException;
use Automattic\SiteBuild\GeneratedJsonFallbackStep;
use Automattic\SiteBuild\ImageTreatment;
use Automattic\SiteBuild\BandColor;
use Automattic\SiteBuild\ContrastMath;
use Automattic\SiteBuild\Surface;
use Automattic\SiteBuild\CssChecks;
use Automattic\SiteBuild\CssScrub;
use Automattic\SiteBuild\CtaStyle;
use Automattic\SiteBuild\PaletteFloor;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\LlmOptions;
use Automattic\SiteBuild\Measure;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\TypeScale;
use Automattic\SiteBuild\TypeTreatment;
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
 * Validates the structure the templates depend on (version 3, the six color
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

    private const REQUIRED_COLORS = ['base', 'contrast', 'primary', 'secondary', 'accent', 'band'];
    private const ROOT_BLOCK_GAP_FALLBACK = 'var:preset|spacing|md';
    private const ROOT_BLOCK_GAP_REFERENCES = [
        'var:preset|spacing|xs',
        'var:preset|spacing|sm',
        'var:preset|spacing|md',
        'var:preset|spacing|lg',
        'var:preset|spacing|xl',
        'var:preset|spacing|xxl',
        'var(--wp--preset--spacing--xs)',
        'var(--wp--preset--spacing--sm)',
        'var(--wp--preset--spacing--md)',
        'var(--wp--preset--spacing--lg)',
        'var(--wp--preset--spacing--xl)',
        'var(--wp--preset--spacing--xxl)',
    ];

    /**
     * The per-slug contrast floors against `base` that prompts/theme-json.md
     * states as non-negotiable, mirrored here so the direction writeback can
     * tell a model hex that was moved to clear one from ordinary drift.
     */
    private const CONTRAST_FLOORS = [
        'contrast' => ContrastMath::NORMAL_TEXT,
        'primary' => ContrastMath::NORMAL_TEXT,
        'secondary' => ContrastMath::NORMAL_TEXT,
        'accent' => ContrastMath::NORMAL_TEXT,
    ];
    private const REQUIRED_FONTS = ['heading', 'body'];
    private const OPTIONAL_FONTS = ['accent'];
    /**
     * Code-owned font presets every theme ships. The status-readout footer
     * archetype sets its rows in `mono`, and a pure system stack needs no
     * bundled font file, so the preset is deterministic and free.
     */
    private const PIPELINE_FONTS = [
        'mono' => 'ui-monospace, Menlo, Consolas, monospace',
    ];

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
        'band'      => '#E6E6E6',
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
     * Density-owned component spacing (BIGR-954). xs is the tight
     * intra-component text rhythm (an eyebrow/heading/line stack inside one
     * card or list row — BIGR-777); sm/md are component-level insets and gaps,
     * and md also feeds ROOT_GUTTER, so the page's inline gutter follows the
     * committed density with no extra wiring. The swing is deliberately
     * smaller than the section ramp's (~±25%): component spacing must stay
     * readable at both extremes. `measured` keeps the historical fixed values.
     *
     * @var array<string,list<array{slug: string, name: string, size: string}>>
     */
    private const COMPONENT_SPACING_PROFILES = [
        'expansive' => [
            ['slug' => 'xs', 'name' => 'Extra Small', 'size' => 'clamp(0.5rem, 0.75vw, 0.75rem)'],
            ['slug' => 'sm', 'name' => 'Small', 'size' => 'clamp(1.25rem, 1.6vw, 1.5rem)'],
            ['slug' => 'md', 'name' => 'Medium', 'size' => 'clamp(2.5rem, 3vw, 3rem)'],
        ],
        'airy' => [
            ['slug' => 'xs', 'name' => 'Extra Small', 'size' => 'clamp(0.375rem, 0.6vw, 0.625rem)'],
            ['slug' => 'sm', 'name' => 'Small', 'size' => 'clamp(1rem, 1.25vw, 1.25rem)'],
            ['slug' => 'md', 'name' => 'Medium', 'size' => 'clamp(2rem, 2.5vw, 2.5rem)'],
        ],
        'measured' => [
            ['slug' => 'xs', 'name' => 'Extra Small', 'size' => 'clamp(0.25rem, 0.5vw, 0.5rem)'],
            ['slug' => 'sm', 'name' => 'Small', 'size' => 'clamp(0.75rem, 1vw, 1rem)'],
            ['slug' => 'md', 'name' => 'Medium', 'size' => 'clamp(1.5rem, 2vw, 2rem)'],
        ],
        'dense' => [
            ['slug' => 'xs', 'name' => 'Extra Small', 'size' => 'clamp(0.25rem, 0.4vw, 0.375rem)'],
            ['slug' => 'sm', 'name' => 'Small', 'size' => 'clamp(0.625rem, 0.8vw, 0.875rem)'],
            ['slug' => 'md', 'name' => 'Medium', 'size' => 'clamp(1.25rem, 1.5vw, 1.5rem)'],
        ],
        // The packed xs floor stays at the dense/measured 0.25rem: below 4px
        // an eyebrow/heading stack reads as a collision, not a rhythm.
        'packed' => [
            ['slug' => 'xs', 'name' => 'Extra Small', 'size' => 'clamp(0.25rem, 0.35vw, 0.3125rem)'],
            ['slug' => 'sm', 'name' => 'Small', 'size' => 'clamp(0.5rem, 0.65vw, 0.75rem)'],
            ['slug' => 'md', 'name' => 'Medium', 'size' => 'clamp(1rem, 1.25vw, 1.25rem)'],
        ],
    ];

    /**
     * Density-owned section padding. lg/xl/xxl remain the compact, standard,
     * and spacious semantic choices; only their physical breathing room moves.
     *
     * @var array<string,list<array{slug: string, name: string, size: string}>>
     */
    private const SECTION_SPACING_PROFILES = [
        'expansive' => [
            ['slug' => 'lg', 'name' => 'Compact', 'size' => 'clamp(5rem, 7.5vw, 8rem)'],
            ['slug' => 'xl', 'name' => 'Standard', 'size' => 'clamp(6.5rem, 10vw, 12rem)'],
            ['slug' => 'xxl', 'name' => 'Spacious', 'size' => 'clamp(8rem, 13vw, 16rem)'],
        ],
        'airy' => [
            ['slug' => 'lg', 'name' => 'Compact', 'size' => 'clamp(4rem, 6vw, 6rem)'],
            ['slug' => 'xl', 'name' => 'Standard', 'size' => 'clamp(5rem, 8vw, 9rem)'],
            ['slug' => 'xxl', 'name' => 'Spacious', 'size' => 'clamp(6rem, 10vw, 12rem)'],
        ],
        'measured' => [
            ['slug' => 'lg', 'name' => 'Compact', 'size' => 'clamp(3rem, 4vw, 4rem)'],
            ['slug' => 'xl', 'name' => 'Standard', 'size' => 'clamp(4rem, 6vw, 6rem)'],
            ['slug' => 'xxl', 'name' => 'Spacious', 'size' => 'clamp(5rem, 7vw, 7rem)'],
        ],
        'dense' => [
            ['slug' => 'lg', 'name' => 'Compact', 'size' => 'clamp(2.25rem, 3vw, 3rem)'],
            ['slug' => 'xl', 'name' => 'Standard', 'size' => 'clamp(3rem, 4.5vw, 4.5rem)'],
            ['slug' => 'xxl', 'name' => 'Spacious', 'size' => 'clamp(3.75rem, 5.5vw, 5.5rem)'],
        ],
        'packed' => [
            ['slug' => 'lg', 'name' => 'Compact', 'size' => 'clamp(1.75rem, 2.25vw, 2.25rem)'],
            ['slug' => 'xl', 'name' => 'Standard', 'size' => 'clamp(2.25rem, 3.5vw, 3.5rem)'],
            ['slug' => 'xxl', 'name' => 'Spacious', 'size' => 'clamp(3rem, 4.25vw, 4.25rem)'],
        ],
    ];
    /**
     * Build-supplied wiring the model no longer writes. It maps presets to
     * roles and makes zero aesthetic choices — every value is a var:preset
     * token whose actual color/family the model chose and whose type size the
     * committed direction selected, so sites stay visually distinct. No
     * borders, radii, shadows or decorative treatment.
     * (The direction-committed type treatment, CTA and shape wiring are
     * deliberate exceptions, and execute explicit design commitments rather
     * than making choices here.)
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
     * md is a component-level inset (~1.5–2rem at measured density), matching
     * the ~1rem-per-side page gutter the designs author on their outermost
     * container. Because md is density-scaled (COMPONENT_SPACING_PROFILES),
     * airy sites breathe wider and dense sites pull tighter at the page edge.
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
    private const DEPTH_REPORT_FILE = 'theme-json-depth.txt';

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
                'logs/' . self::DEPTH_REPORT_FILE,
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
        $theme = self::normalizeSpacingSettings(
            $theme,
            DesignDirectionStep::densityFor($project),
        );
        [$theme, $measureRepairs] = self::applyMeasure(
            $theme,
            $this->htmlFirst ? null : DesignDirectionStep::measureFor($project),
        );
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
        [$theme, $blockGapWarnings] = self::repairRootBlockGap($theme);
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
        // Mirrors the applyMeasure gate above: on the HTML-first path the
        // carried design CSS owns the rendered typography, so the committed
        // ramp never displaces the sizes the design authored.
        [$theme, $typeScaleRepairs] = self::applyTypeScale(
            $theme,
            $this->htmlFirst ? null : DesignDirectionStep::typeScaleFor($project),
        );
        [$theme, $sizeWarnings] = self::repairFontSizes($theme);

        // Last: the scaffold references the preset slugs repaired above. The
        // committed heading treatment, CTA construction and shape are then
        // authoritative over their model-authored leaves.
        [$theme, $scaffoldWarnings] = self::repairScaffold($theme);
        if ($this->htmlFirst) {
            $theme = self::removeGeneratedControlTypography($theme);
        }
        [$theme, $accentCaptionWarnings] = self::repairAccentCaption($theme);
        [$theme, $typeTreatmentRepairs] = self::repairTypeTreatment(
            $theme,
            DesignDirectionStep::typeTreatmentFor($project) ?? '',
        );
        [$theme, $ctaRepairs] = self::repairCtaStyle(
            $theme,
            DesignDirectionStep::ctaStyleFor($project) ?? '',
        );
        [$theme, $shapeRepairs, $shapeWarnings] = self::repairShapeWiring(
            $theme,
            DesignDirectionStep::shapeFor($project) ?? '',
        );
        [$theme, $depthRepairs] = self::repairDepthPreset(
            $theme,
            DesignDirectionStep::depthFor($project),
        );
        [$theme, $groupPaddingWarnings] = self::repairGroupBlockPadding($theme);
        $warnings = array_merge(
            $warnings,
            $blockGapWarnings,
            $layoutWarnings,
            $colorWarnings,
            $fontWarnings,
            $accentCaptionWarnings,
            $sizeWarnings,
            $scaffoldWarnings,
            $groupPaddingWarnings,
            $shapeWarnings,
        );

        // Floors run on the palette about to be written, after every other
        // repair. A committed surface texture raises the body-ink floor to
        // 7:1 so the overlay's sheet leaves 4.5:1 (Surface::contrastFloor).
        [$theme, $floorWarnings] = self::applyPaletteFloor(
            $theme,
            Surface::contrastFloor(DesignDirectionStep::surfaceFor($project)),
            DesignDirectionStep::colorEconomyFor($project),
        );
        $warnings = array_merge($warnings, $floorWarnings);

        // The bounded render-time treatment owns the duotone catalog after
        // palette repair/floors, so its preset uses the colors that ship.
        $theme = ImageTreatment::applyThemeJson(
            $theme,
            $direction['image_treatment'] ?? null,
        );

        $bindRepairs = array_merge(
            $colorRepairs,
            $fontRepairs,
            $measureRepairs,
            $typeScaleRepairs,
            $typeTreatmentRepairs,
            $ctaRepairs,
        );
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

        $depthReport = ['Successful deterministic depth preset repairs: ' . count($depthRepairs)];
        foreach ($depthRepairs as $repair) {
            $depthReport[] = '- ' . $repair;
        }
        $project->writeText('logs/' . self::DEPTH_REPORT_FILE, implode("\n", $depthReport) . "\n");
        if ($depthRepairs !== []) {
            Narrator::write('  [theme-json] wired the committed depth preset; see logs/'
                . self::DEPTH_REPORT_FILE . "\n");
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
    public static function normalizeSpacingSettings(array $theme, ?string $density = null): array
    {
        if (!isset($theme['settings']) || !is_array($theme['settings'])) {
            $theme['settings'] = [];
        }
        if (!isset($theme['settings']['spacing']) || !is_array($theme['settings']['spacing'])) {
            $theme['settings']['spacing'] = [];
        }

        $theme['settings']['spacing']['blockGap'] = true;
        $theme['settings']['spacing']['defaultSpacingSizes'] = false;
        $density = BoundedChoice::explicit($density, DesignDirectionStep::DENSITIES) ?? 'measured';
        $theme['settings']['spacing']['spacingSizes'] = array_merge(
            self::COMPONENT_SPACING_PROFILES[$density],
            self::SECTION_SPACING_PROFILES[$density],
        );

        return $theme;
    }

    /**
     * Keep the root sibling rhythm on the bounded spacing scale the build
     * installs. A copied prompt placeholder or invented slug would otherwise
     * survive as an unresolved CSS variable and remove block spacing across
     * the delivered theme.
     *
     * @param array<mixed> $theme
     * @return array{0:array<mixed>,1:list<string>}
     */
    public static function repairRootBlockGap(array $theme): array
    {
        if (!isset($theme['styles']) || !is_array($theme['styles'])) {
            $theme['styles'] = [];
        }
        if (!isset($theme['styles']['spacing']) || !is_array($theme['styles']['spacing'])) {
            $theme['styles']['spacing'] = [];
        }

        if (!array_key_exists('blockGap', $theme['styles']['spacing'])
            || $theme['styles']['spacing']['blockGap'] === null
        ) {
            $theme['styles']['spacing']['blockGap'] = self::ROOT_BLOCK_GAP_FALLBACK;
            return [$theme, []];
        }

        $authored = $theme['styles']['spacing']['blockGap'];
        if (is_string($authored) && in_array($authored, self::ROOT_BLOCK_GAP_REFERENCES, true)) {
            return [$theme, []];
        }

        $theme['styles']['spacing']['blockGap'] = self::ROOT_BLOCK_GAP_FALLBACK;
        return [$theme, [
            'theme/theme.json styles.spacing.blockGap: authored ' . Warnings::value($authored)
                . '; delivered ' . Warnings::value(self::ROOT_BLOCK_GAP_FALLBACK)
                . '; disposition=unresolved or unsupported spacing preset reference replaced '
                . 'with the canonical default',
        ]];
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

    /**
     * Replace model-authored widths with the committed block-first pair.
     * A null commitment is a no-op for pre-field and HTML-first builds.
     *
     * @param array<mixed> $theme
     * @return array{0:array<mixed>,1:list<string>}
     */
    public static function applyMeasure(array $theme, ?string $measure): array
    {
        $widths = Measure::widths($measure);
        if ($widths === null) {
            return [$theme, []];
        }

        $settings = is_array($theme['settings'] ?? null) ? $theme['settings'] : [];
        // Keep the raw container for the warning: a malformed layout is dropped
        // here before normalizeLayoutWidths can name it, so this row is the only
        // place that authored value is still recorded.
        $rawLayout = $settings['layout'] ?? null;
        $authored = is_array($rawLayout)
            && ($rawLayout === [] || !array_is_list($rawLayout))
                ? $rawLayout
                : null;
        $layout = $authored ?? [];
        $alreadyBound = array_key_exists('contentSize', $layout)
            && array_key_exists('wideSize', $layout)
            && $layout['contentSize'] === $widths['contentSize']
            && $layout['wideSize'] === $widths['wideSize'];
        $settings['layout'] = array_replace($layout, $widths);
        $theme['settings'] = $settings;

        if ($alreadyBound) {
            return [$theme, []];
        }
        return [$theme, [
            'theme/theme.json: settings.layout authored ' . Warnings::value($authored ?? $rawLayout)
                . ' delivered committed "' . $measure . '" measure '
                . Warnings::value($widths)
                . '; disposition replaced model-authored widths with deterministic direction token',
        ]];
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
     * Last pass on the delivered palette: WCAG / hue / chroma floors.
     * Walks settings.color.palette the same way repairColors does. Pure.
     *
     * @param array<mixed> $theme
     * @return array{0:array<mixed>,1:list<string>} theme, warnings
     */
    public static function applyPaletteFloor(
        array $theme,
        ?float $contrastOnBase = null,
        ?string $colorEconomy = null,
    ): array
    {
        $palette = $theme['settings']['color']['palette'] ?? null;
        if (!is_array($palette)) {
            return [$theme, []];
        }
        $map = [];
        foreach ($palette as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $slug = is_string($entry['slug'] ?? null) ? trim($entry['slug']) : '';
            if ($slug === '') {
                continue;
            }
            $color = $entry['color'] ?? null;
            if (!is_string($color) || ContrastMath::hexToRgb($color) === null) {
                continue;
            }
            $map[$slug] = trim($color);
        }
        $warnings = [];
        $fixed = PaletteFloor::repair($map, $warnings, $contrastOnBase, $colorEconomy);
        foreach ($theme['settings']['color']['palette'] as $i => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $slug = is_string($entry['slug'] ?? null) ? trim($entry['slug']) : '';
            if ($slug === '' || !array_key_exists($slug, $fixed)) {
                continue;
            }
            $theme['settings']['color']['palette'][$i]['color'] = $fixed[$slug];
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
        // loop because `base` may sit after the entry being decided. The
        // contrast ink is resolved the same way: accent is a fill, and its
        // floor holds for the better of its two label inks (base or contrast).
        $deliveredBase = self::deliveredBase($palette, $preferredHexes);
        $deliveredContrast = self::deliveredSlugHex($palette, $preferredHexes, 'contrast');
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
                if (self::writebackWouldBlind($slug, $current, $preferred, $deliveredBase, $deliveredContrast)) {
                    // The model's hex clears WCAG on the delivered base and the
                    // direction's does not. Keep the readable one and say so;
                    // ContrastFixStep only reports on the base/contrast pair and
                    // is skipped outright on the HTML-first path, so nothing
                    // downstream would catch this.
                    $against = $slug === 'accent'
                        ? 'its best label ink (base or contrast)'
                        : 'base ' . $deliveredBase;
                    $warnings[] = "theme.json palette slug '{$slug}': authored {$authored}; delivered {$authored}"
                        . "; disposition kept the model hex because the design-direction hex {$preferred} scored "
                        . self::slugRatioLabel($slug, $preferred, $deliveredBase, $deliveredContrast)
                        . ':1 on ' . $against
                        . ', below the ' . self::CONTRAST_FLOORS[$slug] . ':1 floor for this slug, '
                        . 'which the model hex clears at '
                        . self::slugRatioLabel($slug, $current, $deliveredBase, $deliveredContrast) . ':1'
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
            $neutral = $needed === 'band'
                ? (BandColor::fromBase($deliveredBase) ?? self::FALLBACK_COLORS[$needed])
                : self::FALLBACK_COLORS[$needed];
            if (
                $fromDirection === null
                || self::clearsFloor($needed, $fromDirection, $deliveredBase, $deliveredContrast)
            ) {
                $hex = $fromDirection ?? $neutral;
                if ($needed === 'band') {
                    $repairs[] = "palette missing slug 'band': delivered {$hex}; disposition derived the "
                        . 'committed large-area surface from base';
                } else {
                    $warnings[] = "theme.json palette missing slug '{$needed}'; filled with {$hex}";
                }
            } else {
                // Same floor the writeback above enforces, applied to the one
                // other way a direction hex reaches the palette. There is no
                // model hex to keep here, so the choice is the direction's own
                // against the neutral default: take whichever actually reads
                // (per the slug's own measurement), and never make the slug
                // less readable.
                $hex = (self::slugRatio($needed, $neutral, $deliveredBase, $deliveredContrast) ?? 0.0)
                    > (self::slugRatio($needed, $fromDirection, $deliveredBase, $deliveredContrast) ?? 0.0)
                    ? $neutral
                    : $fromDirection;
                $against = $needed === 'accent'
                    ? 'its best label ink (base or contrast)'
                    : 'base ' . $deliveredBase;
                $warnings[] = "theme.json palette missing slug '{$needed}': authored {$fromDirection}"
                    . "; delivered {$hex}; disposition the design-direction hex scored "
                    . self::slugRatioLabel($needed, $fromDirection, $deliveredBase, $deliveredContrast)
                    . ':1 on ' . $against
                    . ', below the ' . self::CONTRAST_FLOORS[$needed] . ':1 floor for this slug'
                    . self::hueDriftNote($needed, $fromDirection, $hex);
            }
            $palette[] = ['slug' => $needed, 'color' => $hex, 'name' => ucfirst($needed)];
        }

        $baseIndex = null;
        $bandIndex = null;
        foreach ($palette as $index => $entry) {
            if (($entry['slug'] ?? null) === 'base') {
                $baseIndex = $index;
            } elseif (($entry['slug'] ?? null) === 'band') {
                $bandIndex = $index;
            }
        }
        if ($baseIndex !== null && $bandIndex !== null) {
            $base = (string) $palette[$baseIndex]['color'];
            $band = (string) $palette[$bandIndex]['color'];
            if (!BandColor::valid($base, $band)) {
                $fixedBand = BandColor::fromBase($base);
                if ($fixedBand !== null) {
                    $palette[$bandIndex]['color'] = $fixedBand;
                    $repairs[] = "palette slug 'band': authored {$band}; delivered {$fixedBand}; "
                        . 'disposition enforced a same-family surface 10 lightness points from base '
                        . 'without crossing the page light/dark key';
                }
            }
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
        // Code-owned presets fill silently: nothing the model authored was
        // lost, so a warning row here would be noise on every build.
        foreach (self::PIPELINE_FONTS as $slug => $stack) {
            if (!in_array($slug, array_column($families, 'slug'), true)) {
                $families[] = ['slug' => $slug, 'name' => ucfirst($slug), 'fontFamily' => $stack];
            }
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
     * compliant below the floor prompts/theme-json.md states for it.
     *
     * `base` is exempt: it is the reference background, and replacing it is a
     * design decision rather than a readability one. Text roles measure
     * against base; `accent` is a fill and measures against its best label
     * ink (base or contrast) — the same measurement PaletteFloor holds.
     */
    private static function writebackWouldBlind(
        string $slug,
        string $authored,
        string $preferred,
        string $base,
        ?string $contrast,
    ): bool {
        return self::clearsFloor($slug, $authored, $base, $contrast)
            && !self::clearsFloor($slug, $preferred, $base, $contrast);
    }

    /**
     * Whether one hex meets the floor its slug carries. A slug with no
     * floor, and a hex we cannot measure, both pass: this gate exists to
     * catch a measured failure, not to reject unfamiliar input.
     */
    private static function clearsFloor(string $slug, string $hex, string $base, ?string $contrast): bool
    {
        $floor = self::CONTRAST_FLOORS[$slug] ?? null;
        if ($floor === null) {
            return true;
        }
        $ratio = self::slugRatio($slug, $hex, $base, $contrast);
        return $ratio === null || $ratio >= $floor;
    }

    /**
     * The measurement a slug's floor holds: text roles read on base, the
     * accent fill is read BY its better label ink. Null when unmeasurable.
     */
    private static function slugRatio(string $slug, string $hex, string $base, ?string $contrast): ?float
    {
        $onBase = self::ratioOn($hex, $base);
        if ($slug !== 'accent') {
            return $onBase;
        }
        $onContrast = $contrast === null ? null : self::ratioOn($hex, $contrast);
        if ($onBase === null) {
            return $onContrast;
        }
        return $onContrast === null ? $onBase : max($onBase, $onContrast);
    }

    private static function slugRatioLabel(string $slug, string $hex, string $base, ?string $contrast): string
    {
        $ratio = self::slugRatio($slug, $hex, $base, $contrast);
        return $ratio === null ? 'an unmeasurable' : number_format($ratio, 2);
    }

    /**
     * One delivered slug hex, preferring the direction's commitment and
     * falling back to the model's palette entry — the same resolution order
     * deliveredBase() uses. Null when neither names a readable hex.
     */
    private static function deliveredSlugHex(mixed $palette, array $preferredHexes, string $slug): ?string
    {
        $fromDirection = is_string($preferredHexes[$slug] ?? null)
            ? self::normalizeHex($preferredHexes[$slug])
            : null;
        if ($fromDirection !== null) {
            return $fromDirection;
        }
        foreach (is_array($palette) ? $palette : [] as $entry) {
            if (!is_array($entry) || ($entry['slug'] ?? null) !== $slug) {
                continue;
            }
            $hex = is_string($entry['color'] ?? null) ? self::normalizeHex($entry['color']) : null;
            if ($hex !== null) {
                return $hex;
            }
        }
        return null;
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
        // The prompt tells the model not to emit fontSizes, so a wholly absent
        // array is compliance, not a defect: the deterministic fill below is
        // the build honoring its own contract and needs no warning rows.
        $authoredAbsent = $sizes === null;
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
        $fallbackProfile = TypeScale::fontSizes(TypeScale::DEFAULT) ?? [];
        foreach ($fallbackProfile as $fallback) {
            if (in_array($fallback['slug'], $slugs, true)) {
                continue;
            }
            $entries[] = $fallback;
            if (!$authoredAbsent) {
                $warnings[] = "theme.json fontSizes missing slug '{$fallback['slug']}'; "
                    . "filled with {$fallback['size']}";
            }
        }

        $theme['settings']['typography']['fontSizes'] = $entries;
        return [$theme, $warnings];
    }

    /**
     * Replace every model-authored size with the committed modular ramp.
     * Displacing an authored scale is repair-report evidence, never a
     * warning: the authored scale was deliberately outside the model's
     * ownership. Writing the ramp into an absent slot reports nothing,
     * because nothing was replaced.
     *
     * @param array<mixed> $theme
     * @return array{0:array<mixed>,1:list<string>}
     */
    public static function applyTypeScale(array $theme, ?string $scale): array
    {
        $profile = TypeScale::fontSizes($scale);
        if ($profile === null) {
            return [$theme, []];
        }

        $settings = is_array($theme['settings'] ?? null) ? $theme['settings'] : [];
        $typography = is_array($settings['typography'] ?? null) ? $settings['typography'] : [];
        $authored = $typography['fontSizes'] ?? null;
        $theme['settings'] = $settings;
        $theme['settings']['typography'] = $typography;
        $theme['settings']['typography']['fontSizes'] = $profile;

        // The prompt forbids the model to author sizes, so the normal case is
        // an absent array: the build supplies the ramp and replaces nothing.
        // A writeback line exists only when an authored scale was displaced.
        if ($authored === null || $authored === $profile) {
            return [$theme, []];
        }
        return [$theme, [
            'theme/theme.json: settings.typography.fontSizes authored '
                . Warnings::value($authored) . ' delivered committed "' . $scale
                . '" modular scale; disposition replaced model-authored scale with deterministic direction token',
        ]];
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
        [$theme, $motionWarnings] = self::removeMotionKitCustomCss($theme);
        [$theme, $resourceWarnings] = self::removeResourceLoadingCustomCss($theme);
        [$theme, $fontFaceWarnings] = self::removeForeignFontFaces($theme);
        return [
            $theme,
            array_merge(
                $colorWarnings,
                $shadowWarnings,
                $shapeWarnings,
                $motionWarnings,
                $resourceWarnings,
                $fontFaceWarnings,
            ),
        ];
    }

    /**
     * Remove font faces theme.json would fetch from a host the model chose.
     *
     * A `fontFace` entry's `src` becomes a CSS `url()` once WordPress renders
     * theme.json: `@font-face { src: url(https://…) }` on every page. The
     * build bundles every face it ships as a theme file under assets/fonts/
     * (BundleFontsStep), so `file:./assets/fonts/…` is the one legitimate
     * form. Anything else is a fetch from a model-chosen host, and the
     * sink-side red-team test found it shipping on the HTML-first graph,
     * which has no bundling step to overwrite it (BIGR-969).
     *
     * A face keeps its bundled sources and loses the rest; a face with no
     * bundled source goes; a family with no face left loses the key. Every
     * removal is recorded durably.
     *
     * Pure — unit-testable.
     *
     * @param array<mixed> $theme
     * @return array{0:array<mixed>,1:list<string>} theme, warnings
     */
    public static function removeForeignFontFaces(array $theme): array
    {
        $families = $theme['settings']['typography']['fontFamilies'] ?? null;
        if (!is_array($families)) {
            return [$theme, []];
        }

        $warnings = [];
        foreach ($families as $i => $family) {
            if (!is_array($family) || !isset($family['fontFace'])) {
                continue;
            }
            $slug = is_string($family['slug'] ?? null) && $family['slug'] !== '' ? $family['slug'] : (string) $i;
            $location = "theme/theme.json settings.typography.fontFamilies[{$slug}].fontFace";
            if (!is_array($family['fontFace'])) {
                $warnings[] = "{$location}: authored " . Warnings::value($family['fontFace'])
                    . '; delivered removed; disposition fontFace must be a list of faces';
                unset($families[$i]['fontFace']);
                continue;
            }

            $kept = [];
            foreach ($family['fontFace'] as $face) {
                if (!is_array($face)) {
                    $warnings[] = "{$location}: authored " . Warnings::value($face)
                        . '; delivered removed; disposition a face must be an object';
                    continue;
                }
                $sources = $face['src'] ?? [];
                $sources = is_string($sources) ? [$sources] : (is_array($sources) ? $sources : []);
                $bundled = [];
                foreach ($sources as $source) {
                    if (is_string($source)
                        && preg_match('#^file:\./assets/fonts/[A-Za-z0-9][A-Za-z0-9._-]*$#', $source) === 1
                    ) {
                        $bundled[] = $source;
                        continue;
                    }
                    $warnings[] = "{$location}: authored src " . Warnings::value($source)
                        . '; delivered removed; disposition font faces ship only as bundled theme files'
                        . ' under assets/fonts/, never from another host';
                }
                if ($bundled === []) {
                    continue;
                }
                $face['src'] = $bundled;
                $kept[] = $face;
            }
            if ($kept === []) {
                unset($families[$i]['fontFace']);
            } else {
                $families[$i]['fontFace'] = $kept;
            }
        }
        $theme['settings']['typography']['fontFamilies'] = $families;
        return [$theme, $warnings];
    }

    /**
     * Remove every resource-loading form from theme.json custom CSS.
     *
     * WordPress trusts theme-origin CSS: a `css` string under `styles` ships
     * to every visitor exactly as written, with no kses pass and no core
     * sanitizer. This was the one CSS sink the build never scrubbed.
     * PageStylesStep and CustomMotionStep both refuse `@import` and `url()`,
     * but a model-authored `@import url(https://…)` or `background:
     * url(https://…)` in theme.json shipped verbatim. That is a beacon and a
     * third-party dependency the site never asked for (BIGR-969).
     *
     * Three rungs, smallest unit first. CssScrub removes `@import` statements
     * and declarations that reference an external authority. CssChecks then
     * drops any remaining declaration whose value uses a resource-loading
     * function, relative and data: URLs included, which matches the
     * page-styles policy. When a loading form still survives both (an
     * unparseable region, an at-rule prelude), the whole string is removed
     * rather than delivered unreviewed. Every removal is recorded durably.
     *
     * Every `css` string under `styles` is walked, at any depth, so a rule
     * nested in a block, element, or variation style is scrubbed too.
     *
     * Pure — unit-testable.
     *
     * @param array<mixed> $theme
     * @return array{0:array<mixed>,1:list<string>} theme, warnings
     */
    public static function removeResourceLoadingCustomCss(array $theme): array
    {
        if (!is_array($theme['styles'] ?? null)) {
            return [$theme, []];
        }

        $warnings = [];
        $remove = static function (array $node, string $path) use (&$remove, &$warnings): array {
            foreach ($node as $key => $value) {
                if ($key === 'css' && is_string($value)) {
                    $node[$key] = self::scrubResourceLoadingCss($value, "{$path}.css", $warnings);
                    continue;
                }
                if (is_array($value)) {
                    $node[$key] = $remove($value, $path . '.' . $key);
                }
            }
            return $node;
        };
        $theme['styles'] = $remove($theme['styles'], 'styles');
        return [$theme, $warnings];
    }

    /**
     * One custom CSS string, scrubbed of every resource-loading form.
     *
     * @param list<string> $warnings
     */
    private static function scrubResourceLoadingCss(string $css, string $location, array &$warnings): string
    {
        $scrubbed = CssScrub::scrub($css);
        foreach ($scrubbed['removals'] as $removal) {
            $warnings[] = "theme/theme.json {$location}: authored "
                . Warnings::value($removal['authored_value'])
                . "; delivered {$removal['delivered_value']}; disposition {$removal['disposition']}";
        }

        [$repaired, $dropped] = CssChecks::dropDeclarations(
            $scrubbed['css'],
            static fn (array $declaration): bool =>
                CssChecks::resourceLoadingProblem($declaration['value']) !== null,
        );
        foreach ($dropped as $declaration) {
            $warnings[] = "theme/theme.json {$location}: authored declaration "
                . Warnings::value(trim($declaration['raw']))
                . '; delivered removed; disposition removed a resource-loading CSS value'
                . ' — theme.json custom CSS may not fetch images, fonts, or stylesheets';
        }

        // The fallback judges comment-free CSS: a comment that mentions
        // url() loads nothing, and losing the whole string for it would cut
        // far above the smallest harmful unit.
        if (CssChecks::resourceLoadingProblem(CssChecks::withoutComments($repaired)) !== null) {
            $warnings[] = "theme/theme.json {$location}: authored " . Warnings::value($css)
                . '; delivered removed; disposition removed the whole custom CSS string'
                . ' — a resource-loading form survived declaration-level removal';
            return '';
        }
        return $repaired;
    }

    /**
     * Remove custom-CSS declarations that redefine a motion-kit class.
     *
     * The motion kit is a closed system: `assets/motion/motion.css` owns every
     * hidden and revealed state for `.reveal*`, `.stagger-children`,
     * `.hero-entrance`, the ambient classes and the hover classes, and
     * `assets/motion/motion.js` drives them. Both ship verbatim and are never
     * LLM-generated. `prompts/page-styles.md` already forbids writing CSS for
     * those classes, and PageStylesStep enforces it through its scoped-selector
     * policy; theme.json custom CSS had neither the instruction nor the check.
     *
     * pulso2 shipped `.reveal-up { opacity: 0; transform: …; clip-path: inset(0
     * 0 100% 0); animation: nn-reveal-up … view() }` in `styles.css`. The kit's
     * `motion-skip` escape — applied to every target already above the fold —
     * clears `opacity` and `animation` but deliberately leaves `transform`
     * alone for authored hover effects, so the generated `clip-path` survived
     * with nothing left to animate it away and the whole hero copy was clipped
     * to nothing (BIGR-881).
     *
     * Every `css` string under `styles` is walked, at any depth, so a rule
     * nested in a block or element style is repaired too.
     *
     * @param array<mixed> $theme
     * @return array{0:array<mixed>,1:list<string>} theme, warnings
     */
    private static function removeMotionKitCustomCss(array $theme): array
    {
        if (!is_array($theme['styles'] ?? null)) {
            return [$theme, []];
        }

        $warnings = [];
        $remove = static function (array $node, string $path) use (&$remove, &$warnings): array {
            foreach ($node as $key => $value) {
                if ($key === 'css' && is_string($value)) {
                    [$repaired, $dropped] = CssChecks::dropMotionKitDeclarations($value);
                    foreach ($dropped as $declaration) {
                        $warnings[] = "theme/theme.json {$path}.css: authored declaration "
                            . Warnings::value($declaration)
                            . '; delivered removed; disposition removed custom CSS for a motion-kit class'
                            . ' — assets/motion/ owns those states and the JS driver reveals them';
                    }
                    // The committed profile owns every `--motion-*` value on
                    // :root; a local override retunes the element and
                    // everything under it. Both sibling steps already check
                    // this and prompts/theme-json.md already promises the
                    // build removes it — this is the missing half (BIGR-887).
                    [$repaired, $overrides] = CssChecks::dropDeclarations(
                        $repaired,
                        static fn (array $declaration): bool =>
                            str_starts_with(strtolower($declaration['property']), '--motion-'),
                    );
                    foreach ($overrides as $declaration) {
                        $warnings[] = "theme/theme.json {$path}.css: authored declaration "
                            . Warnings::value(trim($declaration['raw']))
                            . '; delivered removed; disposition motion custom properties are owned by'
                            . ' the committed profile and cannot be overridden';
                    }
                    if ($dropped !== [] || $overrides !== []) {
                        $node[$key] = $repaired;
                    }
                    continue;
                }
                if (is_array($value)) {
                    $node[$key] = $remove($value, $path . '.' . $key);
                }
            }
            return $node;
        };
        $theme['styles'] = $remove($theme['styles'], 'styles');
        return [$theme, $warnings];
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
     * Publish the one build-owned shadow preset consumed by Depth::kitCss().
     * Unrelated generated presets are left intact because removing one after
     * generation could invalidate a reference in the same build. The prompt
     * no longer asks the model to spend tokens inventing them. Duplicate or
     * conflicting `depth` slugs collapse to the committed definition.
     *
     * A direction without an explicit commitment is a no-op, matching
     * depthFor(): an isolated run must not invent a visual choice.
     *
     * @param array<mixed> $theme
     * @return array{0:array<mixed>,1:list<string>} theme, successful repairs
     */
    public static function repairDepthPreset(array $theme, mixed $depth): array
    {
        $preset = Depth::preset($depth);
        if ($preset === null) {
            return [$theme, []];
        }

        $repairs = [];
        if (!is_array($theme['settings'] ?? null)
            || (($theme['settings'] ?? []) !== [] && array_is_list($theme['settings']))) {
            $theme['settings'] = [];
            $repairs[] = 'theme/theme.json settings authored malformed; delivered object for depth preset';
        }
        if (!is_array($theme['settings']['shadow'] ?? null)
            || (($theme['settings']['shadow'] ?? []) !== [] && array_is_list($theme['settings']['shadow']))) {
            $theme['settings']['shadow'] = [];
            $repairs[] = 'theme/theme.json settings.shadow authored malformed; delivered object for depth preset';
        }

        $authored = $theme['settings']['shadow']['presets'] ?? [];
        if (!is_array($authored) || ($authored !== [] && !array_is_list($authored))) {
            $authored = [];
            $repairs[] = 'theme/theme.json settings.shadow.presets authored malformed; delivered bounded preset list';
        }

        $delivered = [];
        $inserted = false;
        foreach ($authored as $entry) {
            if (is_array($entry) && strtolower(trim((string) ($entry['slug'] ?? ''))) === 'depth') {
                if (!$inserted) {
                    $delivered[] = $preset;
                    $inserted = true;
                }
                continue;
            }
            $delivered[] = $entry;
        }
        if (!$inserted) {
            $delivered[] = $preset;
        }
        if ($delivered !== $authored) {
            $repairs[] = 'theme/theme.json settings.shadow.presets.depth authored '
                . Warnings::value(array_values(array_filter(
                    $authored,
                    static fn (mixed $entry): bool => is_array($entry)
                        && strtolower(trim((string) ($entry['slug'] ?? ''))) === 'depth',
                )))
                . '; delivered ' . Warnings::value($preset)
                . '; disposition wired committed depth and collapsed duplicate slug definitions';
        }
        $theme['settings']['shadow']['presets'] = $delivered;

        return [$theme, $repairs];
    }

    /**
     * Execute the committed CTA construction at styles.elements.button while
     * preserving model-authored typography and shape-owned border.radius.
     * Competing core/button, variation, nested-element, responsive and
     * interaction construction is removed before the authoritative base and
     * states are installed. A pre-field direction is a complete no-op.
     *
     * @param array<mixed> $theme
     * @return array{0:array<mixed>,1:list<string>} theme, successful repair notes
     */
    public static function repairCtaStyle(array $theme, string $style): array
    {
        $desired = CtaStyle::themeStyle($style);
        if ($desired === null) {
            return [$theme, []];
        }

        $authoredTheme = $theme;
        $repairs = [];
        $styles = $theme['styles'] ?? null;
        if (!is_array($styles) || ($styles !== [] && array_is_list($styles))) {
            $styles = [];
        }
        $authoredLabel = $styles['elements']['button']['color']['text'] ?? null;
        $styles = self::stripCompetingCtaStyles($styles, 'styles', null, $style, $repairs);

        $elements = $styles['elements'] ?? null;
        if (!is_array($elements) || ($elements !== [] && array_is_list($elements))) {
            $elements = [];
        }
        $button = $elements['button'] ?? null;
        if (!is_array($button) || ($button !== [] && array_is_list($button))) {
            if (array_key_exists('button', $elements)) {
                $repairs[] = 'theme/theme.json styles.elements.button: authored '
                    . Warnings::value($button)
                    . '; delivered object; disposition replaced malformed button style to enforce committed '
                    . $style . ' CTA construction';
            }
            $button = [];
        }
        $committedCss = is_string($desired['css'] ?? null) ? $desired['css'] : null;
        unset($desired['css']);
        $button = self::mergeCommittedCtaStyle(
            $button,
            $desired,
            'styles.elements.button',
            $style,
            $repairs,
        );
        if ($committedCss !== null) {
            $residualCss = is_string($button['css'] ?? null) ? trim($button['css']) : '';
            $deliveredCss = $residualCss === '' ? $committedCss : $residualCss . "\n" . $committedCss;
            if (($button['css'] ?? null) !== $deliveredCss) {
                $repairs[] = 'theme/theme.json styles.elements.button.css: authored '
                    . Warnings::value($button['css'] ?? null)
                    . ' delivered ' . Warnings::value($deliveredCss)
                    . '; disposition appended build-owned CSS for committed ' . $style . ' CTA construction';
            }
            $button['css'] = $deliveredCss;
        }

        // Solid's accent can be light or dark, so ContrastFixStep owns the
        // exact readable label choice. Preserve only a prior deterministic
        // base/contrast result — in either preset spelling, because
        // ContrastFixStep writes the CSS-variable form and this repair is
        // replayed on its output by validate-theme; arbitrary model colors are
        // not part of the bounded construction and are replaced before that
        // later check.
        if ($style === 'solid') {
            $label = self::deterministicCtaLabel($authoredLabel) !== null
                ? $authoredLabel
                : 'var:preset|color|base';
            if (($button['color']['text'] ?? null) !== $label) {
                $repairs[] = 'theme/theme.json styles.elements.button.color.text: authored '
                    . Warnings::value($button['color']['text'] ?? null)
                    . ' delivered ' . Warnings::value($label)
                    . '; disposition supplied a label color for deterministic contrast repair';
            }
            $button['color']['text'] = $label;
        }

        // The `block` wrapper needs one rule theme.json cannot express as
        // structured style (see CtaStyle::BLOCK_WRAPPER_CSS). Ship it
        // through top-level styles.css, strip a stale copy first so a changed
        // commitment converges, and touch nothing when neither applies.
        $authoredRootCss = $styles['css'] ?? null;
        $hasStaleRule = is_string($authoredRootCss)
            && str_contains($authoredRootCss, CtaStyle::BLOCK_WRAPPER_CSS);
        if ($style === 'block' || $hasStaleRule) {
            $stripped = trim(str_replace(
                CtaStyle::BLOCK_WRAPPER_CSS,
                '',
                is_string($authoredRootCss) ? $authoredRootCss : '',
            ));
            $deliveredRoot = $stripped;
            if ($style === 'block') {
                $deliveredRoot = $stripped === ''
                    ? CtaStyle::BLOCK_WRAPPER_CSS
                    : $stripped . "\n" . CtaStyle::BLOCK_WRAPPER_CSS;
            }
            $deliveredValue = $deliveredRoot === '' ? null : $deliveredRoot;
            if ($deliveredValue !== $authoredRootCss) {
                $repairs[] = 'theme/theme.json styles.css: authored '
                    . Warnings::value($authoredRootCss)
                    . ' delivered ' . Warnings::value($deliveredValue)
                    . '; disposition ' . ($style === 'block'
                        ? 'appended build-owned wrapper rules for committed block CTA construction'
                        : 'removed stale block CTA wrapper rule for committed ' . $style . ' CTA construction');
            }
            if ($deliveredValue === null) {
                unset($styles['css']);
            } else {
                $styles['css'] = $deliveredValue;
            }
        }

        $elements['button'] = $button;
        $styles['elements'] = $elements;
        $theme['styles'] = $styles;
        if (self::sameJsonValue($theme, $authoredTheme)) {
            return [$theme, []];
        }
        return [$theme, self::changedCtaRepairs($repairs, $authoredTheme, $theme)];
    }

    /**
     * The palette slug of a label ink ContrastFixStep may have chosen, in
     * either preset spelling theme.json accepts, or null for anything else.
     */
    private static function deterministicCtaLabel(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        if (preg_match('/^var:preset\|color\|(base|contrast)$/', trim($value), $m) === 1
            || preg_match('/^var\(--wp--preset--color--(base|contrast)\)$/', trim($value), $m) === 1
        ) {
            return $m[1];
        }
        return null;
    }

    /**
     * Only the rows whose leaf really changed. The stripper and the merger
     * narrate every leaf they pass through — a competing declaration removed,
     * the committed value enforced — and most of those pairs land back on the
     * value the theme already held. One foreign label used to surface all
     * twenty-six of them as warning rows. A row is kept when its path reads
     * differently in the delivered theme than in the authored one, or when it
     * names no path this filter can read.
     *
     * @param list<string> $repairs
     * @param array<mixed> $authored
     * @param array<mixed> $delivered
     * @return list<string>
     */
    private static function changedCtaRepairs(array $repairs, array $authored, array $delivered): array
    {
        /** @var array<string,list<string>> $byPath rows per path, in first-seen order */
        $byPath = [];
        $kept = [];
        foreach ($repairs as $repair) {
            if (preg_match('/^theme\/theme\.json ([^\s:]+):/', $repair, $m) !== 1) {
                $kept[] = $repair;
                continue;
            }
            $byPath[$m[1]][] = $repair;
        }
        foreach ($byPath as $path => $rows) {
            $before = self::jsonPathValue($authored, $path);
            $after = self::jsonPathValue($delivered, $path);
            if (self::sameJsonValue($before, $after)) {
                continue;
            }
            if (count($rows) === 1) {
                $kept[] = $rows[0];
                continue;
            }
            // A leaf removed and then enforced is one change; the merge row's
            // "authored null" is the stripper's doing, not the model's. Say
            // what the model wrote and what shipped, with the final verdict.
            $last = end($rows);
            $disposition = preg_match('/; disposition (.*)$/', (string) $last, $d) === 1
                ? $d[1]
                : 'enforced committed CTA construction';
            $kept[] = 'theme/theme.json ' . $path . ': authored ' . Warnings::value($before)
                . ' delivered ' . Warnings::value($after) . '; disposition ' . $disposition;
        }
        return array_values(array_unique($kept));
    }

    /** One dot-separated theme.json path, or null when any segment is missing. */
    private static function jsonPathValue(array $theme, string $path): mixed
    {
        $value = $theme;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    /** Compare decoded JSON values without treating object-key order as data. */
    private static function sameJsonValue(mixed $left, mixed $right): bool
    {
        return self::canonicalJsonValue($left) === self::canonicalJsonValue($right);
    }

    /** Recursively sort object-shaped arrays while preserving list order and scalar types. */
    private static function canonicalJsonValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalJsonValue(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $child) {
            $value[$key] = self::canonicalJsonValue($child);
        }
        return $value;
    }

    /**
     * @param array<mixed> $node
     * @param ?string $target button | null
     * @param list<string> $repairs
     * @return array<mixed>
     */
    private static function stripCompetingCtaStyles(
        array $node,
        string $path,
        ?string $target,
        string $style,
        array &$repairs,
    ): array {
        if ($target === 'button') {
            $preserveSolidLabel = $style === 'solid' && $path === 'styles.elements.button';
            $color = $node['color'] ?? null;
            if (is_array($color) && ($color === [] || !array_is_list($color))) {
                foreach (['background', 'gradient', 'text'] as $property) {
                    if ($property === 'text' && $preserveSolidLabel) {
                        continue;
                    }
                    self::removeCtaLeaf(
                        $color,
                        $property,
                        $path . '.color.' . $property,
                        $style,
                        $repairs,
                    );
                }
                if ($color === []) {
                    unset($node['color']);
                } else {
                    $node['color'] = $color;
                }
            } elseif (array_key_exists('color', $node)) {
                self::removeCtaLeaf($node, 'color', $path . '.color', $style, $repairs);
            }

            $border = $node['border'] ?? null;
            if (is_array($border) && ($border === [] || !array_is_list($border))) {
                foreach (array_keys($border) as $property) {
                    if ($property === 'radius') {
                        continue;
                    }
                    self::removeCtaLeaf(
                        $border,
                        (string) $property,
                        $path . '.border.' . $property,
                        $style,
                        $repairs,
                    );
                }
                if ($border === []) {
                    unset($node['border']);
                } else {
                    $node['border'] = $border;
                }
            } elseif (array_key_exists('border', $node)) {
                self::removeCtaLeaf($node, 'border', $path . '.border', $style, $repairs);
            }

            $spacing = $node['spacing'] ?? null;
            if (is_array($spacing) && ($spacing === [] || !array_is_list($spacing))) {
                self::removeCtaLeaf($spacing, 'padding', $path . '.spacing.padding', $style, $repairs);
                if ($spacing === []) {
                    unset($node['spacing']);
                } else {
                    $node['spacing'] = $spacing;
                }
            }

            $typography = $node['typography'] ?? null;
            if (is_array($typography) && ($typography === [] || !array_is_list($typography))) {
                self::removeCtaLeaf(
                    $typography,
                    'textDecoration',
                    $path . '.typography.textDecoration',
                    $style,
                    $repairs,
                );
                if ($typography === []) {
                    unset($node['typography']);
                } else {
                    $node['typography'] = $typography;
                }
            }
            if (is_string($node['css'] ?? null)) {
                $css = rtrim($node['css']);
                $ownedCss = CtaStyle::themeStyle($style)['css'] ?? null;
                if (is_string($ownedCss) && str_ends_with($css, $ownedCss)) {
                    $css = rtrim(substr($css, 0, -strlen($ownedCss)));
                }
                [$deliveredCss, $dropped] = CssChecks::dropDeclarations(
                    $css,
                    static fn (array $declaration): bool => CssChecks::isCtaAffectingDeclaration(
                        $declaration['property'],
                        $declaration['value'],
                    ),
                    true,
                );
                if ($dropped !== [] || $css !== rtrim($node['css'])) {
                    if (trim($deliveredCss) === '') {
                        unset($node['css']);
                    } else {
                        $node['css'] = $deliveredCss;
                    }
                    foreach ($dropped as $declaration) {
                        $repairs[] = 'theme/theme.json ' . $path . '.css: authored declaration '
                            . Warnings::value(trim($declaration['raw']))
                            . '; delivered removed; disposition removed competing custom CSS for committed '
                            . $style . ' CTA construction';
                    }
                }
            } elseif (array_key_exists('css', $node)) {
                self::removeCtaLeaf($node, 'css', $path . '.css', $style, $repairs);
            }
        }

        foreach ([['blocks', 'core/button'], ['elements', 'button']] as [$family, $ownedName]) {
            $children = $node[$family] ?? null;
            if (!is_array($children) || ($children !== [] && array_is_list($children))) {
                continue;
            }
            foreach ($children as $name => $child) {
                if (!is_string($name)
                    || !is_array($child)
                    || ($child !== [] && array_is_list($child))
                ) {
                    continue;
                }
                $childPath = $path . '.' . $family . '.' . $name;
                $child = self::stripCompetingCtaStyles(
                    $child,
                    $childPath,
                    $name === $ownedName ? 'button' : null,
                    $style,
                    $repairs,
                );
                if ($child === []) {
                    unset($children[$name]);
                } else {
                    $children[$name] = $child;
                }
            }
            if ($children === []) {
                unset($node[$family]);
            } else {
                $node[$family] = $children;
            }
        }

        $variations = $node['variations'] ?? null;
        if (is_array($variations) && ($variations === [] || !array_is_list($variations))) {
            foreach ($variations as $name => $child) {
                if (!is_string($name)
                    || !is_array($child)
                    || ($child !== [] && array_is_list($child))
                ) {
                    continue;
                }
                $child = self::stripCompetingCtaStyles(
                    $child,
                    $path . '.variations.' . $name,
                    $target,
                    $style,
                    $repairs,
                );
                if ($child === []) {
                    unset($variations[$name]);
                } else {
                    $variations[$name] = $child;
                }
            }
            if ($variations === []) {
                unset($node['variations']);
            } else {
                $node['variations'] = $variations;
            }
        }

        foreach (array_keys($node) as $state) {
            $child = $node[$state] ?? null;
            if (!is_string($state)
                || (!str_starts_with($state, ':')
                    && !str_starts_with($state, '@')
                    && !in_array($state, ['mobile', 'tablet', 'desktop'], true))
                || !is_array($child)
                || ($child !== [] && array_is_list($child))
            ) {
                continue;
            }
            $child = self::stripCompetingCtaStyles(
                $child,
                $path . '.' . $state,
                $target,
                $style,
                $repairs,
            );
            if ($child === []) {
                unset($node[$state]);
            } else {
                $node[$state] = $child;
            }
        }
        return $node;
    }

    /** @param array<mixed> $node @param list<string> $repairs */
    private static function removeCtaLeaf(
        array &$node,
        string $property,
        string $path,
        string $style,
        array &$repairs,
    ): void {
        if (!array_key_exists($property, $node)) {
            return;
        }
        $repairs[] = 'theme/theme.json ' . $path . ': authored '
            . Warnings::value($node[$property])
            . '; delivered removed; disposition removed competing declaration for committed '
            . $style . ' CTA construction';
        unset($node[$property]);
    }

    /**
     * @param array<mixed> $delivered
     * @param array<mixed> $committed
     * @param list<string> $repairs
     * @return array<mixed>
     */
    private static function mergeCommittedCtaStyle(
        array $delivered,
        array $committed,
        string $path,
        string $style,
        array &$repairs,
    ): array {
        foreach ($committed as $property => $value) {
            $childPath = $path . '.' . $property;
            if (is_array($value) && ($value === [] || !array_is_list($value))) {
                $existing = $delivered[$property] ?? null;
                if (!is_array($existing) || ($existing !== [] && array_is_list($existing))) {
                    if (array_key_exists($property, $delivered)) {
                        $repairs[] = 'theme/theme.json ' . $childPath . ': authored '
                            . Warnings::value($existing)
                            . '; delivered object; disposition replaced malformed container for committed '
                            . $style . ' CTA construction';
                    }
                    $existing = [];
                }
                $delivered[$property] = self::mergeCommittedCtaStyle(
                    $existing,
                    $value,
                    $childPath,
                    $style,
                    $repairs,
                );
                continue;
            }
            if (($delivered[$property] ?? null) !== $value) {
                $repairs[] = 'theme/theme.json ' . $childPath . ': authored '
                    . Warnings::value($delivered[$property] ?? null)
                    . ' delivered ' . Warnings::value($value)
                    . '; disposition enforced committed ' . $style . ' CTA construction';
            }
            $delivered[$property] = $value;
        }
        return $delivered;
    }

    /**
     * Execute the committed site-wide heading case/tracking language while
     * preserving every other typography choice, especially lineHeight.
     * Per-level, core/heading, core/post-title, core/site-title, variation,
     * and responsive structured leaves are removed so the authoritative
     * styles.elements.heading pair can inherit. A malformed styles.elements or
     * styles.elements.heading node (scalar or list) is rebuilt so the pair
     * always lands; each rebuild is recorded as a repair. A direction
     * persisted before this field existed remains a complete no-op.
     *
     * Scope: this repair owns structured theme.json leaves only. Generated
     * page CSS is guarded separately by PageStylesStep's declaration checks;
     * wp:heading block ATTRIBUTES stay outside both guards as a documented
     * boundary — prompts/section.md forbids them and SupportDomainGuard's
     * reviewed typography domain deliberately keeps the keys available to
     * other blocks.
     *
     * @param array<mixed> $theme
     * @return array{0:array<mixed>,1:list<string>} theme, successful repair notes
     */
    public static function repairTypeTreatment(array $theme, string $treatment): array
    {
        $committed = TypeTreatment::typography($treatment);
        if ($committed === null) {
            return [$theme, []];
        }

        $repairs = [];
        $styles = is_array($theme['styles'] ?? null)
            && (($theme['styles'] ?? []) === [] || !array_is_list($theme['styles']))
                ? $theme['styles']
                : [];
        $theme['styles'] = self::repairTypeTreatmentStyleNode(
            $styles,
            'styles',
            null,
            false,
            $treatment,
            $committed,
            $repairs,
        );
        return [$theme, $repairs];
    }

    /**
     * @param array<mixed> $node
     * @param ?string $target heading | null
     * @param array{textTransform:string,letterSpacing:string} $committed
     * @param list<string> $repairs
     * @return array<mixed>
     */
    private static function repairTypeTreatmentStyleNode(
        array $node,
        string $path,
        ?string $target,
        bool $authoritative,
        string $treatment,
        array $committed,
        array &$repairs,
    ): array {
        if ($target === 'heading') {
            $typography = $node['typography'] ?? null;
            if ($authoritative) {
                if (!is_array($typography)
                    || ($typography !== [] && array_is_list($typography))
                ) {
                    if (array_key_exists('typography', $node)) {
                        $repairs[] = "theme/theme.json {$path}.typography: authored "
                            . Warnings::value($typography)
                            . '; delivered object containing the committed heading treatment'
                            . '; disposition replaced malformed typography container';
                    }
                    $typography = [];
                }
                foreach ($committed as $property => $value) {
                    if (($typography[$property] ?? null) !== $value) {
                        $repairs[] = "theme/theme.json {$path}.typography.{$property}: authored "
                            . Warnings::value($typography[$property] ?? null)
                            . ' delivered ' . Warnings::value($value)
                            . "; disposition enforced committed {$treatment} heading treatment";
                    }
                    $typography[$property] = $value;
                }
                $node['typography'] = $typography;
            } elseif (is_array($typography)
                && ($typography === [] || !array_is_list($typography))
            ) {
                foreach (array_keys($committed) as $property) {
                    if (!array_key_exists($property, $typography)) {
                        continue;
                    }
                    $repairs[] = "theme/theme.json {$path}.typography.{$property}: authored "
                        . Warnings::value($typography[$property])
                        . '; delivered removed; disposition inherited the committed '
                        . $treatment . ' heading treatment';
                    unset($typography[$property]);
                }
                if ($typography === []) {
                    unset($node['typography']);
                } else {
                    $node['typography'] = $typography;
                }
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
                $blocks[$block] = self::repairTypeTreatmentStyleNode(
                    $child,
                    $path . '.blocks.' . $block,
                    in_array($block, ['core/heading', 'core/post-title', 'core/site-title'], true)
                        ? 'heading'
                        : null,
                    false,
                    $treatment,
                    $committed,
                    $repairs,
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
                $isHeading = in_array($element, ['heading', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true);
                $childPath = $path . '.elements.' . $element;
                $elements[$element] = self::repairTypeTreatmentStyleNode(
                    $child,
                    $childPath,
                    $isHeading ? 'heading' : null,
                    $childPath === 'styles.elements.heading',
                    $treatment,
                    $committed,
                    $repairs,
                );
            }
            $rootHeading = $elements['heading'] ?? null;
            $rootHeadingMalformed = array_key_exists('heading', $elements)
                && (!is_array($rootHeading)
                    || ($rootHeading !== [] && array_is_list($rootHeading)));
            if ($path === 'styles' && ($rootHeadingMalformed || !isset($elements['heading']))) {
                if ($rootHeadingMalformed) {
                    $repairs[] = 'theme/theme.json styles.elements.heading: authored '
                        . Warnings::value($rootHeading)
                        . '; delivered object containing the committed heading treatment'
                        . '; disposition replaced malformed heading element';
                }
                $elements['heading'] = self::repairTypeTreatmentStyleNode(
                    [],
                    $path . '.elements.heading',
                    'heading',
                    true,
                    $treatment,
                    $committed,
                    $repairs,
                );
            }
            $node['elements'] = $elements;
        } elseif ($path === 'styles') {
            if (array_key_exists('elements', $node)) {
                $repairs[] = 'theme/theme.json styles.elements: authored '
                    . Warnings::value($node['elements'])
                    . '; delivered object containing the committed heading treatment'
                    . '; disposition replaced malformed elements container';
            }
            $node['elements'] = [
                'heading' => self::repairTypeTreatmentStyleNode(
                    [],
                    'styles.elements.heading',
                    'heading',
                    true,
                    $treatment,
                    $committed,
                    $repairs,
                ),
            ];
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
                $variations[$variation] = self::repairTypeTreatmentStyleNode(
                    $child,
                    $path . '.variations.' . $variation,
                    $target,
                    false,
                    $treatment,
                    $committed,
                    $repairs,
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
            $node[$state] = self::repairTypeTreatmentStyleNode(
                $child,
                $path . '.' . $state,
                $target,
                false,
                $treatment,
                $committed,
                $repairs,
            );
        }

        return $node;
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
