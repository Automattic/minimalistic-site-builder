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

    /**
     * The trusted CSS keeps an overlay image-led while bounding an arbitrary
     * white pixel to #666 beneath header text. Foreground selection uses the
     * same worst case, so the top state is not merely assumed readable.
     */
    public const OVERLAY_SCRIM_ALPHA = 0.60;
    public const OVERLAY_WORST_CASE_RGB = [102, 102, 102];

    /** Palette hooks implemented by the trusted header CSS kit. */
    public const SURFACES = ['base', 'contrast', 'primary', 'secondary', 'accent'];

    /** These compositions are too tall to remain useful as persistent chrome. */
    public const TALL_ARCHETYPES = ['centered-masthead', 'oversized-wordmark', 'double-decker'];

    private const FIELDS = [
        'behavior',
        'mode',
        'transition',
        'topSurface',
        'scrolledSurface',
        'foreground',
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
     * @return array{behavior:string,mode:string,transition:string,topSurface:string,
     *               scrolledSurface:string,foreground:string}
     */
    public static function resolve(
        array $pages,
        string $mode,
        array $palette,
        ?string $forcedArchetype = null,
        string $transition = self::TRANSITION_SMOOTH,
        ?string $authoredTopSurface = null,
        ?string $authoredForeground = null,
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
                ]);
            }

            // A palette with no safe light-on-solid pair cannot support the
            // overlay's one-foreground guarantee. Deliver ordinary static,
            // opaque chrome instead; the caller records the behavior loss.
            [$surface, $opaqueForeground] = self::opaquePair(
                $palette,
                $authoredTopSurface,
                $authoredForeground,
            );
            return self::validateArtifact([
                'behavior' => self::STATIC,
                'mode' => self::MODE_STACKED,
                'transition' => $transition,
                'topSurface' => $surface,
                'scrolledSurface' => $surface,
                'foreground' => $opaqueForeground,
            ]);
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

        return self::validateArtifact([
            'behavior' => $behavior,
            'mode' => self::MODE_STACKED,
            'transition' => $transition,
            'topSurface' => $top,
            'scrolledSurface' => $behavior === self::STATIC ? $top : $scrolled,
            'foreground' => $foreground,
        ]);
    }

    /**
     * Strictly validate the closed six-field artifact. Palette membership and
     * contrast are intentionally left to the resolver/final validator.
     *
     * @param array<mixed> $artifact
     * @return array{behavior:string,mode:string,transition:string,topSurface:string,
     *               scrolledSurface:string,foreground:string}
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
        if ($behavior === self::OVERLAY_TO_SOLID) {
            if ($mode !== self::MODE_OVERLAY || $top !== self::TRANSPARENT) {
                throw new \InvalidArgumentException('overlay-to-solid requires overlay mode and a transparent top surface');
            }
        } else {
            if ($mode !== self::MODE_STACKED || !in_array($top, self::SURFACES, true)) {
                throw new \InvalidArgumentException("{$behavior} requires stacked mode and an opaque top surface");
            }
            if ($behavior === self::STATIC && $scrolled !== $top) {
                throw new \InvalidArgumentException('static behavior requires identical top and scrolled surfaces');
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
     * @return array{0:string,1:string}
     */
    private static function opaquePair(array $palette, ?string $authoredTop, ?string $authoredForeground): array
    {
        [$top, $foreground] = self::opaquePairWithSafety($palette, $authoredTop, $authoredForeground);
        return [$top, $foreground];
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
            if ($slug === $top) {
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
