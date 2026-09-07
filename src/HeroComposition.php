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
        'foreground-split',
        'layered-poster',
        'panel-stage',
        'marquee-name',
        'metadata-corners',
        'portrait-backdrop',
        'wordmark-stage',
    ];

    /** The giant name headline of wordmark-stage (frm W2e). */
    public const WORDMARK_CLASS = 'hero-composition__wordmark';

    /** The optional facts ledger of wordmark-stage: one flex group of two or three short paragraphs (frm W2e). */
    public const FACTS_CLASS = 'hero-composition__facts';

    /** @var list<int> */
    public const FACTS_COUNTS = [2, 3];

    /**
     * Per-character advance the wordmark pin assumes, in em (frm W2e): a
     * generous median for a display face in either case, so a long name
     * pins smaller rather than running off a phone.
     */
    public const WORDMARK_CHAR_EM = 0.66;

    /**
     * Per-character advances of a bold grotesque, in em (frm PR-2v). The
     * flat 0.66 let "momentum" overrun its plate and left "STUDIO GESTALTEN"
     * short of the band. Letters outside the table take the case default;
     * each case scales by the theme's heading face through HeroHeadlineFit.
     *
     * @var array<string,float>
     */
    private const WORDMARK_GLYPH_EM = [
        'I' => 0.30, 'J' => 0.48, 'L' => 0.58, 'E' => 0.60, 'F' => 0.58, 'T' => 0.62, 'M' => 0.88, 'W' => 0.95,
        'i' => 0.28, 'l' => 0.28, 'j' => 0.28, 't' => 0.36, 'f' => 0.34, 'r' => 0.40, 'm' => 0.95, 'w' => 0.88,
        ' ' => 0.30,
    ];
    private const WORDMARK_UPPER_EM = 0.70;
    private const WORDMARK_LOWER_EM = 0.58;
    private const WORDMARK_DIGIT_EM = 0.60;
    private const WORDMARK_OTHER_EM = 0.32;

    /**
     * The width of a name in em for the theme's heading face, or for a
     * generic bold grotesque when the theme is unknown.
     *
     * @param array<string,mixed>|null $theme
     */
    public static function wordmarkEm(string $text, ?array $theme = null): float
    {
        // The theme may set every heading uppercase (frm PR-2x): the pin
        // measures the name the way it renders, or "Studio Blank" measured
        // in mixed case wraps as "STUDIO BLANK" and overruns the viewport.
        if ($theme !== null && self::headingsUppercase($theme)) {
            $text = mb_strtoupper($text, 'UTF-8');
        }
        $upperScale = $theme === null ? 1.0 : HeroHeadlineFit::characterEmFor($theme, true) / self::WORDMARK_UPPER_EM;
        $lowerScale = $theme === null ? 1.0 : HeroHeadlineFit::characterEmFor($theme, false) / self::WORDMARK_LOWER_EM;
        $em = 0.0;
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            if (isset(self::WORDMARK_GLYPH_EM[$char])) {
                $width = self::WORDMARK_GLYPH_EM[$char];
                $scale = $char === ' ' ? 1.0 : (mb_strtoupper($char, 'UTF-8') === $char ? $upperScale : $lowerScale);
            } elseif (preg_match('/\p{Lu}/u', $char) === 1) {
                $width = self::WORDMARK_UPPER_EM;
                $scale = $upperScale;
            } elseif (preg_match('/\p{Ll}/u', $char) === 1) {
                $width = self::WORDMARK_LOWER_EM;
                $scale = $lowerScale;
            } elseif (preg_match('/\p{N}/u', $char) === 1) {
                $width = self::WORDMARK_DIGIT_EM;
                $scale = $upperScale;
            } else {
                $width = self::WORDMARK_OTHER_EM;
                $scale = 1.0;
            }
            $em += $width * $scale;
        }
        return max(0.3, round($em, 2));
    }

    /** Whether the theme renders headings (or the H1) in uppercase. */
    public static function headingsUppercase(array $theme): bool
    {
        foreach (['heading', 'h1'] as $element) {
            $transform = $theme['styles']['elements'][$element]['typography']['textTransform'] ?? null;
            if (is_string($transform) && strtolower(trim($transform)) === 'uppercase') {
                return true;
            }
        }
        return false;
    }

    /** The one portrait plate of portrait-backdrop (frm W2d). */
    public const PORTRAIT_CLASS = 'hero-composition__portrait';

    /** The corner facts of metadata-corners: one flex group of two or three short paragraphs (frm W2c). */
    public const META_CLASS = 'hero-composition__meta';

    /** The fact counts the corner group may hold (frm W2c). */
    public const META_FACT_COUNTS = [2, 3];

    /** The one decorative element of marquee-name: the site name at display scale behind the copy (frm W2b). */
    public const MARQUEE_CLASS = 'hero-composition__marquee';

    /**
     * The floating objects of a 3d-object site's marquee-name hero (frm W7c):
     * one aria-hidden group, last in the root, of two to four transparent
     * object cutouts the theme pins around the copy and drifts. Decorative,
     * so they never count as hero media.
     */
    public const OBJECTS_CLASS = 'hero-composition__objects';

    /** The object counts the floating group may hold (frm W7c). */
    public const OBJECT_COUNTS = [2, 3, 4];

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
        // frm W2a: the product-landing opener of the reference corpus (Zova).
        // A rounded, tinted panel on the page ground holds the copy on the
        // leading side and one foreground illustration or mockup on the
        // trailing side; a second, wider product image may sit below the
        // copy row inside the same panel. Two images at most, so a caller
        // capped at one image never sees it; the header stays stacked above
        // the panel.
        'panel-stage' => [
            'canvases' => ['full-bleed', 'framed'],
            'media_modes' => ['foreground-image'],
            'min_images' => 1,
            'max_images' => 2,
            'backgrounds' => ['base', 'tinted'],
            'default_background' => 'base',
            'fallback_background' => 'base',
            'header_modes' => ['stacked'],
            'copy_capacity' => 'compact',
            'mobile_transformations' => ['stack-copy-first'],
            'layout_archetype' => 'asymmetric-split',
            'fallback_family' => 'foreground-split',
            'root_hook' => '.hero-composition--panel-stage',
            'prompt' => 'hero-compositions/panel-stage.md',
            'headline_registers' => ['restrained', 'display'],
            'height_profiles' => ['standard', 'immersive'],
            'media_aspects' => ['landscape', 'square'],
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
        // frm W2b: the playful-portfolio opener of the reference corpus
        // (Cohesion). The site name runs giant and clipped behind a centered
        // stack: one small avatar plate, the headline, at most one line and
        // one action. The name is decorative (aria-hidden paragraph the kit
        // paints at low opacity), never a heading, and it sits last in the
        // root so the H1 stays the hero's first text line. The header stays
        // stacked above the stage.
        'marquee-name' => [
            'canvases' => ['full-bleed', 'framed'],
            'media_modes' => ['foreground-image'],
            'min_images' => 1,
            'max_images' => 1,
            'backgrounds' => ['base', 'tinted'],
            'default_background' => 'base',
            'fallback_background' => 'base',
            'header_modes' => ['stacked'],
            'copy_capacity' => 'compact',
            'mobile_transformations' => ['stack-media-first'],
            'layout_archetype' => 'centered-stack',
            'fallback_family' => 'foreground-split',
            'root_hook' => '.hero-composition--marquee-name',
            'prompt' => 'hero-compositions/marquee-name.md',
            'headline_registers' => ['display', 'poster'],
            'height_profiles' => ['standard', 'immersive'],
            'media_aspects' => ['square', 'portrait'],
            'media_weights' => ['balanced'],
            'defaults' => [
                'media_mode' => 'foreground-image', 'headline_register' => 'display',
                'text_anchor' => 'center',
                'headline_line_target' => ['desktop' => [1, 2], 'mobile' => [2, 4]],
                'focal_region' => 'none', 'text_safe_region' => 'full',
                'height_profile' => 'standard', 'cta_treatment' => 'prominent',
                'mobile_transformation' => 'stack-media-first',
                'media_aspect' => 'square', 'media_weight' => 'balanced',
            ],
        ],
        // frm W2c: Spector's opener. One full-bleed portrait cover, the copy
        // a start-aligned stack of short lines centered in the frame, and two
        // or three small facts the build lifts to the top corners. The
        // facts sit LAST in the cover so the H1 stays the first text line and
        // the copy budget reads only the copy group.
        'metadata-corners' => [
            'canvases' => ['full-bleed', 'framed'],
            'media_modes' => ['cover-image'],
            'min_images' => 1,
            'max_images' => 1,
            'backgrounds' => ['image', 'contrast'],
            'default_background' => 'image',
            'fallback_background' => 'contrast',
            'header_modes' => ['stacked', 'overlay'],
            'copy_capacity' => 'compact',
            'mobile_transformations' => ['retain-media-overlay'],
            'layout_archetype' => 'full-bleed-cover',
            'fallback_family' => 'cover',
            'root_hook' => '.hero-composition--metadata-corners',
            'prompt' => 'hero-compositions/metadata-corners.md',
            'headline_registers' => ['display', 'poster'],
            'height_profiles' => ['immersive'],
            'media_aspects' => ['landscape'],
            'media_weights' => ['dominant'],
            'defaults' => [
                'media_mode' => 'cover-image', 'headline_register' => 'poster',
                'text_anchor' => 'center-start',
                'headline_line_target' => ['desktop' => [2, 3], 'mobile' => [2, 5]],
                'focal_region' => 'end', 'text_safe_region' => 'start',
                'height_profile' => 'immersive', 'cta_treatment' => 'quiet',
                'mobile_transformation' => 'retain-media-overlay',
                'media_aspect' => 'landscape', 'media_weight' => 'dominant',
            ],
        ],
        // frm W2d: Luzia's opener. One large portrait of the person centered
        // on the page ground, then one copy row under it: the headline on the
        // leading side, one line and the action on the trailing side. The
        // plate is opaque (no cutout: keyed hair edges fray), sized and
        // rounded by the theme; the header stays stacked above.
        'portrait-backdrop' => [
            'canvases' => ['full-bleed', 'framed'],
            'media_modes' => ['foreground-image'],
            'min_images' => 1,
            'max_images' => 1,
            'backgrounds' => ['base', 'tinted'],
            'default_background' => 'base',
            'fallback_background' => 'base',
            'header_modes' => ['stacked'],
            'copy_capacity' => 'compact',
            'mobile_transformations' => ['stack-media-first'],
            'layout_archetype' => 'asymmetric-split',
            'fallback_family' => 'foreground-split',
            'root_hook' => '.hero-composition--portrait-backdrop',
            'prompt' => 'hero-compositions/portrait-backdrop.md',
            'headline_registers' => ['display', 'restrained'],
            'height_profiles' => ['standard', 'immersive'],
            'media_aspects' => ['portrait', 'square'],
            'media_weights' => ['dominant'],
            'defaults' => [
                'media_mode' => 'foreground-image', 'headline_register' => 'display',
                'text_anchor' => 'center-start',
                'headline_line_target' => ['desktop' => [2, 3], 'mobile' => [2, 5]],
                'focal_region' => 'none', 'text_safe_region' => 'full',
                'height_profile' => 'standard', 'cta_treatment' => 'prominent',
                'mobile_transformation' => 'stack-media-first',
                'media_aspect' => 'portrait', 'media_weight' => 'dominant',
            ],
        ],
        // frm W2e: fabrica, dasstudio and calderr open on the name itself.
        // The site name set giant is the level-1 heading, in the heading
        // face and case the direction commits; one line and at most one
        // action under it; an optional facts ledger of two or three short
        // paragraphs on the trailing side. No picture: the name is the
        // picture. The build pins the name's size to the viewport from its
        // character count, so it fills the measure on every screen.
        'wordmark-stage' => [
            'canvases' => ['full-bleed', 'framed'],
            // The foreground mode with a zero budget: the recipe sits in the
            // foreground pools (a stated light page keeps it) and generates
            // nothing, because max_images is 0 and the gate reads the budget.
            'media_modes' => ['foreground-image'],
            'min_images' => 0,
            'max_images' => 0,
            'backgrounds' => ['base', 'tinted', 'contrast'],
            'default_background' => 'base',
            'fallback_background' => 'base',
            'header_modes' => ['stacked'],
            'copy_capacity' => 'compact',
            'mobile_transformations' => ['stack-copy-first'],
            'layout_archetype' => 'centered-stack',
            'fallback_family' => 'foreground-split',
            'root_hook' => '.hero-composition--wordmark-stage',
            'prompt' => 'hero-compositions/wordmark-stage.md',
            'headline_registers' => ['poster', 'display'],
            'height_profiles' => ['compact', 'standard'],
            'media_aspects' => ['square'],
            'media_weights' => ['balanced'],
            'defaults' => [
                'media_mode' => 'foreground-image', 'headline_register' => 'poster',
                'text_anchor' => 'center-start',
                'headline_line_target' => ['desktop' => [1, 2], 'mobile' => [1, 3]],
                'focal_region' => 'none', 'text_safe_region' => 'full',
                'height_profile' => 'standard', 'cta_treatment' => 'quiet',
                'mobile_transformation' => 'stack-copy-first',
                'media_aspect' => 'square', 'media_weight' => 'balanced',
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
    public static function planProjection(array $blueprint, ?string $statedBackground = null): array
    {
        $recipe = trim((string) ($blueprint['recipe'] ?? ''));
        $meta = self::metadata($recipe);
        $allowed = array_values($meta['backgrounds']);
        $default = (string) $meta['default_background'];
        // frm PR-2q: a hero surface the brief states, when the recipe allows
        // it, is the one surface the plan may deliver. fabrica's "hero is one
        // dark photo panel with a giant lowercase wordmark" opened on the
        // page ground twice.
        if ($statedBackground !== null
            && in_array($statedBackground, $allowed, true)
            && !in_array('image', $allowed, true)) {
            // A cover recipe keeps its picture: a "dark hero" there is the
            // dimmed image, not a solid panel.
            $allowed = [$statedBackground];
            $default = $statedBackground;
        }
        return [
            'layout_archetype' => (string) $meta['layout_archetype'],
            'allowed_backgrounds' => $allowed,
            'default_background' => $default,
            'fallback_family' => (string) $meta['fallback_family'],
        ];
    }

    /**
     * Bounded phrases a brief uses to ask for a dark hero panel (frm PR-2q).
     * They name the hero's own surface, not the page ground, which GroundKey
     * reads on its own.
     *
     * @var list<string>
     */
    private const STATED_DARK_HERO_PHRASES = [
        'dark hero panel', 'dark photo panel', 'dark hero band', 'hero is one dark panel', 'hero is a dark panel',
        'near-black hero panel', 'black hero panel', 'dark panel hero', 'hero in a dark panel', 'hero on a dark panel',
        'one dark photo panel', 'dark rounded hero panel',
    ];

    /** The hero surface a brief states in so many words, or null. */
    public static function statedHeroSurface(string $brief): ?string
    {
        $text = mb_strtolower(preg_replace('/\s+/u', ' ', $brief) ?? $brief, 'UTF-8');
        foreach (self::STATED_DARK_HERO_PHRASES as $phrase) {
            if (preg_match('/(?<![\p{L}-])' . preg_quote($phrase, '/') . '(?![\p{L}-])/u', $text) === 1) {
                return 'contrast';
            }
        }
        return null;
    }

    /** @param array<string,mixed> $meta */
    public static function statedHeroSurfaceFor(array $meta): ?string
    {
        foreach (['original_prompt', 'prompt'] as $key) {
            $text = $meta[$key] ?? null;
            if (is_string($text) && trim($text) !== '' && self::statedHeroSurface($text) !== null) {
                return self::statedHeroSurface($text);
            }
        }
        return null;
    }

    /**
     * Phrases that put the hero itself inside a frame (frm PR-2y): parley's
     * "painted desert-sky cover hero in a rounded frame". The hero is exempt
     * from a framed canvas (prompts/hero.md), and only the wordmark-stage
     * plate takes the rounded band geometry (frm PR-2s), so a stated hero
     * frame needs its own reader, the way the stated dark hero panel has one.
     *
     * @var list<string>
     */
    private const STATED_HERO_FRAME_PHRASES = [
        'hero in a rounded frame', 'cover hero in a rounded frame', 'cover in a rounded frame',
        'in a rounded frame', 'in a rounded photo frame', 'rounded cover hero', 'rounded-frame hero',
        'framed cover hero', 'hero in a frame', 'cover hero in a frame', 'framed hero photo', 'framed hero image',
    ];

    /** The build-owned root marker a stated hero frame stamps on the hero root. */
    public const FRAME_MARKER_PREFIX = 'hero-frame--';

    public const FRAME_MARKER = 'hero-frame--rounded';

    /** Whether the brief puts the hero itself inside a rounded frame. */
    public static function statedHeroFrame(string $brief): bool
    {
        $text = mb_strtolower(preg_replace('/\s+/u', ' ', $brief) ?? $brief, 'UTF-8');
        foreach (self::STATED_HERO_FRAME_PHRASES as $phrase) {
            if (preg_match('/(?<![\p{L}-])' . preg_quote($phrase, '/') . '(?![\p{L}-])/u', $text) === 1) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $meta */
    public static function statedHeroFrameFor(array $meta): bool
    {
        foreach (['original_prompt', 'prompt'] as $key) {
            $text = $meta[$key] ?? null;
            if (is_string($text) && trim($text) !== '' && self::statedHeroFrame($text)) {
                return true;
            }
        }
        return false;
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
        return in_array($recipe, self::compatible($constraints), true);
    }

    /**
     * Select stably inside the objectively compatible pool.
     *
     * @throws \InvalidArgumentException when caller constraints leave no valid recipe
     */
    /**
     * Recipes that only a brief may ask for (frm PR-2m): a giant name
     * scrolling behind the hero is the cohesion brief's own device, and
     * the stable hash handed it to luzia-like16, whose brief names no such
     * thing. The stated path still returns them; the hash pick yields.
     *
     * @var list<string>
     */
    public const STATED_ONLY_RECIPES = ['marquee-name'];

    /**
     * The stable pick from the compatible pool minus the excluded recipes
     * (frm PR-2m), hashed the same way as select(). Falls back to select()
     * when the exclusion would empty the pool.
     *
     * @param array<string,mixed> $constraints
     * @param list<string> $excluded
     */
    public static function selectExcluding(
        string $stableIdentifier,
        string $conceptSeed,
        array $constraints,
        array $excluded,
    ): string {
        $constraints = self::validateConstraints($constraints);
        $pool = array_values(array_diff(self::compatible($constraints), $excluded));
        if ($pool === []) {
            return self::select($stableIdentifier, $conceptSeed, $constraints);
        }
        $identity = mb_strtolower(trim($stableIdentifier), 'UTF-8');
        $context = json_encode(
            [$identity, $conceptSeed, $constraints, $excluded],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        $index = (int) (hexdec(substr(hash('sha256', $context), 0, 8)) % count($pool));
        return $pool[$index];
    }

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
        $meta = self::metadata($recipe);
        $document = BlockMarkup::parse($markup);
        $root = $document->topLevel();
        // frm W7c: the floating-object group is scenery. It is cut out of
        // the markup the media checks read, so its cutouts never count as
        // hero media; its own shape is checked further down.
        $objectGroups = [];
        foreach ($document->indices() as $index) {
            $attrs = $document->attrs($index) ?? [];
            $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (in_array(self::OBJECTS_CLASS, $classes, true)) {
                $objectGroups[] = $index;
            }
        }
        $mediaMarkup = $markup;
        if ($objectGroups !== []) {
            $start = $document->openingOffset($objectGroups[0]);
            $end = $document->endOffset($objectGroups[0]);
            if ($end !== null && $end > $start) {
                $mediaMarkup = substr($markup, 0, $start) . substr($markup, $end);
            }
        }
        $imageCount = preg_match_all('~<img\b~i', $mediaMarkup, $unused);
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
        // frm W2b: the marquee name is the recipe's whole point. Exactly one
        // marked paragraph, outside the copy region (the H1 stays the first
        // text line and the copy budget stays honest), with text in it.
        if ($recipe === 'marquee-name') {
            $marquees = [];
            foreach ($document->indices() as $index) {
                $attrs = $document->attrs($index) ?? [];
                $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if (in_array(self::MARQUEE_CLASS, $classes, true)) {
                    $marquees[] = $index;
                }
            }
            $sound = count($marquees) === 1
                && $document->name($marquees[0]) === 'paragraph'
                && !self::hasAncestorClass($document, $marquees[0], 'hero-composition__copy')
                && trim(strip_tags($document->innerHtml($marquees[0]))) !== '';
            if (!$sound) {
                $warnings[] = self::markupWarning(
                    $part,
                    'recipe marquee name',
                    ['required_class' => self::MARQUEE_CLASS, 'count' => 1, 'block' => 'paragraph', 'outside' => 'hero-composition__copy'],
                    ['matching_blocks' => count($marquees)],
                    'safe parseable hero was retained; restore the one marked, non-empty name paragraph outside the copy region',
                );
            }
        }
        // frm W2c: the corner facts are the recipe's device. Exactly one
        // marked flex group, outside and after the copy region, holding two
        // or three non-empty paragraphs and nothing else.
        if ($recipe === 'metadata-corners') {
            $metas = [];
            foreach ($document->indices() as $index) {
                $attrs = $document->attrs($index) ?? [];
                $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if (in_array(self::META_CLASS, $classes, true)) {
                    $metas[] = $index;
                }
            }
            $facts = [];
            $sound = count($metas) === 1
                && $document->name($metas[0]) === 'group'
                && !self::hasAncestorClass($document, $metas[0], 'hero-composition__copy');
            if ($sound) {
                $copyEnd = null;
                foreach ($document->indices() as $index) {
                    $attrs = $document->attrs($index) ?? [];
                    $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    if (in_array('hero-composition__copy', $classes, true)) {
                        $copyEnd = $document->endOffset($index);
                        break;
                    }
                }
                $metaStart = $document->openingOffset($metas[0]);
                $sound = $copyEnd !== null && $metaStart !== null && $metaStart >= $copyEnd;
                foreach ($document->children($metas[0]) as $child) {
                    $facts[] = $document->name($child) === 'paragraph'
                        && trim(strip_tags($document->innerHtml($child))) !== '';
                }
                $sound = $sound
                    && in_array(count($facts), self::META_FACT_COUNTS, true)
                    && !in_array(false, $facts, true);
            }
            if (!$sound) {
                $warnings[] = self::markupWarning(
                    $part,
                    'recipe corner facts',
                    ['required_class' => self::META_CLASS, 'count' => 1, 'block' => 'group', 'after' => 'hero-composition__copy', 'facts' => self::META_FACT_COUNTS],
                    ['matching_blocks' => count($metas), 'facts' => count($facts)],
                    'safe parseable hero was retained; restore the one marked fact group of two or three short paragraphs after the copy region',
                );
            }
        }
        // frm W2e: the giant name is the recipe's device. Exactly one level-1
        // heading, marked, inside the copy region; at most one facts group,
        // outside the copy region, of two or three non-empty paragraphs.
        if ($recipe === 'wordmark-stage') {
            $marks = [];
            $headings = 0;
            foreach ($document->indices() as $index) {
                $attrs = $document->attrs($index) ?? [];
                $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if ($document->name($index) === 'heading' && (int) ($attrs['level'] ?? 2) === 1) {
                    $headings++;
                }
                if (in_array(self::WORDMARK_CLASS, $classes, true)) {
                    $marks[] = $index;
                }
            }
            $sound = $headings === 1
                && count($marks) === 1
                && $document->name($marks[0]) === 'heading'
                && self::hasAncestorClass($document, $marks[0], 'hero-composition__copy');
            $factGroups = [];
            foreach ($document->indices() as $index) {
                $attrs = $document->attrs($index) ?? [];
                $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if (in_array(self::FACTS_CLASS, $classes, true)) {
                    $factGroups[] = $index;
                }
            }
            $facts = [];
            if (count($factGroups) > 1) {
                $sound = false;
            } elseif (count($factGroups) === 1) {
                $sound = $sound
                    && $document->name($factGroups[0]) === 'group'
                    && !self::hasAncestorClass($document, $factGroups[0], 'hero-composition__copy');
                foreach ($document->children($factGroups[0]) as $child) {
                    $facts[] = $document->name($child) === 'paragraph'
                        && trim(strip_tags($document->innerHtml($child))) !== '';
                }
                $sound = $sound && in_array(count($facts), self::FACTS_COUNTS, true) && !in_array(false, $facts, true);
            }
            if (!$sound) {
                $warnings[] = self::markupWarning(
                    $part,
                    'recipe wordmark headline',
                    ['required_class' => self::WORDMARK_CLASS, 'headings' => 1, 'inside' => 'hero-composition__copy', 'facts_groups' => [0, 1], 'facts' => self::FACTS_COUNTS],
                    ['level_1_headings' => $headings, 'marked_blocks' => count($marks), 'facts_groups' => count($factGroups), 'facts' => count($facts)],
                    'safe parseable hero was retained; restore the one marked level-1 name heading in the copy region and at most one fact group of two or three short paragraphs beside it',
                );
            }
        }
        // frm W2d: the portrait plate is the recipe's device. Exactly one
        // marked image inside the media region, and the copy region holds
        // one two-column row (headline leading, line and action trailing).
        if ($recipe === 'portrait-backdrop') {
            $portraits = [];
            foreach ($document->indices() as $index) {
                $attrs = $document->attrs($index) ?? [];
                $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if (in_array(self::PORTRAIT_CLASS, $classes, true)) {
                    $portraits[] = $index;
                }
            }
            $plate = count($portraits) === 1
                && $document->name($portraits[0]) === 'image'
                && self::hasAncestorClass($document, $portraits[0], 'hero-composition__media');
            $rows = [];
            foreach ($document->indices() as $index) {
                if ($document->name($index) !== 'columns' || !self::hasAncestorClass($document, $index, 'hero-composition__copy')) {
                    continue;
                }
                $rows[] = count(array_filter(
                    $document->children($index),
                    static fn (int $child): bool => $document->name($child) === 'column',
                ));
            }
            if (!$plate || $rows !== [2]) {
                $warnings[] = self::markupWarning(
                    $part,
                    'recipe portrait plate',
                    ['required_class' => self::PORTRAIT_CLASS, 'count' => 1, 'block' => 'image', 'inside' => 'hero-composition__media', 'copy_rows' => [2]],
                    ['matching_images' => count($portraits), 'copy_rows' => $rows],
                    'safe parseable hero was retained; restore the one marked portrait in the media region and the one two-column copy row',
                );
            }
        }
        // frm W7c: the floating objects are optional scenery. When the group
        // is present it is exactly one, a group outside the copy region,
        // holding two to four transparent (.png) images and nothing else.
        if ($objectGroups !== []) {
            $objects = [];
            $sound = count($objectGroups) === 1
                && $document->name($objectGroups[0]) === 'group'
                && !self::hasAncestorClass($document, $objectGroups[0], 'hero-composition__copy');
            if ($sound) {
                foreach ($document->children($objectGroups[0]) as $child) {
                    $objects[] = $document->name($child) === 'image'
                        && preg_match('~<img\b[^>]*\bsrc\s*=\s*["\'][^"\']*\.png["\']~i', $document->innerHtml($child)) === 1;
                }
                $sound = in_array(count($objects), self::OBJECT_COUNTS, true) && !in_array(false, $objects, true);
            }
            if (!$sound) {
                $warnings[] = self::markupWarning(
                    $part,
                    'recipe floating objects',
                    ['required_class' => self::OBJECTS_CLASS, 'count' => 1, 'block' => 'group', 'outside' => 'hero-composition__copy', 'objects' => self::OBJECT_COUNTS, 'object' => 'wp:image with a .png src'],
                    ['matching_groups' => count($objectGroups), 'objects' => count($objects)],
                    'safe parseable hero was retained; restore the one marked object group of two to four transparent images outside the copy region',
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

        $images = self::imageFacts($mediaMarkup);
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

    /**
     * Bind the marquee-name paragraph to the site name (frm PR-2f). The
     * recipe asks the model to write the name exactly as the spec states it;
     * a paraphrase, a tagline or a slogan in that slot becomes the page's
     * scenery, so the boundary replaces whatever text the marked paragraph
     * holds with the site name and records the repair. Only the one marked
     * paragraph is touched; the objective check still decides whether the
     * hero has it at all.
     *
     * @param list<array<string,mixed>> $repairs
     */
    public static function bindMarqueeName(string $markup, string $siteName, string $part, array &$repairs = []): string
    {
        $siteName = trim($siteName);
        if ($siteName === '') {
            return $markup;
        }
        $document = BlockMarkup::parse($markup);
        foreach ($document->indices() as $index) {
            if ($document->name($index) !== 'paragraph' || !$document->isStructurallySafe($index)) {
                continue;
            }
            $attrs = $document->attrs($index) ?? [];
            $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (!in_array(self::MARQUEE_CLASS, $classes, true)) {
                continue;
            }
            $own = $document->ownHtml($index);
            if (preg_match('/^(\s*<p\b[^>]*>)(.*)(<\/p>\s*)$/su', $own, $m) !== 1) {
                continue;
            }
            $text = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($text === $siteName) {
                return $markup;
            }
            $document->spliceOwnHtml($index, 0, strlen($own), $m[1] . htmlspecialchars($siteName, ENT_QUOTES | ENT_HTML5, 'UTF-8') . $m[3]);
            $repairs[] = [
                'code' => 'marquee-name-bound',
                'part' => $part,
                'block' => 'paragraph.' . self::MARQUEE_CLASS,
                'authored' => $text,
                'delivered' => $siteName,
                'disposition' => 'repaired',
                'note' => 'the marquee paragraph carries the site name exactly, never a paraphrase or a slogan',
            ];
            return $document->render();
        }
        return $markup;
    }

    /**
     * Bind the wordmark-stage headline to the site name and pin its size
     * (frm W2e). The level-1 heading in the copy region carries the site
     * name exactly, takes the wordmark class, and gets one explicit size
     * that fills the measure on every screen: the copy group's inline size
     * (a container) divided by the name's estimated em length, capped so a
     * short name never runs past 18rem. The headline fit leaves an explicit size alone, so the pin
     * survives; the theme's own case transform still applies.
     *
     * @param list<array<string,mixed>> $repairs
     */
    /** @param array<string,mixed>|null $theme the theme.json data, for the heading face's advances (frm PR-2v) */
    public static function bindWordmarkHeadline(string $markup, string $siteName, string $part, array &$repairs = [], ?array $theme = null): string
    {
        $siteName = trim($siteName);
        if ($siteName === '') {
            return $markup;
        }
        $document = BlockMarkup::parse($markup);
        foreach ($document->indices() as $index) {
            if ($document->name($index) !== 'heading' || !$document->isStructurallySafe($index)) {
                continue;
            }
            $attrs = $document->attrs($index) ?? [];
            if ((int) ($attrs['level'] ?? 2) !== 1) {
                continue;
            }
            $own = $document->ownHtml($index);
            if (preg_match('/^(\s*<h1\b[^>]*>)(.*)(<\/h1>\s*)$/su', $own, $m) !== 1) {
                continue;
            }
            $text = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            // Container units, not viewport units (frm W2e): the copy group
            // spans the band (PR-2t), so the name fills the band's own inline
            // size on every screen instead of wrapping at the cap. The width
            // is measured per character and per heading face (PR-2v), at 90%
            // of the container so a wide face keeps a margin.
            $lineEm = self::wordmarkEm($siteName, $theme);
            $longestEm = 0.3;
            foreach (preg_split('/\s+/u', $siteName, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                $longestEm = max($longestEm, self::wordmarkEm($word, $theme));
            }
            // Two terms (frm PR-2r): the whole name on one line where the
            // container is wide, and on a narrow container the longest word
            // at up to 3rem, so a two-word name wraps to two large lines on
            // a phone instead of shrinking to one small one. The max() picks
            // the line term on desktop (it exceeds 3rem there) and the word
            // term on a phone (the line term falls below it).
            $size = 'min(18rem, max(calc(90cqi / ' . number_format($lineEm, 2, '.', '')
                . '), min(calc(90cqi / ' . number_format($longestEm, 2, '.', '') . '), 3rem)))';
            $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $classes = array_values(array_filter($classes, static fn (string $c): bool => $c !== 'has-display-font-size'));
            if (!in_array(self::WORDMARK_CLASS, $classes, true)) {
                $classes[] = self::WORDMARK_CLASS;
            }
            $attrs['className'] = implode(' ', $classes);
            unset($attrs['fontSize']);
            if (!is_array($attrs['style'] ?? null)) {
                unset($attrs['style']);
            }
            if (!is_array($attrs['style']['typography'] ?? null)) {
                unset($attrs['style']['typography']);
            }
            $attrs['style']['typography']['fontSize'] = $size;
            $document->setAttrs($index, $attrs);
            $open = $m[1];
            $open = preg_replace('/\s+style="[^"]*"/', '', $open, 1) ?? $open;
            if (preg_match('/\bclass="([^"]*)"/', $open, $cm) === 1) {
                $tokens = preg_split('/\s+/', trim($cm[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $tokens = array_values(array_filter($tokens, static fn (string $c): bool => $c !== 'has-display-font-size'));
                if (!in_array(self::WORDMARK_CLASS, $tokens, true)) {
                    $tokens[] = self::WORDMARK_CLASS;
                }
                $open = str_replace($cm[0], 'class="' . implode(' ', $tokens) . '"', $open);
            } else {
                $open = preg_replace('/^(\s*<h1)/', '$1 class="wp-block-heading ' . self::WORDMARK_CLASS . '"', $open, 1) ?? $open;
            }
            $open = preg_replace('/>$/', ' style="font-size:' . $size . '">', rtrim($open), 1) ?? $open;
            $document->spliceOwnHtml($index, 0, strlen($own), $open . htmlspecialchars($siteName, ENT_QUOTES | ENT_HTML5, 'UTF-8') . $m[3]);
            if ($text !== $siteName) {
                $repairs[] = [
                    'code' => 'wordmark-name-bound',
                    'part' => $part,
                    'block' => 'heading.' . self::WORDMARK_CLASS,
                    'authored' => $text,
                    'delivered' => $siteName,
                    'disposition' => 'repaired',
                    'note' => 'the wordmark headline carries the site name exactly, never a paraphrase or a slogan',
                ];
            }
            $repairs[] = [
                'code' => 'wordmark-size-pinned',
                'part' => $part,
                'block' => 'heading.' . self::WORDMARK_CLASS,
                'authored' => 'display preset',
                'delivered' => $size,
                'disposition' => 'repaired',
            ];
            return $document->render();
        }
        return $markup;
    }

    /**
     * The hero the brief states in so many words, or null when it is silent
     * (frm PR-2g). The same bounded-phrase shape as GroundKey::statedInBrief:
     * these are the client's own instructions about the opening, and the
     * stable hash pick never outranks them. First match in list order wins.
     *
     * @var array<string,list<string>>
     */
    private const STATED_PHRASES = [
        'marquee-name' => [
            'marquee of my name', 'marquee of the name', 'name behind the hero', 'giant name behind',
            'giant marquee', 'wordmark behind the hero', 'name marquee',
        ],
        'portrait-backdrop' => [
            'portrait backdrop', 'portrait behind', 'portrait centered behind', 'photo centered behind',
            'portrait photo centered', 'headshot behind', 'photo behind the copy', 'portrait behind the copy',
        ],
        'wordmark-stage' => [
            'giant serif name', 'name as the hero headline', 'name set huge as the hero', 'giant name as the hero',
            'huge uppercase wordmark', 'giant lowercase wordmark', 'huge lowercase wordmark', 'giant uppercase wordmark',
            'wordmark hero', 'giant wordmark hero', 'huge wordmark hero', 'wordmark as the hero',
            'hero is a huge wordmark', 'hero is a giant wordmark', 'name set giant', 'giant name hero',
        ],
        'metadata-corners' => [
            'metadata in the corners', 'metadata corners', 'corner metadata', 'facts in the corners',
            'details in the corners', 'corner facts',
        ],
        'panel-stage' => [
            'gradient panel hero', 'panel hero', 'rounded panel hero', 'hero panel',
            'gradient hero panel', 'dashboard mockup', 'product mockup below',
        ],
        'cinematic-safe-zone' => [
            'full-bleed photo', 'full-bleed portrait', 'full-bleed image', 'cinematic photo',
            'cinematic hero', 'full-bleed hero', 'hero photo', 'portrait hero',
            'full-bleed high-contrast portrait', 'high-contrast portrait',
            'photo hero', 'cinematic dusk photo', 'full-bleed cover',
            // frm PR-2o: parley's "painted desert-sky cover hero in a rounded
            // frame" fell to the hash and the light-page ration.
            'cover hero', 'painted cover hero', 'photo cover hero', 'painted cover', 'cover photo hero',
            'painted landscape hero', 'illustrated cover hero', 'image cover hero',
        ],
        'foreground-split' => [
            'split hero', 'copy left, image right', 'image right', 'photo on the right', 'hero split',
        ],
    ];

    /**
     * The floating objects must be transparent cutouts, and the filename is
     * the pipeline's transparency trigger (a `.png` placeholder is keyed
     * after generation). cohesion-like15 authored its four objects as .jpg
     * and shipped white plates over the marquee (frm PR-7e). The boundary
     * renames every image source inside the object group to .png and
     * records the repair; the collector then requests the keyed cutout.
     *
     * @param list<array<string,mixed>> $repairs
     */
    public static function keyObjectFilenames(string $markup, string $part, array &$repairs = []): string
    {
        $document = BlockMarkup::parse($markup);
        $changed = false;
        foreach ($document->indices() as $index) {
            if ($document->name($index) !== 'image' || !$document->isStructurallySafe($index)) {
                continue;
            }
            if (!self::hasAncestorClass($document, $index, self::OBJECTS_CLASS)) {
                continue;
            }
            $own = $document->ownHtml($index);
            $renamed = preg_replace_callback(
                '~(<img\b[^>]*\bsrc\s*=\s*["\'])([^"\']*?)\.(?:jpe?g|webp|gif|avif)(["\'])~i',
                static fn (array $m): string => $m[1] . $m[2] . '.png' . $m[3],
                $own,
                -1,
                $count,
            );
            if (!is_string($renamed) || $count === 0) {
                continue;
            }
            $document->spliceOwnHtml($index, 0, strlen($own), $renamed);
            $attrs = $document->attrs($index) ?? [];
            if (is_string($attrs['url'] ?? null)) {
                $attrs['url'] = preg_replace('~\.(?:jpe?g|webp|gif|avif)$~i', '.png', $attrs['url']) ?? $attrs['url'];
                $document->setAttrs($index, $attrs);
            }
            preg_match('~\bsrc\s*=\s*["\']([^"\']*)~i', $own, $before);
            $repairs[] = [
                'part' => $part,
                'block' => 'image.floating-object',
                'authored' => basename((string) ($before[1] ?? '')),
                'delivered' => basename(preg_replace('~\.(?:jpe?g|webp|gif|avif)$~i', '.png', (string) ($before[1] ?? '')) ?? ''),
                'note' => 'a floating object is a keyed cutout; its source must be a .png placeholder',
            ];
            $changed = true;
        }
        return $changed ? $document->render() : $markup;
    }

    public static function statedInBrief(string $brief): ?string
    {
        $text = mb_strtolower(preg_replace('/\s+/u', ' ', $brief) ?? $brief, 'UTF-8');
        foreach (self::STATED_PHRASES as $recipe => $phrases) {
            foreach ($phrases as $phrase) {
                if (preg_match('/(?<![\p{L}-])' . preg_quote($phrase, '/') . '(?![\p{L}-])/u', $text) === 1) {
                    return $recipe;
                }
            }
        }
        return null;
    }
}
