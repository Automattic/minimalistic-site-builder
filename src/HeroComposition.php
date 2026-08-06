<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Reviewed, code-owned catalog and objective selector for front-page heroes.
 *
 * Selection filters caller-owned capabilities first, then uses a stable hash
 * only inside the compatible pool. No prompt prose or site-industry keywords
 * participate in the decision.
 */
final class HeroComposition
{
    /** @var list<string> */
    public const RECIPES = [
        'cinematic-safe-zone',
        'editorial-split',
        'framed-portrait',
        'focal-subject-stage',
        'layered-poster',
    ];

    // Caller-constraint enum. Every cataloged recipe now carries an image;
    // 'none' remains a valid HeroBlueprint media_mode only as the delivered
    // value of a media-loss degradation, never as a requestable constraint.
    public const MEDIA_MODES = ['cover-image', 'foreground-image'];
    public const COPY_CAPACITIES = ['compact', 'standard', 'expanded'];
    public const CANVASES = ['full-bleed', 'framed'];

    /** @var list<string> */
    private const CONSTRAINT_KEYS = [
        'hero_canvas', 'allowed_hero_media_modes', 'max_hero_images', 'hero_copy_capacity',
    ];

    /**
     * @var array<string,array<string,mixed>>
     *
     * Every entry intentionally owns its complete executable metadata and a
     * complete default blueprint. Prompt fragments carry authoring guidance;
     * this table remains the structural source of truth.
     */
    private const CATALOG = [
        'cinematic-safe-zone' => [
            'canvases' => ['full-bleed', 'framed'],
            'media_modes' => ['cover-image'],
            'min_images' => 1,
            'max_images' => 1,
            'backgrounds' => ['image', 'contrast'],
            'default_background' => 'image',
            'fallback_background' => 'contrast',
            'header_modes' => ['stacked', 'overlay'],
            'copy_capacity' => 'compact',
            'backdrops' => ['solid'],
            'mobile_transformations' => ['stack-media-first', 'retain-media-overlay'],
            'layout_archetype' => 'full-bleed-cover',
            'fallback_family' => 'cover',
            'root_hook' => '.hero-composition--cinematic-safe-zone',
            'prompt' => 'hero-compositions/cinematic-safe-zone.md',
            // BIGR-775: the register is fixed at restrained — audited display
            // headlines overflowed their measure inside the cover's copy zone
            // (atlas7) — and the copy region is centered rather than pinned to
            // a corner of the frame.
            'headline_registers' => ['restrained'],
            'height_profiles' => ['standard', 'immersive'],
            'defaults' => [
                'media_mode' => 'cover-image', 'headline_register' => 'restrained',
                'text_anchor' => 'center',
                'headline_line_target' => ['desktop' => [1, 2], 'mobile' => [2, 4]],
                'focal_region' => 'end', 'text_safe_region' => 'center',
                'height_profile' => 'immersive', 'cta_treatment' => 'prominent',
                'stage_backdrop' => 'solid',
                'mobile_transformation' => 'stack-media-first',
            ],
        ],
        'editorial-split' => [
            'canvases' => ['full-bleed', 'framed'],
            'media_modes' => ['foreground-image'],
            'min_images' => 1,
            'max_images' => 1,
            'backgrounds' => ['base', 'tinted', 'contrast'],
            'default_background' => 'base',
            'fallback_background' => 'base',
            'header_modes' => ['stacked'],
            'copy_capacity' => 'standard',
            'backdrops' => ['solid'],
            'mobile_transformations' => ['stack-copy-first', 'stack-media-first'],
            'layout_archetype' => 'asymmetric-split',
            'fallback_family' => 'foreground-split',
            'root_hook' => '.hero-composition--editorial-split',
            'prompt' => 'hero-compositions/editorial-split.md',
            'headline_registers' => ['restrained', 'display'],
            'height_profiles' => ['compact', 'standard'],
            'defaults' => [
                'media_mode' => 'foreground-image', 'headline_register' => 'display',
                'text_anchor' => 'center-start',
                'headline_line_target' => ['desktop' => [1, 3], 'mobile' => [2, 5]],
                'focal_region' => 'none', 'text_safe_region' => 'full',
                'height_profile' => 'standard', 'cta_treatment' => 'prominent',
                'stage_backdrop' => 'solid',
                'mobile_transformation' => 'stack-copy-first',
            ],
        ],
        'framed-portrait' => [
            'canvases' => ['full-bleed', 'framed'],
            'media_modes' => ['foreground-image'],
            'min_images' => 1,
            'max_images' => 1,
            'backgrounds' => ['base', 'tinted', 'contrast'],
            'default_background' => 'tinted',
            'fallback_background' => 'base',
            'header_modes' => ['stacked'],
            'copy_capacity' => 'standard',
            'backdrops' => ['solid'],
            'mobile_transformations' => ['stack-media-first', 'stack-copy-first'],
            'layout_archetype' => 'asymmetric-split',
            'fallback_family' => 'foreground-split',
            'root_hook' => '.hero-composition--framed-portrait',
            'prompt' => 'hero-compositions/framed-portrait.md',
            'headline_registers' => ['restrained', 'display'],
            'height_profiles' => ['compact', 'standard'],
            'defaults' => [
                'media_mode' => 'foreground-image', 'headline_register' => 'restrained',
                'text_anchor' => 'center-start',
                'headline_line_target' => ['desktop' => [1, 3], 'mobile' => [2, 5]],
                'focal_region' => 'none', 'text_safe_region' => 'full',
                'height_profile' => 'standard', 'cta_treatment' => 'quiet',
                'stage_backdrop' => 'solid',
                'mobile_transformation' => 'stack-media-first',
            ],
        ],
        'focal-subject-stage' => [
            'canvases' => ['full-bleed', 'framed'],
            'media_modes' => ['foreground-image'],
            'min_images' => 1,
            'max_images' => 1,
            'backgrounds' => ['base', 'tinted', 'contrast'],
            'default_background' => 'base',
            'fallback_background' => 'base',
            'header_modes' => ['stacked'],
            'copy_capacity' => 'compact',
            // BIGR-776: the focal exhibit is the one recipe whose solid stage
            // may instead carry a quiet generated texture canvas that also
            // runs behind the stacked header.
            'backdrops' => ['solid', 'texture'],
            'mobile_transformations' => ['stack-media-first', 'stack-copy-first'],
            'layout_archetype' => 'asymmetric-split',
            'fallback_family' => 'foreground-split',
            'root_hook' => '.hero-composition--focal-subject-stage',
            'prompt' => 'hero-compositions/focal-subject-stage.md',
            'headline_registers' => ['restrained', 'display'],
            'height_profiles' => ['standard', 'immersive'],
            'defaults' => [
                'media_mode' => 'foreground-image', 'headline_register' => 'display',
                'text_anchor' => 'center-start',
                'headline_line_target' => ['desktop' => [1, 3], 'mobile' => [2, 4]],
                'focal_region' => 'none', 'text_safe_region' => 'full',
                'height_profile' => 'immersive', 'cta_treatment' => 'prominent',
                'stage_backdrop' => 'solid',
                'mobile_transformation' => 'stack-media-first',
            ],
        ],
        'layered-poster' => [
            'canvases' => ['full-bleed', 'framed'],
            'media_modes' => ['cover-image'],
            'min_images' => 1,
            'max_images' => 1,
            'backgrounds' => ['image', 'contrast'],
            'default_background' => 'image',
            'fallback_background' => 'contrast',
            'header_modes' => ['stacked', 'overlay'],
            // BIGR-775: poster copy stays compact — audited layered-poster
            // heroes stacked a third caption line under the standfirst.
            'copy_capacity' => 'compact',
            'backdrops' => ['solid'],
            'mobile_transformations' => ['flatten-layers'],
            'layout_archetype' => 'full-bleed-cover',
            'fallback_family' => 'cover',
            'root_hook' => '.hero-composition--layered-poster',
            'prompt' => 'hero-compositions/layered-poster.md',
            'headline_registers' => ['display', 'poster'],
            'height_profiles' => ['standard', 'immersive'],
            'defaults' => [
                'media_mode' => 'cover-image', 'headline_register' => 'poster',
                // BIGR-775 follow-up: a top-pinned safe zone left a dead band
                // under the copy on the viewport-scale stage (lumen8) — the
                // zone rides the cover's vertical center.
                'text_anchor' => 'center-start',
                'headline_line_target' => ['desktop' => [1, 4], 'mobile' => [2, 6]],
                'focal_region' => 'end', 'text_safe_region' => 'start',
                'height_profile' => 'immersive', 'cta_treatment' => 'prominent',
                'stage_backdrop' => 'solid',
                'mobile_transformation' => 'flatten-layers',
            ],
        ],
    ];

    public static function assertKnown(string $recipe): void
    {
        if (!isset(self::CATALOG[$recipe])) {
            throw new \InvalidArgumentException(
                "unknown hero recipe '{$recipe}' (use one of: " . implode(', ', self::RECIPES) . ')'
            );
        }
    }

    /** @return array<string,mixed> */
    public static function metadata(string $recipe): array
    {
        self::assertKnown($recipe);
        return self::CATALOG[$recipe];
    }

    /** @return array<string,array<string,mixed>> */
    public static function catalog(): array
    {
        return self::CATALOG;
    }

    public static function recipeTemplate(string $recipe): string
    {
        return (string) self::metadata($recipe)['prompt'];
    }

    public static function rootHook(string $recipe): string
    {
        return (string) self::metadata($recipe)['root_hook'];
    }

    /**
     * Image gating accepts either a recipe id or its persisted blueprint.
     * The blueprint's delivered media_mode wins so a later deterministic
     * degradation to `none` cannot accidentally keep image generation armed.
     *
     * @param string|array<string,mixed> $recipeOrBlueprint
     */
    public static function usesGeneratedImages(string|array $recipeOrBlueprint): bool
    {
        if (is_array($recipeOrBlueprint)) {
            $recipe = trim((string) ($recipeOrBlueprint['recipe'] ?? ''));
            self::assertKnown($recipe);
            $mode = strtolower(trim((string) ($recipeOrBlueprint['media_mode'] ?? '')));
            return in_array($mode, ['cover-image', 'foreground-image'], true);
        }
        return (int) self::metadata($recipeOrBlueprint)['min_images'] > 0;
    }

    /**
     * Project the authoritative hero recipe into PagePlan's generic fields.
     *
     * @param array<string,mixed> $blueprint
     * @return array{layout_archetype:string,allowed_backgrounds:list<string>,default_background:string,fallback_family:string}
     */
    public static function planProjection(array $blueprint): array
    {
        $recipe = trim((string) ($blueprint['recipe'] ?? ''));
        $meta = self::metadata($recipe);
        return [
            'layout_archetype' => (string) $meta['layout_archetype'],
            'allowed_backgrounds' => array_values($meta['backgrounds']),
            'default_background' => (string) $meta['default_background'],
            'fallback_family' => (string) $meta['fallback_family'],
        ];
    }

    /**
     * Strictly validate caller-owned design constraints. Unknown properties
     * fail so a misspelled operator flag never looks enforced when it is not.
     *
     * @param mixed $constraints
     * @return array<string,mixed>
     */
    public static function validateConstraints(mixed $constraints): array
    {
        if (!is_array($constraints)) {
            throw new \InvalidArgumentException('design_constraints must be an object');
        }
        foreach (array_keys($constraints) as $key) {
            if (!is_string($key) || !in_array($key, self::CONSTRAINT_KEYS, true)) {
                throw new \InvalidArgumentException("unknown design_constraints field '" . (string) $key . "'");
            }
        }

        $out = [];
        if (array_key_exists('hero_canvas', $constraints)) {
            $out['hero_canvas'] = self::enumConstraint(
                'design_constraints.hero_canvas',
                $constraints['hero_canvas'],
                self::CANVASES,
            );
        }
        if (array_key_exists('allowed_hero_media_modes', $constraints)) {
            $raw = $constraints['allowed_hero_media_modes'];
            if (!is_array($raw) || !array_is_list($raw) || $raw === []) {
                throw new \InvalidArgumentException(
                    'design_constraints.allowed_hero_media_modes must be a non-empty list'
                );
            }
            $modes = [];
            foreach ($raw as $mode) {
                $normalized = self::enumConstraint(
                    'design_constraints.allowed_hero_media_modes',
                    $mode,
                    self::MEDIA_MODES,
                );
                if (!in_array($normalized, $modes, true)) {
                    $modes[] = $normalized;
                }
            }
            $out['allowed_hero_media_modes'] = $modes;
        }
        if (array_key_exists('max_hero_images', $constraints)) {
            $max = $constraints['max_hero_images'];
            if (!is_int($max) || $max < 1 || $max > 2) {
                throw new \InvalidArgumentException(
                    'design_constraints.max_hero_images must be an integer from 1 through 2'
                );
            }
            $out['max_hero_images'] = $max;
        }
        if (array_key_exists('hero_copy_capacity', $constraints)) {
            $out['hero_copy_capacity'] = self::enumConstraint(
                'design_constraints.hero_copy_capacity',
                $constraints['hero_copy_capacity'],
                self::COPY_CAPACITIES,
            );
        }

        return $out;
    }

    /** @return list<string> */
    public static function compatible(array $constraints = []): array
    {
        $constraints = self::validateConstraints($constraints);
        return array_values(array_filter(self::RECIPES, static function (string $recipe) use ($constraints): bool {
            $meta = self::CATALOG[$recipe];
            if (isset($constraints['hero_canvas'])
                && !in_array($constraints['hero_canvas'], $meta['canvases'], true)) {
                return false;
            }
            if (isset($constraints['allowed_hero_media_modes'])
                && array_intersect($constraints['allowed_hero_media_modes'], $meta['media_modes']) === []) {
                return false;
            }
            if (isset($constraints['max_hero_images'])
                && $meta['max_images'] > $constraints['max_hero_images']) {
                return false;
            }
            if (isset($constraints['hero_copy_capacity'])
                && $meta['copy_capacity'] !== $constraints['hero_copy_capacity']) {
                return false;
            }
            return true;
        }));
    }

    /** Whether a known recipe satisfies a validated constraint set. */
    public static function isCompatible(string $recipe, array $constraints = []): bool
    {
        self::assertKnown($recipe);
        return in_array($recipe, self::compatible($constraints), true);
    }

    /**
     * Select stably inside the objectively compatible pool.
     *
     * @throws \InvalidArgumentException when caller constraints leave no valid recipe
     */
    public static function select(string $stableIdentifier, string $conceptSeed, array $constraints = []): string
    {
        $constraints = self::validateConstraints($constraints);
        $pool = self::compatible($constraints);
        if ($pool === []) {
            throw new \InvalidArgumentException(
                'design_constraints leave no compatible hero recipe; adjust media, image-count, canvas, or copy-capacity requirements'
            );
        }

        $identity = mb_strtolower(trim($stableIdentifier), 'UTF-8');
        $context = json_encode(
            [$identity, $conceptSeed, $constraints],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        $index = (int) (hexdec(substr(hash('sha256', $context), 0, 8)) % count($pool));
        return $pool[$index];
    }

    /**
     * Advisory objective checks for safe, parseable recipe internals. Root
     * shape and identity are repaired by GeneratedMarkup; these checks keep a
     * missing/extra media slot or helper region actionable without rewriting a
     * valid authored composition toward a different recipe.
     *
     * @return list<string>
     */
    public static function markupWarnings(string $markup, string $recipe, string $part): array
    {
        self::assertKnown($recipe);
        $meta = self::metadata($recipe);
        $document = BlockMarkup::parse($markup);
        $root = $document->topLevel();
        $imageCount = preg_match_all('~<img\b~i', $markup, $unused);
        $imageCount = is_int($imageCount) ? $imageCount : 0;
        $copyRegions = 0;
        $mediaRegions = 0;
        $directCovers = 0;
        $covers = 0;
        foreach ($document->indices() as $index) {
            $name = $document->name($index);
            if ($name === 'cover') {
                $covers++;
            }
            $classes = preg_split(
                '/\s+/',
                trim((string) (($document->attrs($index) ?? [])['className'] ?? '')),
                -1,
                PREG_SPLIT_NO_EMPTY,
            ) ?: [];
            if (in_array('hero-composition__copy', $classes, true)) {
                $copyRegions++;
            }
            if (in_array('hero-composition__media', $classes, true)) {
                $mediaRegions++;
            }
            if ($root !== null && $document->parent($index) === $root && $name === 'cover') {
                $directCovers++;
            }
        }

        $warnings = [];
        $minImages = (int) $meta['min_images'];
        $maxImages = (int) $meta['max_images'];
        if ($imageCount < $minImages || $imageCount > $maxImages) {
            $warnings[] = self::markupWarning(
                $part,
                'recipe media count',
                ['recipe' => $recipe, 'min_images' => $minImages, 'max_images' => $maxImages],
                ['image_count' => $imageCount],
                'safe parseable hero was retained for later recipe repair; no media, copy, or sibling was invented',
            );
        }
        if ($copyRegions < 1) {
            $warnings[] = self::markupWarning(
                $part,
                'recipe copy region',
                ['required_class' => 'hero-composition__copy', 'minimum' => 1],
                ['matching_regions' => $copyRegions],
                'safe parseable hero was retained; restore the assigned copy-region hook without changing its recipe',
            );
        }

        $mediaModes = (array) $meta['media_modes'];
        if (in_array('cover-image', $mediaModes, true) && $directCovers !== 1) {
            $warnings[] = self::markupWarning(
                $part,
                'recipe direct cover',
                ['direct_wp_cover_count' => 1],
                ['direct_wp_cover_count' => $directCovers],
                'safe parseable hero was retained; overlay finalization may degrade to stacked until the assigned cover is restored',
            );
        } elseif (in_array('foreground-image', $mediaModes, true)
            && $mediaRegions < $minImages
        ) {
            $warnings[] = self::markupWarning(
                $part,
                'recipe foreground media regions',
                ['required_class' => 'hero-composition__media', 'minimum' => $minImages],
                ['matching_regions' => $mediaRegions],
                'safe parseable hero was retained; restore only the missing assigned foreground-media region hooks',
            );
        }
        if (in_array('foreground-image', $mediaModes, true)
            && $covers > 0
        ) {
            $warnings[] = self::markupWarning(
                $part,
                'foreground recipe cover usage',
                ['wp_cover_count' => 0, 'media_modes' => $mediaModes],
                ['wp_cover_count' => $covers],
                'safe parseable hero was retained; replace only the background cover with the assigned foreground-media block',
            );
        }
        // BIGR-775 advisory copy-budget check: every hero holds at most the
        // headline plus ONE supporting paragraph (naturaleza9's three stacked
        // bodies read as clutter even inside the old standard budget).
        // copy_capacity stays a selection-only dimension. Overrun keeps the
        // safe hero and stays actionable.
        $copyTextBlocks = 0;
        foreach ($document->indices() as $index) {
            if (!in_array($document->name($index), ['heading', 'paragraph'], true)) {
                continue;
            }
            for ($parent = $document->parent($index); $parent !== null; $parent = $document->parent($parent)) {
                $parentClasses = preg_split(
                    '/\s+/',
                    trim((string) (($document->attrs($parent) ?? [])['className'] ?? '')),
                    -1,
                    PREG_SPLIT_NO_EMPTY,
                ) ?: [];
                if (in_array('hero-composition__copy', $parentClasses, true)) {
                    $copyTextBlocks++;
                    break;
                }
            }
        }
        $textBudget = 2;
        if ($copyTextBlocks > $textBudget) {
            $warnings[] = self::markupWarning(
                $part,
                'hero copy budget',
                ['copy_capacity' => $meta['copy_capacity'], 'max_text_blocks' => $textBudget],
                ['text_blocks' => $copyTextBlocks],
                'safe parseable hero was retained; fold the overflow lines into the standfirst instead of stacking more copy',
            );
        }

        // BIGR-775 advisory headline-punctuation check: an em/en dash joins
        // two thoughts the H1 should not carry together (audited: atlas7).
        if (preg_match('~<h1\b[^>]*>(.*?)</h1>~is', $markup, $h1Match) === 1) {
            $headline = html_entity_decode(strip_tags($h1Match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('/[\x{2013}\x{2014}]/u', $headline) === 1) {
                $warnings[] = self::markupWarning(
                    $part,
                    'hero headline punctuation',
                    ['headline' => 'a short phrase without em/en dashes'],
                    ['headline' => $headline],
                    'safe parseable hero was retained; move the dash-joined clause into the standfirst',
                );
            }
        }

        $images = self::imageFacts($markup);
        $expectedAspect = match ($recipe) {
            'framed-portrait' => 'portrait',
            'cinematic-safe-zone', 'layered-poster' => 'landscape',
            default => null,
        };
        if ($expectedAspect !== null && $images !== []) {
            $aspects = array_values(array_map(
                static fn (array $image): string => self::imageAspect($image['alt']),
                $images,
            ));
            if (array_filter($aspects, static fn (string $aspect): bool => $aspect !== $expectedAspect) !== []) {
                $warnings[] = self::markupWarning(
                    $part,
                    'recipe image aspect',
                    ['aspect' => $expectedAspect],
                    ['aspects' => $aspects],
                    'safe parseable hero was retained; regenerate or recrop only the mismatched assigned image slot',
                );
            }
        }
        return $warnings;
    }

    /** @return list<array{src:string,alt:string}> */
    private static function imageFacts(string $markup): array
    {
        preg_match_all('~<img\b[^>]*>~is', $markup, $matches);
        $out = [];
        foreach ($matches[0] ?? [] as $tag) {
            $out[] = [
                'src' => self::htmlAttribute((string) $tag, 'src'),
                'alt' => self::htmlAttribute((string) $tag, 'alt'),
            ];
        }
        return $out;
    }

    private static function htmlAttribute(string $tag, string $name): string
    {
        $pattern = '~\b' . preg_quote($name, '~') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~i';
        if (preg_match($pattern, $tag, $match, PREG_UNMATCHED_AS_NULL) !== 1) {
            return '';
        }
        return html_entity_decode((string) ($match[1] ?? $match[2] ?? $match[3] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function imageAspect(string $alt): string
    {
        if (!str_starts_with(trim($alt), 'AI_IMAGE:')) {
            return 'missing';
        }
        $fields = array_map('trim', explode('|', $alt));
        return strtolower((string) end($fields));
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

    /** @param list<string> $allowed */
    private static function enumConstraint(string $field, mixed $value, array $allowed): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException("{$field} must be one of: " . implode(', ', $allowed));
        }
        $normalized = strtolower(trim($value));
        if (!in_array($normalized, $allowed, true)) {
            throw new \InvalidArgumentException(
                "{$field} must be one of: " . implode(', ', $allowed) . '; got ' . self::describe($value)
            );
        }
        return $normalized;
    }

    private static function describe(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $encoded === false ? get_debug_type($value) : $encoded;
    }
}
