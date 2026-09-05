<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Deterministic design-floor detector. Pure: no I/O, no network, no LLM.
 *
 * Frozen contract: check() takes block markup plus a decoded theme.json array
 * and returns structured findings `{rule, detail, path}`. Callers format those
 * into warnings.json strings via warningRow(); nothing here rewrites markup.
 * A swallowed throw becomes a `scan-failed` finding naming the rule (or parse)
 * and the exception — never an empty "clean" result.
 *
 * @phpstan-type Finding array{rule: string, detail: string, path: string}
 */
final class DesignFloor
{
    public const RULE_NESTED_CARDS = 'nested-cards';
    public const RULE_GRADIENT_TEXT = 'gradient-text';
    public const RULE_JUSTIFIED_TEXT = 'justified-text';
    public const RULE_SIDE_TAB = 'side-tab';
    public const RULE_ALL_CAPS_BODY = 'all-caps-body';
    public const RULE_SKIPPED_HEADING = 'skipped-heading';
    public const RULE_KICKER_ABOVE_HEADING = 'kicker-above-heading';
    public const RULE_TINY_TEXT = 'tiny-text';
    public const RULE_WIDE_TRACKING = 'wide-tracking';
    public const RULE_TIGHT_LEADING = 'tight-leading';
    public const RULE_FLAT_TYPE_HIERARCHY = 'flat-type-hierarchy';
    public const RULE_SCAN_FAILED = 'scan-failed';

    /** @var list<string> */
    public const MARKUP_RULES = [
        self::RULE_NESTED_CARDS,
        self::RULE_GRADIENT_TEXT,
        self::RULE_JUSTIFIED_TEXT,
        self::RULE_SIDE_TAB,
        self::RULE_ALL_CAPS_BODY,
        self::RULE_SKIPPED_HEADING,
        self::RULE_KICKER_ABOVE_HEADING,
    ];

    /** @var list<string> */
    public const THEME_RULES = [
        self::RULE_TINY_TEXT,
        self::RULE_WIDE_TRACKING,
        self::RULE_TIGHT_LEADING,
        self::RULE_FLAT_TYPE_HIERARCHY,
    ];

    /** Outer image-card treatment markers from prompts/section.md. */
    private const CARD_MARKERS = [
        'card-style--flush',
        'card-style--framed',
        'card-style--overlap',
        'card-style--borderless',
    ];

    private const BODY_BLOCKS = ['paragraph', 'list', 'list-item', 'quote', 'pullquote'];
    private const CAPTION_SLUGS = ['caption', 'small', 'x-small', 'tiny'];
    private const BODY_BLOCK_KEYS = ['core/paragraph', 'core/list'];
    private const TINY_REM = 0.75;
    private const WIDE_TRACKING_EM = 0.08;
    private const TIGHT_LEADING = 1.3;
    private const FLAT_RATIO = 2.0;
    private const BODY_CAPS_MIN_CHARS = 80;
    private const KICKER_MAX_CHARS = 90;
    private const ROOT_PX = 16.0;

    /**
     * @param array<string, mixed> $themeJson decoded theme.json
     * @param null|callable(string):BlockMarkup $parser test seam; production omits it
     * @param string|null $faultRule test seam: throw inside that markup rule before it runs
     * @return list<Finding>
     */
    public static function check(
        string $markup,
        array $themeJson,
        ?callable $parser = null,
        ?string $faultRule = null,
    ): array {
        $findings = [];
        if (trim($markup) !== '') {
            $document = null;
            try {
                $parsed = $parser === null ? BlockMarkup::parse($markup) : $parser($markup);
                if (!$parsed instanceof BlockMarkup) {
                    throw new \RuntimeException('parser returned no block document');
                }
                $document = $parsed;
            } catch (\Throwable $error) {
                $findings[] = self::scanFailed('parse', $error);
            }
            if ($document instanceof BlockMarkup) {
                foreach (self::markupRunners() as $rule => $runner) {
                    try {
                        if ($faultRule === $rule) {
                            throw new \RuntimeException('injected DesignFloor fault for ' . $rule);
                        }
                        array_push($findings, ...$runner($document));
                    } catch (\Throwable $error) {
                        $findings[] = self::scanFailed($rule, $error);
                    }
                }
            }
        }

        foreach (self::themeRunners() as $rule => $runner) {
            try {
                array_push($findings, ...$runner($themeJson));
            } catch (\Throwable $error) {
                $findings[] = self::scanFailed($rule, $error);
            }
        }

        return $findings;
    }

    /**
     * Map one finding onto this repo's warnings.json string shape.
     *
     * @param Finding $finding
     */
    public static function warningRow(string $file, array $finding): string
    {
        return 'design-floor: file=' . $file
            . '; rule=' . $finding['rule']
            . '; path=' . $finding['path']
            . '; authored=' . Warnings::value($finding['detail'])
            . '; delivered=unchanged'
            . '; disposition=reported, not repaired';
    }

    /**
     * @return array<string, callable(BlockMarkup):list<Finding>>
     */
    private static function markupRunners(): array
    {
        return [
            self::RULE_NESTED_CARDS => static fn (BlockMarkup $document): array => self::nestedCards($document),
            self::RULE_GRADIENT_TEXT => static fn (BlockMarkup $document): array => self::gradientText($document),
            self::RULE_JUSTIFIED_TEXT => static fn (BlockMarkup $document): array => self::justifiedText($document),
            self::RULE_SIDE_TAB => static fn (BlockMarkup $document): array => self::sideTab($document),
            self::RULE_ALL_CAPS_BODY => static fn (BlockMarkup $document): array => self::allCapsBody($document),
            self::RULE_SKIPPED_HEADING => static fn (BlockMarkup $document): array => self::skippedHeading($document),
            self::RULE_KICKER_ABOVE_HEADING => static fn (BlockMarkup $document): array => self::kickerAboveHeading($document),
        ];
    }

    /**
     * @return array<string, callable(array<string, mixed>):list<Finding>>
     */
    private static function themeRunners(): array
    {
        return [
            self::RULE_TINY_TEXT => static fn (array $themeJson): array => self::tinyText($themeJson),
            self::RULE_WIDE_TRACKING => static fn (array $themeJson): array => self::wideTracking($themeJson),
            self::RULE_TIGHT_LEADING => static fn (array $themeJson): array => self::tightLeading($themeJson),
            self::RULE_FLAT_TYPE_HIERARCHY => static fn (array $themeJson): array => self::flatTypeHierarchy($themeJson),
        ];
    }

    /** @return Finding */
    private static function scanFailed(string $target, \Throwable $error): array
    {
        $message = str_replace(["\r", "\n"], ' ', $error->getMessage());
        return self::finding(
            self::RULE_SCAN_FAILED,
            $target . ' threw ' . $error::class . ': ' . $message,
            $target,
        );
    }

    /** @return list<Finding> */
    private static function nestedCards(BlockMarkup $document): array
    {
        $findings = [];
        foreach ($document->indices() as $index) {
            if ($document->name($index) !== 'group' || !self::isCard($document, $index)) {
                continue;
            }
            $ancestor = $document->parent($index);
            while ($ancestor !== null) {
                if ($document->name($ancestor) === 'group' && self::isCard($document, $ancestor)) {
                    $findings[] = self::finding(
                        self::RULE_NESTED_CARDS,
                        'card wrapper nested inside another card wrapper',
                        self::blockPath($document, $index),
                    );
                    break;
                }
                $ancestor = $document->parent($ancestor);
            }
        }
        return $findings;
    }

    /** @return list<Finding> */
    private static function gradientText(BlockMarkup $document): array
    {
        $findings = [];
        foreach ($document->indices() as $index) {
            $inline = self::inlineDeclarations($document, $index);
            if (!self::hasTextClip($inline)) {
                continue;
            }
            if (!self::hasGradient($document, $index, $inline)) {
                continue;
            }
            $findings[] = self::finding(
                self::RULE_GRADIENT_TEXT,
                'background-clip:text combined with a gradient background',
                self::blockPath($document, $index),
            );
        }
        return $findings;
    }

    /** @return list<Finding> */
    private static function justifiedText(BlockMarkup $document): array
    {
        $findings = [];
        foreach ($document->indices() as $index) {
            if (!in_array($document->name($index), self::BODY_BLOCKS, true)) {
                continue;
            }
            if (!self::isJustified($document, $index)) {
                continue;
            }
            $findings[] = self::finding(
                self::RULE_JUSTIFIED_TEXT,
                'text-align:justify on body copy',
                self::blockPath($document, $index),
            );
        }
        return $findings;
    }

    /** @return list<Finding> */
    private static function sideTab(BlockMarkup $document): array
    {
        $findings = [];
        foreach ($document->indices() as $index) {
            if (!self::isSideTabSurface($document, $index)) {
                continue;
            }
            $widths = self::borderWidths($document, $index);
            if (!self::isSideTabBorder($widths)) {
                continue;
            }
            $findings[] = self::finding(
                self::RULE_SIDE_TAB,
                'coloured left/right border wider than 1px on a card, list item, callout or alert',
                self::blockPath($document, $index),
            );
        }
        return $findings;
    }

    /** @return list<Finding> */
    private static function allCapsBody(BlockMarkup $document): array
    {
        $findings = [];
        foreach ($document->indices() as $index) {
            $name = $document->name($index);
            if (!in_array($name, ['paragraph', 'list-item', 'list'], true)) {
                continue;
            }
            if ($name === 'list') {
                continue;
            }
            if (!self::isUppercase($document, $index)) {
                continue;
            }
            $attrs = $document->attrs($index) ?? [];
            if (self::isCaptionSlug($attrs['fontSize'] ?? null)) {
                continue;
            }
            $text = self::readingText($document->innerHtml($index));
            if (mb_strlen($text, 'UTF-8') < self::BODY_CAPS_MIN_CHARS) {
                continue;
            }
            $findings[] = self::finding(
                self::RULE_ALL_CAPS_BODY,
                'text-transform:uppercase on a run of body copy',
                self::blockPath($document, $index),
            );
        }
        return $findings;
    }

    /** @return list<Finding> */
    private static function skippedHeading(BlockMarkup $document): array
    {
        $findings = [];
        $previous = null;
        foreach ($document->indices() as $index) {
            if ($document->name($index) !== 'heading') {
                continue;
            }
            $level = self::headingLevel($document, $index);
            if ($level === null) {
                continue;
            }
            if ($previous !== null && $level > $previous['level'] + 1) {
                $findings[] = self::finding(
                    self::RULE_SKIPPED_HEADING,
                    'heading level jump h' . $previous['level'] . ' -> h' . $level,
                    self::blockPath($document, $index),
                );
            }
            $previous = ['level' => $level, 'index' => $index];
        }
        return $findings;
    }

    /** @return list<Finding> */
    private static function kickerAboveHeading(BlockMarkup $document): array
    {
        $findings = [];
        foreach ($document->indices() as $index) {
            if ($document->name($index) !== 'heading') {
                continue;
            }
            $level = self::headingLevel($document, $index);
            if ($level === null) {
                continue;
            }
            $kicker = self::previousSibling($document, $index);
            if ($kicker === null || !self::isKickerCandidate($document, $kicker)) {
                continue;
            }
            $findings[] = self::finding(
                self::RULE_KICKER_ABOVE_HEADING,
                'kicker or eyebrow above a heading',
                self::blockPath($document, $kicker),
            );
        }
        return $findings;
    }

    /** @param array<string, mixed> $themeJson @return list<Finding> */
    private static function tinyText(array $themeJson): array
    {
        $sizes = self::namedSizesRem($themeJson);
        if ($sizes === []) {
            return [];
        }
        $findings = [];
        foreach (self::bodySizeUses($themeJson, $sizes) as $path => $rem) {
            if ($rem >= self::TINY_REM) {
                continue;
            }
            $findings[] = self::finding(
                self::RULE_TINY_TEXT,
                'named font size ' . self::formatRem($rem) . 'rem used for body (floor 0.75rem)',
                $path,
            );
        }
        return $findings;
    }

    /** @param array<string, mixed> $themeJson @return list<Finding> */
    private static function wideTracking(array $themeJson): array
    {
        $findings = [];
        foreach (self::bodyTypographyLeaves($themeJson) as $path => $typography) {
            $spacing = $typography['letterSpacing'] ?? null;
            $em = self::trackingToEm($spacing);
            if ($em === null || $em <= self::WIDE_TRACKING_EM) {
                continue;
            }
            $findings[] = self::finding(
                self::RULE_WIDE_TRACKING,
                'letter-spacing ' . (is_string($spacing) ? $spacing : Warnings::value($spacing))
                    . ' on body-size text (floor ~0.08em)',
                $path . '.letterSpacing',
            );
        }
        return $findings;
    }

    /** @param array<string, mixed> $themeJson @return list<Finding> */
    private static function tightLeading(array $themeJson): array
    {
        $findings = [];
        foreach (self::bodyTypographyLeaves($themeJson) as $path => $typography) {
            $leading = $typography['lineHeight'] ?? null;
            $ratio = self::lineHeightRatio($leading);
            if ($ratio === null || $ratio >= self::TIGHT_LEADING) {
                continue;
            }
            $findings[] = self::finding(
                self::RULE_TIGHT_LEADING,
                'body line-height ' . (is_scalar($leading) ? (string) $leading : Warnings::value($leading))
                    . ' (floor 1.3)',
                $path . '.lineHeight',
            );
        }
        return $findings;
    }

    /** @param array<string, mixed> $themeJson @return list<Finding> */
    private static function flatTypeHierarchy(array $themeJson): array
    {
        $sizes = self::namedSizesRem($themeJson);
        if (count($sizes) < 2) {
            return [];
        }
        $values = array_values($sizes);
        $largest = max($values);
        $smallest = min($values);
        if ($smallest <= 0.0) {
            return [];
        }
        $ratio = $largest / $smallest;
        if ($ratio >= self::FLAT_RATIO) {
            return [];
        }
        return [self::finding(
            self::RULE_FLAT_TYPE_HIERARCHY,
            'largest named font size / smallest = ' . sprintf('%.3f', $ratio) . ' (floor 2.0)',
            'settings.typography.fontSizes',
        )];
    }

    private static function isCard(BlockMarkup $document, int $index): bool
    {
        foreach (self::classTokens($document, $index) as $token) {
            if (in_array($token, self::CARD_MARKERS, true)) {
                return true;
            }
        }
        return false;
    }

    private static function isSideTabSurface(BlockMarkup $document, int $index): bool
    {
        $name = $document->name($index);
        if ($name === 'list-item') {
            return true;
        }
        if ($name === 'group' && self::isCard($document, $index)) {
            return true;
        }
        if ($name !== 'group') {
            return false;
        }
        foreach (self::classTokens($document, $index) as $token) {
            if ($token === 'callout' || $token === 'alert'
                || str_ends_with($token, '--callout')
                || str_ends_with($token, '--alert')
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Thick left XOR right accent, not a full box.
     *
     * @param array{top:?float,right:?float,bottom:?float,left:?float} $widths
     */
    private static function isSideTabBorder(array $widths): bool
    {
        $left = $widths['left'] ?? 0.0;
        $right = $widths['right'] ?? 0.0;
        $top = $widths['top'] ?? 0.0;
        $bottom = $widths['bottom'] ?? 0.0;
        $side = max($left, $right);
        if ($side <= 1.0) {
            return false;
        }
        $vertical = max($top, $bottom);
        if ($left > 1.0 && $right > 1.0 && abs($left - $right) < 0.01 && $vertical >= $side - 0.01) {
            return false;
        }
        return $side > $vertical + 0.01 || ($vertical <= 1.0 && ($left > 1.0 xor $right > 1.0));
    }

    /**
     * @return array{top:?float,right:?float,bottom:?float,left:?float}
     */
    private static function borderWidths(BlockMarkup $document, int $index): array
    {
        $sides = ['top' => null, 'right' => null, 'bottom' => null, 'left' => null];
        $attrs = $document->attrs($index) ?? [];
        $border = is_array($attrs['style'] ?? null) ? ($attrs['style']['border'] ?? null) : null;
        if (is_array($border)) {
            foreach (array_keys($sides) as $side) {
                $fromSide = is_array($border[$side] ?? null) ? ($border[$side]['width'] ?? null) : null;
                $fromMap = is_array($border['width'] ?? null) ? ($border['width'][$side] ?? null) : null;
                $sides[$side] = self::cssPx($fromSide) ?? self::cssPx($fromMap);
            }
            if (is_string($border['width'] ?? null)) {
                $all = self::cssPx($border['width']);
                foreach (array_keys($sides) as $side) {
                    $sides[$side] ??= $all;
                }
            }
        }
        foreach (self::inlineDeclarations($document, $index) as $decl) {
            $property = $decl['property'];
            $value = $decl['value'];
            if ($value === null) {
                continue;
            }
            if ($property === 'border-left-width') {
                $sides['left'] = self::cssPx($value) ?? $sides['left'];
            } elseif ($property === 'border-right-width') {
                $sides['right'] = self::cssPx($value) ?? $sides['right'];
            } elseif ($property === 'border-top-width') {
                $sides['top'] = self::cssPx($value) ?? $sides['top'];
            } elseif ($property === 'border-bottom-width') {
                $sides['bottom'] = self::cssPx($value) ?? $sides['bottom'];
            } elseif ($property === 'border-left' || $property === 'border-right'
                || $property === 'border-top' || $property === 'border-bottom'
            ) {
                $px = self::firstPxIn($value);
                $side = substr($property, 7);
                $sides[$side] = $px ?? $sides[$side];
            }
        }
        return $sides;
    }

    /** @param list<array{property:string, value:?string, segment:string}> $inline */
    private static function hasTextClip(array $inline): bool
    {
        foreach ($inline as $decl) {
            if ($decl['value'] === null) {
                continue;
            }
            $property = str_replace('-webkit-', '', $decl['property']);
            if ($property !== 'background-clip') {
                continue;
            }
            if (preg_match('/\btext\b/i', $decl['value']) === 1) {
                return true;
            }
        }
        return false;
    }

    /** @param list<array{property:string, value:?string, segment:string}> $inline */
    private static function hasGradient(BlockMarkup $document, int $index, array $inline): bool
    {
        $attrs = $document->attrs($index) ?? [];
        $style = is_array($attrs['style'] ?? null) ? $attrs['style'] : [];
        $color = is_array($style['color'] ?? null) ? $style['color'] : [];
        foreach (['gradient', 'background'] as $key) {
            $value = $color[$key] ?? null;
            if (is_string($value) && self::valueHasGradient($value)) {
                return true;
            }
        }
        if (is_string($attrs['gradient'] ?? null) && $attrs['gradient'] !== '') {
            return true;
        }
        foreach (self::classTokens($document, $index) as $token) {
            if (str_contains($token, '-gradient-background') || str_ends_with($token, '-gradient')) {
                return true;
            }
        }
        foreach ($inline as $decl) {
            if ($decl['value'] !== null && self::valueHasGradient($decl['value'])) {
                return true;
            }
        }
        return false;
    }

    private static function valueHasGradient(string $value): bool
    {
        return preg_match('/(?:repeating-)?(?:linear|radial|conic)-gradient\s*\(/i', $value) === 1;
    }

    private static function isJustified(BlockMarkup $document, int $index): bool
    {
        $attrs = $document->attrs($index) ?? [];
        if (($attrs['align'] ?? null) === 'justify') {
            return true;
        }
        $typography = is_array($attrs['style']['typography'] ?? null)
            ? $attrs['style']['typography']
            : [];
        if (($typography['textAlign'] ?? null) === 'justify') {
            return true;
        }
        foreach (self::classTokens($document, $index) as $token) {
            if ($token === 'has-text-align-justify') {
                return true;
            }
        }
        foreach (self::inlineDeclarations($document, $index) as $decl) {
            if ($decl['property'] === 'text-align'
                && is_string($decl['value'])
                && preg_match('/^\s*justify(?:-all)?\s*$/i', $decl['value']) === 1
            ) {
                return true;
            }
        }
        return false;
    }

    private static function isUppercase(BlockMarkup $document, int $index): bool
    {
        $attrs = $document->attrs($index) ?? [];
        $typography = is_array($attrs['style']['typography'] ?? null)
            ? $attrs['style']['typography']
            : [];
        $transform = $typography['textTransform'] ?? null;
        if (is_string($transform) && strtolower($transform) === 'uppercase') {
            return true;
        }
        foreach (self::inlineDeclarations($document, $index) as $decl) {
            if ($decl['property'] === 'text-transform'
                && is_string($decl['value'])
                && preg_match('/^\s*uppercase\s*$/i', $decl['value']) === 1
            ) {
                return true;
            }
        }
        return false;
    }

    private static function isKickerCandidate(BlockMarkup $document, int $index): bool
    {
        $name = $document->name($index);
        if (!in_array($name, ['paragraph', 'heading'], true)) {
            return false;
        }
        $attrs = $document->attrs($index) ?? [];
        if ($name === 'heading') {
            $level = self::headingLevel($document, $index);
            if ($level === null || $level < 4) {
                return false;
            }
        }
        // The committed section badge (frm W6a) is the one label form the
        // build paints on purpose; the delivery boundary already removed any
        // badge the direction did not commit, so this class is never a
        // freelance eyebrow by the time the floor reads it.
        if ($name === 'paragraph'
            && is_string($attrs['className'] ?? null)
            && in_array(\Automattic\SiteBuild\SectionLabel::BADGE_CLASS, preg_split('/\s+/', trim($attrs['className'])) ?: [], true)) {
            return false;
        }
        $text = self::readingText($document->innerHtml($index));
        if ($text === '' || mb_strlen($text, 'UTF-8') > self::KICKER_MAX_CHARS) {
            return false;
        }
        $typography = is_array($attrs['style']['typography'] ?? null)
            ? $attrs['style']['typography']
            : [];
        $fontSize = $attrs['fontSize'] ?? '';
        $textTransform = is_string($typography['textTransform'] ?? null)
            ? $typography['textTransform']
            : '';
        $letterSpacing = is_string($typography['letterSpacing'] ?? null)
            ? $typography['letterSpacing']
            : '';
        if ($name === 'heading') {
            return true;
        }
        return self::isCaptionSlug($fontSize)
            || strtolower($textTransform) === 'uppercase'
            || trim($letterSpacing) !== ''
            || self::inlineHasUppercaseOrTracking($document, $index);
    }

    private static function inlineHasUppercaseOrTracking(BlockMarkup $document, int $index): bool
    {
        foreach (self::inlineDeclarations($document, $index) as $decl) {
            if ($decl['value'] === null) {
                continue;
            }
            if ($decl['property'] === 'text-transform'
                && preg_match('/uppercase/i', $decl['value']) === 1
            ) {
                return true;
            }
            if ($decl['property'] === 'letter-spacing' && trim($decl['value']) !== '') {
                return true;
            }
        }
        return false;
    }

    private static function headingLevel(BlockMarkup $document, int $index): ?int
    {
        $level = ($document->attrs($index) ?? [])['level'] ?? 2;
        if (is_int($level) && $level >= 1 && $level <= 6) {
            return $level;
        }
        if (is_float($level) && $level == (int) $level && $level >= 1 && $level <= 6) {
            return (int) $level;
        }
        if (is_string($level) && preg_match('/^[1-6]$/', $level) === 1) {
            return (int) $level;
        }
        return null;
    }

    private static function previousSibling(BlockMarkup $document, int $index): ?int
    {
        $parent = $document->parent($index);
        $siblings = $parent === null
            ? array_values(array_filter(
                $document->indices(),
                static fn (int $candidate): bool => $document->parent($candidate) === null,
            ))
            : $document->children($parent);
        $position = array_search($index, $siblings, true);
        if ($position === false || $position === 0) {
            return null;
        }
        return $siblings[$position - 1];
    }

    private static function isCaptionSlug(mixed $slug): bool
    {
        return is_string($slug) && in_array($slug, self::CAPTION_SLUGS, true);
    }

    /**
     * @return array<string, float> slug => rem (clamp judged at max end)
     */
    private static function namedSizesRem(array $themeJson): array
    {
        $sizes = [];
        $entries = $themeJson['settings']['typography']['fontSizes'] ?? null;
        if (!is_array($entries)) {
            return [];
        }
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_string($entry['slug'] ?? null) || !is_string($entry['size'] ?? null)) {
                continue;
            }
            $rem = self::sizeToRem($entry['size']);
            if ($rem === null) {
                continue;
            }
            $sizes[$entry['slug']] = $rem;
        }
        return $sizes;
    }

    /**
     * @param array<string, float> $sizes
     * @return array<string, float> path => rem
     */
    private static function bodySizeUses(array $themeJson, array $sizes): array
    {
        $used = [];
        if (isset($sizes['body'])) {
            $used['settings.typography.fontSizes[body]'] = $sizes['body'];
        }
        $root = $themeJson['styles']['typography']['fontSize'] ?? null;
        $rootRem = self::resolveFontSize($root, $sizes);
        if ($rootRem !== null) {
            $used['styles.typography.fontSize'] = $rootRem;
        }
        $blocks = is_array($themeJson['styles']['blocks'] ?? null) ? $themeJson['styles']['blocks'] : [];
        foreach (self::BODY_BLOCK_KEYS as $block) {
            $fontSize = $blocks[$block]['typography']['fontSize'] ?? null;
            $rem = self::resolveFontSize($fontSize, $sizes);
            if ($rem !== null) {
                $used["styles.blocks.{$block}.typography.fontSize"] = $rem;
            }
        }
        return $used;
    }

    /**
     * @return array<string, array<string, mixed>> path => typography object
     */
    private static function bodyTypographyLeaves(array $themeJson): array
    {
        $leaves = [];
        $root = $themeJson['styles']['typography'] ?? null;
        if (is_array($root)) {
            $leaves['styles.typography'] = $root;
        }
        $blocks = is_array($themeJson['styles']['blocks'] ?? null) ? $themeJson['styles']['blocks'] : [];
        foreach (self::BODY_BLOCK_KEYS as $block) {
            $typography = $blocks[$block]['typography'] ?? null;
            if (is_array($typography)) {
                $leaves["styles.blocks.{$block}.typography"] = $typography;
            }
        }
        return $leaves;
    }

    /** @param array<string, float> $sizes */
    private static function resolveFontSize(mixed $value, array $sizes): ?float
    {
        if (is_string($value) && isset($sizes[$value])) {
            return $sizes[$value];
        }
        $slug = self::fontSizeSlug($value);
        if ($slug !== null && isset($sizes[$slug])) {
            return $sizes[$slug];
        }
        if (is_string($value)) {
            return self::sizeToRem($value);
        }
        return null;
    }

    private static function fontSizeSlug(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        if (preg_match('/^var:preset\|font-size\|([a-z0-9_-]+)$/', $value, $match) === 1) {
            return $match[1];
        }
        if (preg_match('/^var\(\s*--wp--preset--font-size--([a-z0-9_-]+)\s*\)$/', $value, $match) === 1) {
            return $match[1];
        }
        return null;
    }

    private static function sizeToRem(string $size): ?float
    {
        if (preg_match_all('/([0-9.]+)\s*(rem|em|px)/i', $size, $matches, PREG_SET_ORDER) !== false
            && $matches !== []
        ) {
            $rems = array_map(
                static fn (array $token): float => strtolower($token[2]) === 'px'
                    ? (float) $token[1] / self::ROOT_PX
                    : (float) $token[1],
                $matches,
            );
            return max($rems);
        }
        return null;
    }

    private static function trackingToEm(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^([0-9.]+)\s*em$/i', $value, $match) === 1) {
            return (float) $match[1];
        }
        if (preg_match('/^([0-9.]+)\s*rem$/i', $value, $match) === 1) {
            return (float) $match[1];
        }
        if (preg_match('/^([0-9.]+)\s*px$/i', $value, $match) === 1) {
            return (float) $match[1] / self::ROOT_PX;
        }
        if (preg_match('/^[0-9.]+$/', $value) === 1) {
            return (float) $value;
        }
        return null;
    }

    private static function lineHeightRatio(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^([0-9.]+)\s*%$/', $value, $match) === 1) {
            return (float) $match[1] / 100.0;
        }
        if (preg_match('/^([0-9.]+)\s*(?:em|rem)?$/i', $value, $match) === 1) {
            return (float) $match[1];
        }
        if (preg_match('/^([0-9.]+)\s*px$/i', $value, $match) === 1) {
            return (float) $match[1] / self::ROOT_PX;
        }
        return null;
    }

    private static function cssPx(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        return self::firstPxIn($value);
    }

    private static function firstPxIn(string $value): ?float
    {
        if (preg_match('/([0-9.]+)\s*px/i', $value, $match) === 1) {
            return (float) $match[1];
        }
        if (preg_match('/([0-9.]+)\s*rem/i', $value, $match) === 1) {
            return (float) $match[1] * self::ROOT_PX;
        }
        if (preg_match('/^([0-9.]+)$/', trim($value), $match) === 1) {
            return (float) $match[1];
        }
        return null;
    }

    /** @return list<string> */
    private static function classTokens(BlockMarkup $document, int $index): array
    {
        $tokens = [];
        $className = ($document->attrs($index) ?? [])['className'] ?? null;
        if (is_string($className) && $className !== '') {
            foreach (preg_split('/\s+/', trim($className)) ?: [] as $token) {
                if ($token !== '') {
                    $tokens[] = $token;
                }
            }
        }
        $own = $document->ownHtml($index);
        if ($own !== '' && preg_match('/\A\s*<[a-zA-Z][^>]*>/', $own, $match) === 1) {
            $attr = MarkupScan::tagAttribute($match[0], 'class');
            if ($attr !== null) {
                foreach (preg_split('/\s+/', trim($attr[0])) ?: [] as $token) {
                    if ($token !== '') {
                        $tokens[] = $token;
                    }
                }
            }
        }
        return array_values(array_unique($tokens));
    }

    /**
     * @return list<array{property:string, value:?string, segment:string}>
     */
    private static function inlineDeclarations(BlockMarkup $document, int $index): array
    {
        $own = $document->ownHtml($index);
        if ($own === '' || preg_match('/\A\s*<[a-zA-Z][^>]*>/', $own, $match) !== 1) {
            return [];
        }
        $attr = MarkupScan::tagAttribute($match[0], 'style');
        if ($attr === null) {
            return [];
        }
        return MarkupScan::parseInlineStyle(html_entity_decode($attr[0], ENT_QUOTES));
    }

    private static function readingText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private static function blockPath(BlockMarkup $document, int $index): string
    {
        $segments = [];
        $cursor = $index;
        while (true) {
            $parent = $document->parent($cursor);
            $siblings = $parent === null
                ? array_values(array_filter(
                    $document->indices(),
                    static fn (int $candidate): bool => $document->parent($candidate) === null,
                ))
                : $document->children($parent);
            $ordinal = 0;
            foreach ($siblings as $sibling) {
                if ($document->name($sibling) !== $document->name($cursor)) {
                    continue;
                }
                if ($sibling === $cursor) {
                    break;
                }
                $ordinal++;
            }
            array_unshift($segments, 'wp:' . $document->name($cursor) . '[' . $ordinal . ']');
            if ($parent === null) {
                break;
            }
            $cursor = $parent;
        }
        return implode('/', $segments);
    }

    /** @return Finding */
    private static function finding(string $rule, string $detail, string $path): array
    {
        return ['rule' => $rule, 'detail' => $detail, 'path' => $path];
    }

    private static function formatRem(float $rem): string
    {
        $formatted = rtrim(rtrim(sprintf('%.4f', $rem), '0'), '.');
        return $formatted === '' ? '0' : $formatted;
    }
}
