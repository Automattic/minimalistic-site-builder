<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * The motion-kit contract shared by every step that touches motion: the fixed
 * profile list the design direction picks from, the fixed class vocabulary the
 * section prompts may place (whose CSS ships statically in assets/motion/ —
 * never LLM-generated), the per-profile allowance the motion-sanity step
 * enforces, and the numeric bounds for the --motion-* variables the
 * page-styles call may tune.
 *
 * Keep the class lists in sync with assets/motion/motion.css and the "Motion
 * classes" section of prompts/section.md, where sections learn them.
 */
final class Motion
{
    /** Profiles the design direction may commit to (assets/motion/profiles/). */
    public const PROFILES = ['calm', 'energetic', 'dramatic', 'minimal', 'none'];

    /** Fallback when the model returns no usable profile. */
    public const DEFAULT_PROFILE = 'calm';

    /** Scroll/entrance classes: revealed by motion.js, or pure-CSS on load. */
    public const SCROLL_CLASSES = [
        'reveal', 'reveal-up', 'reveal-fade', 'reveal-scale',
        'stagger-children', 'hero-entrance',
    ];

    /** Ambient classes: signature effects, budgeted to ONE per page. */
    public const AMBIENT_CLASSES = ['ken-burns', 'gradient-shift', 'ambient-drift'];

    /**
     * Hover classes. Their CSS is generated per-site by the page-styles step
     * (see PageStylesStep::CLASSES), not by the motion kit, but the motion
     * profile still gates them: `none` means no motion at all.
     */
    public const HOVER_CLASSES = ['hover-lift', 'hover-reveal'];

    /** JS-owned state class; authored markup must never carry it. */
    public const STATE_CLASS = 'is-visible';

    /**
     * Bounds for the --motion-* overrides the page-styles call may emit.
     * Values outside these ranges make the whole override block invalid.
     */
    public const DURATION_MS = [150, 1200];
    public const DISTANCE_PX = [8, 48];
    public const STAGGER_MS = [40, 150];

    /**
     * The easing functions a --motion-ease override may use: the CSS keywords
     * plus the exact beziers the profiles ship (compared with whitespace
     * stripped, lowercased).
     */
    public const EASING_ALLOWLIST = [
        'ease', 'ease-in', 'ease-out', 'ease-in-out', 'linear',
        'cubic-bezier(0.22,1,0.36,1)',
        'cubic-bezier(0.25,0.1,0.25,1)',
        'cubic-bezier(0.34,1.56,0.64,1)',
        'cubic-bezier(0.16,1,0.3,1)',
        'cubic-bezier(0.4,0,0.2,1)',
        'cubic-bezier(0.45,0,0.55,1)',
    ];

    /** @return string[] every class the motion kit's CSS implements */
    public static function kitClasses(): array
    {
        return array_merge(self::SCROLL_CLASSES, self::AMBIENT_CLASSES);
    }

    /**
     * The motion classes a profile permits in markup. Unknown profiles get the
     * `none` treatment — an unrecognized commitment must fail closed, not
     * animate.
     *
     * @return string[]
     */
    public static function allowedClasses(string $profile): array
    {
        return match ($profile) {
            'calm', 'energetic', 'dramatic'
                => array_merge(self::SCROLL_CLASSES, self::AMBIENT_CLASSES, self::HOVER_CLASSES),
            'minimal' => self::HOVER_CLASSES,
            default   => [],
        };
    }

    /**
     * Whether a class token is motion-flavored — either part of the vocabulary
     * or an invented variant of it (`reveal-left`, `motion-spin`,
     * `ken-burns-slow`, …) that has no CSS and must be stripped. Pure —
     * unit-testable.
     */
    public static function looksLikeMotionClass(string $token): bool
    {
        if ($token === self::STATE_CLASS) {
            return true;
        }
        if (in_array($token, self::kitClasses(), true) || in_array($token, self::HOVER_CLASSES, true)) {
            return true;
        }
        return preg_match(
            '/^(?:reveal|stagger|ambient|motion|ken-burns|gradient-shift|hero-entrance)(?:-[\w-]+)?$/',
            $token
        ) === 1;
    }
}
