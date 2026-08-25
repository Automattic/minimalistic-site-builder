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
        'mixed-width-editorial',
        'equal-card-grid',
        'list-with-thumbnails',
    ];

    /** Background treatments a planned section may carry. */
    public const BACKGROUNDS = ['base', 'tinted', 'contrast', 'image'];

    /** Selection-only copy dimension, the same three steps heroes use. */
    public const COPY_CAPACITIES = ['compact', 'standard', 'expanded'];

    /** The root class family that marks a delivered section's assignment. */
    public const MARKER_PREFIX = 'section-composition--';

    /**
     * Eligibility context keys. A predicate reads only these, so a caller
     * cannot silently pass a fact the catalog never consults.
     *
     * @var list<string>
     */
    public const CONTEXT_KEYS = [self::CONTEXT_PHOTOGRAPHY_SITE];

    /** True when the brief is a photography, photojournalism, or gallery site. */
    public const CONTEXT_PHOTOGRAPHY_SITE = 'photography_site';

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
        'asymmetric-split' => [
            'backgrounds' => ['base', 'tinted', 'contrast', 'image'],
            'default_background' => 'base',
            'min_images' => 0,
            'max_images' => 4,
            'copy_capacity' => 'standard',
            'requires_row' => true,
            'requires_context' => [],
            'ineligible_reason' => '',
            'root_hook' => '.section-composition--asymmetric-split',
            'prompt' => 'section-compositions/asymmetric-split.md',
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
            'requires_context' => [self::CONTEXT_PHOTOGRAPHY_SITE],
            'ineligible_reason' => 'staggered rows are reserved for photography and gallery sites',
            'root_hook' => '.section-composition--offset-grid',
            'prompt' => 'section-compositions/offset-grid.md',
        ],
        'mixed-width-editorial' => [
            'backgrounds' => ['base', 'tinted', 'contrast', 'image'],
            'default_background' => 'base',
            'min_images' => 0,
            'max_images' => 12,
            'copy_capacity' => 'standard',
            'requires_row' => true,
            'requires_context' => [],
            'ineligible_reason' => '',
            'root_hook' => '.section-composition--mixed-width-editorial',
            'prompt' => 'section-compositions/mixed-width-editorial.md',
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
     * turns site facts into the predicate's inputs, so no caller re-derives
     * the photography gate for itself.
     *
     * @param array<mixed> $siteSpec
     * @return array<string,bool>
     */
    public static function siteContext(array $siteSpec, string $prompt = ''): array
    {
        return [
            self::CONTEXT_PHOTOGRAPHY_SITE => PhotographySite::matches($siteSpec, $prompt),
        ];
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
     * @return list<string>
     */
    public static function markupWarnings(string $markup, string $archetype, string $part): array
    {
        self::assertKnown($archetype);
        $meta = self::metadata($archetype);
        $document = BlockMarkup::parse($markup);

        $imageCount = preg_match_all('~<img\b~i', $markup);
        $imageCount = is_int($imageCount) ? $imageCount : 0;

        $rows = 0;
        foreach ($document->indices() as $index) {
            if (in_array($document->name($index), ['columns', 'gallery', 'media-text'], true)) {
                $rows++;
            }
        }

        $warnings = [];
        $marker = self::marker($archetype);
        $root = $document->topLevel();
        $rootClasses = $root === null
            ? []
            : (preg_split(
                '/\s+/',
                trim((string) (($document->attrs($root) ?? [])['className'] ?? '')),
                -1,
                PREG_SPLIT_NO_EMPTY,
            ) ?: []);
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

        return $warnings;
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
