<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Closed, deterministic contract for the site's header at rest and after the
 * page has scrolled. Generated markup may suggest colors and positioning, but
 * only this resolver chooses behavior and palette-token surfaces.
 */
final class HeaderBehavior
{
    public const FILE = 'headerBehavior.json';

    public const STATIC = 'static';
    public const STICKY_SOFT = 'sticky-soft';
    public const OVERLAY_TO_SOLID = 'overlay-to-solid';
    public const BEHAVIORS = [self::STATIC, self::STICKY_SOFT, self::OVERLAY_TO_SOLID];

    public const MODE_STACKED = 'stacked';
    public const MODE_OVERLAY = 'overlay';
    public const MODES = [self::MODE_STACKED, self::MODE_OVERLAY];

    public const TRANSITION_SMOOTH = 'smooth';
    public const TRANSITION_INSTANT = 'instant';
    public const TRANSITIONS = [self::TRANSITION_SMOOTH, self::TRANSITION_INSTANT];

    public const TRANSPARENT = 'transparent';

    public const TREATMENT_SOLID = 'solid';
    public const TREATMENT_TRANSPARENT = 'transparent';
    public const TREATMENT_GLASS = 'glass';
    public const TOP_TREATMENTS = [self::TREATMENT_SOLID, self::TREATMENT_TRANSPARENT, self::TREATMENT_GLASS];
    public const SCROLLED_TREATMENTS = [self::TREATMENT_SOLID, self::TREATMENT_GLASS];

    /**
     * The trusted CSS keeps an overlay image-led while bounding an arbitrary
     * white pixel to #666 beneath header text. Foreground selection uses the
     * same worst case, so the top state is not merely assumed readable.
     */
    public const OVERLAY_SCRIM_ALPHA = 0.60;
    public const OVERLAY_WORST_CASE_RGB = [102, 102, 102];

    /**
     * Tint opacity of the frosted-glass states the trusted kit paints with
     * color-mix. Every glass grant below proves the foreground readable
     * against the full worst-case luminance range this alpha admits, so the
     * number is part of the contrast contract, not just a look.
     */
    public const GLASS_ALPHA = 0.80;

    /** Palette hooks implemented by the trusted header CSS kit. */
    public const SURFACES = ['base', 'contrast', 'primary', 'secondary', 'accent'];

    /** These compositions are too tall to remain useful as persistent chrome. */
    public const TALL_ARCHETYPES = ['centered-masthead', 'oversized-wordmark'];

    private const FIELDS = [
        'behavior',
        'mode',
        'transition',
        'topSurface',
        'scrolledSurface',
        'foreground',
        'topTreatment',
        'scrolledTreatment',
    ];

    /**
     * Choose whether persistent navigation benefits the planned site shape.
     * A transparent overlay always needs its solid scrolled state. Stacked
     * chrome is sticky only when there is enough navigation/page depth to
     * justify occupying the viewport; forced tall archetypes remain static.
     *
     * @param array<int,array<string,mixed>> $pages
     */
    public static function behaviorFor(
        array $pages,
        string $mode,
        ?string $forcedArchetype = null,
    ): string {
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException("unknown header mode '{$mode}'");
        }
        if ($mode === self::MODE_OVERLAY) {
            return self::OVERLAY_TO_SOLID;
        }
        if (in_array((string) $forcedArchetype, self::TALL_ARCHETYPES, true)) {
            return self::STATIC;
        }
        if (count($pages) > 1) {
            return self::STICKY_SOFT;
        }
        foreach ($pages as $page) {
            if (count((array) ($page['sections'] ?? [])) >= 4) {
                return self::STICKY_SOFT;
            }
        }
        return self::STATIC;
    }

    public static function transitionFor(string $motionProfile): string
    {
        return in_array($motionProfile, ['minimal', 'none'], true)
            ? self::TRANSITION_INSTANT
            : self::TRANSITION_SMOOTH;
    }

    /**
     * Resolve the exact artifact. `$palette` is a slug => color map; entries
     * which are not concrete hex colors are ignored for contrast decisions.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param array<string,string>            $palette
     * @param ?string $pageBackground palette slug painted behind the page
     *                                body; a transparent sticky top reveals
     *                                it, so it joins the contrast contract.
     * @return array{behavior:string,mode:string,transition:string,topSurface:string,
     *               scrolledSurface:string,foreground:string,topTreatment:string,
     *               scrolledTreatment:string}
     */
    public static function resolve(
        array $pages,
        string $mode,
        array $palette,
        ?string $forcedArchetype = null,
        string $transition = self::TRANSITION_SMOOTH,
        ?string $authoredTopSurface = null,
        ?string $authoredForeground = null,
        ?string $pageBackground = null,
    ): array {
        if (!in_array($transition, self::TRANSITIONS, true)) {
            throw new \InvalidArgumentException("unknown header transition '{$transition}'");
        }
        $palette = self::concretePalette($palette);
        $requested = self::behaviorFor($pages, $mode, $forcedArchetype);

        if ($requested === self::OVERLAY_TO_SOLID) {
            $openingSurfaces = self::overlayOpeningSurfaces($pages);
            $foreground = $openingSurfaces === null
                ? null
                : self::overlayForeground($palette, $authoredForeground, $openingSurfaces);
            $scrolled = $foreground === null
                ? null
                : self::bestSurface(
                    $palette,
                    $foreground,
                    ['contrast', 'primary', 'secondary', 'base', 'accent'],
                    $transition === self::TRANSITION_SMOOTH
                        ? self::OVERLAY_WORST_CASE_RGB
                        : null,
                );
            if ($foreground !== null && $scrolled !== null) {
                return self::validateArtifact([
                    'behavior' => self::OVERLAY_TO_SOLID,
                    'mode' => self::MODE_OVERLAY,
                    'transition' => $transition,
                    'topSurface' => self::TRANSPARENT,
                    'scrolledSurface' => $scrolled,
                    'foreground' => $foreground,
                    // The plan-time overlay rests behind the kit scrim — the
                    // dark translucent veil is factually a glass treatment.
                    // HeaderHeroStep upgrades it to a truly transparent top
                    // once the delivered opening covers prove their own dim
                    // sufficient (clearOverlayTopIsSafe).
                    'topTreatment' => self::TREATMENT_GLASS,
                    'scrolledTreatment' => self::TREATMENT_SOLID,
                ]);
            }

            // A palette with no safe light-on-solid pair cannot support the
            // overlay's one-foreground guarantee. Fall back to the stacked
            // path wholesale: with enough site depth and an opaque
            // contrast-safe pair the header keeps sticky-soft, and only when
            // that path's own palette safety check also fails does it end at
            // static. The caller records the behavior loss either way.
            return self::resolve(
                $pages,
                self::MODE_STACKED,
                $palette,
                $forcedArchetype,
                $transition,
                $authoredTopSurface,
                $authoredForeground,
                $pageBackground,
            );
        }

        [$top, $foreground, $safe] = self::opaquePairWithSafety(
            $palette,
            $authoredTopSurface,
            $authoredForeground,
        );
        $behavior = $requested;
        $scrolled = $top;
        if ($behavior === self::STICKY_SOFT && $safe) {
            $scrolled = self::closestDistinctSafeSurface(
                $palette,
                $top,
                $foreground,
                $transition === self::TRANSITION_SMOOTH,
            ) ?? $top;
        } elseif ($behavior === self::STICKY_SOFT) {
            $behavior = self::STATIC;
        }

        [$topTreatment, $scrolledTreatment] = $behavior === self::STICKY_SOFT
            ? self::stickyTreatments(
                $pages,
                $palette,
                $foreground,
                $top,
                $scrolled,
                $transition,
                $pageBackground,
            )
            : [self::TREATMENT_SOLID, self::TREATMENT_SOLID];

        return self::validateArtifact([
            'behavior' => $behavior,
            'mode' => self::MODE_STACKED,
            'transition' => $transition,
            'topSurface' => $top,
            'scrolledSurface' => $behavior === self::STATIC ? $top : $scrolled,
            'foreground' => $foreground,
            'topTreatment' => $topTreatment,
            'scrolledTreatment' => $scrolledTreatment,
        ]);
    }

    /**
     * Choose the sticky header's start/scrolled paint treatments, preferring
     * the airiest pair that stays provably readable. The candidate order asks
     * for a fully transparent start first, then frosted glass, then today's
     * opaque token; the scrolled state prefers glass over solid.
     *
     * Every non-solid grant is proven, not assumed:
     * - a transparent start reveals only the page background (at rest) and
     *   the opening band's planned surface (during the enter transition), so
     *   both must be palette tokens the foreground clears at 4.5:1;
     * - a glass state at GLASS_ALPHA admits exactly the luminance segment
     *   between its tint composited over black and over white, so
     *   transitionIsSafe over that segment bounds arbitrary content;
     * - CSS interpolates background-color premultiplied, which makes every
     *   top-to-scrolled path a straight sRGB segment per worst-case
     *   underlying pixel — the same convexity proof covers the midpoints, so
     *   smooth transitions need no unsafe window.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param array<string,string>            $palette
     * @return array{0:string,1:string}
     */
    private static function stickyTreatments(
        array $pages,
        array $palette,
        string $foreground,
        string $top,
        string $scrolled,
        string $transition,
        ?string $pageBackground,
    ): array {
        $fg = isset($palette[$foreground]) ? ContrastMath::hexToRgb($palette[$foreground]) : null;
        $topRgb = isset($palette[$top]) ? ContrastMath::hexToRgb($palette[$top]) : null;
        $scrolledRgb = isset($palette[$scrolled]) ? ContrastMath::hexToRgb($palette[$scrolled]) : null;
        if ($fg === null || $topRgb === null || $scrolledRgb === null) {
            return [self::TREATMENT_SOLID, self::TREATMENT_SOLID];
        }
        $smooth = $transition === self::TRANSITION_SMOOTH;

        // Surfaces a transparent start actually reveals: the page body
        // background at rest, plus every planned opening band during the
        // enter transition. An image or unplanned opening cannot be verified
        // and therefore rules the transparent start out (glass still can be
        // granted — its worst case covers arbitrary content).
        $behind = null;
        $openings = self::stackedOpeningSurfaces($pages);
        $pageBgRgb = self::pageBackgroundRgb($palette, $pageBackground);
        if ($openings !== null && $pageBgRgb !== null) {
            $behind = [$pageBgRgb];
            foreach ($openings as $slug) {
                $rgb = ContrastMath::hexToRgb($palette[$slug]);
                if ($rgb === null) {
                    $behind = null;
                    break;
                }
                $behind[] = $rgb;
            }
        }

        $transparentSafe = $behind !== null;
        foreach ((array) $behind as $rgb) {
            if (ContrastMath::ratio($fg, $rgb) < ContrastMath::NORMAL_TEXT) {
                $transparentSafe = false;
                break;
            }
        }
        $glassTopSafe = self::glassStateIsSafe($fg, $topRgb);
        $glassScrolledSafe = self::glassStateIsSafe($fg, $scrolledRgb);

        $candidates = [
            [self::TREATMENT_TRANSPARENT, self::TREATMENT_GLASS],
            [self::TREATMENT_TRANSPARENT, self::TREATMENT_SOLID],
            [self::TREATMENT_GLASS, self::TREATMENT_GLASS],
            [self::TREATMENT_GLASS, self::TREATMENT_SOLID],
            [self::TREATMENT_SOLID, self::TREATMENT_GLASS],
            [self::TREATMENT_SOLID, self::TREATMENT_SOLID],
        ];
        foreach ($candidates as [$topTreatment, $scrolledTreatment]) {
            if (($topTreatment === self::TREATMENT_TRANSPARENT && !$transparentSafe)
                || ($topTreatment === self::TREATMENT_GLASS && !$glassTopSafe)
                || ($scrolledTreatment === self::TREATMENT_GLASS && !$glassScrolledSafe)) {
                continue;
            }
            if ($smooth && !self::stickyTransitionIsSafe(
                $fg,
                $topTreatment,
                $topRgb,
                $scrolledTreatment,
                $scrolledRgb,
                (array) $behind,
            )) {
                continue;
            }
            return [$topTreatment, $scrolledTreatment];
        }
        return [self::TREATMENT_SOLID, self::TREATMENT_SOLID];
    }

    /**
     * Whether the smooth top-to-scrolled interpolation stays readable for
     * every worst-case pixel beneath the header. Premultiplied rgba
     * interpolation makes each path a straight sRGB segment once the
     * underlying pixel is fixed, so checking the segment per extreme
     * underlay (black and white for glass, each verified surface for a
     * transparent start) covers the whole family.
     *
     * @param array{0:int,1:int,2:int}       $fg
     * @param array{0:int,1:int,2:int}       $topRgb
     * @param array{0:int,1:int,2:int}       $scrolledRgb
     * @param list<array{0:int,1:int,2:int}> $behind
     */
    private static function stickyTransitionIsSafe(
        array $fg,
        string $topTreatment,
        array $topRgb,
        string $scrolledTreatment,
        array $scrolledRgb,
        array $behind,
    ): bool {
        $ends = static fn (array $under): array => $scrolledTreatment === self::TREATMENT_GLASS
            ? self::glassComposite($scrolledRgb, $under)
            : $scrolledRgb;
        if ($topTreatment === self::TREATMENT_TRANSPARENT) {
            foreach ($behind as $under) {
                if (!self::transitionIsSafe($fg, $under, $ends($under))) {
                    return false;
                }
            }
            return $behind !== [];
        }
        $extremes = [[0, 0, 0], [255, 255, 255]];
        foreach ($extremes as $under) {
            $start = $topTreatment === self::TREATMENT_GLASS
                ? self::glassComposite($topRgb, $under)
                : $topRgb;
            if (!self::transitionIsSafe($fg, $start, $ends($under))) {
                return false;
            }
        }
        return true;
    }

    /**
     * A glass state at GLASS_ALPHA admits exactly the luminance segment from
     * its tint over black to its tint over white; luminance is monotone
     * along that gray shift, so the segment check is the exact worst case
     * for arbitrary content beneath the tint.
     *
     * @param array{0:int,1:int,2:int} $fg
     * @param array{0:int,1:int,2:int} $tint
     */
    public static function glassStateIsSafe(array $fg, array $tint): bool
    {
        return self::transitionIsSafe(
            $fg,
            self::glassComposite($tint, [0, 0, 0]),
            self::glassComposite($tint, [255, 255, 255]),
        );
    }

    /**
     * Core Cover serializes dimRatio through a 10-point opacity class. Model
     * the value the browser receives, not a more favorable authored fraction.
     * Values outside Core's implemented 0..100 class range have no trustworthy
     * rendered opacity and cannot participate in a clear-top grant.
     */
    public static function renderedCoverDim(float $dimRatio): ?int
    {
        if (!is_finite($dimRatio) || $dimRatio < 0 || $dimRatio > 100) {
            return null;
        }
        return (int) (10 * round($dimRatio / 10, 0, PHP_ROUND_HALF_UP));
    }

    /**
     * Whether the overlay header may rest with no kit scrim at all: the
     * foreground must stay readable over the opening cover's own rendered
     * dim for every pixel the image can produce (the dim composited over pure
     * white and pure black bound the luminance range), and — when the
     * underlay is stationary — along the whole premultiplied path into the
     * scrolled solid. Same proof shape as the sticky grants: the clear state
     * is earned per delivered opening, never assumed. HeaderHeroStep uses an
     * instant landing for fixed clear headers because scrolling can replace
     * their underlay during a timed transition.
     *
     * @param array{0:int,1:int,2:int}      $foreground
     * @param array{0:int,1:int,2:int}      $protection cover dim color
     * @param array{0:int,1:int,2:int}|null $scrolled   scrolled solid surface
     */
    public static function clearOverlayTopIsSafe(
        array $foreground,
        array $protection,
        float $dimRatio,
        ?array $scrolled,
        bool $smooth,
    ): bool {
        $renderedDim = self::renderedCoverDim($dimRatio);
        if ($renderedDim === null) {
            return false;
        }
        $alpha = $renderedDim / 100;
        foreach ([[255, 255, 255], [0, 0, 0]] as $under) {
            $rest = ContrastMath::compositeOver($protection, $alpha, $under);
            if (ContrastMath::ratio($foreground, $rest) < ContrastMath::NORMAL_TEXT) {
                return false;
            }
            if ($smooth && $scrolled !== null && !self::transitionIsSafe($foreground, $rest, $scrolled)) {
                return false;
            }
        }
        return true;
    }

    /**
     * The smallest rendered cover dimRatio (Core's 10-point classes, capped
     * so the image remains an image) whose own dim proves the clear resting
     * state, or null when none does. Lets a just-short delivered dim be raised
     * as a recorded repair instead of keeping the redundant kit scrim.
     *
     * @param array{0:int,1:int,2:int}      $foreground
     * @param array{0:int,1:int,2:int}      $protection
     * @param array{0:int,1:int,2:int}|null $scrolled
     */
    public static function minimalClearOverlayDim(
        array $foreground,
        array $protection,
        ?array $scrolled,
        bool $smooth,
        int $cap = 70,
    ): ?int {
        for ($dim = 40; $dim <= $cap; $dim += 10) {
            if (self::clearOverlayTopIsSafe($foreground, $protection, (float) $dim, $scrolled, $smooth)) {
                return $dim;
            }
        }
        return null;
    }

    /**
     * @param array{0:int,1:int,2:int} $tint
     * @param array{0:int,1:int,2:int} $under
     * @return array{0:int,1:int,2:int}
     */
    private static function glassComposite(array $tint, array $under): array
    {
        $out = [];
        for ($channel = 0; $channel < 3; $channel++) {
            $out[] = (int) round(
                self::GLASS_ALPHA * $tint[$channel] + (1 - self::GLASS_ALPHA) * $under[$channel]
            );
        }
        return $out;
    }

    /**
     * Return every planned opening surface a transparent sticky start would
     * sit above during its enter transition. Unlike the overlay variant, an
     * image opening disqualifies outright: a stacked header has no scrim, so
     * only token-backed bands can be verified.
     *
     * @param array<int,array<string,mixed>> $pages
     * @return list<string>|null
     */
    private static function stackedOpeningSurfaces(array $pages): ?array
    {
        if ($pages === []) {
            return null;
        }
        $surfaces = [];
        foreach ($pages as $page) {
            $opening = ((array) ($page['sections'] ?? []))[0] ?? null;
            if (!is_array($opening)) {
                return null;
            }
            $background = (string) ($opening['background'] ?? '');
            if (!in_array($background, self::SURFACES, true)) {
                return null;
            }
            if (!in_array($background, $surfaces, true)) {
                $surfaces[] = $background;
            }
        }
        return $surfaces;
    }

    /**
     * @param array<string,string> $palette
     * @return array{0:int,1:int,2:int}|null
     */
    private static function pageBackgroundRgb(array $palette, ?string $pageBackground): ?array
    {
        $slug = $pageBackground !== null && isset($palette[$pageBackground])
            ? $pageBackground
            : 'base';
        return isset($palette[$slug]) ? ContrastMath::hexToRgb($palette[$slug]) : null;
    }

    /**
     * Strictly validate the closed six-field artifact. Palette membership and
     * contrast are intentionally left to the resolver/final validator.
     *
     * @param array<mixed> $artifact
     * @return array{behavior:string,mode:string,transition:string,topSurface:string,
     *               scrolledSurface:string,foreground:string,topTreatment:string,
     *               scrolledTreatment:string}
     */
    public static function validateArtifact(array $artifact): array
    {
        $keys = array_keys($artifact);
        sort($keys);
        $expected = self::FIELDS;
        sort($expected);
        if ($keys !== $expected) {
            throw new \InvalidArgumentException(
                'header behavior artifact must contain exactly: ' . implode(', ', self::FIELDS)
            );
        }
        foreach (self::FIELDS as $field) {
            if (!is_string($artifact[$field]) || trim($artifact[$field]) === '') {
                throw new \InvalidArgumentException("header behavior field '{$field}' must be a non-empty string");
            }
        }
        $behavior = $artifact['behavior'];
        $mode = $artifact['mode'];
        $transition = $artifact['transition'];
        $top = $artifact['topSurface'];
        $scrolled = $artifact['scrolledSurface'];
        $foreground = $artifact['foreground'];
        $topTreatment = $artifact['topTreatment'];
        $scrolledTreatment = $artifact['scrolledTreatment'];
        if (!in_array($behavior, self::BEHAVIORS, true)) {
            throw new \InvalidArgumentException("unknown header behavior '{$behavior}'");
        }
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException("unknown header mode '{$mode}'");
        }
        if (!in_array($transition, self::TRANSITIONS, true)) {
            throw new \InvalidArgumentException("unknown header transition '{$transition}'");
        }
        if (!in_array($foreground, self::SURFACES, true)) {
            throw new \InvalidArgumentException('header foreground must be an opaque palette slug');
        }
        if (!in_array($scrolled, self::SURFACES, true)) {
            throw new \InvalidArgumentException('scrolled header surface must be an opaque palette slug');
        }
        if (!in_array($topTreatment, self::TOP_TREATMENTS, true)) {
            throw new \InvalidArgumentException("unknown header top treatment '{$topTreatment}'");
        }
        if (!in_array($scrolledTreatment, self::SCROLLED_TREATMENTS, true)) {
            throw new \InvalidArgumentException("unknown header scrolled treatment '{$scrolledTreatment}'");
        }
        if ($behavior === self::OVERLAY_TO_SOLID) {
            if ($mode !== self::MODE_OVERLAY || $top !== self::TRANSPARENT) {
                throw new \InvalidArgumentException('overlay-to-solid requires overlay mode and a transparent top surface');
            }
            if (!in_array($topTreatment, [self::TREATMENT_GLASS, self::TREATMENT_TRANSPARENT], true)
                || $scrolledTreatment !== self::TREATMENT_SOLID) {
                throw new \InvalidArgumentException(
                    'overlay-to-solid requires a scrim-glass or earned-transparent top treatment '
                        . 'and a solid scrolled treatment'
                );
            }
        } else {
            if ($mode !== self::MODE_STACKED || !in_array($top, self::SURFACES, true)) {
                throw new \InvalidArgumentException("{$behavior} requires stacked mode and an opaque top surface");
            }
            if ($behavior === self::STATIC && $scrolled !== $top) {
                throw new \InvalidArgumentException('static behavior requires identical top and scrolled surfaces');
            }
            if ($behavior === self::STATIC
                && ($topTreatment !== self::TREATMENT_SOLID || $scrolledTreatment !== self::TREATMENT_SOLID)) {
                throw new \InvalidArgumentException('static behavior requires solid top and scrolled treatments');
            }
        }

        /** @var array{behavior:string,mode:string,transition:string,topSurface:string,
         *              scrolledSurface:string,foreground:string} $artifact */
        return $artifact;
    }

    /**
     * Classes owned by the generated header part's root group. The outer
     * template shell owns positioning; these classes own only visual states.
     *
     * @param array<mixed> $artifact
     * @return list<string>
     */
    public static function rootClasses(array $artifact): array
    {
        $artifact = self::validateArtifact($artifact);
        if ($artifact['behavior'] === self::STATIC) {
            return [];
        }
        $classes = [
            'header-behavior-' . $artifact['behavior'],
            'header-start-' . $artifact['topSurface'],
            'header-scrolled-' . $artifact['scrolledSurface'],
            'header-foreground-' . $artifact['foreground'],
        ];
        // Sticky treatment hooks name each airier state; the overlay's
        // default scrim veil is kit-automatic and needs no class, but an
        // earned truly-clear resting state (proven against the opening
        // cover's own dim) is opted into explicitly. The solid token classes
        // above stay present as the no-JS / no-support fallback surface
        // either way.
        if ($artifact['behavior'] === self::STICKY_SOFT) {
            if ($artifact['topTreatment'] !== self::TREATMENT_SOLID) {
                $classes[] = 'header-top-' . $artifact['topTreatment'];
            }
            if ($artifact['scrolledTreatment'] === self::TREATMENT_GLASS) {
                $classes[] = 'header-scrolled-glass';
            }
        }
        if ($artifact['behavior'] === self::OVERLAY_TO_SOLID
            && $artifact['topTreatment'] === self::TREATMENT_TRANSPARENT) {
            $classes[] = 'header-top-transparent';
        }
        if ($artifact['transition'] === self::TRANSITION_INSTANT) {
            $classes[] = 'header-transition-instant';
        }
        return $classes;
    }

    public static function promptContract(string $behavior): string
    {
        return match ($behavior) {
            self::OVERLAY_TO_SOLID => 'DETERMINISTIC HEADER BEHAVIOR: overlay-to-solid. The trusted outer theme '
                . 'shell starts translucently over the opening image with a verified contrast veil, remains '
                . 'available while scrolling, and changes to a safe opaque palette surface. Keep the root '
                . 'visually transparent at the top; do '
                . 'not add positioning, behavior classes, CSS, or JavaScript.',
            self::STICKY_SOFT => 'DETERMINISTIC HEADER BEHAVIOR: sticky-soft. The trusted outer theme shell keeps '
                . 'this compact header available while scrolling and applies a subtle palette-surface transition. '
                . 'Do not add positioning, behavior classes, CSS, or JavaScript.',
            self::STATIC => 'DETERMINISTIC HEADER BEHAVIOR: static. The header scrolls away with the opening '
                . 'composition. Do not add positioning, behavior classes, CSS, or JavaScript.',
            default => throw new \InvalidArgumentException("unknown header behavior '{$behavior}'"),
        };
    }

    /** @param array<string,string> $palette @return array<string,string> */
    private static function concretePalette(array $palette): array
    {
        $out = [];
        foreach ($palette as $slug => $color) {
            if (in_array((string) $slug, self::SURFACES, true)
                && ContrastMath::hexToRgb((string) $color) !== null) {
                $out[(string) $slug] = (string) $color;
            }
        }
        return $out;
    }

    /**
     * @param array<string,string> $palette
     * @param list<string>         $openingSurfaces planned non-image surfaces
     */
    private static function overlayForeground(
        array $palette,
        ?string $authored,
        array $openingSurfaces,
    ): ?string
    {
        $required = [];
        foreach ($openingSurfaces as $surface) {
            $rgb = isset($palette[$surface]) ? ContrastMath::hexToRgb($palette[$surface]) : null;
            if ($rgb === null) {
                return null;
            }
            $required[] = $rgb;
        }
        $candidates = self::orderedSlugs($palette, [$authored, 'base', 'contrast', 'secondary', 'primary', 'accent']);
        usort($candidates, static function (string $a, string $b) use ($palette, $authored): int {
            if ($a === $authored) {
                return -1;
            }
            if ($b === $authored) {
                return 1;
            }
            return self::luminance($palette, $b) <=> self::luminance($palette, $a);
        });
        foreach ($candidates as $slug) {
            $rgb = ContrastMath::hexToRgb($palette[$slug]);
            if ($rgb === null
                || ContrastMath::ratio($rgb, self::OVERLAY_WORST_CASE_RGB) < ContrastMath::NORMAL_TEXT) {
                continue;
            }
            foreach ($required as $surfaceRgb) {
                if (ContrastMath::ratio($rgb, $surfaceRgb) < ContrastMath::NORMAL_TEXT) {
                    continue 2;
                }
            }
            return $slug;
        }
        return null;
    }

    /**
     * Return every opaque surface the transparent header is planned to sit
     * on. Image openings are protected by the trusted scrim; an unknown or
     * absent opening cannot be verified and therefore disables overlay.
     *
     * @param array<int,array<string,mixed>> $pages
     * @return list<string>|null
     */
    private static function overlayOpeningSurfaces(array $pages): ?array
    {
        if ($pages === []) {
            return null;
        }
        $surfaces = [];
        foreach ($pages as $page) {
            $opening = ((array) ($page['sections'] ?? []))[0] ?? null;
            if (!is_array($opening)) {
                return null;
            }
            $background = (string) ($opening['background'] ?? '');
            if ($background === 'image') {
                continue;
            }
            if (!in_array($background, self::SURFACES, true)) {
                return null;
            }
            if (!in_array($background, $surfaces, true)) {
                $surfaces[] = $background;
            }
        }
        return $surfaces;
    }

    /**
     * @param array<string,string> $palette
     * @param list<?string>        $preferred
     * @param array{0:int,1:int,2:int}|null $transitionStart
     */
    private static function bestSurface(
        array $palette,
        string $foreground,
        array $preferred,
        ?array $transitionStart = null,
    ): ?string
    {
        $fg = isset($palette[$foreground]) ? ContrastMath::hexToRgb($palette[$foreground]) : null;
        if ($fg === null) {
            return null;
        }
        $best = null;
        $bestRatio = 0.0;
        foreach (self::orderedSlugs($palette, $preferred) as $slug) {
            $rgb = ContrastMath::hexToRgb($palette[$slug]);
            if ($rgb === null) {
                continue;
            }
            $ratio = ContrastMath::ratio($fg, $rgb);
            if ($transitionStart !== null && !self::transitionIsSafe($fg, $transitionStart, $rgb)) {
                continue;
            }
            if ($ratio >= ContrastMath::NORMAL_TEXT && $ratio > $bestRatio) {
                $best = $slug;
                $bestRatio = $ratio;
            }
        }
        return $best;
    }

    /**
     * @param array<string,string> $palette
     * @return array{0:string,1:string,2:bool}
     */
    private static function opaquePairWithSafety(
        array $palette,
        ?string $authoredTop,
        ?string $authoredForeground,
    ): array {
        $tops = self::orderedSlugs($palette, [$authoredTop, 'base', 'contrast', 'secondary', 'primary', 'accent']);
        $foregrounds = self::orderedSlugs(
            $palette,
            [$authoredForeground, 'contrast', 'base', 'primary', 'secondary', 'accent'],
        );
        $best = null;
        $bestRatio = -1.0;
        foreach ($tops as $top) {
            $topRgb = ContrastMath::hexToRgb($palette[$top]);
            if ($topRgb === null) {
                continue;
            }
            foreach ($foregrounds as $foreground) {
                if ($foreground === $top) {
                    continue;
                }
                $fgRgb = ContrastMath::hexToRgb($palette[$foreground]);
                if ($fgRgb === null) {
                    continue;
                }
                $ratio = ContrastMath::ratio($fgRgb, $topRgb);
                if ($ratio >= ContrastMath::NORMAL_TEXT) {
                    return [$top, $foreground, true];
                }
                if ($ratio > $bestRatio) {
                    $best = [$top, $foreground];
                    $bestRatio = $ratio;
                }
            }
        }
        if (is_array($best)) {
            return [$best[0], $best[1], false];
        }

        // ThemeJsonStep guarantees base/contrast before this step in the full
        // graph. These closed-token defaults keep isolated calls deterministic;
        // a final validator diagnoses a missing palette entry.
        return ['base', 'contrast', false];
    }

    /** @param array<string,string> $palette */
    private static function closestDistinctSafeSurface(
        array $palette,
        string $top,
        string $foreground,
        bool $smooth,
    ): ?string {
        $topRgb = isset($palette[$top]) ? ContrastMath::hexToRgb($palette[$top]) : null;
        $fgRgb = isset($palette[$foreground]) ? ContrastMath::hexToRgb($palette[$foreground]) : null;
        if ($topRgb === null || $fgRgb === null) {
            return null;
        }
        $best = null;
        $distance = PHP_FLOAT_MAX;
        foreach ($palette as $slug => $color) {
            if ($slug === $top || !self::isChromeBarColor($slug, $color)) {
                continue;
            }
            $rgb = ContrastMath::hexToRgb($color);
            if ($rgb === null
                || $rgb === $topRgb
                || ContrastMath::ratio($fgRgb, $rgb) < ContrastMath::NORMAL_TEXT
                || ($smooth && !self::transitionIsSafe($fgRgb, $topRgb, $rgb))) {
                continue;
            }
            $candidateDistance = ($rgb[0] - $topRgb[0]) ** 2
                + ($rgb[1] - $topRgb[1]) ** 2
                + ($rgb[2] - $topRgb[2]) ** 2;
            if ($candidateDistance < $distance) {
                $best = $slug;
                $distance = $candidateDistance;
            }
        }
        return $best;
    }

    /**
     * A header bar may only paint with a paper/ink token or a near-gray.
     * Brand hues (terracotta, sage, grape, neon) as a full chrome surface
     * read as a muddy wash on scroll.
     */
    public static function isChromeBarColor(string $slug, string $hex): bool
    {
        if ($slug === 'base' || $slug === 'contrast') {
            return true;
        }
        $rgb = ContrastMath::hexToRgb($hex);
        if ($rgb === null) {
            return false;
        }
        return (max($rgb) - min($rgb)) / 255 < 0.12;
    }

    /**
     * Whether CSS's sRGB interpolation remains readable against one fixed
     * foreground for its entire path. Relative luminance is convex along an
     * sRGB segment: its maximum is at an endpoint, while its sole interior
     * minimum (when present) is found by bisection over the monotone
     * luminance derivative. Crossing the foreground luminance is never safe
     * because the path necessarily passes through 1:1 contrast.
     *
     * @param array{0:int,1:int,2:int} $foreground
     * @param array{0:int,1:int,2:int} $start
     * @param array{0:int,1:int,2:int} $end
     */
    public static function transitionIsSafe(array $foreground, array $start, array $end): bool
    {
        $foregroundLuminance = ContrastMath::luminance($foreground);
        $startLuminance = ContrastMath::luminance($start);
        $endLuminance = ContrastMath::luminance($end);
        if ($startLuminance <= $foregroundLuminance && $endLuminance <= $foregroundLuminance) {
            $darkestSafeForegroundRatio = ($foregroundLuminance + 0.05)
                / (max($startLuminance, $endLuminance) + 0.05);
            return $darkestSafeForegroundRatio >= ContrastMath::NORMAL_TEXT;
        }
        if ($startLuminance <= $foregroundLuminance || $endLuminance <= $foregroundLuminance) {
            return false;
        }

        $minimum = self::minimumInterpolatedLuminance($start, $end);
        if ($minimum <= $foregroundLuminance) {
            return false;
        }
        return ($minimum + 0.05) / ($foregroundLuminance + 0.05) >= ContrastMath::NORMAL_TEXT;
    }

    /**
     * @param array{0:int,1:int,2:int} $start
     * @param array{0:int,1:int,2:int} $end
     */
    private static function minimumInterpolatedLuminance(array $start, array $end): float
    {
        if (self::interpolatedLuminanceDerivative($start, $end, 0.0) >= 0.0) {
            return self::interpolatedLuminance($start, $end, 0.0);
        }
        if (self::interpolatedLuminanceDerivative($start, $end, 1.0) <= 0.0) {
            return self::interpolatedLuminance($start, $end, 1.0);
        }

        $left = 0.0;
        $right = 1.0;
        for ($iteration = 0; $iteration < 64; $iteration++) {
            $mid = ($left + $right) / 2.0;
            if (self::interpolatedLuminanceDerivative($start, $end, $mid) < 0.0) {
                $left = $mid;
            } else {
                $right = $mid;
            }
        }
        return self::interpolatedLuminance($start, $end, ($left + $right) / 2.0);
    }

    /**
     * @param array{0:int,1:int,2:int} $start
     * @param array{0:int,1:int,2:int} $end
     */
    private static function interpolatedLuminance(array $start, array $end, float $position): float
    {
        $weights = [0.2126, 0.7152, 0.0722];
        $luminance = 0.0;
        for ($channel = 0; $channel < 3; $channel++) {
            $srgb = ($start[$channel] + ($end[$channel] - $start[$channel]) * $position) / 255.0;
            $linear = $srgb <= 0.04045
                ? $srgb / 12.92
                : (($srgb + 0.055) / 1.055) ** 2.4;
            $luminance += $weights[$channel] * $linear;
        }
        return $luminance;
    }

    /**
     * @param array{0:int,1:int,2:int} $start
     * @param array{0:int,1:int,2:int} $end
     */
    private static function interpolatedLuminanceDerivative(array $start, array $end, float $position): float
    {
        $weights = [0.2126, 0.7152, 0.0722];
        $derivative = 0.0;
        for ($channel = 0; $channel < 3; $channel++) {
            $delta = ($end[$channel] - $start[$channel]) / 255.0;
            $srgb = $start[$channel] / 255.0 + $delta * $position;
            $linearDerivative = $srgb <= 0.04045
                ? 1.0 / 12.92
                : (2.4 / 1.055) * (($srgb + 0.055) / 1.055) ** 1.4;
            $derivative += $weights[$channel] * $linearDerivative * $delta;
        }
        return $derivative;
    }

    /**
     * @param array<string,string> $palette
     * @param list<?string>        $preferred
     * @return list<string>
     */
    private static function orderedSlugs(array $palette, array $preferred): array
    {
        $out = [];
        foreach (array_merge($preferred, array_keys($palette)) as $slug) {
            if (is_string($slug) && isset($palette[$slug]) && !in_array($slug, $out, true)) {
                $out[] = $slug;
            }
        }
        return $out;
    }

    /** @param array<string,string> $palette */
    private static function luminance(array $palette, string $slug): float
    {
        $rgb = isset($palette[$slug]) ? ContrastMath::hexToRgb($palette[$slug]) : null;
        return $rgb === null ? -1.0 : ContrastMath::luminance($rgb);
    }
}
