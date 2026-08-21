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

    /**
     * The classes a site-wide motion note may name. `hero-entrance` is excluded
     * on purpose: prompts/section.md treats it as hero-only, and the direction
     * is read by every section, so naming it here would license it everywhere.
     *
     * @return string[]
     */
    public static function noteClasses(): array
    {
        return array_values(array_diff(self::kitClasses(), ['hero-entrance']));
    }

    /**
     * The prompt sentence for a validated class list. Persisted JSON stores
     * the list; format() is the only place this sentence is rendered.
     *
     * @param list<string> $classes
     */
    public static function formatNote(array $classes): string
    {
        return $classes === [] ? '' : 'Use kit classes: ' . implode(', ', $classes) . '.';
    }

    /**
     * Read a motion note as the bounded list of kit classes it is.
     *
     * The note names classes; it is not art direction to be interpreted. Every
     * token must be a kit class exactly, so a phrase the kit cannot ship drops
     * whole rather than turning on whichever class its letters happen to
     * contain — the old substring table read "do not fade in" as a fade and
     * "surprise" as `rise`.
     *
     * Accepts a JSON list, or one string of comma/space separated names, since
     * models write both. Site-wide budgets: one ambient (the page-level cap in
     * prompts/section.md) and one hover language. Entrance classes are not
     * capped here — stagger and a reveal belong on different block kinds, and
     * motion-sanity enforces the per-block pairs that fight over one transform.
     *
     * @return array{note:string,classes:list<string>,dropped:list<string>}
     */
    public static function validateNote(mixed $raw, string $profile): array
    {
        $empty = ['note' => '', 'classes' => [], 'dropped' => []];
        $tokens = self::noteTokens($raw);
        if ($tokens === []) {
            return $empty;
        }

        $allowed = self::allowedClasses($profile);
        $permitted = array_intersect(self::noteClasses(), $allowed);
        $kept = [];
        $dropped = [];
        foreach ($tokens as $token) {
            if (in_array($token, $kept, true)) {
                continue;
            }
            if (in_array($token, self::kitClasses(), true) && !in_array($token, self::noteClasses(), true)) {
                $dropped[] = $token . ' (hero-only; a site-wide note cannot license it)';
                continue;
            }
            if (!in_array($token, self::noteClasses(), true)) {
                $dropped[] = $token . ' (not a motion-kit class)';
                continue;
            }
            if (!in_array($token, $permitted, true)) {
                $dropped[] = $token . " (the {$profile} profile does not ship it)";
                continue;
            }
            $bucket = self::noteBucket($token);
            if ($bucket !== 'entrance') {
                $taken = array_filter($kept, static fn (string $c): bool => self::noteBucket($c) === $bucket);
                if ($taken !== []) {
                    $dropped[] = $token . ' (' . $bucket . ' budget already spent on ' . reset($taken) . ')';
                    continue;
                }
            }
            $kept[] = $token;
        }

        return [
            'note' => self::formatNote($kept),
            'classes' => $kept,
            'dropped' => $dropped,
        ];
    }

    /**
     * Split an authored note into candidate class tokens. Separators only —
     * no interpretation, so an unmappable phrase yields tokens that simply
     * fail the membership test.
     *
     * @return list<string>
     */
    private static function noteTokens(mixed $raw): array
    {
        $parts = [];
        foreach (is_array($raw) ? $raw : [$raw] as $item) {
            if (!is_string($item)) {
                continue;
            }
            foreach (preg_split('/[\s,;]+/u', strtolower(trim($item))) ?: [] as $token) {
                $token = trim($token, " \t\"'.`");
                if ($token !== '') {
                    $parts[] = $token;
                }
            }
        }
        return $parts;
    }

    /** Which site-wide note budget a class draws from. Entrance is uncapped. */
    private static function noteBucket(string $class): string
    {
        if (in_array($class, self::AMBIENT_CLASSES, true)) {
            return 'ambient';
        }
        return in_array($class, self::HOVER_CLASSES, true) ? 'hover' : 'entrance';
    }
}
