<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * The motion-kit contract shared by every step that touches motion: the fixed
 * profile list the design direction picks from, the fixed class vocabulary the
 * section prompts may place (whose CSS ships statically in assets/motion/ —
 * never LLM-generated), the per-profile allowance the motion-sanity step
 * enforces. Profile stylesheets own motion timing and choreography; generated
 * page CSS cannot override them.
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

    /** Hover classes implemented by the static kit and gated by the profile. */
    public const HOVER_CLASSES = ['hover-lift', 'hover-reveal'];

    /** Hard cap promised by the section prompt; each part is one section. */
    public const MAX_ENTRANCES_PER_SECTION = 2;

    /** JS-owned state class; authored markup must never carry it. */
    public const STATE_CLASS = 'is-visible';

    /** @return string[] every class the motion kit's CSS implements */
    public static function kitClasses(): array
    {
        return array_merge(self::SCROLL_CLASSES, self::AMBIENT_CLASSES, self::HOVER_CLASSES);
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
                => self::kitClasses(),
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
        if (in_array($token, self::kitClasses(), true)) {
            return true;
        }
        return preg_match(
            '/^(?:(?:reveal|stagger|ambient|motion|ken-burns|gradient-shift|hero-entrance)|hover-(?:lift|reveal))(?:-[\w-]+)?$/',
            $token
        ) === 1;
    }
}
