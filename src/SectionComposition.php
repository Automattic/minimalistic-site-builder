<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Reviewed, code-owned catalog for page-section layout archetypes.
 *
 * This is the deliberate mirror of `HeroComposition`. Before it existed, a
 * section archetype was a seven-value string enum in `PagePlanStep` plus one
 * line of prose in two prompts, so the model reinterpreted the assignment on
 * every build. Here each archetype owns its complete executable metadata: the
 * surfaces it may be painted, its media range, its copy capacity, its root
 * class marker, its prompt fragment, and its eligibility predicate.
 *
 * Two boundaries keep this catalog honest:
 *
 * - `PagePlanStep` remains the only place that SELECTS an archetype. This
 *   class answers "what is this archetype" and "may this site use it"; it
 *   never picks one.
 * - `markupWarnings()` is ADVISORY. It inspects delivered markup, records
 *   actionable rows, and never throws at the step boundary — a defect in
 *   generated content must never abort the build (see AGENTS.md).
 */
final class SectionComposition
{
    /**
     * The composition menu. `PagePlanStep::ARCHETYPES` and the archetype list
     * in `prompts/page-plan.md` both describe this same set.
     *
     * @var list<string>
     */
    public const ARCHETYPES = [
        'full-bleed-cover',
        'asymmetric-split',
        'centered-stack',
        'offset-grid',
        'equal-card-grid',
        'list-with-thumbnails',
        'bento-grid',
        'faq-split',
        'cta-panel',
    ];

    /** The contained panel a closing cta-panel band wraps its invitation in. */
    public const CTA_PANEL_CLASS = 'cta-panel';

    /** The fewest accordion items a faq-split must carry to read as one. */
    public const FAQ_MIN_ITEMS = 3;

    /** The one card in a bento that is set apart by an inverted surface. */
    public const BENTO_HIGHLIGHT_CLASS = 'card-highlight';

    /** Background treatments a planned section may carry. */
    public const BACKGROUNDS = ['base', 'tinted', 'contrast', 'image'];

    /** Selection-only copy dimension, the same three steps heroes use. */
    public const COPY_CAPACITIES = ['compact', 'standard', 'expanded'];

    /** The root class family that marks a delivered section's assignment. */
    public const MARKER_PREFIX = 'section-composition--';

    /**
     * The class one column carries when the archetype asked for a pinned lead.
     *
     * `ScaffoldThemeStep::STYLE_CSS` owns the guaranteed rule for this token:
     * `position: sticky` with a top offset, `align-self: flex-start`, and the
     * sticky part gated to `min-width: 782px`. `PageStylesStep::CLASSES` only
     * documents a contract for a model-authored appendix, and that appendix can
     * be dropped, so this archetype never depends on it. The archetype does not
     * invent a mechanism; it decides WHEN the guaranteed one is asked for,
     * which is the part the model was getting wrong on its own.
     */
    public const PIN_CLASS = 'sticky-side';

    /**
     * Every placeholder a recipe fragment may carry. `recipeVars()` is the one
     * place that fills them, so a fragment cannot grow a variable no caller
     * supplies — `PromptRenderer` throws on an unresolved placeholder, and that
     * throw would land mid-build.
     *
     * @var list<string>
     */
    public const RECIPE_VARS = ['pin_directive'];

    /**
     * How many more images one region may hold than its sibling before the row
     * is reported as unbalanced.
     *
     * Two is the first difference that cannot be absorbed. One plate against
     * none is the archetype's most common shape and is fine. Two against none,
     * or three against one, puts a stack of media beside a single block and the
     * short region necessarily ends far above the long one. The `cat-luthier`
     * band that motivated BIGR-945 ran 1 image against 3 and rendered 3107px
     * tall with roughly 1950px of empty background under its short region.
     */
    private const MEDIA_SPREAD_LIMIT = 2;

    /**
     * Eligibility context keys. A predicate reads only these, so a caller
     * cannot silently pass a fact the catalog never consults.
     *
     * @var list<string>
     */
    public const CONTEXT_KEYS = [self::CONTEXT_BROKEN_GRID_RHYTHM];

    /**
     * True when the committed design direction chose a band rhythm that
     * breaks the grid: `offset` or `gallery` (DesignDirectionStep::RHYTHMS).
     */
    public const CONTEXT_BROKEN_GRID_RHYTHM = 'broken_grid_rhythm';

    /**
     * The band rhythms under which a staggered row is a design decision
     * rather than an accident.
     *
     * @var list<string>
     */
    public const BROKEN_GRID_RHYTHMS = ['offset', 'gallery'];

    /**
     * @var array<string,array<string,mixed>>
     *
     * Every entry owns its complete executable metadata. The prompt fragment
     * carries the authoring guidance; this table stays the structural source
     * of truth.
     *
     * `backgrounds` records what the pipeline accepts TODAY: the plan may pair
     * any archetype with any of the four treatments, and `page-plan.md` says
     * so. The field is therefore permissive on purpose rather than inert — a
     * later archetype that genuinely cannot hold a surface narrows its own
     * list without touching the six entries beside it.
     *
     * The media ranges are deliberately wide. They exist to catch an archetype
     * the model ignored (a `list-with-thumbnails` with no thumbnail), not to
     * ration imagery, which `collect-images` and the image budget already own.
     */
    private const CATALOG = [
        'full-bleed-cover' => [
            'backgrounds' => ['base', 'tinted', 'contrast', 'image'],
            'default_background' => 'image',
            'min_images' => 0,
            'max_images' => 6,
            'copy_capacity' => 'compact',
            // No row requirement: one cover with copy over it is the topology.
            // A "must contain wp:cover" check is deliberately absent — a band
            // planned on a non-image surface legitimately ships as a
            // full-width group with a gradient, so the check would report a
            // valid composition. L2 assigns the surface and the archetype
            // together, and can add the check without that ambiguity.
            'requires_row' => false,
            'requires_context' => [],
            'ineligible_reason' => '',
            'root_hook' => '.section-composition--full-bleed-cover',
            'prompt' => 'section-compositions/full-bleed-cover.md',
        ],
        // BIGR-945: the one unequal-column recipe.
        //
        // It replaced `mixed-width-editorial`. The two shared one topology —
        // a `wp:columns` of unequal widths summing to 100%, run wide or full,
        // with `copy-flush` on the band's own copy stack — and their metadata
        // differed in `max_images` alone. They differed in prose only: two
        // regions against two or three, and a lead-and-support reading against
        // a feature-and-notes one. Nothing enforced either difference, so a
        // 60/40 row of one image and one copy block was a legal delivery of
        // both names, and the planner spent 77% of its archetype budget on the
        // vaguer of the two (see `PagePlanStep::ARCHETYPE_SHARE_DIVISOR`).
        //
        // The region count and the weight reading survive as an authoring
        // choice inside the prompt fragment, NOT as catalog metadata: no
        // planned field carries them, so nothing here could check one. Making
        // them checked axes — the `HeroComposition` `media_aspect` /
        // `media_weight` treatment BIGR-912 gave three merged hero recipes —
        // needs a page-plan field first, and is deliberately left to that
        // change rather than declared here and left inert.
        //
        // The wider `max_images` is the retired archetype's, kept so a
        // three-region feature-and-notes row still fits.
        'asymmetric-split' => [
            'backgrounds' => ['base', 'tinted', 'contrast', 'image'],
            'default_background' => 'base',
            'min_images' => 0,
            'max_images' => 12,
            'copy_capacity' => 'standard',
            'requires_row' => true,
            'requires_context' => [],
            'ineligible_reason' => '',
            'root_hook' => '.section-composition--asymmetric-split',
            'prompt' => 'section-compositions/asymmetric-split.md',
            // The one archetype built from regions of deliberately different
            // weight. Two consequences follow, and both are checked below: its
            // regions can end at very different heights, so it is the only
            // archetype that may ask for a pinned lead; and a lopsided media
            // spread across those regions is a defect here, where it is the
            // whole point of a stagger and unremarkable in a card row.
            'unequal_regions' => true,
        ],
        'centered-stack' => [
            'backgrounds' => ['base', 'tinted', 'contrast', 'image'],
            'default_background' => 'base',
            'min_images' => 0,
            'max_images' => 2,
            'copy_capacity' => 'standard',
            // One column is the whole point, so no row block is required and
            // none is forbidden either — a stack may still hold one grid.
            'requires_row' => false,
            'requires_context' => [],
            'ineligible_reason' => '',
            'root_hook' => '.section-composition--centered-stack',
            'prompt' => 'section-compositions/centered-stack.md',
        ],
        'offset-grid' => [
            'backgrounds' => ['base', 'tinted', 'contrast', 'image'],
            'default_background' => 'base',
            // A stagger needs at least two items to be visible as a stagger.
            'min_images' => 2,
            'max_images' => 12,
            'copy_capacity' => 'compact',
            'requires_row' => true,
            'requires_context' => [self::CONTEXT_BROKEN_GRID_RHYTHM],
            'ineligible_reason' => 'staggered rows need an offset or gallery band rhythm from the design direction',
            'root_hook' => '.section-composition--offset-grid',
            'prompt' => 'section-compositions/offset-grid.md',
        ],
        'equal-card-grid' => [
            'backgrounds' => ['base', 'tinted', 'contrast', 'image'],
            'default_background' => 'base',
            'min_images' => 0,
            'max_images' => 12,
            'copy_capacity' => 'standard',
            'requires_row' => true,
            'requires_context' => [],
            'ineligible_reason' => '',
            'root_hook' => '.section-composition--equal-card-grid',
            'prompt' => 'section-compositions/equal-card-grid.md',
        ],
        'list-with-thumbnails' => [
            'backgrounds' => ['base', 'tinted', 'contrast', 'image'],
            'default_background' => 'base',
            // "each a small image beside its text" — a row with no thumbnail
            // is a plain list, not this archetype.
            'min_images' => 1,
            'max_images' => 12,
            'copy_capacity' => 'expanded',
            'requires_row' => true,
            'requires_context' => [],
            'ineligible_reason' => '',
            'root_hook' => '.section-composition--list-with-thumbnails',
            'prompt' => 'section-compositions/list-with-thumbnails.md',
        ],
        // frm W3a: the reference corpus' feature/proof composition. Two card
        // rows of unequal count (2 then 3, or 3 then 2) so the grid reads as
        // a bento rather than a ledger, and exactly one card inverted as the
        // highlight. Both facts are checked in markupWarnings().
        'bento-grid' => [
            'backgrounds' => ['base', 'tinted', 'contrast', 'image'],
            'default_background' => 'base',
            'min_images' => 0,
            'max_images' => 6,
            'copy_capacity' => 'standard',
            'requires_row' => true,
            'requires_context' => [],
            'ineligible_reason' => '',
            'root_hook' => '.section-composition--bento-grid',
            'prompt' => 'section-compositions/bento-grid.md',
        ],
        // frm W3b: the reference corpus' FAQ. A split band whose leading
        // region introduces the questions and whose trailing region is a
        // native accordion of core/details blocks (three or more). The item
        // count is checked in markupWarnings().
        'faq-split' => [
            'backgrounds' => ['base', 'tinted', 'contrast'],
            'default_background' => 'base',
            'min_images' => 0,
            'max_images' => 1,
            'copy_capacity' => 'expanded',
            'requires_row' => true,
            'requires_context' => [],
            'ineligible_reason' => '',
            'root_hook' => '.section-composition--faq-split',
            'prompt' => 'section-compositions/faq-split.md',
        ],
        // frm W3d: the reference corpus' closing invitation. The band stays on
        // the page ground; inside it one contained, rounded panel (the
        // committed panel radius) carries one heading, one line and exactly
        // one action, with an optional image beside the copy. Both the panel
        // and the single action are checked in markupWarnings().
        'cta-panel' => [
            'backgrounds' => ['base', 'tinted'],
            'default_background' => 'base',
            'min_images' => 0,
            'max_images' => 1,
            'copy_capacity' => 'compact',
            'requires_row' => false,
            'requires_context' => [],
            'ineligible_reason' => '',
            'root_hook' => '.section-composition--cta-panel',
            'prompt' => 'section-compositions/cta-panel.md',
        ],
    ];

    public static function isKnown(string $archetype): bool
    {
        return isset(self::CATALOG[$archetype]);
    }

    public static function assertKnown(string $archetype): void
    {
        if (!isset(self::CATALOG[$archetype])) {
            throw new \InvalidArgumentException(
                "unknown section archetype '{$archetype}' (use one of: "
                . implode(', ', self::ARCHETYPES) . ')'
            );
        }
    }

    /** @return array<string,mixed> */
    public static function metadata(string $archetype): array
    {
        self::assertKnown($archetype);
        return self::CATALOG[$archetype];
    }

    /** @return array<string,array<string,mixed>> */
    public static function catalog(): array
    {
        return self::CATALOG;
    }

    public static function recipeTemplate(string $archetype): string
    {
        return (string) self::metadata($archetype)['prompt'];
    }

    public static function rootHook(string $archetype): string
    {
        return (string) self::metadata($archetype)['root_hook'];
    }

    /** The one root class token a delivered section of this archetype carries. */
    public static function marker(string $archetype): string
    {
        self::assertKnown($archetype);
        return self::MARKER_PREFIX . $archetype;
    }

    /**
     * Whether this build asks the author to pin one region of the band.
     *
     * The catalog owns the condition so the model never has to judge it. Two
     * facts decide it, and both are already on the plan:
     *
     * - The archetype must be `pinnable`. Only an unequal-column band can have
     *   one region outrun the other; a card grid or a centered stack cannot.
     * - The section must carry an item pattern. `page-plan.md` sets that field
     *   on genuinely list-like sections — menus, catalogs, schedules,
     *   programmes, archives, pricing and service sets — and null on stories,
     *   single CTAs and quotes. A non-null value on a split band IS the sticky
     *   case: a short lead beside a long repeated set.
     *
     * Nothing here reads a height, because no height exists at authoring time.
     * The item pattern is the plan's own statement that one region repeats and
     * the other does not, which is the same fact one step earlier.
     */
    public static function pinsLeadColumn(string $archetype, ?string $itemPattern): bool
    {
        if (!self::hasUnequalRegions($archetype)) {
            return false;
        }
        return $itemPattern !== null && trim($itemPattern) !== '';
    }

    /**
     * Whether this archetype composes regions of deliberately different weight.
     * Only such a band can pin one region, and only in such a band is a
     * lopsided media spread a defect rather than the composition.
     */
    public static function hasUnequalRegions(string $archetype): bool
    {
        self::assertKnown($archetype);
        return (self::CATALOG[$archetype]['unequal_regions'] ?? false) === true;
    }

    /**
     * The variables one recipe fragment is rendered with. Keys always cover
     * `RECIPE_VARS`, whatever the archetype, so no fragment can hit an
     * unresolved placeholder mid-build.
     *
     * @return array<string,string>
     */
    public static function recipeVars(string $archetype, ?string $itemPattern): array
    {
        return ['pin_directive' => self::pinDirective($archetype, $itemPattern)];
    }

    /**
     * The authoring instruction for the pin, or '' when this build does not ask
     * for one. `SectionUnit` renders this into the recipe fragment, so a
     * section that cannot use the pin never reads a word about it.
     */
    public static function pinDirective(string $archetype, ?string $itemPattern): string
    {
        if (!self::pinsLeadColumn($archetype, $itemPattern)) {
            return '';
        }
        $pin = self::PIN_CLASS;

        return <<<TEXT
- Pinned lead (REQUIRED for this section): this band pairs a short lead region
  with a long repeated set, so the lead stays in view while the set scrolls past
  it. Put `"className":"{$pin}"` on the `wp:column` that holds the heading and
  its copy — never on the column that holds the repeated items.
  Build exactly two regions when you pin; three regions never pin.
  Keep the pinned column to one heading, at most one lead line, and at most two
  short paragraphs, so it always fits one screen: a pinned column taller than
  the viewport strands its own bottom out of reach.
  The pin is desktop-only and the theme already handles that, so author the
  band as an ordinary two-column row and add nothing else for the stacked state.
TEXT;
    }

    /** @return list<string> */
    public static function backgrounds(string $archetype): array
    {
        return array_values((array) self::metadata($archetype)['backgrounds']);
    }

    public static function defaultBackground(string $archetype): string
    {
        return (string) self::metadata($archetype)['default_background'];
    }

    /**
     * The eligibility context for one build. This is the single place that
     * turns the committed design direction into the predicate's inputs, so no
     * caller re-derives the rhythm gate for itself.
     *
     * @param array<mixed> $designDirection the persisted designDirection.json
     *        data; an empty array (no direction yet) grants nothing
     * @return array<string,bool>
     */
    public static function directionContext(array $designDirection): array
    {
        $rhythm = Steps\DesignDirectionStep::explicitRhythm($designDirection);
        return [
            self::CONTEXT_BROKEN_GRID_RHYTHM => $rhythm !== null
                && in_array($rhythm, self::BROKEN_GRID_RHYTHMS, true),
        ];
    }

    /**
     * Whether one archetype is eligible for this build, read from the
     * persisted design direction. The page plan and fix-blocks both ask this
     * one question here, so no step re-derives the gate.
     */
    public static function eligibleForProject(string $archetype, Project $project): bool
    {
        return self::eligible(
            $archetype,
            self::directionContext(Steps\DesignDirectionStep::dataFor($project)),
        );
    }

    /**
     * The archetype marker on a delivered section's root, or null when the
     * root carries none or the markup does not parse. SectionUnit stamps this
     * marker to repeat the plan's assignment in the delivered part.
     */
    public static function rootMarker(string $markup): ?string
    {
        return self::rootClassWithPrefix($markup, self::MARKER_PREFIX);
    }

    /**
     * The first class token with the given prefix on the delivered root
     * block, or null when there is none or the markup does not parse. The
     * substring check first skips the parse on a part that cannot match.
     */
    public static function rootClassWithPrefix(string $markup, string $prefix): ?string
    {
        if (!str_contains($markup, $prefix)) {
            return null;
        }
        try {
            $document = BlockMarkup::parse($markup);
            $root = $document->topLevel();
            if ($root === null) {
                return null;
            }
            foreach (self::classTokens($document, $root) as $token) {
                if (str_starts_with($token, $prefix)) {
                    return $token;
                }
            }
        } catch (\Throwable) {
            return null;
        }
        return null;
    }

    /**
     * The eligibility predicate. An archetype with no `requires_context` is
     * always eligible; one with requirements needs every named context key to
     * be true. Unknown context keys fail loudly, so a misspelled fact never
     * looks enforced when it is not.
     *
     * @param array<string,bool> $context
     */
    public static function eligible(string $archetype, array $context = []): bool
    {
        self::assertKnown($archetype);
        self::assertContext($context);
        foreach ((array) self::CATALOG[$archetype]['requires_context'] as $key) {
            if (($context[$key] ?? false) !== true) {
                return false;
            }
        }
        return true;
    }

    /**
     * Every archetype this build may use, in catalog order.
     *
     * @param array<string,bool> $context
     * @return list<string>
     */
    public static function eligibleArchetypes(array $context = []): array
    {
        self::assertContext($context);
        return array_values(array_filter(
            self::ARCHETYPES,
            static fn (string $archetype): bool => self::eligible($archetype, $context),
        ));
    }

    /**
     * Why an archetype can be ineligible, phrased for a warning row. Returns
     * '' for an archetype that no context can rule out.
     */
    public static function ineligibleReason(string $archetype): string
    {
        return (string) self::metadata($archetype)['ineligible_reason'];
    }

    /**
     * Advisory objective checks on one delivered section. The root marker and
     * the block structure are repaired upstream where a repair is safe; these
     * checks keep the rest actionable without rewriting a valid authored
     * composition toward a different archetype.
     *
     * Each row names the file, the contract it failed, the authored value, the
     * delivered value, and the disposition, so the later repair pass can act on
     * the row alone.
     *
     * `$itemPattern` is the section's planned item pattern, or null. It is the
     * same value `pinsLeadColumn()` reads, so the delivered markup is checked
     * against the instruction this section was actually given rather than
     * against the archetype in the abstract.
     *
     * @return list<string>
     */
    public static function markupWarnings(
        string $markup,
        string $archetype,
        string $part,
        ?string $itemPattern = null,
    ): array {
        self::assertKnown($archetype);
        $meta = self::metadata($archetype);
        $document = BlockMarkup::parse($markup);

        $imageCount = preg_match_all('~<img\b~i', $markup);
        $imageCount = is_int($imageCount) ? $imageCount : 0;

        $rows = 0;
        foreach ($document->indices() as $index) {
            if (in_array($document->name($index), ['columns', 'gallery', 'media-text'], true)
                || in_array('masonry-3', self::classTokens($document, $index), true)
            ) {
                $rows++;
            }
        }

        $warnings = [];
        $marker = self::marker($archetype);
        $root = $document->topLevel();
        $rootClasses = $root === null ? [] : self::classTokens($document, $root);
        if (!in_array($marker, $rootClasses, true)) {
            $warnings[] = self::markupWarning(
                $part,
                'archetype root marker',
                ['required_class' => $marker],
                ['root_classes' => array_values($rootClasses)],
                'safe parseable section was retained; restore the assigned root marker without changing the archetype',
            );
        }

        $minImages = (int) $meta['min_images'];
        $maxImages = (int) $meta['max_images'];
        if ($imageCount < $minImages || $imageCount > $maxImages) {
            $warnings[] = self::markupWarning(
                $part,
                'archetype media count',
                ['archetype' => $archetype, 'min_images' => $minImages, 'max_images' => $maxImages],
                ['image_count' => $imageCount],
                'safe parseable section was retained for later archetype repair; no media or copy was invented',
            );
        }

        if ($archetype === 'bento-grid') {
            $columnRows = 0;
            $highlights = 0;
            foreach ($document->indices() as $index) {
                if ($document->name($index) === 'columns') {
                    $columnRows++;
                }
                if (in_array(self::BENTO_HIGHLIGHT_CLASS, self::classTokens($document, $index), true)) {
                    $highlights++;
                }
            }
            if ($columnRows < 2) {
                $warnings[] = self::markupWarning(
                    $part,
                    'bento row count',
                    ['archetype' => $archetype, 'minimum_column_rows' => 2],
                    ['column_row_count' => $columnRows],
                    'safe parseable section was retained; a bento is two card rows of unequal count, not one row',
                );
            }
            if ($highlights !== 1) {
                $warnings[] = self::markupWarning(
                    $part,
                    'bento highlight',
                    ['archetype' => $archetype, 'highlighted_cards' => 1, 'class' => self::BENTO_HIGHLIGHT_CLASS],
                    ['highlighted_cards' => $highlights],
                    'safe parseable section was retained; exactly one card carries the inverted highlight',
                );
            }
        }

        if ($archetype === 'cta-panel') {
            $panels = 0;
            $buttons = 0;
            foreach ($document->indices() as $index) {
                if ($document->name($index) === 'group'
                    && in_array(self::CTA_PANEL_CLASS, self::classTokens($document, $index), true)) {
                    $panels++;
                }
                if ($document->name($index) === 'button') {
                    $buttons++;
                }
            }
            if ($panels !== 1) {
                $warnings[] = self::markupWarning(
                    $part,
                    'cta panel container',
                    ['archetype' => $archetype, 'panel_groups' => 1, 'class' => self::CTA_PANEL_CLASS],
                    ['panel_groups' => $panels],
                    'safe parseable section was retained; the closing invitation lives in exactly one contained panel group',
                );
            }
            if ($buttons !== 1) {
                $warnings[] = self::markupWarning(
                    $part,
                    'cta panel action',
                    ['archetype' => $archetype, 'buttons' => 1],
                    ['buttons' => $buttons],
                    'safe parseable section was retained; a closing panel carries exactly one action',
                );
            }
        }

        if ($archetype === 'faq-split') {
            $items = 0;
            foreach ($document->indices() as $index) {
                if ($document->name($index) === 'details') {
                    $items++;
                }
            }
            if ($items < self::FAQ_MIN_ITEMS) {
                $warnings[] = self::markupWarning(
                    $part,
                    'faq accordion items',
                    ['archetype' => $archetype, 'minimum_details_blocks' => self::FAQ_MIN_ITEMS],
                    ['details_block_count' => $items],
                    'safe parseable section was retained; a FAQ split is an accordion of core/details blocks, not a paragraph list',
                );
            }
        }

        if (($meta['requires_row'] ?? false) === true && $rows < 1) {
            $warnings[] = self::markupWarning(
                $part,
                'archetype row topology',
                [
                    'archetype' => $archetype,
                    'required_blocks' => ['core/columns', 'core/gallery', 'core/media-text'],
                    'minimum' => 1,
                ],
                ['row_block_count' => $rows],
                'safe parseable section was retained; restore the assigned side-by-side rows instead of one stacked column',
            );
        }

        if (self::pinsLeadColumn($archetype, $itemPattern)) {
            // The pin must sit on the LEAD column — the one that does not hold
            // the repeated items. A pin on the items column passes a bare
            // presence check but pins the long region and strands the lead,
            // which is the exact defect the pin exists to prevent.
            $pinned = 0;
            $pinnedLead = 0;
            foreach ($document->indices() as $index) {
                if (!in_array(self::PIN_CLASS, self::classTokens($document, $index), true)) {
                    continue;
                }
                $pinned++;
                if (!self::subtreeHasClassToken($document, $index, ItemPattern::ITEM_MARKER)) {
                    $pinnedLead++;
                }
            }
            if ($pinnedLead === 0) {
                $warnings[] = self::markupWarning(
                    $part,
                    'archetype pinned lead',
                    [
                        'archetype' => $archetype,
                        'item_pattern' => $itemPattern,
                        'required_class' => self::PIN_CLASS,
                        'required_on' => 'the wp:column holding the lead copy',
                    ],
                    ['pinned_blocks' => $pinned, 'pinned_lead_columns' => 0],
                    $pinned === 0
                        ? 'safe parseable section was retained; add the pin class to the lead column so the short region stops stranding a blank quadrant beside the long one'
                        : 'safe parseable section was retained; move the pin class off the repeated-items column and onto the lead column — a pinned items column pins the long region and strands the lead',
                );
            }
        }

        $spread = self::hasUnequalRegions($archetype) ? self::mediaSpread($document) : null;
        if ($spread !== null && $spread['spread'] >= self::MEDIA_SPREAD_LIMIT) {
            $warnings[] = self::markupWarning(
                $part,
                'archetype region balance',
                [
                    'archetype' => $archetype,
                    'max_media_spread' => self::MEDIA_SPREAD_LIMIT - 1,
                ],
                [
                    'images_per_region' => $spread['per_region'],
                    'spread' => $spread['spread'],
                ],
                'safe parseable section was retained; balance the regions or pin the short one — a region carrying this many more images than its sibling cannot end near it, and the difference renders as a tall blank quadrant',
            );
        }

        return $warnings;
    }

    /**
     * How unevenly one row spreads its images across its own regions.
     *
     * This is the objective half of the defect BIGR-945 names. Markup carries
     * no heights, so nothing here can measure the blank quadrant directly. It
     * measures the one authored fact that reliably produces it: a region
     * holding several images beside a sibling holding one or none cannot end at
     * a similar height, whatever the copy does.
     *
     * Every `wp:columns` row with two or more regions is judged and the worst
     * spread is kept, so one balanced row cannot hide an unbalanced sibling. A
     * band that legitimately puts one plate beside one copy column — the
     * archetype's most common and most successful shape — is never reported.
     *
     * @return array{per_region:list<int>,spread:int}|null
     */
    private static function mediaSpread(BlockMarkup $document): ?array
    {
        $worst = null;
        foreach ($document->indices() as $index) {
            if ($document->name($index) !== 'columns') {
                continue;
            }
            $perRegion = [];
            foreach ($document->children($index) as $child) {
                if ($document->name($child) !== 'column') {
                    continue;
                }
                $found = preg_match_all('~<img\b~i', $document->innerHtml($child));
                $perRegion[] = is_int($found) ? $found : 0;
            }
            if (count($perRegion) < 2) {
                continue;
            }
            $spread = max($perRegion) - min($perRegion);
            if ($worst === null || $spread > $worst['spread']) {
                $worst = ['per_region' => $perRegion, 'spread' => $spread];
            }
        }

        return $worst;
    }

    /** Whether this block, or any block under it, carries this class token. */
    private static function subtreeHasClassToken(BlockMarkup $document, int $index, string $token): bool
    {
        if (in_array($token, self::classTokens($document, $index), true)) {
            return true;
        }
        foreach ($document->children($index) as $child) {
            if (self::subtreeHasClassToken($document, $child, $token)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The class tokens on one block. `masonry-3` is a row: the prompts prefer
     * one masonry group over repeated wp:columns above six mixed-aspect items,
     * so a band that took that advice still satisfies `requires_row`.
     *
     * @return list<string>
     */
    private static function classTokens(BlockMarkup $document, int $index): array
    {
        return preg_split(
            '/\s+/',
            trim((string) (($document->attrs($index) ?? [])['className'] ?? '')),
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];
    }

    /** @param array<string,bool> $context */
    private static function assertContext(array $context): void
    {
        foreach (array_keys($context) as $key) {
            if (!is_string($key) || !in_array($key, self::CONTEXT_KEYS, true)) {
                throw new \InvalidArgumentException(
                    "unknown section eligibility context field '" . (string) $key . "'"
                );
            }
        }
        foreach ($context as $key => $value) {
            if (!is_bool($value)) {
                throw new \InvalidArgumentException(
                    "section eligibility context '{$key}' must be a boolean"
                );
            }
        }
    }

    private static function markupWarning(
        string $part,
        string $block,
        mixed $authored,
        mixed $delivered,
        string $disposition,
    ): string {
        return "file='theme/parts/{$part}.html'; block=" . self::describe($block)
            . '; authored=' . self::describe($authored)
            . '; delivered=' . self::describe($delivered)
            . '; disposition=' . $disposition;
    }

    private static function describe(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $encoded === false ? get_debug_type($value) : $encoded;
    }
}
