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
     * display:none / visibility:hidden anywhere in the CSS — generated
     * content must stay visible.
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
        return $problems;
    }

    /** Maximum nested CSS blocks inspected by the generated-CSS scanner. */
    private const MAX_DECLARATION_SCAN_DEPTH = 64;

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

    /** Decode the CSS identifier escapes needed to recognize owned properties. */
    private static function decodeIdentifier(string $identifier): string
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
