<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Shared plumbing for the model-CSS validators (page-styles and custom-motion):
 * the checks that keep generated CSS safe to append to style.css. Each step
 * supplies only its policy — which at-rules are allowed, which selectors count
 * as scoped — and its own problem wording where the rulesets differ.
 * All methods are pure — unit-testable. Callers strip comments first.
 */
final class CssChecks
{
    /**
     * Whether the braces balance. Walks the depth instead of comparing
     * totals: a leading stray `}` balanced by a trailing open brace nets to
     * zero but still escapes whatever rule or wrapper the CSS is concatenated
     * into.
     */
    public static function braceDepthBalanced(string $css): bool
    {
        $depth = 0;
        foreach (str_split($css) as $char) {
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}' && --$depth < 0) {
                return false;
            }
        }
        return $depth === 0;
    }

    /**
     * The problem string when the CSS uses a resource-loading value form, or
     * null when clean. url() is not the only one: image-set("…"), image("…"),
     * cross-fade() and friends fetch too (including with vendor prefixes,
     * which is why the match is a bare substring).
     */
    public static function resourceLoadingProblem(string $css): ?string
    {
        if (preg_match('/(?:image-set|cross-fade|element|paint|url|src|image)\s*\(/i', $css) === 1) {
            return 'resource-loading CSS functions (url(), image-set(), image(), cross-fade(), …) are not allowed';
        }
        return null;
    }

    /**
     * display:none / visibility:hidden / a clip-path that clips everything
     * away, anywhere in the CSS — generated content must stay visible.
     *
     * @return string[]
     */
    public static function hiddenContentProblems(string $css): array
    {
        $problems = [];
        if (preg_match('/(?<![-\w])display\s*:\s*none(?:\s*!important)?\s*(?=;|}|$)/i', $css) === 1) {
            $problems[] = 'display:none hides generated content';
        }
        if (preg_match('/(?<![-\w])visibility\s*:\s*hidden(?:\s*!important)?\s*(?=;|}|$)/i', $css) === 1) {
            $problems[] = 'visibility:hidden hides generated content';
        }
        // Scoped to rules OUTSIDE @keyframes, exactly like the opacity check
        // the two callers run alongside this one. A `from { clip-path: inset(0
        // 0 100% 0) }` is the canonical wipe-in and a `from { clip-path:
        // circle(0) }` the canonical iris-in — both are legal entrances that
        // END visible, and rejecting them killed the user's one explicit
        // animation request outright (BIGR-887). A `forwards`/`both` fill
        // parking an element in a hidden LAST keyframe is a real defect, and
        // this exemption is why CustomMotionStep's non-start-keyframe walk
        // has to check `clip-path` there alongside `opacity`.
        $seen = [];
        foreach (self::scanDeclarations($css) as $declaration) {
            if ($declaration['kind'] === 'keyframe'
                || strtolower(trim($declaration['property'])) !== 'clip-path'
            ) {
                continue;
            }
            $value = trim($declaration['value']);
            if (!self::clipsEverythingAway($value) || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $problems[] = 'clip-path clips generated content away entirely: ' . $value;
        }
        return $problems;
    }

    /**
     * Whether a `clip-path` value leaves no visible area at all.
     *
     * This exists because a hidden resting state does not have to be spelled
     * `opacity: 0`. pulso2 shipped a hero whose copy was
     * `clip-path: inset(0 0 100% 0)` — fully readable to every visibility
     * check we had, and completely invisible on screen (BIGR-881).
     *
     * Deliberately narrow: only shapes that are provably empty from their
     * literal value are reported. A partial `inset(50% 0 0 0)`, a `var()`, and
     * a `polygon()` (whose area needs real geometry) are left to the author.
     */
    private static function clipsEverythingAway(string $value): bool
    {
        $value = trim(self::withoutComments($value));
        $value = trim((string) preg_replace('/\s*!\s*important\s*$/i', '', $value));

        // inset(): opposite edges that meet or cross leave zero area. The
        // round <radius> tail never adds area back, so it is dropped first.
        if (preg_match('/^inset\(\s*([^)]*?)\s*(?:round\b[^)]*)?\)$/i', $value, $inset) === 1) {
            $parts = preg_split('/[\s,]+/', trim($inset[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $sides = match (count($parts)) {
                1       => [$parts[0], $parts[0], $parts[0], $parts[0]],
                2       => [$parts[0], $parts[1], $parts[0], $parts[1]],
                3       => [$parts[0], $parts[1], $parts[2], $parts[1]],
                4       => $parts,
                default => null,
            };
            if ($sides === null) {
                return false;
            }
            [$top, $right, $bottom, $left] = array_map(self::insetPercentage(...), $sides);
            foreach ([[$top, $bottom], [$left, $right]] as [$near, $far]) {
                if ($near !== null && $far !== null && $near + $far >= 100.0) {
                    return true;
                }
            }
            return false;
        }

        // A zero-radius circle()/ellipse() has no area either.
        if (preg_match('/^(?:circle|ellipse)\(\s*([^)]*?)\s*(?:at\b[^)]*)?\)$/i', $value, $round) === 1) {
            $radii = preg_split('/[\s,]+/', trim($round[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($radii === []) {
                return false;
            }
            foreach ($radii as $radius) {
                if (preg_match('/^0*(?:\.0+)?(?:%|[a-z]+)?$/i', $radius) !== 1) {
                    return false;
                }
            }
            return true;
        }

        return false;
    }

    /**
     * One inset() side as a percentage of the box, or null when it cannot be
     * compared without layout. A non-percentage length is only comparable at
     * zero, which never closes the box on its own.
     */
    private static function insetPercentage(string $side): ?float
    {
        if (preg_match('/^([+-]?(?:\d+(?:\.\d*)?|\.\d+))%$/', $side, $match) === 1) {
            return (float) $match[1];
        }
        if (preg_match('/^[+-]?(?:0+(?:\.0*)?|\.0+)(?:[a-z]+)?$/i', $side) === 1) {
            return 0.0;
        }
        return null;
    }

    /**
     * Whether a selector names a motion-kit class ANYWHERE in it — as the
     * subject, as an ancestor, or inside a functional pseudo.
     *
     * Unlike the shape policy, which asks what a rule styles, this asks what a
     * rule touches. `.stagger-children > *` styles a bare universal subject and
     * still redefines kit choreography, so the subject compound alone is the
     * wrong unit here.
     *
     * Quoted strings and attribute values are removed first so `[data-x=".reveal"]`
     * is not read as a class.
     */
    public static function selectorNamesMotionClass(string $selector): bool
    {
        $plain = (string) preg_replace(
            ['~/\*.*?\*/~s', '/"(?:\\\\.|[^"\\\\])*"/s', "/'(?:\\\\.|[^'\\\\])*'/s", '/\[[^\]]*\]/s'],
            ' ',
            $selector,
        );
        if (preg_match_all('/(?<!\\\\)\.(-?[_a-zA-Z][\w-]*)/', $plain, $classes) === 0) {
            return false;
        }
        foreach ($classes[1] as $class) {
            if (Motion::looksLikeMotionClass($class)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Drop every declaration whose own rule, or any rule nesting it, names a
     * motion-kit class.
     *
     * The kit's CSS and its JS driver are one system: `motion.js` registers
     * targets and flips `.is-visible`, and `motion.css` owns every hidden and
     * revealed state, including the `motion-skip` escape it applies to content
     * already above the fold. Generated CSS that redefines a kit class only
     * ever half-wins — `motion-skip` clears `opacity` and `animation`, so a
     * generated `clip-path`/`transform` hidden state survives with nothing left
     * to reveal it, and the content never appears (BIGR-881).
     *
     * Two removal widths, cut at the smallest unit that isolates the defect
     * (escalation ladder, rung 3):
     *
     * - The rule's SUBJECT is a kit class (`.reveal-up`, `.stagger-children >
     *   *`) — it is styling the kit element itself, so every declaration goes.
     * - Only an ANCESTOR is a kit class (`.hero-entrance h1`) — the rule
     *   styles something else that merely lives inside a kit element, so only
     *   the properties that can fight the kit's choreography go, plus any
     *   declaration whose VALUE hides outright. `display` is judged by value
     *   and not by name: `display: none` under a kit ancestor is the BIGR-881
     *   shape and nothing else in the theme.json path would catch it, while
     *   `display: flex` is ordinary layout. `.hero-entrance h1 {
     *   letter-spacing: -0.03em; max-width: 18ch }` is ordinary design intent
     *   and used to be deleted whole (BIGR-887).
     *
     * Removal is per declaration, so an emptied kit rule stays in place as
     * inert bytes rather than forcing a structural rewrite of the surrounding
     * CSS. Keyframes the removed declarations referenced are left alone: with
     * no rule naming them they render nothing.
     *
     * @return array{0:string,1:list<string>} repaired CSS and dropped declarations
     */
    public static function dropMotionKitDeclarations(
        string $css,
        bool $bareDeclarationList = false,
    ): array {
        [$repaired, $dropped] = self::dropDeclarations(
            $css,
            static function (array $declaration): bool {
                if ($declaration['kind'] !== 'style') {
                    return false;
                }
                if (self::selectorTargetsMotionElement($declaration['context'])) {
                    return true;
                }
                foreach ([$declaration['context'], ...$declaration['ancestors']] as $selector) {
                    if (self::selectorNamesMotionClass(
                        self::withoutExcludedOrRelationalArguments($selector),
                    )) {
                        return self::isMotionCapableProperty($declaration['property'])
                            || self::hiddenContentProblems(
                                'a{' . $declaration['property'] . ':' . $declaration['value'] . '}'
                            ) !== [];
                    }
                }
                return false;
            },
            $bareDeclarationList,
        );
        return [
            $repaired,
            array_map(static fn (array $declaration): string => trim($declaration['raw']), $dropped),
        ];
    }

    /**
     * Whether a selector styles a motion-kit ELEMENT itself, rather than
     * something that merely lives inside one.
     *
     * Only the rightmost (subject) compound counts, so `.hero-entrance h1`
     * styles an h1 and not the entrance. `:not()` arguments are stripped
     * first: `.card:not(.reveal-up)` deliberately EXCLUDES kit elements, so
     * reading its name there had it removed as if it targeted them.
     *
     * `.stagger-children > *` is included on purpose even though its subject
     * is the universal selector: motion.js registers those direct children as
     * targets, so that selector IS the kit's own.
     */
    public static function selectorTargetsMotionElement(string $selector): bool
    {
        foreach (self::splitSelectorList($selector) as $candidate) {
            $compound = self::rightmostSelectorCompound($candidate);
            if ($compound === '') {
                continue;
            }
            // Drop negation/relational arguments — they name what is excluded.
            $subject = self::withoutExcludedOrRelationalArguments($compound);
            if (self::selectorNamesMotionClass($subject)) {
                return true;
            }
            // The kit registers `.stagger-children > *` itself.
            $head = trim(substr($candidate, 0, strlen($candidate) - strlen($compound)));
            if ($head !== ''
                && preg_match('/(?:>|\s)$/', $head) === 1
                && preg_match('/(?<![\w-])stagger-children(?![\w-])/i', $head) === 1
                && preg_match('/^\*(?:$|[:\[])/', trim($compound)) === 1
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Remove class references that do not describe the selected element.
     * `:not(.reveal-up)` explicitly excludes that kit class, while
     * `:has(.reveal-up)` selects its container. Neither makes the subject (or
     * an ancestor subject) part of the motion kit.
     */
    private static function withoutExcludedOrRelationalArguments(string $selector): string
    {
        return (string) preg_replace(
            '/:(?:not|has)\((?:[^()]*|\([^()]*\))*\)/i',
            '',
            $selector,
        );
    }

    /**
     * Whether a property can hide an element at rest or compete with the
     * motion kit's own choreography.
     *
     * The narrow cut for a rule that only sits INSIDE a kit element. Anything
     * outside this list — colour, spacing, type, borders — cannot produce the
     * BIGR-881 failure and is ordinary design intent.
     */
    public static function isMotionCapableProperty(string $property): bool
    {
        return preg_match(
            '/^(?:-[a-z]+-)?(?:opacity|visibility|clip-path|clip|filter|backdrop-filter'
            . '|will-change|transform(?:-[a-z]+)?|translate|rotate|scale|perspective(?:-[a-z]+)?'
            . '|animation(?:-[a-z]+)*|transition(?:-[a-z]+)*|offset(?:-[a-z]+)*)$/i',
            trim($property),
        ) === 1;
    }

    /** Maximum nested CSS blocks inspected by the generated-CSS scanner. */
    private const MAX_DECLARATION_SCAN_DEPTH = 64;

    /** Media preludes resolve rem/em against the initial font size only. */
    private const MEDIA_PRELUDE_ROOT_FONT_SIZE = 16.0;

    /**
     * Whether a CSS property can directly change a corner radius owned by the
     * design direction. Covers the shorthand, physical/logical longhands, and
     * vendor-prefixed equivalents. Custom properties start with `--` and do
     * not match.
     */
    public static function isShapeOwnedRadiusProperty(string $property): bool
    {
        return preg_match(
            '/^(?:-[a-z]+-)?border(?:-[a-z]+)*-radius$/i',
            trim($property),
        ) === 1;
    }

    /**
     * Whether one declaration can reset or directly set a shape-owned corner.
     * `all` affects border-radius only for the CSS-wide reset keywords; values
     * such as `all: 1s` or `all: var(--transition)` are not CSS declarations
     * that reset corners and therefore stay outside this policy.
     */
    public static function isShapeAffectingDeclaration(string $property, string $value): bool
    {
        if (self::isShapeOwnedRadiusProperty($property)) {
            return true;
        }
        if (strtolower(trim($property)) !== 'all') {
            return false;
        }

        $value = trim(self::withoutComments($value));
        $value = (string) preg_replace('/\s*!\s*important\s*$/i', '', $value);
        $keyword = strtolower(self::decodeIdentifier(trim($value)));
        if (in_array($keyword, ['initial', 'inherit', 'unset', 'revert', 'revert-layer'], true)) {
            return true;
        }
        // A custom/environment substitution may resolve to any CSS-wide value
        // (including its fallback), so an owned `all: var(--x, initial)` must
        // not provide a reset bypass.
        [, $opaque] = self::animationReferences($value);
        return $opaque;
    }

    /** Whether a declaration can replace the committed CTA construction. */
    public static function isCtaAffectingDeclaration(string $property, string $value): bool
    {
        $property = strtolower(trim($property));
        if ($property === '' || str_starts_with($property, '--')) {
            return false;
        }
        if (preg_match(
            '/^(?:color|background(?:-.+)?|border(?:-.+)?|padding(?:-.+)?|'
                . '(?:min-|max-)?width|display|box-sizing|text-align|text-decoration(?:-.+)?|'
                . 'text-underline-offset|gap|row-gap|column-gap|content)$/',
            $property,
        ) === 1) {
            return true;
        }
        return $property === 'all' && self::isShapeAffectingDeclaration($property, $value);
    }

    /** Whether a selector's subject is a core button wrapper or rendered control. */
    public static function selectorTargetsCta(string $selector): bool
    {
        return self::selectorTargetsCtaInternal($selector);
    }

    private static function selectorTargetsCtaInternal(string $selector): bool
    {
        foreach (self::splitSelectorList($selector) as $candidate) {
            $compound = self::rightmostSelectorCompound($candidate);
            if ($compound === '') {
                continue;
            }
            [$plain, $functionalTarget] = self::withoutFunctionalPseudos(
                $compound,
                static fn (string $arguments): bool => self::selectorTargetsCtaInternal($arguments),
            );
            if ($functionalTarget) {
                return true;
            }
            $plain = self::withoutSelectorAttributes($plain);
            if (preg_match(
                '/\.(?:wp-block-button|wp-block-button__link|wp-element-button)(?![-\w])/i',
                $plain,
            ) === 1) {
                return true;
            }
            if (preg_match('/^(?:(?:\*|[-\w]+)\|)?button(?![-\w])/i', ltrim($plain)) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a selector's subject is one of the build-owned corner surfaces:
     * a core image/image element, the rendered button control, a cover
     * canvas, or a media-text block and its media half. Only the rightmost
     * (subject) compound is considered, so `.wp-block-image + .card` does not
     * accidentally make a generic card shape-owned. Attribute values,
     * negation/relational pseudo arguments, comments, and quoted strings are
     * ignored. :is() and :where() keep their subject semantics and are walked.
     */
    public static function selectorTargetsShape(string $selector): bool
    {
        return self::selectorTargetsShapeInternal($selector, true);
    }

    private static function selectorTargetsShapeInternal(string $selector, bool $includeBroad): bool
    {
        foreach (self::splitSelectorList($selector) as $candidate) {
            $compound = self::rightmostSelectorCompound($candidate);
            if ($compound === '' || self::hasSubjectPseudoElement($compound)) {
                continue;
            }

            [$plain, $functionalTargetsShape] = self::withoutFunctionalPseudos(
                $compound,
                static fn (string $arguments): bool => self::selectorTargetsShapeInternal(
                    $arguments,
                    false,
                ),
            );
            if ($functionalTargetsShape) {
                return true;
            }
            $plain = self::withoutSelectorAttributes($plain);
            if (preg_match(
                '/\.(?:wp-block-image|wp-block-button__link|wp-element-button'
                    . '|wp-block-cover(?:__(?:background|image-background|video-background))?'
                    . '|wp-block-media-text(?:__media)?)(?![-\w])/i',
                $plain,
            ) === 1) {
                return true;
            }
            if (preg_match('/^(?:(?:\*|[-\w]+)\|)?(?:img|button|a|figure)(?![-\w])/i', ltrim($plain)) === 1) {
                return true;
            }
            if (!$includeBroad || self::hasExplicitNonShapeSubject($plain)) {
                continue;
            }

            // A positive :is()/:where() list narrows an otherwise implicit
            // universal subject. It is broad only when at least one branch can
            // itself select an owned surface; `:is(.card, .panel)` remains an
            // explicit generic-component selector.
            [, $functionalMayTargetShape, $hasPositiveSubjectPseudo] = self::withoutFunctionalPseudos(
                $compound,
                static fn (string $arguments): bool => self::selectorTargetsShapeInternal(
                    $arguments,
                    true,
                ),
            );
            if ($hasPositiveSubjectPseudo) {
                if ($functionalMayTargetShape) {
                    return true;
                }
                continue;
            }

            // Universal, attribute-only, and predicate-only subjects can all
            // match an img/button at runtime. Treat them as owned instead of
            // letting a broad generated rule bypass the corner commitment.
            if (preg_match('/(?<![-\\\\\w]):root(?![-\w(])/i', $plain) !== 1) {
                return true;
            }
        }
        return false;
    }

    /** Whether the subject has an explicit generic component/type/id anchor. */
    private static function hasExplicitNonShapeSubject(string $plain): bool
    {
        $plain = trim($plain);
        if (str_contains($plain, '&')) {
            // `&` is decided by the caller's external block context.
            return true;
        }
        if (preg_match('/(?<!\\\\)[.#][-_a-zA-Z][-_a-zA-Z0-9]*/', $plain) === 1) {
            return true;
        }
        return preg_match(
            '/^(?:(?:\*|[-_a-zA-Z][-_a-zA-Z0-9]*)\|)?[-_a-zA-Z][-_a-zA-Z0-9]*/',
            $plain,
        ) === 1;
    }

    /**
     * Whether a selector's rightmost subject contains one exact root token.
     * This is used for CSS whose containing block supplies an implicit `&`,
     * and for dedicated generated classes. Selector lists and balanced
     * functional pseudos are walked without treating :not() or :has()
     * arguments as the selected subject.
     */
    /**
     * Whether a selector's subject is a heading: h1–h6, `.wp-block-heading`,
     * or `.wp-block-post-title`. Only the rightmost compound is considered, so
     * `h1 + p` does not count as a heading rule. :is() and :where() keep their
     * subject semantics and are walked.
     */
    public static function selectorTargetsHeading(string $selector): bool
    {
        foreach (self::splitSelectorList($selector) as $candidate) {
            $compound = self::rightmostSelectorCompound($candidate);
            if ($compound === '' || self::hasSubjectPseudoElement($compound)) {
                continue;
            }

            [$plain, $functionalTargetsHeading] = self::withoutFunctionalPseudos(
                $compound,
                static fn (string $arguments): bool => self::selectorTargetsHeading($arguments),
            );
            if ($functionalTargetsHeading) {
                return true;
            }
            $plain = self::withoutSelectorAttributes($plain);
            if (preg_match(
                '/(?<![-\\\\\w])\.(?:wp-block-heading|wp-block-post-title)(?![-\w])/i',
                $plain,
            ) === 1) {
                return true;
            }
            if (preg_match('/^(?:(?:\*|[-\w]+)\|)?h[1-6](?![-\w])/i', ltrim($plain)) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Properties that split a word across lines: hyphenation, overflow-wrap,
     * and word-break (including the legacy `word-wrap` alias and vendor
     * hyphen prefixes).
     */
    public static function isWordSplitProperty(string $property): bool
    {
        $property = strtolower($property);
        return in_array($property, [
            'overflow-wrap',
            'word-wrap',
            'word-break',
            'hyphens',
            '-webkit-hyphens',
            '-ms-hyphens',
            '-moz-hyphens',
        ], true);
    }

    /** Whether a declaration overrides the build-owned text-wrap policy. */
    public static function isTextWrapProperty(string $property): bool
    {
        return in_array(strtolower($property), [
            'text-wrap',
            'text-wrap-style',
            'text-wrap-mode',
        ], true);
    }

    /**
     * Drop every generated text-wrap declaration. PageStylesStep supplies one
     * deterministic policy after this repair, so direct paragraph selectors,
     * broad selectors, longhands, priorities, and keyframes cannot take
     * ownership back.
     *
     * @return array{0:string,1:list<string>} repaired CSS and dropped declarations
     */
    public static function dropTextWrapDeclarations(string $css): array
    {
        [$repaired, $dropped] = self::dropDeclarations(
            $css,
            static fn (array $declaration): bool => self::isTextWrapProperty(
                $declaration['property'],
            ),
            self::looksLikeDeclarationList($css),
        );
        return [
            $repaired,
            array_map(static fn (array $declaration): string => trim($declaration['raw']), $dropped),
        ];
    }

    /**
     * Drop word-splitting declarations whose selector subject is a heading.
     * Body copy and non-heading siblings keep their authored behavior.
     * Keyframe steps are left alone.
     *
     * @return array{0:string,1:list<string>} repaired CSS and dropped declarations
     */
    public static function dropHeadingWordSplitDeclarations(string $css): array
    {
        [$repaired, $dropped] = self::dropDeclarations(
            $css,
            static fn (array $declaration): bool => $declaration['kind'] === 'style'
                && self::isWordSplitProperty($declaration['property'])
                && self::selectorTargetsHeading($declaration['context']),
            self::looksLikeDeclarationList($css),
        );
        return [
            $repaired,
            array_map(static fn (array $declaration): string => trim($declaration['raw']), $dropped),
        ];
    }

    public static function selectorTargetsSubject(string $selector, string $subject): bool
    {
        if ($subject !== '&'
            && preg_match('/^\.[-_a-zA-Z][-_a-zA-Z0-9]*$/D', $subject) !== 1
        ) {
            return false;
        }

        foreach (self::splitSelectorList($selector) as $candidate) {
            $compound = self::rightmostSelectorCompound($candidate);
            if ($compound === '' || self::hasSubjectPseudoElement($compound)) {
                continue;
            }
            [$plain, $functionalTargetsSubject] = self::withoutFunctionalPseudos(
                $compound,
                static fn (string $arguments): bool => self::selectorTargetsSubject($arguments, $subject),
            );
            if ($functionalTargetsSubject) {
                return true;
            }
            $plain = self::withoutSelectorAttributes($plain);
            if ($subject === '&') {
                for ($i = 0, $length = strlen($plain); $i < $length; ++$i) {
                    if ($plain[$i] === '\\') {
                        ++$i;
                    } elseif ($plain[$i] === '&') {
                        return true;
                    }
                }
                continue;
            }
            if (preg_match('/(?<!\\\\)' . preg_quote($subject, '/') . '(?![-\w])/i', $plain) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Scan real declarations without treating declaration-looking text inside
     * strings, comments, functions, escaped tokens, or custom-property blocks
     * as CSS. Ordinary rules, nested rules, grouping at-rules, and keyframe
     * steps are walked recursively. Set $bareDeclarationList for a theme.json
     * custom-CSS value that contains declarations without a selector wrapper.
     *
     * `context` is the declaration's direct selector or keyframe-step prelude;
     * `ancestors` contains outer selectors and at-rules in source order. The
     * exact byte spans let a caller make a scoped policy decision and remove
     * only that declaration without reformatting any surviving CSS.
     *
     * @return list<array{
     *   property:string,
     *   value:string,
     *   raw:string,
     *   start:int,
     *   end:int,
     *   context:string,
     *   ancestors:list<string>,
     *   kind:'style'|'keyframe'|'at-rule'|'declaration-list',
     *   structurallySafe:bool
     * }>
     */
    public static function scanDeclarations(string $css, bool $bareDeclarationList = false): array
    {
        $declarations = [];
        self::scanDeclarationRegion(
            $css,
            0,
            strlen($css),
            $bareDeclarationList ? 'style' : 'rules',
            $bareDeclarationList ? '<declaration-list>' : '',
            [],
            $bareDeclarationList ? 'declaration-list' : 'declaration-list',
            0,
            true,
            $declarations,
        );
        usort(
            $declarations,
            static fn (array $left, array $right): int => $left['start'] <=> $right['start'],
        );
        return $declarations;
    }

    /**
     * Where a scanned declaration stands at one render viewport width.
     *
     * Three answers, because a scoped declaration is not one question.
     * 'apply' — every ancestor is a width media query that holds here.
     * 'inert' — a width media query is provably false, so the declaration
     * cannot enter the cascade at all and a caller may ignore it outright.
     * 'unprovable' — a static width comparison cannot settle it: a non-media
     * at-rule, a non-width feature, range or negated syntax, a media query
     * list (a disjunction, where one false arm proves nothing), or an outer
     * selector from nested CSS, whose match the caller's own selector test
     * never saw. A provably false ancestor outranks an unprovable one: false
     * stays false however much else is unknown.
     *
     * @param list<string> $ancestors as produced by scanDeclarations()
     * @return 'apply'|'inert'|'unprovable'
     */
    public static function declarationScopeAtViewport(array $ancestors, float $viewportWidth): string
    {
        $unprovable = false;
        foreach ($ancestors as $ancestor) {
            $ancestor = trim(self::withoutComments($ancestor));
            if (!str_starts_with($ancestor, '@')) {
                $unprovable = true;
                continue;
            }
            $scope = self::mediaWidthScope($ancestor, $viewportWidth);
            if ($scope === 'inert') {
                return 'inert';
            }
            $unprovable = $unprovable || $scope === 'unprovable';
        }
        return $unprovable ? 'unprovable' : 'apply';
    }

    /** @return 'apply'|'inert'|'unprovable' */
    private static function mediaWidthScope(string $prelude, float $viewportWidth): string
    {
        if (preg_match('/\A@media\b(.*)\z/is', $prelude, $match) !== 1) {
            return 'unprovable';
        }
        $condition = trim($match[1]);
        if ($condition === ''
            || preg_match_all('/\(([^()]*)\)/', $condition, $features, PREG_SET_ORDER) < 1
        ) {
            return 'unprovable';
        }

        $inert = false;
        foreach ($features as $feature) {
            if (preg_match('/\A\s*(min|max)-width\s*:\s*(.+?)\s*\z/i', $feature[1], $parts) !== 1) {
                return 'unprovable';
            }
            $boundary = self::mediaLengthPixels($parts[2]);
            if ($boundary === null) {
                return 'unprovable';
            }
            $inert = $inert || !(strtolower($parts[1]) === 'min'
                ? $viewportWidth >= $boundary
                : $viewportWidth <= $boundary);
        }

        // Whatever sits between the features has to be media-type glue this
        // comparison already accounts for. A `not` inverts the result, a media
        // type this build never renders as decides nothing, and a comma is a
        // disjunction — each makes the prelude undecidable rather than false.
        $glue = (string) preg_replace('/\([^()]*\)/', ' ', $condition);
        foreach (preg_split('/\s+/', $glue, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (!in_array(strtolower($token), ['only', 'screen', 'all', 'and'], true)) {
                return 'unprovable';
            }
        }
        return $inert ? 'inert' : 'apply';
    }

    /**
     * A media feature length in CSS pixels. `rem` and `em` in a prelude resolve
     * against the initial font size, never the document's own root rule, and a
     * unitless number is only a length when it is zero.
     */
    private static function mediaLengthPixels(string $value): ?float
    {
        if (preg_match('/\A([+-]?(?:\d+(?:\.\d+)?|\.\d+))(px|r?em)?\z/i', trim($value), $match) !== 1) {
            return null;
        }
        $number = (float) $match[1];
        return match (strtolower($match[2] ?? '')) {
            'px' => $number,
            'em', 'rem' => $number * self::MEDIA_PRELUDE_ROOT_FONT_SIZE,
            default => $number === 0.0 ? 0.0 : null,
        };
    }

    /**
     * Split a declaration value from its CSS priority using identifier
     * semantics, including escaped spellings such as `!\69mportant`.
     * Comments are trivia in both the value and priority.
     *
     * @return array{value:string,important:bool}
     */
    public static function splitDeclarationPriority(string $value): array
    {
        $plain = trim(self::withoutComments($value));
        $bang = strrpos($plain, '!');
        if ($bang === false) {
            return ['value' => $plain, 'important' => false];
        }
        $priority = trim(substr($plain, $bang + 1));
        if (strtolower(self::decodeIdentifier($priority)) !== 'important') {
            return ['value' => $plain, 'important' => false];
        }
        return [
            'value' => trim(substr($plain, 0, $bang)),
            'important' => true,
        ];
    }

    /**
     * Drop declarations selected by a caller policy. The predicate receives
     * the complete scan row, including selector/keyframe context. Replacements
     * are applied from the end by exact source span, so every untouched byte is
     * preserved and declaration-looking text in values cannot be corrupted.
     *
     * @param callable(array{property:string,value:string,raw:string,start:int,end:int,context:string,ancestors:list<string>,kind:string,structurallySafe:bool}):bool $shouldDrop
     * @return array{0:string,1:list<array{property:string,value:string,raw:string,start:int,end:int,context:string,ancestors:list<string>,kind:string,structurallySafe:bool}>}
     */
    public static function dropDeclarations(
        string $css,
        callable $shouldDrop,
        bool $bareDeclarationList = false,
    ): array {
        $dropped = [];
        foreach (self::scanDeclarations($css, $bareDeclarationList) as $declaration) {
            if ($shouldDrop($declaration)) {
                $dropped[] = $declaration;
            }
        }
        if ($dropped === []) {
            return [$css, []];
        }

        $repaired = $css;
        foreach (array_reverse($dropped) as $declaration) {
            $repaired = substr($repaired, 0, $declaration['start'])
                . substr($repaired, $declaration['end']);
        }
        return [$repaired, $dropped];
    }

    /**
     * Shape-affecting declarations whose delivered scope is owned by the
     * committed image/button corner language. Style-rule rows are selected by
     * the caller's selector policy. Keyframe rows are selected only when that
     * keyframe is referenced by animation/animation-name in an owned rule; an
     * opaque reference such as var() conservatively owns every local keyframe.
     *
     * $bareOwned marks selector-less declarations as owned. Animation
     * references in that declaration list are followed just like references
     * in an owned style rule; unrelated local keyframes remain untouched.
     *
     * @param callable(string):bool $selectorOwned
     * @return list<array{property:string,value:string,raw:string,start:int,end:int,context:string,ancestors:list<string>,kind:string,structurallySafe:bool}>
     */
    public static function shapeAffectingDeclarations(
        string $css,
        callable $selectorOwned,
        bool $bareDeclarationList = false,
        bool $bareOwned = false,
    ): array {
        $declarations = self::scanDeclarations($css, $bareDeclarationList);
        $definedKeyframes = [];
        foreach ($declarations as $declaration) {
            if ($declaration['kind'] !== 'keyframe') {
                continue;
            }
            $name = self::keyframeNameOf($declaration);
            if ($name !== null) {
                $definedKeyframes[$name] = true;
            }
        }

        $ownedKeyframes = [];
        foreach ($declarations as $declaration) {
            if ($declaration['kind'] === 'keyframe'
                || !self::declarationScopeIsOwned($declaration, $selectorOwned, $bareOwned)
                || preg_match('/^(?:-[a-z]+-)?animation(?:-name)?$/i', $declaration['property']) !== 1
            ) {
                continue;
            }
            $shorthand = preg_match(
                '/^(?:-[a-z]+-)?animation$/i',
                $declaration['property'],
            ) === 1;
            [$names, $opaque] = self::animationReferences($declaration['value'], $shorthand);
            if ($opaque) {
                $ownedKeyframes = $definedKeyframes;
                continue;
            }
            foreach ($names as $name) {
                if (isset($definedKeyframes[$name])) {
                    $ownedKeyframes[$name] = true;
                }
            }
        }

        return array_values(array_filter(
            $declarations,
            static function (array $declaration) use ($selectorOwned, $bareOwned, $ownedKeyframes): bool {
                if (!self::isShapeAffectingDeclaration($declaration['property'], $declaration['value'])) {
                    return false;
                }
                if ($declaration['kind'] !== 'keyframe') {
                    return self::declarationScopeIsOwned($declaration, $selectorOwned, $bareOwned);
                }
                $name = self::keyframeNameOf($declaration);
                return $name !== null && isset($ownedKeyframes[$name]);
            },
        ));
    }

    /**
     * CTA-construction declarations in owned rules and in the local
     * keyframes those rules reference. This mirrors the shape ownership walk
     * so animation CSS cannot smuggle a construction override through a
     * keyframe while unrelated keyframes remain byte-for-byte intact.
     *
     * @param callable(string):bool $selectorOwned
     * @return list<array{property:string,value:string,raw:string,start:int,end:int,context:string,ancestors:list<string>,kind:string,structurallySafe:bool}>
     */
    public static function ctaAffectingDeclarations(
        string $css,
        callable $selectorOwned,
        bool $bareDeclarationList = false,
        bool $bareOwned = false,
    ): array {
        $declarations = self::scanDeclarations($css, $bareDeclarationList);
        $definedKeyframes = [];
        foreach ($declarations as $declaration) {
            if ($declaration['kind'] !== 'keyframe') {
                continue;
            }
            $name = self::keyframeNameOf($declaration);
            if ($name !== null) {
                $definedKeyframes[$name] = true;
            }
        }

        $ownedKeyframes = [];
        foreach ($declarations as $declaration) {
            if ($declaration['kind'] === 'keyframe'
                || !self::declarationScopeIsOwned($declaration, $selectorOwned, $bareOwned)
                || preg_match('/^(?:-[a-z]+-)?animation(?:-name)?$/i', $declaration['property']) !== 1
            ) {
                continue;
            }
            $shorthand = preg_match('/^(?:-[a-z]+-)?animation$/i', $declaration['property']) === 1;
            [$names, $opaque] = self::animationReferences($declaration['value'], $shorthand);
            if ($opaque) {
                $ownedKeyframes = $definedKeyframes;
                continue;
            }
            foreach ($names as $name) {
                if (isset($definedKeyframes[$name])) {
                    $ownedKeyframes[$name] = true;
                }
            }
        }

        return array_values(array_filter(
            $declarations,
            static function (array $declaration) use ($selectorOwned, $bareOwned, $ownedKeyframes): bool {
                if (!self::isCtaAffectingDeclaration($declaration['property'], $declaration['value'])) {
                    return false;
                }
                if ($declaration['kind'] !== 'keyframe') {
                    return self::declarationScopeIsOwned($declaration, $selectorOwned, $bareOwned);
                }
                $name = self::keyframeNameOf($declaration);
                return $name !== null && isset($ownedKeyframes[$name]);
            },
        ));
    }

    /**
     * Compatibility view used by the current shape consumers. Despite the
     * legacy method name, CSS-wide `all` resets are included because they also
     * override a committed radius.
     *
     * @return list<string> exact authored declarations, without semicolons
     */
    public static function shapeOwnedRadiusDeclarations(string $css): array
    {
        $declarations = self::scanDeclarations($css, self::looksLikeDeclarationList($css));
        return array_values(array_map(
            static fn (array $declaration): string => trim($declaration['raw']),
            array_filter(
                $declarations,
                static fn (array $declaration): bool => self::isShapeAffectingDeclaration(
                    $declaration['property'],
                    $declaration['value'],
                ),
            ),
        ));
    }

    /**
     * Compatibility repair used by the current shape consumers. New callers
     * that need target scoping should call dropDeclarations() directly and use
     * each declaration's context/ancestors in their predicate.
     *
     * @return array{0:string,1:list<string>} repaired CSS and dropped declarations
     */
    public static function dropShapeOwnedRadiusDeclarations(string $css): array
    {
        [$repaired, $dropped] = self::dropDeclarations(
            $css,
            static fn (array $declaration): bool => self::isShapeAffectingDeclaration(
                $declaration['property'],
                $declaration['value'],
            ),
            self::looksLikeDeclarationList($css),
        );
        return [
            $repaired,
            array_map(static fn (array $declaration): string => trim($declaration['raw']), $dropped),
        ];
    }

    /**
     * @param array{property:string,value:string,raw:string,start:int,end:int,context:string,ancestors:list<string>,kind:string,structurallySafe:bool} $declaration
     * @param callable(string):bool $selectorOwned
     */
    private static function declarationScopeIsOwned(
        array $declaration,
        callable $selectorOwned,
        bool $bareOwned,
    ): bool {
        if ($declaration['kind'] === 'declaration-list') {
            return $bareOwned;
        }
        if ($declaration['kind'] === 'keyframe') {
            return false;
        }
        return $selectorOwned($declaration['context']);
    }

    /**
     * @param array{ancestors:list<string>} $declaration
     */
    private static function keyframeNameOf(array $declaration): ?string
    {
        foreach (array_reverse($declaration['ancestors']) as $prelude) {
            if (preg_match('/^@(?:-[a-z]+-)?keyframes\s+(.+)$/i', trim($prelude), $match) !== 1) {
                continue;
            }
            $name = trim($match[1]);
            if (strlen($name) >= 2
                && (($name[0] === '"' && $name[strlen($name) - 1] === '"')
                    || ($name[0] === "'" && $name[strlen($name) - 1] === "'"))
            ) {
                $name = substr($name, 1, -1);
            }
            $name = self::decodeIdentifier($name);
            return $name === '' ? null : $name;
        }
        return null;
    }

    /** @return array{0:list<string>,1:bool} referenced names and whether the value is opaque */
    private static function animationReferences(string $value, bool $shorthand = false): array
    {
        $plain = trim(self::withoutComments($value));
        $withoutImportant = trim((string) preg_replace('/\s*!\s*important\s*$/i', '', $plain));
        $wide = strtolower(self::decodeIdentifier($withoutImportant));
        $opaque = in_array($wide, ['inherit', 'unset', 'revert', 'revert-layer'], true);
        $names = [];
        $length = strlen($value);

        for ($i = 0; $i < $length;) {
            $char = $value[$i];
            if ($char === '/' && ($value[$i + 1] ?? '') === '*') {
                $i = self::skipComment($value, $i, $length);
                continue;
            }
            if ($char === '"' || $char === "'") {
                $after = self::skipQuoted($value, $i, $length, $char);
                $insideEnd = $after <= $length && ($value[$after - 1] ?? '') === $char ? $after - 1 : $after;
                $names[] = self::decodeIdentifier(substr($value, $i + 1, max(0, $insideEnd - $i - 1)));
                $i = $after;
                continue;
            }
            if ($char === '\\' || preg_match('/[-_a-zA-Z]/', $char) === 1) {
                $after = self::identifierEnd($value, $i, $length);
                $name = self::decodeIdentifier(substr($value, $i, $after - $i));
                $lookahead = $after;
                while ($lookahead < $length && ctype_space($value[$lookahead])) {
                    ++$lookahead;
                }
                if (($value[$lookahead] ?? '') === '(') {
                    if (in_array(strtolower($name), ['var', 'env'], true)) {
                        $opaque = true;
                    }
                    $i = self::skipDelimited($value, $lookahead, $length, '(', ')');
                    continue;
                }
                if (!$shorthand || !self::isAnimationShorthandComponent($name)) {
                    $names[] = $name;
                }
                $i = $after;
                continue;
            }
            if (ctype_digit($char) || $char === '.') {
                // Keep a duration unit such as the `s` in `1s` out of the
                // custom-ident set, even if a keyframe happens to use that name.
                ++$i;
                while ($i < $length && preg_match('/[0-9a-zA-Z_.%+-]/', $value[$i]) === 1) {
                    ++$i;
                }
                continue;
            }
            ++$i;
        }

        return [array_values(array_unique(array_filter($names, static fn (string $name): bool => $name !== ''))), $opaque];
    }

    /** Whether an identifier is consumed by animation shorthand grammar rather than as its name. */
    private static function isAnimationShorthandComponent(string $identifier): bool
    {
        return in_array(strtolower($identifier), [
            'ease',
            'linear',
            'ease-in',
            'ease-out',
            'ease-in-out',
            'step-start',
            'step-end',
            'infinite',
            'normal',
            'reverse',
            'alternate',
            'alternate-reverse',
            'none',
            'forwards',
            'backwards',
            'both',
            'running',
            'paused',
            'auto',
        ], true) || preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:ms|s)$/i', $identifier) === 1;
    }

    private static function identifierEnd(string $css, int $start, int $end): int
    {
        $i = $start;
        while ($i < $end) {
            if ($css[$i] === '\\') {
                ++$i;
                $hex = 0;
                while ($i < $end && $hex < 6 && ctype_xdigit($css[$i])) {
                    ++$i;
                    ++$hex;
                }
                if ($hex > 0 && $i < $end && preg_match('/[ \t\r\n\f]/', $css[$i]) === 1) {
                    ++$i;
                } elseif ($hex === 0 && $i < $end) {
                    ++$i;
                }
                continue;
            }
            if (preg_match('/[-_a-zA-Z0-9]/', $css[$i]) !== 1) {
                break;
            }
            ++$i;
        }
        return $i;
    }

    /**
     * Recursive mixed rule/declaration scanner. Regions never overlap; matching
     * a block performs one bounded look-ahead, then its body is scanned once.
     *
     * @param 'rules'|'style'|'keyframes' $mode
     * @param 'style'|'keyframe'|'at-rule'|'declaration-list' $kind
     * @param list<string> $ancestors
     * @param list<array{property:string,value:string,raw:string,start:int,end:int,context:string,ancestors:list<string>,kind:string,structurallySafe:bool}> $declarations
     */
    private static function scanDeclarationRegion(
        string $css,
        int $start,
        int $end,
        string $mode,
        string $context,
        array $ancestors,
        string $kind,
        int $depth,
        bool $structurallySafe,
        array &$declarations,
    ): void {
        if ($depth > self::MAX_DECLARATION_SCAN_DEPTH || $start >= $end) {
            return;
        }

        $cursor = $start;
        $i = $start;
        while ($i < $end) {
            $char = $css[$i];
            if ($char === '/' && ($css[$i + 1] ?? '') === '*') {
                $i = self::skipComment($css, $i, $end);
                continue;
            }
            if ($char === '"' || $char === "'") {
                $i = self::skipQuoted($css, $i, $end, $char);
                continue;
            }
            if ($char === '\\') {
                $i = min($end, $i + 2);
                continue;
            }
            if ($char === '(' || $char === '[') {
                $i = self::skipDelimited($css, $i, $end, $char, $char === '(' ? ')' : ']');
                continue;
            }
            if ($char === ';') {
                self::appendDeclaration(
                    $css,
                    $cursor,
                    $i,
                    $i + 1,
                    $context,
                    $ancestors,
                    $kind,
                    $structurallySafe,
                    $declarations,
                );
                $cursor = ++$i;
                continue;
            }
            if ($char !== '{') {
                ++$i;
                continue;
            }

            $close = self::matchingBlockEnd($css, $i, $end);
            $implicitClose = $close === null;
            if ($implicitClose) {
                // Browsers recover an unclosed rule at EOF. Walk the recovered
                // body so an effective radius cannot hide behind malformed
                // generated CSS, but mark every row unsafe: callers must drop
                // the whole CSS field instead of source-editing this unit.
                $close = $end;
            }

            $prefix = substr($css, $cursor, $i - $cursor);
            $colon = self::topLevelColon($prefix);
            $property = $colon === null ? null : self::propertyName(substr($prefix, 0, $colon));
            if ($property !== null && str_starts_with($property, '--')) {
                // Custom properties may carry arbitrary balanced blocks and
                // semicolons. Keep the block inside this one declaration and
                // resume at its eventual outer semicolon.
                if ($implicitClose) {
                    return;
                }
                $i = $close + 1;
                continue;
            }

            // Anything before a rule block that is not a complete declaration
            // is its selector/at-rule prelude. A valid declaration immediately
            // before it would already have ended at a semicolon.
            $prelude = self::contextText($prefix);
            self::scanNestedBlock(
                $css,
                $i + 1,
                $close,
                $prelude,
                $mode,
                $context,
                $ancestors,
                $depth + 1,
                $structurallySafe && !$implicitClose,
                $declarations,
            );
            if ($implicitClose) {
                return;
            }
            $cursor = $close + 1;
            $i = $close + 1;
        }

        self::appendDeclaration(
            $css,
            $cursor,
            $end,
            $end,
            $context,
            $ancestors,
            $kind,
            $structurallySafe,
            $declarations,
        );
    }

    /**
     * @param 'rules'|'style'|'keyframes' $parentMode
     * @param list<string> $ancestors
     * @param list<array{property:string,value:string,raw:string,start:int,end:int,context:string,ancestors:list<string>,kind:string,structurallySafe:bool}> $declarations
     */
    private static function scanNestedBlock(
        string $css,
        int $start,
        int $end,
        string $prelude,
        string $parentMode,
        string $parentContext,
        array $ancestors,
        int $depth,
        bool $structurallySafe,
        array &$declarations,
    ): void {
        $lineage = $ancestors;
        if ($parentContext !== ''
            && $parentContext !== '<declaration-list>'
            && !in_array($parentContext, $lineage, true)
        ) {
            $lineage[] = $parentContext;
        }

        if ($parentMode === 'keyframes') {
            self::scanDeclarationRegion(
                $css,
                $start,
                $end,
                'style',
                $prelude,
                $ancestors,
                'keyframe',
                $depth,
                $structurallySafe,
                $declarations,
            );
            return;
        }

        $plainPrelude = strtolower(trim(self::withoutComments($prelude)));
        if (preg_match('/^@(?:-[a-z]+-)?keyframes\b/i', $plainPrelude) === 1) {
            $lineage[] = $prelude;
            self::scanDeclarationRegion(
                $css,
                $start,
                $end,
                'keyframes',
                '',
                $lineage,
                'keyframe',
                $depth,
                $structurallySafe,
                $declarations,
            );
            return;
        }

        if (str_starts_with($plainPrelude, '@')) {
            if (!in_array($prelude, $lineage, true)) {
                $lineage[] = $prelude;
            }
            $declarationAtRule = preg_match(
                '/^@(?:font-face|page|property|counter-style|font-palette-values)\b/i',
                $plainPrelude,
            ) === 1;
            $insideStyle = $parentMode === 'style';
            self::scanDeclarationRegion(
                $css,
                $start,
                $end,
                ($declarationAtRule || $insideStyle) ? 'style' : 'rules',
                $declarationAtRule ? $prelude : ($insideStyle ? $parentContext : ''),
                $lineage,
                $declarationAtRule ? 'at-rule' : ($insideStyle ? 'style' : 'at-rule'),
                $depth,
                $structurallySafe,
                $declarations,
            );
            return;
        }

        self::scanDeclarationRegion(
            $css,
            $start,
            $end,
            'style',
            $prelude,
            $lineage,
            'style',
            $depth,
            $structurallySafe,
            $declarations,
        );
    }

    /**
     * @param 'style'|'keyframe'|'at-rule'|'declaration-list' $kind
     * @param list<string> $ancestors
     * @param list<array{property:string,value:string,raw:string,start:int,end:int,context:string,ancestors:list<string>,kind:string,structurallySafe:bool}> $declarations
     */
    private static function appendDeclaration(
        string $css,
        int $segmentStart,
        int $valueEnd,
        int $declarationEnd,
        string $context,
        array $ancestors,
        string $kind,
        bool $structurallySafe,
        array &$declarations,
    ): void {
        if ($segmentStart >= $valueEnd) {
            return;
        }
        $segment = substr($css, $segmentStart, $valueEnd - $segmentStart);
        $colon = self::topLevelColon($segment);
        if ($colon === null) {
            return;
        }
        $property = self::propertyName(substr($segment, 0, $colon));
        if ($property === null) {
            return;
        }

        $propertyStart = self::skipTrivia($css, $segmentStart, $segmentStart + $colon);
        $raw = substr($css, $propertyStart, $valueEnd - $propertyStart);
        $declarations[] = [
            'property' => $property,
            'value' => trim(substr($segment, $colon + 1)),
            'raw' => rtrim($raw),
            'start' => $propertyStart,
            'end' => $declarationEnd,
            'context' => $context,
            'ancestors' => array_values(array_filter($ancestors, static fn (string $item): bool => $item !== '')),
            'kind' => $kind,
            'structurallySafe' => $structurallySafe,
        ];
    }

    /** First structural colon in a declaration-sized segment. */
    private static function topLevelColon(string $segment): ?int
    {
        $length = strlen($segment);
        $curly = 0;
        for ($i = 0; $i < $length;) {
            $char = $segment[$i];
            if ($char === '/' && ($segment[$i + 1] ?? '') === '*') {
                $i = self::skipComment($segment, $i, $length);
                continue;
            }
            if ($char === '"' || $char === "'") {
                $i = self::skipQuoted($segment, $i, $length, $char);
                continue;
            }
            if ($char === '\\') {
                $i = min($length, $i + 2);
                continue;
            }
            if ($char === '(' || $char === '[') {
                $i = self::skipDelimited($segment, $i, $length, $char, $char === '(' ? ')' : ']');
                continue;
            }
            if ($char === '{') {
                ++$curly;
            } elseif ($char === '}' && $curly > 0) {
                --$curly;
            } elseif ($char === ':' && $curly === 0) {
                return $i;
            }
            ++$i;
        }
        return null;
    }

    private static function propertyName(string $raw): ?string
    {
        $property = self::decodeIdentifier(trim(self::withoutComments($raw)));
        if (preg_match('/^(?:--[^\s:;{}]+|-?[_a-zA-Z][-_a-zA-Z0-9]*)$/D', $property) !== 1) {
            return null;
        }
        return strtolower($property);
    }

    /** Decode CSS identifier escapes without otherwise normalizing the token. */
    public static function decodeIdentifier(string $identifier): string
    {
        return (string) preg_replace_callback(
            '/\\\\(?:([0-9a-fA-F]{1,6})[ \t\r\n\f]?|([^\r\n\f]))/',
            static function (array $match): string {
                if (($match[1] ?? '') === '') {
                    return $match[2] ?? '';
                }
                $codepoint = hexdec($match[1]);
                if ($codepoint === 0 || $codepoint > 0x10FFFF || ($codepoint >= 0xD800 && $codepoint <= 0xDFFF)) {
                    return "\u{FFFD}";
                }
                return mb_chr($codepoint, 'UTF-8');
            },
            $identifier,
        );
    }

    private static function looksLikeDeclarationList(string $css): bool
    {
        $length = strlen($css);
        for ($i = 0; $i < $length;) {
            $char = $css[$i];
            if ($char === '/' && ($css[$i + 1] ?? '') === '*') {
                $i = self::skipComment($css, $i, $length);
                continue;
            }
            if ($char === '"' || $char === "'") {
                $i = self::skipQuoted($css, $i, $length, $char);
                continue;
            }
            if ($char === '\\') {
                $i = min($length, $i + 2);
                continue;
            }
            if ($char === '(' || $char === '[') {
                $i = self::skipDelimited($css, $i, $length, $char, $char === '(' ? ')' : ']');
                continue;
            }
            if ($char === '{') {
                $prefix = substr($css, 0, $i);
                $colon = self::topLevelColon($prefix);
                $property = $colon === null ? null : self::propertyName(substr($prefix, 0, $colon));
                return $property !== null && str_starts_with($property, '--');
            }
            ++$i;
        }
        return true;
    }

    private static function matchingBlockEnd(string $css, int $open, int $end): ?int
    {
        $depth = 1;
        for ($i = $open + 1; $i < $end;) {
            $char = $css[$i];
            if ($char === '/' && ($css[$i + 1] ?? '') === '*') {
                $i = self::skipComment($css, $i, $end);
                continue;
            }
            if ($char === '"' || $char === "'") {
                $i = self::skipQuoted($css, $i, $end, $char);
                continue;
            }
            if ($char === '\\') {
                $i = min($end, $i + 2);
                continue;
            }
            if ($char === '(' || $char === '[') {
                $i = self::skipDelimited($css, $i, $end, $char, $char === '(' ? ')' : ']');
                continue;
            }
            if ($char === '{') {
                ++$depth;
            } elseif ($char === '}' && --$depth === 0) {
                return $i;
            }
            ++$i;
        }
        return null;
    }

    private static function skipDelimited(
        string $css,
        int $open,
        int $end,
        string $opening,
        string $closing,
    ): int {
        $stack = [$closing];
        for ($i = $open + 1; $i < $end;) {
            $char = $css[$i];
            if ($char === '/' && ($css[$i + 1] ?? '') === '*') {
                $i = self::skipComment($css, $i, $end);
                continue;
            }
            if ($char === '"' || $char === "'") {
                $i = self::skipQuoted($css, $i, $end, $char);
                continue;
            }
            if ($char === '\\') {
                $i = min($end, $i + 2);
                continue;
            }
            if ($char === '(' || $char === '[' || $char === '{') {
                $stack[] = match ($char) {
                    '(' => ')',
                    '[' => ']',
                    '{' => '}',
                };
                ++$i;
                continue;
            }
            if ($char === $stack[count($stack) - 1]) {
                array_pop($stack);
                if ($stack === []) {
                    return $i + 1;
                }
            }
            ++$i;
        }
        return $end;
    }

    private static function skipQuoted(string $css, int $quote, int $end, string $delimiter): int
    {
        for ($i = $quote + 1; $i < $end;) {
            if ($css[$i] === '\\') {
                $i = min($end, $i + 2);
                continue;
            }
            if ($css[$i] === $delimiter) {
                return $i + 1;
            }
            ++$i;
        }
        return $end;
    }

    private static function skipComment(string $css, int $start, int $end): int
    {
        $close = strpos($css, '*/', $start + 2);
        return $close === false || $close >= $end ? $end : $close + 2;
    }

    private static function skipTrivia(string $css, int $start, int $end): int
    {
        $i = $start;
        while ($i < $end) {
            if (ctype_space($css[$i])) {
                ++$i;
                continue;
            }
            if ($css[$i] === '/' && ($css[$i + 1] ?? '') === '*') {
                $next = self::skipComment($css, $i, $end);
                if ($next >= $end) {
                    return $i;
                }
                $i = $next;
                continue;
            }
            break;
        }
        return $i;
    }

    private static function withoutComments(string $css): string
    {
        return (string) preg_replace('~/\*.*?\*/~s', '', $css);
    }

    private static function contextText(string $prelude): string
    {
        return trim((string) preg_replace('/\s+/', ' ', self::withoutComments($prelude)));
    }

    /** @return list<string> */
    private static function splitSelectorList(string $selector): array
    {
        $parts = [];
        $start = 0;
        $length = strlen($selector);
        for ($i = 0; $i < $length;) {
            $char = $selector[$i];
            if ($char === '/' && ($selector[$i + 1] ?? '') === '*') {
                $i = self::skipComment($selector, $i, $length);
                continue;
            }
            if ($char === '"' || $char === "'") {
                $i = self::skipQuoted($selector, $i, $length, $char);
                continue;
            }
            if ($char === '\\') {
                $i = min($length, $i + 2);
                continue;
            }
            if ($char === '(' || $char === '[') {
                $i = self::skipDelimited($selector, $i, $length, $char, $char === '(' ? ')' : ']');
                continue;
            }
            if ($char === ',') {
                $parts[] = trim(substr($selector, $start, $i - $start));
                $start = ++$i;
                continue;
            }
            ++$i;
        }
        $parts[] = trim(substr($selector, $start));
        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    private static function rightmostSelectorCompound(string $selector): string
    {
        $start = 0;
        $length = strlen($selector);
        for ($i = 0; $i < $length;) {
            $char = $selector[$i];
            if ($char === '/' && ($selector[$i + 1] ?? '') === '*') {
                $i = self::skipComment($selector, $i, $length);
                continue;
            }
            if ($char === '"' || $char === "'") {
                $i = self::skipQuoted($selector, $i, $length, $char);
                continue;
            }
            if ($char === '\\') {
                $i = min($length, $i + 2);
                continue;
            }
            if ($char === '(' || $char === '[') {
                $i = self::skipDelimited($selector, $i, $length, $char, $char === '(' ? ')' : ']');
                continue;
            }
            if ($char === '>' || $char === '+' || $char === '~'
                || ($char === '|' && ($selector[$i + 1] ?? '') === '|')
            ) {
                $i += $char === '|' ? 2 : 1;
                $start = $i;
                continue;
            }
            if (ctype_space($char)) {
                while ($i < $length && ctype_space($selector[$i])) {
                    ++$i;
                }
                $start = $i;
                continue;
            }
            ++$i;
        }
        return trim(substr($selector, $start));
    }

    private static function hasSubjectPseudoElement(string $compound): bool
    {
        $length = strlen($compound);
        for ($i = 0; $i < $length;) {
            $char = $compound[$i];
            if ($char === '/' && ($compound[$i + 1] ?? '') === '*') {
                $i = self::skipComment($compound, $i, $length);
                continue;
            }
            if ($char === '"' || $char === "'") {
                $i = self::skipQuoted($compound, $i, $length, $char);
                continue;
            }
            if ($char === '\\') {
                $i = min($length, $i + 2);
                continue;
            }
            if ($char === '[' || $char === '(') {
                $i = self::skipDelimited($compound, $i, $length, $char, $char === '(' ? ')' : ']');
                continue;
            }
            if ($char === ':' && ($compound[$i + 1] ?? '') === ':') {
                return true;
            }
            if ($char === ':' && preg_match('/\G:(?:before|after|first-line|first-letter)(?![-\w])/i', $compound, $match, 0, $i) === 1) {
                return true;
            }
            ++$i;
        }
        return false;
    }

    /**
     * @param null|callable(string):bool $subjectMatcher
     * @return array{0:string,1:bool,2:bool} selector with functional pseudos
     *         removed, matched :is/:where subject, whether one was present
     */
    private static function withoutFunctionalPseudos(string $compound, ?callable $subjectMatcher = null): array
    {
        $plain = '';
        $targetsShape = false;
        $hasPositiveSubjectPseudo = false;
        $length = strlen($compound);
        for ($i = 0; $i < $length;) {
            if ($compound[$i] === '/' && ($compound[$i + 1] ?? '') === '*') {
                $i = self::skipComment($compound, $i, $length);
                continue;
            }
            if ($compound[$i] !== ':') {
                $plain .= $compound[$i++];
                continue;
            }

            if (preg_match('/\G:([-\w]+)\s*\(/', $compound, $match, 0, $i) !== 1) {
                $plain .= $compound[$i++];
                continue;
            }
            $open = $i + strlen($match[0]) - 1;
            $after = self::skipDelimited($compound, $open, $length, '(', ')');
            if ($after <= $open || ($compound[$after - 1] ?? '') !== ')') {
                $plain .= substr($compound, $i);
                break;
            }
            $name = strtolower($match[1]);
            if (in_array($name, ['is', 'where'], true)) {
                $hasPositiveSubjectPseudo = true;
                $arguments = substr($compound, $open + 1, $after - $open - 2);
                $targetsShape = $targetsShape || ($subjectMatcher !== null
                    ? $subjectMatcher($arguments)
                    : self::selectorTargetsShape($arguments));
            }
            $i = $after;
        }
        return [$plain, $targetsShape, $hasPositiveSubjectPseudo];
    }

    private static function withoutSelectorAttributes(string $compound): string
    {
        $plain = '';
        $length = strlen($compound);
        for ($i = 0; $i < $length;) {
            if ($compound[$i] === '/' && ($compound[$i + 1] ?? '') === '*') {
                $i = self::skipComment($compound, $i, $length);
                continue;
            }
            if ($compound[$i] === '[') {
                $i = self::skipDelimited($compound, $i, $length, '[', ']');
                continue;
            }
            $plain .= $compound[$i++];
        }
        return $plain;
    }

    /**
     * One problem per at-rule used outside the allowlist.
     *
     * @param string[] $allowed lowercase at-rule names
     * @return string[]
     */
    public static function disallowedAtRules(string $css, array $allowed): array
    {
        $problems = [];
        if (preg_match_all('/@([a-zA-Z-]+)/', $css, $atRules) > 0) {
            foreach (array_unique($atRules[1]) as $at) {
                if (!in_array(strtolower($at), $allowed, true)) {
                    $problems[] = "disallowed at-rule: @{$at}";
                }
            }
        }
        return $problems;
    }

    /**
     * The style-rule selectors (split on commas) the predicate rejects.
     * @media preludes are dropped first so only rule selectors precede a '{';
     * the stray closing braces that leaves behind don't affect the match.
     *
     * @param callable(string): bool $isAllowed
     * @return string[]
     */
    public static function unscopedSelectors(string $css, callable $isAllowed): array
    {
        $unscoped = [];
        $rules = (string) preg_replace('/@media[^{]*\{/i', '', $css);
        if (preg_match_all('/(?:^|[{}])\s*([^{};]+?)\s*\{/s', $rules, $m) > 0) {
            foreach ($m[1] as $selectorList) {
                foreach (explode(',', $selectorList) as $selector) {
                    $selector = trim($selector);
                    if (!$isAllowed($selector)) {
                        $unscoped[] = $selector;
                    }
                }
            }
        }
        return $unscoped;
    }
}
