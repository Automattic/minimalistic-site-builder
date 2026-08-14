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
     * Map a free-text motion note onto kit classes the committed profile can
     * actually ship. An unmappable note is not a promise.
     *
     * @return array{note:string,classes:list<string>}
     */
    public static function mapNote(mixed $raw, string $profile): array
    {
        $note = is_string($raw) ? trim($raw) : '';
        if ($note === '') {
            return ['note' => '', 'classes' => []];
        }

        $allowed = self::allowedClasses($profile);
        if ($allowed === []) {
            return ['note' => '', 'classes' => []];
        }

        $haystack = strtolower($note);
        $mapped = [];
        foreach (self::notePhrases() as $class => $phrases) {
            if (!in_array($class, $allowed, true)) {
                continue;
            }
            $aliases = array_merge([$class, str_replace('-', ' ', $class)], $phrases);
            foreach ($aliases as $phrase) {
                if ($phrase !== '' && str_contains($haystack, $phrase)) {
                    $mapped[] = $class;
                    break;
                }
            }
        }

        $mapped = array_values(array_unique($mapped));
        if ($mapped === []) {
            return ['note' => '', 'classes' => []];
        }

        return [
            'note' => 'Use kit classes: ' . implode(', ', $mapped) . '.',
            'classes' => $mapped,
        ];
    }

    /**
     * Drop authored rules that re-implement the motion kit. Design CSS that
     * writes `.reveal-up { animation: … }` plus the kit's own entrance is
     * why the same element plays twice.
     */
    public static function stripAuthoredKitCss(string $css): string
    {
        if ($css === '') {
            return $css;
        }
        $css = preg_replace(
            '/@keyframes\s+(?:kenburns|revealUp|revealFade|arrive)\b[^{]*\{(?:[^{}]|\{[^{}]*\})*\}/i',
            '',
            $css,
        ) ?? $css;

        $out = '';
        $offset = 0;
        $length = strlen($css);
        while ($offset < $length) {
            $open = strpos($css, '{', $offset);
            if ($open === false) {
                $out .= substr($css, $offset);
                break;
            }
            $selector = substr($css, $offset, $open - $offset);
            $depth = 1;
            $i = $open + 1;
            while ($i < $length && $depth > 0) {
                if ($css[$i] === '{') {
                    $depth++;
                } elseif ($css[$i] === '}') {
                    $depth--;
                }
                $i++;
            }
            $block = substr($css, $offset, $i - $offset);
            if (self::selectorIsAuthoredKit(trim($selector))) {
                $offset = $i;
                continue;
            }
            $out .= $block;
            $offset = $i;
        }
        return $out;
    }

    private static function selectorIsAuthoredKit(string $selector): bool
    {
        if ($selector === '' || str_starts_with($selector, '@')) {
            return false;
        }
        foreach (preg_split('/\s*,\s*/', $selector) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match(
                '/^\.(?:reveal|reveal-up|reveal-fade|reveal-scale|stagger-children|hero-entrance|'
                    . 'ken-burns|gradient-shift|ambient-drift|hover-lift|hover-reveal)'
                    . '(?:\s*>\s*\*(?::nth-child\(\d+\))?|\s+img|:hover|:focus)?$/i',
                $part,
            ) !== 1) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string,list<string>> */
    private static function notePhrases(): array
    {
        return [
            'hero-entrance' => ['hero entrance', 'hero arrive', 'hero focus', 'focus pull'],
            'ken-burns' => ['ken burns', 'hero image breathe', 'image breathe', 'slow zoom', 'breathe'],
            'stagger-children' => ['one by one', 'cards rise', 'stagger', 'cascade'],
            'reveal-up' => ['rise', 'arrive from below', 'settle up'],
            'reveal-fade' => ['fade in', 'soft fade', 'fade'],
            'reveal-scale' => ['scale in', 'zoom settle'],
            'hover-lift' => [
                'press on', 'overshoot', 'hover lift', 'lift on hover', 'labels press',
                'buttons press', 'press', 'inset',
            ],
            'hover-reveal' => ['hover reveal', 'image reveal on hover'],
            'gradient-shift' => ['gradient shift', 'gradient drift'],
            'ambient-drift' => ['ambient drift', 'slow float'],
        ];
    }
}
