<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Page-owned outer spacing for generated landing-page sections.
 *
 * Section markup is authored independently, so allowing every section model to
 * choose its own outer padding produces both oversized seams (two generous
 * edges on the same surface) and missing edges. This class makes that decision
 * once, with the ordered page plan in hand. It edits the block-comment
 * attributes on each section's single top-level wp:group (and its direct cover
 * for an image band) and mirrors the owned vertical declarations into that
 * element's inline style attribute, so the block fixer's re-serialization only
 * ever ADDS spacing CSS — a model-authored padding left orphaned in the HTML
 * would otherwise be reported as fatally dropped vertical-rhythm CSS.
 *
 * A section always owns its top edge. Consecutive exact solid surfaces (base or
 * contrast) share one seam: the current section's bottom edge is zero and the
 * following section owns the gap. Tinted gradients and image assets are treated
 * as distinct even when their plan labels match. Image-section density is put
 * on the direct cover inside the required root group, so it remains inside the
 * image band instead of opening page-background gutters around it. An image
 * section whose root does not carry exactly one editable direct cover is
 * degraded to the solid-band treatment (with a note) rather than failing the
 * build: rejecting a finished theme here would discard every LLM call already
 * spent on one section model's markup mistake.
 */
final class SectionRhythm
{
    /** Density names are page-plan vocabulary; values are theme spacing slugs. */
    public const DENSITY_PRESETS = [
        'compact'  => 'lg',
        'standard' => 'xl',
        'spacious' => 'xxl',
    ];

    /** Only these plan labels guarantee one identical continuous surface. */
    private const COLLAPSIBLE_SURFACES = ['base', 'contrast'];

    /** Utilities whose vertical effect conflicts with page-owned wrapper margins. */
    private const FORBIDDEN_OWNED_WRAPPER_CLASSES = ['overlap-up'];

    /** Inline spacing spellings always superseded by the owned declarations. */
    private const SUPERSEDED_WRAPPER_PROPERTIES = [
        'padding',
        'margin',
        'padding-block',
        'padding-block-start',
        'padding-block-end',
        'margin-block',
        'margin-block-start',
        'margin-block-end',
    ];

    private const BACKGROUNDS = ['base', 'tinted', 'contrast', 'image'];

    /**
     * Rewrite an ordered page's section parts.
     *
     * Entries MUST be in display order because adjacency determines the bottom
     * edge. `slug` is optional and is used only to make notes/errors useful.
     * Every section must have one well-formed top-level wp:group. Missing or
     * invalid plan data fails the build instead of silently keeping model-owned
     * spacing.
     *
     * `$followingBackground` is the footer's inspected surface, when known, so
     * a last section on that same solid surface does not keep a duplicate edge.
     *
     * @param list<array{markup:string,density:string,background:string,slug?:string}> $entries
     * @return array{markups:list<string>,notes:list<string>}
     */
    public static function rewrite(array $entries, ?string $followingBackground = null): array
    {
        if (!array_is_list($entries)) {
            throw new \InvalidArgumentException('section-rhythm: entries must be an ordered list');
        }

        $normalized = [];
        foreach ($entries as $i => $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException('section-rhythm: entry ' . ($i + 1) . ' must be an array');
            }

            $label = self::label($entry, $i);
            $markup = $entry['markup'] ?? null;
            if (!is_string($markup) || trim($markup) === '') {
                throw new \InvalidArgumentException("section-rhythm: {$label} is missing non-empty markup");
            }

            $density = $entry['density'] ?? null;
            if (!is_string($density) || !isset(self::DENSITY_PRESETS[$density])) {
                $given = is_scalar($density) ? (string) $density : get_debug_type($density);
                throw new \InvalidArgumentException(
                    "section-rhythm: {$label} has invalid density '{$given}' — use compact, standard, or spacious"
                );
            }

            $background = $entry['background'] ?? null;
            if (!is_string($background) || !in_array(trim($background), self::BACKGROUNDS, true)) {
                $given = is_scalar($background) ? (string) $background : get_debug_type($background);
                throw new \InvalidArgumentException(
                    "section-rhythm: {$label} has invalid background '{$given}' — use base, tinted, contrast, or image"
                );
            }

            $normalized[] = [
                'markup'     => $markup,
                'density'    => $density,
                'background' => trim($background),
                'label'      => $label,
            ];
        }
        if ($followingBackground !== null && !in_array($followingBackground, self::BACKGROUNDS, true)) {
            throw new \InvalidArgumentException('section-rhythm: invalid following background');
        }

        $markups = [];
        $notes = [];
        foreach ($normalized as $i => $entry) {
            $preset = self::DENSITY_PRESETS[$entry['density']];
            $next = $normalized[$i + 1] ?? null;
            $nextBackground = $next['background'] ?? ($i === count($normalized) - 1 ? $followingBackground : null);
            $sharedSeam = is_string($nextBackground)
                && self::sharesContinuousSurface($entry['background'], $nextBackground);

            [$markup, $changed, $degraded] = self::rewriteOne(
                $entry['markup'],
                $preset,
                $sharedSeam,
                $entry['background'],
                $entry['label'],
            );
            $markups[] = $markup;

            if ($changed) {
                $bottom = $sharedSeam ? '0' : $preset;
                $note = match (true) {
                    $degraded => "{$entry['label']}: image background lacks exactly one usable direct wp:cover;"
                        . " degraded to solid-band outer padding top={$preset}, bottom={$bottom}; outer margins=0",
                    $entry['background'] === 'image' =>
                        "{$entry['label']}: set root padding=0 and image-cover padding top={$preset}, bottom={$preset}; outer margins=0",
                    default => "{$entry['label']}: set outer padding top={$preset}, bottom={$bottom}; outer margins=0",
                };
                if ($sharedSeam) {
                    $owner = $next['label'] ?? 'the footer';
                    $note .= " (shared {$entry['background']} seam is owned by {$owner})";
                }
                $notes[] = $note;
            }
        }

        return ['markups' => $markups, 'notes' => $notes];
    }

    /** Base and contrast are exact solid surfaces; tinted/image are not. */
    private static function sharesContinuousSurface(string $current, string $next): bool
    {
        return $current === $next && in_array($current, self::COLLAPSIBLE_SURFACES, true);
    }

    /**
     * @param array<mixed> $entry
     */
    private static function label(array $entry, int $i): string
    {
        $slug = $entry['slug'] ?? null;
        return is_string($slug) && trim($slug) !== ''
            ? "section '" . trim($slug) . "'"
            : 'section ' . ($i + 1);
    }

    /** @return array{string,bool,bool} rewritten markup, whether it changed, whether an image band was degraded to solid */
    private static function rewriteOne(
        string $markup,
        string $preset,
        bool $sharedSeam,
        string $background,
        string $label,
    ): array {
        $originalMarkup = $markup;
        [$attrs, $openingOffset, $openingLength] = self::rootGroup($markup, $label);
        $before = self::encodeAttrs($attrs);
        $markup = self::stripOwnedWrapperClasses(
            $attrs,
            $markup,
            $openingOffset + $openingLength,
            $label,
        );

        $style = self::objectProperty($attrs, 'style', $label, 'style');
        $spacing = self::objectProperty($style, 'spacing', $label, 'style.spacing');
        $padding = self::boxProperty($spacing, 'padding', $label, 'style.spacing.padding');
        $margin = self::boxProperty($spacing, 'margin', $label, 'style.spacing.margin');
        $shorthandProperties = self::preserveWrapperHorizontalSpacing(
            $markup,
            $openingOffset + $openingLength,
            $padding,
            $margin,
            $label,
        );
        $padding->top = $background === 'image' ? '0' : self::presetRef($preset);
        $padding->bottom = $background === 'image' || $sharedSeam ? '0' : self::presetRef($preset);

        $margin->top = '0';
        $margin->bottom = '0';

        // Patch the wrapper HTML first: it sits after the opener, so the
        // opener's offsets stay valid for the substr_replace below.
        $rewritten = self::patchWrapperStyle($markup, $openingOffset + $openingLength, [
            'margin-top'     => '0',
            'margin-bottom'  => '0',
            'padding-top'    => self::cssSpacingValue($padding->top),
            'padding-bottom' => self::cssSpacingValue($padding->bottom),
        ], $shorthandProperties);
        if (self::encodeAttrs($attrs) !== $before) {
            $opening = '<!-- wp:group ' . self::encodeAttrs($attrs) . ' -->';
            $rewritten = substr_replace($rewritten, $opening, $openingOffset, $openingLength);
        }

        if ($background === 'image') {
            $withCover = self::rewriteImageCover($rewritten, $preset, $label);
            if ($withCover === null) {
                // The plan promised an image band but the markup cannot honor
                // it (zero or multiple direct covers, or a cover opener this
                // pass cannot safely edit). Degrade to the solid-band
                // treatment over the untouched markup instead of rejecting
                // the theme: the root gets the same density edges an opaque
                // background would. Seam semantics stay 'image' — adjacency
                // was already decided against 'image', which never shares a
                // continuous surface, so this section's $sharedSeam and its
                // neighbours' bottom edges remain exactly as planned, and the
                // fallback stays a pure function of markup+plan (the build
                // pass and the validator drift gate can never disagree).
                // 'base' below only selects solid padding placement.
                [$fallback, $changed] = self::rewriteOne($originalMarkup, $preset, $sharedSeam, 'base', $label);
                return [$fallback, $changed, true];
            }
            $rewritten = $withCover;
        }

        return [$rewritten, $rewritten !== $originalMarkup, false];
    }

    /**
     * Put image-band breathing room inside its one direct cover, never outside
     * it. Returns null when the root does not contain exactly one direct
     * wp:cover with an opener this pass can rewrite — the caller degrades the
     * section instead of failing the build for one broken section model.
     */
    private static function rewriteImageCover(string $markup, string $preset, string $label): ?string
    {
        $doc = BlockMarkup::parse($markup);
        $roots = array_values(array_filter(
            $doc->indices(),
            static fn (int $i): bool => $doc->parent($i) === null,
        ));
        $root = $roots[0] ?? null;
        $covers = $root === null ? [] : array_values(array_filter(
            $doc->children($root),
            static fn (int $i): bool => $doc->name($i) === 'cover',
        ));
        if (count($covers) !== 1) {
            return null;
        }

        $cover = $covers[0];
        $offset = $doc->openingOffset($cover);
        $length = $doc->openingLength($cover);
        $rawOpening = substr($markup, $offset, $length);
        if (preg_match('/\A<!--\s+wp:cover(?<tail>(?:(?!-->).)*)-->\z/s', $rawOpening, $opening) !== 1) {
            return null;
        }
        $tail = trim((string) ($opening['tail'] ?? ''));
        if ($tail === '') {
            $attrs = new \stdClass();
        } else {
            if (!str_starts_with($tail, '{') || !str_ends_with($tail, '}')) {
                return null;
            }
            $attrs = json_decode($tail);
            if (!$attrs instanceof \stdClass || json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }
        }
        $before = self::encodeAttrs($attrs);
        $markup = self::stripOwnedWrapperClasses(
            $attrs,
            $markup,
            $offset + $length,
            $label . ' direct cover',
        );
        $style = self::objectProperty($attrs, 'style', $label, 'cover style');
        $spacing = self::objectProperty($style, 'spacing', $label, 'cover style.spacing');
        $padding = self::boxProperty($spacing, 'padding', $label, 'cover style.spacing.padding');
        $margin = self::boxProperty($spacing, 'margin', $label, 'cover style.spacing.margin');
        $shorthandProperties = self::preserveWrapperHorizontalSpacing(
            $markup,
            $offset + $length,
            $padding,
            $margin,
            $label . ' direct cover',
        );
        $padding->top = self::presetRef($preset);
        $padding->bottom = self::presetRef($preset);
        $margin->top = '0';
        $margin->bottom = '0';

        $patched = self::patchWrapperStyle($markup, $offset + $length, [
            'margin-top'     => '0',
            'margin-bottom'  => '0',
            'padding-top'    => self::cssSpacingValue($padding->top),
            'padding-bottom' => self::cssSpacingValue($padding->bottom),
        ], $shorthandProperties);
        if (self::encodeAttrs($attrs) !== $before) {
            $newOpening = '<!-- wp:cover ' . self::encodeAttrs($attrs) . ' -->';
            $patched = substr_replace($patched, $newOpening, $offset, $length);
        }
        return $patched;
    }

    /**
     * Mirror the owned vertical declarations into the wrapper element's
     * inline style attribute.
     *
     * The block fixer regenerates each block's HTML from its comment
     * attributes and reports every inline declaration that disappears in the
     * process; a value this pass replaced in the attributes but left in the
     * HTML would surface there as fatally dropped vertical-rhythm CSS. So:
     * existing owned declarations are rewritten in place, superseded
     * vertical-only spellings are removed, and everything else — including
     * declaration order and the spelling of untouched declarations — is
     * preserved byte-for-byte. Plain padding/margin shorthands are removed only
     * after their effective horizontal values have been mirrored into the block
     * attributes; an unparseable or conflicting shorthand fails this atomic
     * pass rather than silently choosing a horizontal layout. Owned declarations
     * absent from the HTML are NOT appended: the fixer adding CSS is never
     * reported as a loss, and appending in a different order than Gutenberg's
     * serializer would break idempotency across fix-blocks.
     *
     * Markup whose first node after the opener is not an element, or whose
     * wrapper has no style attribute, is returned unchanged.
     *
     * @param array<string,string> $owned CSS property => owned CSS value
     * @param list<string> $shorthandProperties padding/margin shorthands whose
     *        physical side declarations must also be collapsed into attrs
     */
    private static function patchWrapperStyle(
        string $markup,
        int $searchOffset,
        array $owned,
        array $shorthandProperties,
    ): string
    {
        $tagHtml = self::wrapperTag($markup, $searchOffset);
        if ($tagHtml === null) {
            return $markup;
        }
        $style = self::tagAttribute($tagHtml, 'style');
        if ($style === null) {
            return $markup;
        }
        [$value, $valueOffset] = $style;

        $seen = [];
        $out = [];
        foreach (explode(';', $value) as $segment) {
            $colon = strpos($segment, ':');
            $property = strtolower(trim($colon === false ? $segment : substr($segment, 0, $colon)));
            if (isset($owned[$property])) {
                if (!isset($seen[$property])) {
                    $seen[$property] = true;
                    $out[] = $property . ':' . $owned[$property];
                }
                continue;
            }
            if (preg_match('/\A(padding|margin)-(?:right|left)\z/', $property, $side) === 1
                && in_array($side[1], $shorthandProperties, true)
            ) {
                continue;
            }
            if (in_array($property, self::SUPERSEDED_WRAPPER_PROPERTIES, true)) {
                continue;
            }
            $out[] = $segment;
        }

        $newValue = implode(';', $out);
        if ($newValue === $value) {
            return $markup;
        }
        $newTag = substr_replace($tagHtml, $newValue, $valueOffset, strlen($value));
        return substr_replace($markup, $newTag, $searchOffset, strlen($tagHtml));
    }

    /** The first HTML element immediately following a block opener. */
    private static function wrapperTag(string $markup, int $searchOffset): ?string
    {
        $rest = substr($markup, $searchOffset);
        if (preg_match('/\A\s*<[a-zA-Z][a-zA-Z0-9-]*(?=[\x20\t\r\n\f\/>])/', $rest, $start) !== 1) {
            return null;
        }

        $quote = null;
        $length = strlen($rest);
        for ($i = strlen($start[0]); $i < $length; $i++) {
            $char = $rest[$i];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }
            if ($char === '>') {
                return substr($rest, 0, $i + 1);
            }
        }
        return null;
    }

    /** @return array{string,int}|null attribute value and its byte offset inside the tag */
    private static function tagAttribute(string $tagHtml, string $name): ?array
    {
        $pattern = '/[\x20\t\r\n\f]' . preg_quote($name, '/')
            . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i';
        if (preg_match($pattern, $tagHtml, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        return ($match[1][1] ?? -1) !== -1 ? $match[1] : $match[2];
    }

    /**
     * Remove utilities that can override the vertical margin owned by this
     * wrapper. Both comment attrs and saved HTML are patched so the block fixer
     * cannot recover a stale class from either representation.
     */
    private static function stripOwnedWrapperClasses(
        \stdClass $attrs,
        string $markup,
        int $searchOffset,
        string $label,
    ): string {
        if (property_exists($attrs, 'className')) {
            if (!is_string($attrs->className)) {
                throw new \RuntimeException("section-rhythm: {$label} has a non-string className attribute");
            }
            $originalClassName = $attrs->className;
            $newClassName = $originalClassName;
            foreach (self::FORBIDDEN_OWNED_WRAPPER_CLASSES as $class) {
                $newClassName = self::withoutClassToken($newClassName, $class);
            }
            if ($newClassName !== $originalClassName) {
                if ($newClassName === '') {
                    unset($attrs->className);
                } else {
                    $attrs->className = $newClassName;
                }
            }
        }

        $tagHtml = self::wrapperTag($markup, $searchOffset);
        $classAttr = $tagHtml === null ? null : self::tagAttribute($tagHtml, 'class');
        if ($classAttr === null) {
            return $markup;
        }
        [$value, $valueOffset] = $classAttr;
        $newValue = $value;
        foreach (self::FORBIDDEN_OWNED_WRAPPER_CLASSES as $class) {
            $newValue = self::withoutClassToken($newValue, $class);
        }
        if ($newValue === $value) {
            return $markup;
        }
        $newTag = substr_replace($tagHtml, $newValue, $valueOffset, strlen($value));
        return substr_replace($markup, $newTag, $searchOffset, strlen($tagHtml));
    }

    /** Remove every exact occurrence while preserving a no-op byte-for-byte. */
    private static function withoutClassToken(string $classes, string $remove): string
    {
        $tokens = preg_split('/[\x20\t\r\n\f]+/', trim($classes), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!in_array($remove, $tokens, true)) {
            return $classes;
        }
        return implode(' ', array_values(array_filter(
            $tokens,
            static fn (string $class): bool => $class !== $remove,
        )));
    }

    /**
     * Promote the effective horizontal components of wrapper padding/margin
     * declarations into block attributes. Plain shorthands are removed; their
     * accompanying physical longhands are collapsed at the same time so the
     * cascade result, rather than a now-unmasked declaration, is serialized.
     *
     * The inline declaration block is evaluated in source order for the two
     * physical horizontal sides, including !important precedence and explicit
     * right/left longhands. Existing attribute values remain authoritative, but
     * a conflict fails the atomic pass instead of silently choosing a side.
     *
     * @return list<string> padding/margin properties that had a plain shorthand
     */
    private static function preserveWrapperHorizontalSpacing(
        string $markup,
        int $searchOffset,
        \stdClass $padding,
        \stdClass $margin,
        string $label,
    ): array {
        $tagHtml = self::wrapperTag($markup, $searchOffset);
        $style = $tagHtml === null ? null : self::tagAttribute($tagHtml, 'style');
        if ($style === null) {
            return [];
        }

        $states = [
            'padding' => ['right' => null, 'left' => null],
            'margin'  => ['right' => null, 'left' => null],
        ];
        $shorthandProperties = [];
        foreach (explode(';', $style[0]) as $segment) {
            $colon = strpos($segment, ':');
            if ($colon === false) {
                continue;
            }
            $property = strtolower(trim(substr($segment, 0, $colon)));
            $rawValue = substr($segment, $colon + 1);

            if ($property === 'padding' || $property === 'margin') {
                $shorthandProperties[$property] = true;
                $expanded = self::expandBoxShorthand($rawValue, $property);
                if ($expanded === null) {
                    throw new \RuntimeException(
                        "section-rhythm: {$label} has an unparseable inline {$property} shorthand"
                    );
                }
                self::applyCascadeValue($states[$property]['right'], $expanded['right']);
                self::applyCascadeValue($states[$property]['left'], $expanded['left']);
                continue;
            }

            if (preg_match('/\A(padding|margin)-(right|left)\z/', $property, $side) !== 1) {
                continue;
            }
            $value = self::cssDeclarationValue($rawValue);
            $components = $value === null ? null : self::splitCssValues($value['value']);
            if ($value === null
                || $components === null
                || count($components) !== 1
                || !self::validSpacingComponent($value['value'], $side[1], true)
            ) {
                throw new \RuntimeException(
                    "section-rhythm: {$label} has an unparseable inline {$property} declaration"
                );
            }
            self::applyCascadeValue($states[$side[1]][$side[2]], $value);
        }

        $boxes = ['padding' => $padding, 'margin' => $margin];
        foreach ($states as $property => $state) {
            foreach (['right', 'left'] as $side) {
                $candidate = $state[$side];
                if (!is_array($candidate)) {
                    continue;
                }
                $value = self::formatSpacingAttributeValue($candidate);
                if (!property_exists($boxes[$property], $side)) {
                    $boxes[$property]->{$side} = $value;
                    continue;
                }
                $existing = self::cssDeclarationValue((string) $boxes[$property]->{$side});
                if ($existing === null
                    || self::formatComparableSpacingValue($existing)
                        !== self::formatComparableSpacingValue($candidate)
                ) {
                    throw new \RuntimeException(
                        "section-rhythm: {$label} has conflicting inline and attribute {$property}-{$side}"
                    );
                }
            }
        }
        return array_keys($shorthandProperties);
    }

    /** @param array{value:string,important:bool}|null $current @param array{value:string,important:bool} $next */
    private static function applyCascadeValue(?array &$current, array $next): void
    {
        if ($current === null || $next['important'] || !$current['important']) {
            $current = $next;
        }
    }

    /** @return array{value:string,important:bool}|null */
    private static function cssDeclarationValue(string $raw): ?array
    {
        $value = trim($raw);
        if ($value === '') {
            return null;
        }
        $important = preg_match('/\s*!\s*important\s*\z/i', $value) === 1;
        if ($important) {
            $value = trim((string) preg_replace('/\s*!\s*important\s*\z/i', '', $value));
        }
        return $value === '' ? null : ['value' => $value, 'important' => $important];
    }

    /** @param array{value:string,important:bool} $value */
    private static function formatSpacingAttributeValue(array $value): string
    {
        $raw = $value['important']
            ? self::cssSpacingValue($value['value'])
            : self::blockSpacingValue($value['value']);
        return $raw . ($value['important'] ? ' !important' : '');
    }

    /** @param array{value:string,important:bool} $value */
    private static function formatComparableSpacingValue(array $value): string
    {
        $raw = self::cssSpacingValue($value['value']);
        return $raw . ($value['important'] ? ' !important' : '');
    }

    /** Convert a rendered preset variable back to block-attribute syntax. */
    private static function blockSpacingValue(string $value): string
    {
        return preg_match('/^var\(--wp--preset--spacing--([a-z0-9_-]+)\)$/', $value, $match) === 1
            ? "var:preset|spacing|{$match[1]}"
            : $value;
    }

    /**
     * @return array{top:array{value:string,important:bool},right:array{value:string,important:bool},bottom:array{value:string,important:bool},left:array{value:string,important:bool}}|null
     */
    private static function expandBoxShorthand(
        string $raw,
        string $property,
        bool $attributeSyntax = false,
    ): ?array
    {
        $declaration = self::cssDeclarationValue($raw);
        if ($declaration === null) {
            return null;
        }
        $values = self::splitCssValues($declaration['value']);
        if ($values === null) {
            return null;
        }
        $cssWide = ['inherit', 'initial', 'revert', 'revert-layer', 'unset'];
        if ((count($values) !== 1 && array_intersect($cssWide, array_map('strtolower', $values)) !== [])
            || array_filter(
                $values,
                static fn (string $value): bool => !self::validSpacingComponent(
                    $value,
                    $property,
                    false,
                    $attributeSyntax,
                ),
            ) !== []
        ) {
            return null;
        }
        $expanded = match (count($values)) {
            1       => [$values[0], $values[0], $values[0], $values[0]],
            2       => [$values[0], $values[1], $values[0], $values[1]],
            3       => [$values[0], $values[1], $values[2], $values[1]],
            4       => $values,
            default => null,
        };
        if ($expanded === null) {
            return null;
        }
        [$top, $right, $bottom, $left] = $expanded;
        $wrap = static fn (string $value): array => [
            'value' => $value,
            'important' => $declaration['important'],
        ];
        return [
            'top' => $wrap($top), 'right' => $wrap($right),
            'bottom' => $wrap($bottom), 'left' => $wrap($left),
        ];
    }

    /**
     * Conservatively recognize a single padding/margin component that can be
     * moved from a shorthand to a physical longhand without changing meaning.
     * Unknown custom properties are deliberately rejected: `var(--space)` may
     * expand to multiple shorthand components and cannot safely become one
     * right/left value. Project-owned spacing preset variables are scalar.
     */
    private static function validSpacingComponent(
        string $value,
        string $property,
        bool $allowCustomProperty = false,
        bool $attributeSyntax = false,
    ): bool
    {
        $lower = strtolower($value);
        if (in_array($lower, ['inherit', 'initial', 'revert', 'revert-layer', 'unset'], true)) {
            return true;
        }
        if ($lower === 'auto') {
            return $property === 'margin';
        }
        if (($attributeSyntax && preg_match('/\Avar:preset\|spacing\|[a-z0-9_-]+\z/', $value) === 1)
            || preg_match('/\Avar\(--wp--preset--spacing--[a-z0-9_-]+\)\z/', $value) === 1
        ) {
            return true;
        }
        if (preg_match('/\Avar\(/i', $value) === 1) {
            return $allowCustomProperty && self::validCustomPropertyReference($value);
        }
        if (preg_match('/\A[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?\z/', $value) === 1) {
            return (float) $value === 0.0;
        }
        if (preg_match(
            '/\A(?<sign>[+-]?)(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?'
            . '(?:%|cap|ch|cm|cqb|cqh|cqi|cqmax|cqmin|cqw|dvb|dvh|dvi|dvmax|dvmin|dvw|em|ex|ic|in|lh|lvb|lvh|lvi|lvmax|lvmin|lvw|mm|pc|pt|px|q|rem|rlh|svb|svh|svi|svmax|svmin|svw|vb|vh|vi|vmax|vmin|vw)\z/i',
            $value,
            $unit,
        ) !== 1) {
            return false;
        }
        return $property === 'margin' || $unit['sign'] !== '-';
    }

    /** A balanced scalar custom-property reference safe to copy between longhands. */
    private static function validCustomPropertyReference(string $value): bool
    {
        return preg_match(
            '/\Avar\(\s*--[a-zA-Z_][a-zA-Z0-9_-]*\s*(?:,[\s\S]*)?\)\z/',
            $value,
        ) === 1;
    }

    /** @return list<string>|null one to four top-level CSS component values */
    private static function splitCssValues(string $value): ?array
    {
        if (str_contains($value, '/*')) {
            return null;
        }
        $values = [];
        $token = '';
        $closers = [];
        $quote = null;
        $escaped = false;
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($escaped) {
                $token .= $char;
                $escaped = false;
                continue;
            }
            if ($char === '\\') {
                $token .= $char;
                $escaped = true;
                continue;
            }
            if ($quote !== null) {
                $token .= $char;
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $token .= $char;
                $quote = $char;
                continue;
            }
            if ($char === '(' || $char === '[') {
                $token .= $char;
                $closers[] = $char === '(' ? ')' : ']';
                continue;
            }
            if ($char === ')' || $char === ']') {
                if ($closers === [] || array_pop($closers) !== $char) {
                    return null;
                }
                $token .= $char;
                continue;
            }
            if (ctype_space($char) && $closers === []) {
                if ($token !== '') {
                    $values[] = $token;
                    $token = '';
                }
                continue;
            }
            $token .= $char;
        }
        if ($escaped || $quote !== null || $closers !== []) {
            return null;
        }
        if ($token !== '') {
            $values[] = $token;
        }
        return count($values) >= 1 && count($values) <= 4 ? $values : null;
    }

    /** The rendered-CSS spelling of an owned attribute value ('0' or a preset ref). */
    private static function cssSpacingValue(string $attrValue): string
    {
        return preg_match('/^var:preset\|spacing\|([a-z0-9_-]+)$/', $attrValue, $match) === 1
            ? "var(--wp--preset--spacing--{$match[1]})"
            : $attrValue;
    }

    /**
     * Inspect a chrome part's root and return a plan surface when unambiguous.
     * Gradient, tinted and image surfaces deliberately return null because two
     * independent instances need not be visually continuous.
     */
    public static function surfaceFromMarkup(string $markup): ?string
    {
        $doc = BlockMarkup::parse($markup);
        $roots = array_values(array_filter(
            $doc->indices(),
            static fn (int $i): bool => $doc->parent($i) === null,
        ));
        if (count($roots) !== 1 || $doc->name($roots[0]) !== 'group') {
            return null;
        }
        $attrs = $doc->attrs($roots[0]) ?? [];
        if (isset($attrs['gradient'])
            || isset($attrs['style']['color']['gradient'])
            || isset($attrs['style']['background'])
        ) {
            return null;
        }
        $color = $attrs['backgroundColor'] ?? null;
        if ($color === null) {
            $color = $attrs['style']['color']['background'] ?? null;
        }
        return match ($color) {
            null, 'base', 'var:preset|color|base'         => 'base',
            'contrast', 'var:preset|color|contrast'       => 'contrast',
            default                                      => null,
        };
    }

    /**
     * Return the footer surface only when its root explicitly supplies a
     * non-zero top edge. Without that padding there is nothing to own a shared
     * seam, so the last section must keep its bottom edge.
     */
    public static function followingSurfaceFromMarkup(string $markup): ?string
    {
        $surface = self::surfaceFromMarkup($markup);
        if ($surface === null) {
            return null;
        }
        $doc = BlockMarkup::parse($markup);
        $roots = array_values(array_filter(
            $doc->indices(),
            static fn (int $i): bool => $doc->parent($i) === null,
        ));
        if (count($roots) !== 1) {
            return null;
        }
        $attrs = $doc->attrs($roots[0]) ?? [];
        $top = trim((string) ($attrs['style']['spacing']['padding']['top'] ?? ''));
        return $top !== '' && preg_match('/^0+(?:\.0+)?(?:[a-z%]+)?$/i', $top) !== 1
            ? $surface
            : null;
    }

    private static function presetRef(string $slug): string
    {
        return "var:preset|spacing|{$slug}";
    }

    /**
     * Return a four-side spacing object, expanding Gutenberg's valid string
     * shorthand form so this pass can replace only the vertical components.
     */
    private static function boxProperty(
        \stdClass $parent,
        string $property,
        string $label,
        string $path,
    ): \stdClass {
        if (property_exists($parent, $property) && is_string($parent->{$property})) {
            $expanded = self::expandBoxShorthand($parent->{$property}, $property, true);
            if ($expanded === null) {
                throw new \RuntimeException(
                    "section-rhythm: {$label} has an unparseable {$path} shorthand"
                );
            }
            $box = new \stdClass();
            foreach (['top', 'right', 'bottom', 'left'] as $side) {
                $box->{$side} = self::formatSpacingAttributeValue($expanded[$side]);
            }
            $parent->{$property} = $box;
        }
        return self::objectProperty($parent, $property, $label, $path);
    }

    private static function objectProperty(
        \stdClass $parent,
        string $property,
        string $label,
        string $path,
    ): \stdClass {
        if (!property_exists($parent, $property)) {
            $parent->{$property} = new \stdClass();
        }
        if (!$parent->{$property} instanceof \stdClass) {
            throw new \RuntimeException("section-rhythm: {$label} has a non-object {$path} attribute");
        }
        return $parent->{$property};
    }

    /** Encode without collapsing empty/numeric-key JSON objects into arrays. */
    private static function encodeAttrs(\stdClass $attrs): string
    {
        $json = json_encode(
            $attrs,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
            | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if (!is_string($json)) {
            throw new \RuntimeException('section-rhythm: could not serialize root wp:group attributes');
        }
        return str_replace('--', '\\u002d\\u002d', $json);
    }

    /**
     * Find and validate the section's one root group.
     *
     * BlockMarkup deliberately tolerates malformed generated documents. This
     * pass cannot: assigning padding to a child that merely looks like the root
     * would hide a broken section and leave its real outer rhythm uncontrolled.
     * The delimiter checks below therefore verify the opening JSON, matching
     * closing delimiter, and absence of content outside the root block.
     *
     * @return array{\stdClass,int,int} attributes, opening offset, opening length
     */
    private static function rootGroup(string $markup, string $label): array
    {
        $doc = BlockMarkup::parse($markup);
        $roots = array_values(array_filter(
            $doc->indices(),
            static fn (int $i): bool => $doc->parent($i) === null,
        ));
        if (count($roots) !== 1 || $doc->name($roots[0]) !== 'group') {
            throw new \RuntimeException(
                "section-rhythm: {$label} must contain exactly one top-level wp:group"
            );
        }
        $root = $roots[0];

        $leadingLength = strlen($markup) - strlen(ltrim($markup));
        $fromRoot = substr($markup, $leadingLength);
        if (preg_match('/\A<!--\s+wp:group(?<tail>(?:(?!-->).)*)-->/s', $fromRoot, $opening) !== 1) {
            throw new \RuntimeException("section-rhythm: {$label} has a malformed root wp:group opener");
        }

        $tail = trim((string) ($opening['tail'] ?? ''));
        if ($tail === '') {
            $attrs = new \stdClass();
        } else {
            if (!str_starts_with($tail, '{') || !str_ends_with($tail, '}')) {
                throw new \RuntimeException("section-rhythm: {$label} has a malformed root wp:group opener");
            }
            $attrs = json_decode($tail);
            if (!$attrs instanceof \stdClass || json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException("section-rhythm: {$label} root wp:group attributes are invalid JSON");
            }
        }

        // BlockMarkup's innerHtml ends precisely where the matching root closer
        // starts. If the root was never closed it runs to EOF, so this check also
        // rejects an unclosed group.
        $closeOffset = $leadingLength + strlen($opening[0]) + strlen($doc->innerHtml($root));
        $fromClose = substr($markup, $closeOffset);
        if (preg_match('/\A<!--\s+\/wp:group\s+-->/s', $fromClose, $closing) !== 1) {
            throw new \RuntimeException("section-rhythm: {$label} has an unclosed or malformed root wp:group");
        }
        if (trim(substr($fromClose, strlen($closing[0]))) !== '') {
            throw new \RuntimeException(
                "section-rhythm: {$label} has content outside its top-level wp:group"
            );
        }

        return [$attrs, $leadingLength, strlen($opening[0])];
    }
}
