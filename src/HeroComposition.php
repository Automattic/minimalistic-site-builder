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
        'stacked-headline-band',
    ];

    // Caller-constraint enum. Every cataloged recipe now carries an image;
    // 'none' remains a valid HeroBlueprint media_mode only as the delivered
    // value of a media-loss degradation, never as a requestable constraint.
    public const MEDIA_MODES = ['cover-image', 'foreground-image', 'band-image'];

    /**
     * The media modes that put real pixels on the page, so the build must
     * generate an image for them. A mode absent from this list disarms image
     * generation: 'none' is the delivered value of a media-loss degradation,
     * and a future non-image mode must opt in here on purpose.
     *
     * @var list<string>
     */
    public const IMAGE_MEDIA_MODES = ['cover-image', 'foreground-image', 'band-image'];
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
                'mobile_transformation' => 'flatten-layers',
            ],
        ],
        // BIGR-885: the catalog put copy either OVER a cover or BESIDE a
        // constrained plate. This recipe stacks copy ABOVE a full-width image
        // band, so no text ever meets a photograph. That makes it the only
        // recipe with zero overlay-contrast risk, and the safest choice when
        // the direction commits to a framed canvas.
        'stacked-headline-band' => [
            'canvases' => ['full-bleed', 'framed'],
            'media_modes' => ['band-image'],
            'min_images' => 1,
            'max_images' => 1,
            // Solid surfaces only. The copy sits on the surface, never on the
            // image, so 'image' would contradict the recipe: the plan wraps an
            // 'image' band in a wp:cover and puts the copy back over pixels.
            'backgrounds' => ['base', 'tinted', 'contrast'],
            'default_background' => 'base',
            'fallback_background' => 'base',
            // No overlay header: the section's first element is copy, so an
            // overlay header would float over the headline, not over media.
            'header_modes' => ['stacked'],
            'copy_capacity' => 'standard',
            'mobile_transformations' => ['stack-copy-first'],
            // The recipe mixes one reading-measure copy stack with one
            // full-width band, which is the width mix this archetype names.
            // 'full-bleed-cover' is wrong twice over: it promises overlaid
            // text, and both PagePlanStep and ThemeValidator special-case it.
            'layout_archetype' => 'mixed-width-editorial',
            'fallback_family' => 'typographic',
            'root_hook' => '.hero-composition--stacked-headline-band',
            'prompt' => 'hero-compositions/stacked-headline-band.md',
            'headline_registers' => ['restrained', 'display'],
            // The band must stay short: the headline, the standfirst, the
            // action AND a meaningful share of the band share one viewport.
            'height_profiles' => ['compact', 'standard'],
            'defaults' => [
                'media_mode' => 'band-image', 'headline_register' => 'display',
                // The copy leads the section, so it is anchored to the top and
                // to the reading edge. A vertically centered anchor would ask
                // the deterministic pass to center copy that has no frame.
                'text_anchor' => 'top-start',
                'headline_line_target' => ['desktop' => [1, 2], 'mobile' => [2, 4]],
                'focal_region' => 'none', 'text_safe_region' => 'full',
                'height_profile' => 'compact', 'cta_treatment' => 'prominent',
                'mobile_transformation' => 'stack-copy-first',
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
     * Both branches read IMAGE_MEDIA_MODES (BIGR-885). An inline mode list
     * silently disarmed generation for every mode added after it was written,
     * and the recipe then shipped with an empty media slot.
     *
     * @param string|array<string,mixed> $recipeOrBlueprint
     */
    public static function usesGeneratedImages(string|array $recipeOrBlueprint): bool
    {
        if (is_array($recipeOrBlueprint)) {
            $recipe = trim((string) ($recipeOrBlueprint['recipe'] ?? ''));
            self::assertKnown($recipe);
            $mode = strtolower(trim((string) ($recipeOrBlueprint['media_mode'] ?? '')));
            return in_array($mode, self::IMAGE_MEDIA_MODES, true);
        }
        $meta = self::metadata($recipeOrBlueprint);
        return (int) $meta['min_images'] > 0
            && array_intersect(self::IMAGE_MEDIA_MODES, (array) $meta['media_modes']) !== [];
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
        // BIGR-885: the band recipe promises copy ABOVE the image and a band
        // that reaches both viewport edges. Both facts are objective, so they
        // are counted here beside the media/copy region hooks.
        $textInMedia = 0;
        $fullWidthMedia = 0;
        foreach ($document->indices() as $index) {
            $name = $document->name($index);
            $attrs = $document->attrs($index) ?? [];
            if ($name === 'cover') {
                $covers++;
            }
            $classes = preg_split(
                '/\s+/',
                trim((string) ($attrs['className'] ?? '')),
                -1,
                PREG_SPLIT_NO_EMPTY,
            ) ?: [];
            if (in_array('hero-composition__copy', $classes, true)) {
                $copyRegions++;
            }
            $isMediaRegion = in_array('hero-composition__media', $classes, true);
            if ($isMediaRegion) {
                $mediaRegions++;
            }
            $inMedia = $isMediaRegion || self::hasAncestorClass($document, $index, 'hero-composition__media');
            // The REGION itself must be full width. An `align:full` image
            // inside a constrained wrapper still renders at the reading
            // measure, because the wrapper caps it (audited: bindery-en).
            if ($isMediaRegion && (string) ($attrs['align'] ?? '') === 'full') {
                $fullWidthMedia++;
            }
            if ($inMedia && in_array($name, ['heading', 'paragraph', 'buttons', 'button'], true)) {
                $textInMedia++;
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
        } elseif (in_array('band-image', $mediaModes, true)
            && $mediaRegions < $minImages
        ) {
            $warnings[] = self::markupWarning(
                $part,
                'recipe band media region',
                ['required_class' => 'hero-composition__media', 'minimum' => $minImages],
                ['matching_regions' => $mediaRegions],
                'safe parseable hero was retained; restore only the missing assigned image-band region hook',
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
        // BIGR-885 objective failures for the band recipe. A wp:cover and any
        // text inside the media region both put copy over the photograph,
        // which is the one thing this recipe exists to prevent. A band that
        // stops short of the viewport edge is the recipe's other failure.
        if (in_array('band-image', $mediaModes, true)) {
            if ($covers > 0) {
                $warnings[] = self::markupWarning(
                    $part,
                    'band recipe cover usage',
                    ['wp_cover_count' => 0, 'media_modes' => $mediaModes],
                    ['wp_cover_count' => $covers],
                    'safe parseable hero was retained; replace only the cover with a plain full-width image band under the copy',
                );
            }
            if ($textInMedia > 0) {
                $warnings[] = self::markupWarning(
                    $part,
                    'band recipe text over media',
                    ['text_blocks_inside_media' => 0],
                    ['text_blocks_inside_media' => $textInMedia],
                    'safe parseable hero was retained; move only the copy blocks out of the image band and back above it',
                );
            }
            if ($mediaRegions > 0 && $fullWidthMedia < 1) {
                $warnings[] = self::markupWarning(
                    $part,
                    'band recipe media width',
                    ['align' => 'full on the hero-composition__media block itself'],
                    ['align' => 'the media region is capped below full width'],
                    'safe parseable hero was retained; restore only the band alignment so the image reaches both viewport edges',
                );
            }
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
            if (self::hasAncestorClass($document, $index, 'hero-composition__copy')) {
                $copyTextBlocks++;
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
            $headline = PlainText::fromMarkup($h1Match[1]);
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
        // BIGR-885: a recipe may accept more than one aspect. The band runs
        // edge to edge, so `ultrawide` fits its letterbox crop as well as
        // `landscape` does, and image-generation.md permits both for a band
        // that spans the viewport.
        $expectedAspects = match ($recipe) {
            'framed-portrait' => ['portrait'],
            'cinematic-safe-zone', 'layered-poster' => ['landscape'],
            'stacked-headline-band' => ['landscape', 'ultrawide'],
            default => [],
        };
        if ($expectedAspects !== [] && $images !== []) {
            $aspects = array_values(array_map(
                static fn (array $image): string => self::imageAspect($image['alt']),
                $images,
            ));
            $mismatched = array_filter(
                $aspects,
                static fn (string $aspect): bool => !in_array($aspect, $expectedAspects, true),
            );
            if ($mismatched !== []) {
                $warnings[] = self::markupWarning(
                    $part,
                    'recipe image aspect',
                    ['aspect' => implode(' or ', $expectedAspects)],
                    ['aspects' => $aspects],
                    'safe parseable hero was retained; regenerate or recrop only the mismatched assigned image slot',
                );
            }
        }
        return $warnings;
    }

    /** Whether any ancestor of one block carries a class token. */
    private static function hasAncestorClass(BlockMarkup $document, int $index, string $class): bool
    {
        for ($parent = $document->parent($index); $parent !== null; $parent = $document->parent($parent)) {
            $classes = preg_split(
                '/\s+/',
                trim((string) (($document->attrs($parent) ?? [])['className'] ?? '')),
                -1,
                PREG_SPLIT_NO_EMPTY,
            ) ?: [];
            if (in_array($class, $classes, true)) {
                return true;
            }
        }
        return false;
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
