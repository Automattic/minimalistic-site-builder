<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Open hero authoring plus optional reviewed recipes. The model composes an
 * unconstrained opening from the concept; explicit capability limits and
 * recipe assignments use the catalog. Stable selection is the fallback.
 */
final class HeroComposition
{
    /** An authoring mode, not a fourth layout template. RECIPES remain opt-in references. */
    public const AUTHORED = 'authored';

    public static function isAuthored(string $recipe): bool
    {
        return $recipe === self::AUTHORED;
    }

    public static function isKnown(string $recipe): bool
    {
        return self::isAuthored($recipe) || isset(self::CATALOG[$recipe]);
    }

    public static function isAuthoredMarkup(string $markup): bool
    {
        $doc = BlockMarkup::parse($markup);
        $root = $doc->topLevel();
        $classes = $root === null ? '' : (string) (($doc->attrs($root) ?? [])['className'] ?? '');
        return in_array('hero-composition--authored', preg_split('/\s+/', trim($classes)) ?: [], true);
    }
    /** @var list<string> */
    public const RECIPES = [
        'cinematic-safe-zone',
        'foreground-split',
        'layered-poster',
    ];

    /**
     * The shape of the media slot, and how much of the composition it takes.
     *
     * Both were recipe identities until BIGR-912. `editorial-split`,
     * `framed-portrait` and `focal-subject-stage` were one topology — a copy
     * column beside one contained foreground image — separated only by the
     * image aspect and the media scale. Three of six recipes drew the same
     * composition, so half of all sites opened with it. They are one recipe
     * now, and these two axes carry the differences the visitor could see.
     *
     * A cover recipe has one landscape plate that IS the band, so it pins
     * both axes to their only meaningful value.
     *
     * @var list<string>
     */
    public const MEDIA_ASPECTS = ['portrait', 'landscape', 'square'];

    /** @var list<string> */
    public const MEDIA_WEIGHTS = ['balanced', 'dominant'];

    // Caller-constraint enum. 'none' retired with type-manifesto (BIGR-885,
    // removed again after the lumen audit): no cataloged recipe is imageless,
    // so a caller cannot request it. 'none' stays a valid HeroBlueprint
    // media_mode for the delivered value of a media-loss degradation.
    public const MEDIA_MODES = ['cover-image', 'foreground-image'];

    /**
     * The media modes that put real pixels on the page, so the build must
     * generate an image for them. A mode absent from this list disarms image
     * generation: 'none' is the delivered value of a media-loss degradation,
     * and a future non-image mode must opt in here on purpose.
     *
     * @var list<string>
     */
    public const IMAGE_MEDIA_MODES = ['cover-image', 'foreground-image'];
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
            // The cover plate is the band, so both media axes pin.
            'media_aspects' => ['landscape'],
            'media_weights' => ['dominant'],
            'defaults' => [
                'media_mode' => 'cover-image', 'headline_register' => 'restrained',
                'text_anchor' => 'center',
                'headline_line_target' => ['desktop' => [1, 2], 'mobile' => [2, 4]],
                'focal_region' => 'end', 'text_safe_region' => 'center',
                'height_profile' => 'immersive', 'cta_treatment' => 'prominent',
                'mobile_transformation' => 'stack-media-first',
                'media_aspect' => 'landscape', 'media_weight' => 'dominant',
            ],
        ],
        // BIGR-912: the one contained-split recipe. It replaced
        // editorial-split, framed-portrait and focal-subject-stage, which
        // shared this topology and differed only in the two media axes below.
        'foreground-split' => [
            'canvases' => ['full-bleed', 'framed'],
            'media_modes' => ['foreground-image'],
            'min_images' => 1,
            'max_images' => 1,
            'backgrounds' => ['base', 'tinted', 'contrast'],
            'default_background' => 'base',
            'fallback_background' => 'base',
            'header_modes' => ['stacked'],
            // What it delivers, not what it claimed: the three recipes this
            // replaced all declared their budget as one heading, at most one
            // paragraph and one button, which is the compact capacity. Two of
            // them said 'standard' and shipped compact.
            'copy_capacity' => 'compact',
            'mobile_transformations' => ['stack-copy-first', 'stack-media-first'],
            'layout_archetype' => 'asymmetric-split',
            'fallback_family' => 'foreground-split',
            'root_hook' => '.hero-composition--foreground-split',
            'prompt' => 'hero-compositions/foreground-split.md',
            'headline_registers' => ['restrained', 'display'],
            // The retired trio spanned compact..immersive between them; the
            // merged recipe keeps that whole range rather than the narrowest.
            'height_profiles' => ['compact', 'standard', 'immersive'],
            'media_aspects' => ['portrait', 'landscape', 'square'],
            'media_weights' => ['balanced', 'dominant'],
            'defaults' => [
                'media_mode' => 'foreground-image', 'headline_register' => 'display',
                'text_anchor' => 'center-start',
                'headline_line_target' => ['desktop' => [1, 3], 'mobile' => [2, 5]],
                'focal_region' => 'none', 'text_safe_region' => 'full',
                'height_profile' => 'standard', 'cta_treatment' => 'prominent',
                'mobile_transformation' => 'stack-copy-first',
                'media_aspect' => 'landscape', 'media_weight' => 'balanced',
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
            // The cover plate is the band, so both media axes pin.
            'media_aspects' => ['landscape'],
            'media_weights' => ['dominant'],
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
                'media_aspect' => 'landscape', 'media_weight' => 'dominant',
            ],
        ],
    ];

    /** Supported structures, exposed for concept-led choice rather than random assignment. */
    public static function choicePrompt(array $constraints = []): string
    {
        // Count/mode/copy ceilings currently have executable guarantees in
        // the recipe path. Never advertise a freer mode that ignores them.
        if (!self::isCompatible(self::AUTHORED, $constraints)) {
            return self::recipeChoicePrompt($constraints);
        }
        return "Choose the hero composition by starting with this site's concept, not a template. "
            . "Set hero_blueprint.recipe to authored (the default authoring mode, not a layout). "
            . "In rationale name the primary impression and why it belongs to THIS subject and requested style. "
            . "In composition name the focal point, how image and headline work together, and the essential content; "
            . "identify supporting details better placed later rather than filling the opening with everything available. "
            . "Describe actual grouping, alignment and spacing relationships, not independent coordinates for each element. "
            . "In mobile_layout preserve that hierarchy in a deliberate source reading order, not a blind stack of desktop columns. "
            . "Use source_order for an optional ordered list of unique design-* class names on disjoint meaningful elements "
            . "(such as a principal image and supporting details) whose relative DOM order matters. Name the corresponding "
            . "elements in composition; do not prescribe every wrapper or add elements just to populate the list. "
            . "An empty list is valid. This is not a universal image-first rule, content quota or first-screen height limit. "
            . "Before returning the blueprint, resolve competing focal points and details that delay its primary impression. "
            . "Include at least one image; "
            . "multiple images are welcome when they serve the concept. media_mode is foreground-image, cover-image, or mixed. "
            . "The supported blocks own their responsive behavior. "
            . "Do not map aesthetic labels to fixed templates; give the required imagery a meaningful role. "
            . "Keep hero-specific composition in this blueprint, not the site-wide narrative. "
            . "Caller limits (capabilities, not creative targets): "
            . json_encode(self::validateConstraints($constraints), JSON_THROW_ON_ERROR)
            . "\nBlueprint shape (example values are not assignments):\n"
            . json_encode(HeroBlueprint::promptValues(HeroBlueprint::defaultFor(self::AUTHORED)), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    /** Recipe-only reference data for callers that explicitly want a catalog. */
    public static function recipeChoicePrompt(array $constraints = []): string
    {
        $choices = [];
        foreach (self::compatible($constraints) as $recipe) {
            $meta = self::metadata($recipe);
            $choices[$recipe] = [
                'structure' => $meta['layout_archetype'],
                'media_modes' => $meta['media_modes'],
                'headline_registers' => $meta['headline_registers'],
                'height_profiles' => $meta['height_profiles'],
                'mobile_transformations' => $meta['mobile_transformations'],
                'media_aspects' => $meta['media_aspects'],
                'media_weights' => $meta['media_weights'],
                'blueprint_example' => HeroBlueprint::defaultFor($recipe, $constraints),
            ];
        }
        return "Choose the hero composition that best expresses the user's requested style and this concept. "
            . "Set hero_blueprint.recipe to one of the compatible choices below. These are supported structures, "
            . "not a ranking: choose deliberately, not by list position. Choose the permitted media aspect, weight, "
            . "headline register, height and mobile transformation yourself; example values are not assignments. "
            . "A cinematic cover centers restrained copy on a photograph; foreground-split pairs copy with one "
            . "contained image; layered-poster gives display typography the leading role over a photographic plate. "
            . "Describe the hero only in hero_blueprint, not in the site-wide narrative.\n\n"
            . json_encode($choices, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function assertKnown(string $recipe): void
    {
        if (!self::isKnown($recipe)) {
            throw new \InvalidArgumentException(
                "unknown hero recipe '{$recipe}' (use one of: " . implode(', ', self::RECIPES) . ')'
            );
        }
    }

    /** @return array<string,mixed> */
    public static function metadata(string $recipe): array
    {
        self::assertKnown($recipe);
        if (self::isAuthored($recipe)) {
            return [
                'canvases' => self::CANVASES,
                'media_modes' => ['foreground-image', 'cover-image', 'mixed'],
                'min_images' => 1, 'max_images' => PHP_INT_MAX,
                'backgrounds' => ['base', 'tinted', 'contrast', 'image'],
                'default_background' => 'base', 'fallback_background' => 'base',
                'header_modes' => ['stacked', 'overlay'],
                'copy_capacity' => 'expanded',
                'mobile_transformations' => ['authored'],
                'layout_archetype' => self::AUTHORED,
                'fallback_family' => 'typographic',
                'root_hook' => '.hero-composition--authored',
                'prompt' => 'hero.md',
                'headline_registers' => HeroBlueprint::HEADLINE_REGISTERS,
                'height_profiles' => HeroBlueprint::HEIGHT_PROFILES,
                'media_aspects' => self::MEDIA_ASPECTS,
                'media_weights' => self::MEDIA_WEIGHTS,
                'defaults' => [
                    'media_mode' => 'foreground-image', 'headline_register' => 'display',
                    'text_anchor' => 'center-start',
                    'headline_line_target' => ['desktop' => [1, 3], 'mobile' => [1, 6]],
                    'focal_region' => 'none', 'text_safe_region' => 'full',
                    'height_profile' => 'standard', 'cta_treatment' => 'prominent',
                    'mobile_transformation' => 'authored',
                    'media_aspect' => 'landscape', 'media_weight' => 'balanced',
                ],
            ];
        }
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
     * The recipe branch reads min_images. The blueprint branch reads the
     * catalog's max_images first, so a blueprint that drifted to an image
     * media_mode the recipe does not own cannot arm a slot the composition
     * has nowhere to put (BIGR-885).
     *
     * Both branches then read IMAGE_MEDIA_MODES rather than an inline pair
     * (BIGR-885). An inline list silently disarmed generation for every mode
     * added after it was written, and that recipe shipped with an empty slot.
     *
     * @param string|array<string,mixed> $recipeOrBlueprint
     */
    public static function usesGeneratedImages(string|array $recipeOrBlueprint): bool
    {
        if (is_array($recipeOrBlueprint)) {
            $recipe = trim((string) ($recipeOrBlueprint['recipe'] ?? ''));
            self::assertKnown($recipe);
            if ((int) self::metadata($recipe)['max_images'] < 1) {
                return false;
            }
            $mode = strtolower(trim((string) ($recipeOrBlueprint['media_mode'] ?? '')));
            return in_array($mode, self::IMAGE_MEDIA_MODES, true)
                || (self::isAuthored($recipe) && $mode === 'mixed');
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
            // A ceiling, not an exact match (BIGR-912). Every other constraint
            // here reads as a caller capability the recipe must fit inside —
            // max_hero_images directly above is the same shape — and a caller
            // that can host a standard copy region can obviously host a
            // compact one. Exact matching also made the enum fragile: merging
            // the three contained-split recipes left NO recipe at 'standard',
            // so that value would have emptied the pool and aborted the build
            // for a flag the CLI still offers.
            if (isset($constraints['hero_copy_capacity'])
                && self::copyCapacityRank($meta['copy_capacity'])
                    > self::copyCapacityRank($constraints['hero_copy_capacity'])) {
                return false;
            }
            return true;
        }));
    }

    /** Position of one capacity on the compact..expanded scale. */
    private static function copyCapacityRank(string $capacity): int
    {
        $rank = array_search($capacity, self::COPY_CAPACITIES, true);
        return $rank === false ? 0 : (int) $rank;
    }

    /** Whether a known recipe satisfies a validated constraint set. */
    public static function isCompatible(string $recipe, array $constraints = []): bool
    {
        self::assertKnown($recipe);
        if (self::isAuthored($recipe)) {
            $constraints = self::validateConstraints($constraints);
            return array_diff_key($constraints, ['hero_canvas' => true]) === [];
        }
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
     * Select this build's media axes inside the recipe's own allowed values.
     *
     * The recipe is code-owned and seeded; the axes must be too (BIGR-912).
     * They carry the differences the three merged contained-split recipes used
     * to carry as separate ids, and a fixed default would retire that variety
     * the moment the merge landed: the design-direction prompt is told to
     * preserve the defaults it is handed, so every site would open with the
     * same landscape plate at the same scale. Seeding them from the same site
     * identity that picks the recipe restores the spread by construction
     * rather than by asking the model to feel adventurous.
     *
     * An axis with one allowed value returns it unchanged, so a cover recipe
     * is unaffected.
     *
     * @return array{media_aspect:string,media_weight:string}
     */
    public static function selectMediaAxes(
        string $stableIdentifier,
        string $conceptSeed,
        string $recipe,
    ): array {
        $meta = self::metadata($recipe);
        $identity = mb_strtolower(trim($stableIdentifier), 'UTF-8');
        $pick = static function (array $values, string $axis) use ($identity, $conceptSeed): string {
            if (count($values) === 1) {
                return (string) $values[0];
            }
            $context = json_encode(
                [$identity, $conceptSeed, $axis],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
            $index = (int) (hexdec(substr(hash('sha256', $context), 0, 8)) % count($values));
            return (string) $values[$index];
        };

        return [
            'media_aspect' => $pick(array_values((array) $meta['media_aspects']), 'media_aspect'),
            'media_weight' => $pick(array_values((array) $meta['media_weights']), 'media_weight'),
        ];
    }

    /**
     * Advisory objective checks for safe, parseable recipe internals. Root
     * shape and identity are repaired by GeneratedMarkup; these checks keep a
     * missing/extra media slot or helper region actionable without rewriting a
     * valid authored composition toward a different recipe.
     *
     * $blueprint is the delivered blueprint when the caller has it. It sharpens
     * the image-aspect check for a recipe whose slot accepts several aspects
     * (BIGR-912); every other check reads the catalog alone, so a caller
     * without a blueprint keeps all of them.
     *
     * @param array<string,mixed> $blueprint
     * @return list<string>
     */
    public static function markupWarnings(
        string $markup,
        string $recipe,
        string $part,
        array $blueprint = [],
    ): array {
        self::assertKnown($recipe);
        // Authored heroes require imagery, but no fixed topology, image upper
        // limit, copy count or mandatory helper regions. Missing media cannot
        // be placed safely without making a new composition decision.
        if (self::isAuthored($recipe)) {
            $warnings = preg_match('~<img\b~i', $markup) === 1 ? [] : [
                self::markupWarning(
                    $part,
                    'hero image',
                    ['image_count' => 0],
                    ['image_count' => 0],
                    'safe hero retained without its required imagery; add at least one image that serves the composition; no copy or sibling was removed',
                ),
            ];
            array_push($warnings, ...self::sourceOrderWarnings(
                $markup, $blueprint['source_order'] ?? [], "theme/parts/{$part}.html",
            ));
            return $warnings;
        }
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
        $mediaModes = (array) $meta['media_modes'];
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
        // Match the delivery budget: headline, support, and optionally one
        // short caption before the headline. Keep excess copy advisory here.
        $copyTextBlocks = 0;
        $captionAllowed = false;
        $seenHeadline = false;
        foreach ($document->indices() as $index) {
            if (!in_array($document->name($index), ['heading', 'paragraph'], true)) {
                continue;
            }
            if (self::hasAncestorClass($document, $index, 'hero-composition__copy')) {
                $copyTextBlocks++;
                $attrs = $document->attrs($index) ?? [];
                if ($document->name($index) === 'heading' && ($attrs['level'] ?? 2) === 1) {
                    $seenHeadline = true;
                }
                if (!$seenHeadline && $document->name($index) === 'paragraph'
                    && ($attrs['fontSize'] ?? null) === 'caption'
                ) {
                    $text = PlainText::fromMarkup($document->innerHtml($index));
                    $captionAllowed = $captionAllowed || ($text !== '' && mb_strlen($text, 'UTF-8') <= 80);
                }
            }
        }
        $textBudget = 2 + (int) $captionAllowed;
        if ($copyTextBlocks > $textBudget) {
            $warnings[] = self::markupWarning(
                $part,
                'hero copy budget',
                ['copy_capacity' => $meta['copy_capacity'], 'max_text_blocks' => $textBudget],
                ['text_blocks' => $copyTextBlocks],
                'safe parseable hero was retained; move excess copy into a following section, keeping one headline, '
                    . 'one short standfirst and at most one optional caption label',
            );
        }

        $images = self::imageFacts($markup);
        // The aspect a recipe can serve is catalog metadata, not a name: a
        // recipe pinned to one aspect checks against that, and a recipe whose
        // slot can take several (foreground-split, BIGR-912) checks against the
        // ONE the delivered blueprint committed to. Without a blueprint the
        // catalog list still bounds the check, so a caller that has no
        // blueprint to hand loses precision rather than the check.
        $committed = is_string($blueprint['media_aspect'] ?? null)
            ? trim((string) $blueprint['media_aspect'])
            : '';
        $expectedAspects = in_array($committed, (array) $meta['media_aspects'], true)
            ? [$committed]
            : array_values((array) $meta['media_aspects']);
        // A full-bleed cover plate is wide whichever wide source feeds it. The
        // image prompt steers a background toward `ultrawide` so the desktop
        // banner crops less, so that source is the committed landscape plate
        // delivered well, not drift; a contained foreground plate never takes
        // it, so the exact check stays for those recipes.
        if (
            in_array('cover-image', $mediaModes, true)
            && in_array('landscape', $expectedAspects, true)
            && !in_array('ultrawide', $expectedAspects, true)
        ) {
            $expectedAspects[] = 'ultrawide';
        }
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

    /**
     * Advisory DOM-order check, not a visual-quality score or a repair.
     * The model names its own disjoint targets; no class means "image first"
     * universally. Inspect saved HTML, not comment attributes. Missing,
     * duplicated or overlapping targets cannot prove a reading order.
     *
     * @param list<string> $order normalized hero_blueprint.source_order
     * @return list<string>
     */
    public static function sourceOrderWarnings(string $markup, array $order, string $file, ?string $anchor = null): array
    {
        if ($order === []) {
            return [];
        }
        $dom = Html::loadUtf8Html($markup, LIBXML_NONET);
        $root = $anchor === null ? $dom?->documentElement : $dom?->getElementById($anchor);
        $found = array_fill_keys($order, []);
        $sequence = [];
        if ($root !== null) {
            foreach ([$root, ...iterator_to_array($root->getElementsByTagName('*'))] as $node) {
                $classes = preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [];
                foreach ($order as $class) {
                    if (in_array($class, $classes, true)) {
                        $found[$class][] = $node;
                        $sequence[] = $class;
                    }
                }
            }
        }
        $counts = array_map('count', $found);
        $overlap = false;
        $targets = array_merge(...array_values($found));
        foreach ($targets as $i => $node) {
            foreach ($targets as $j => $other) {
                if ($i === $j) {
                    continue;
                }
                for ($ancestor = $node; $ancestor !== null; $ancestor = $ancestor->parentNode) {
                    if ($ancestor->isSameNode($other)) {
                        $overlap = true;
                        break;
                    }
                }
            }
        }
        if ($root !== null && !$overlap && $sequence === $order && count(array_filter($counts, static fn ($count) => $count !== 1)) === 0) {
            return [];
        }
        return ['file=' . self::describe($file) . '; block=' . self::describe($anchor ?? 'hero root')
            . '; path=hero_blueprint.source_order; authored=' . self::describe($order)
            . '; delivered=' . self::describe(['sequence' => $sequence, 'target_counts' => $counts, 'overlapping_targets' => $overlap])
            . '; disposition=retained content unchanged; reconcile the named targets and their DOM order with the hero intent; no visual order was inferred'];
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
