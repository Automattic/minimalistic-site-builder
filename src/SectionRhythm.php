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
 * image band instead of opening page-background gutters around it.
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

    /**
     * Vertical-only inline spellings superseded by the owned longhands. The
     * plain shorthands are included because Gutenberg never re-emits them:
     * once the pass owns the vertical component, an orphaned shorthand would
     * survive in HTML only to be dropped (fatally) by the block fixer. Any
     * horizontal component still declared in the attributes is re-serialized
     * as longhands by the fixer.
     */
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

            [$markup, $changed] = self::rewriteOne(
                $entry['markup'],
                $preset,
                $sharedSeam,
                $entry['background'],
                $entry['label'],
            );
            $markups[] = $markup;

            if ($changed) {
                $bottom = $sharedSeam ? '0' : $preset;
                $note = $entry['background'] === 'image'
                    ? "{$entry['label']}: set root padding=0 and image-cover padding top={$preset}, bottom={$preset}; outer margins=0"
                    : "{$entry['label']}: set outer padding top={$preset}, bottom={$bottom}; outer margins=0";
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

    /** @return array{string,bool} rewritten markup and whether it changed */
    private static function rewriteOne(
        string $markup,
        string $preset,
        bool $sharedSeam,
        string $background,
        string $label,
    ): array {
        [$attrs, $openingOffset, $openingLength] = self::rootGroup($markup, $label);
        $before = self::encodeAttrs($attrs);

        $style = self::objectProperty($attrs, 'style', $label, 'style');
        $spacing = self::objectProperty($style, 'spacing', $label, 'style.spacing');
        $padding = self::objectProperty($spacing, 'padding', $label, 'style.spacing.padding');
        $padding->top = $background === 'image' ? '0' : self::presetRef($preset);
        $padding->bottom = $background === 'image' || $sharedSeam ? '0' : self::presetRef($preset);

        $margin = self::objectProperty($spacing, 'margin', $label, 'style.spacing.margin');
        $margin->top = '0';
        $margin->bottom = '0';

        // Patch the wrapper HTML first: it sits after the opener, so the
        // opener's offsets stay valid for the substr_replace below.
        $rewritten = self::patchWrapperStyle($markup, $openingOffset + $openingLength, [
            'margin-top'     => '0',
            'margin-bottom'  => '0',
            'padding-top'    => self::cssSpacingValue($padding->top),
            'padding-bottom' => self::cssSpacingValue($padding->bottom),
        ]);
        if (self::encodeAttrs($attrs) !== $before) {
            $opening = '<!-- wp:group ' . self::encodeAttrs($attrs) . ' -->';
            $rewritten = substr_replace($rewritten, $opening, $openingOffset, $openingLength);
        }

        if ($background === 'image') {
            $rewritten = self::rewriteImageCover($rewritten, $preset, $label);
        }

        return [$rewritten, $rewritten !== $markup];
    }

    /** Put image-band breathing room inside its one direct cover, never outside it. */
    private static function rewriteImageCover(string $markup, string $preset, string $label): string
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
            throw new \RuntimeException(
                "section-rhythm: {$label} with image background must contain exactly one direct wp:cover"
            );
        }

        $cover = $covers[0];
        $offset = $doc->openingOffset($cover);
        $length = $doc->openingLength($cover);
        $rawOpening = substr($markup, $offset, $length);
        if (preg_match('/\A<!--\s+wp:cover(?<tail>(?:(?!-->).)*)-->\z/s', $rawOpening, $opening) !== 1) {
            throw new \RuntimeException("section-rhythm: {$label} has a malformed direct wp:cover opener");
        }
        $tail = trim((string) ($opening['tail'] ?? ''));
        if ($tail === '') {
            $attrs = new \stdClass();
        } else {
            if (!str_starts_with($tail, '{') || !str_ends_with($tail, '}')) {
                throw new \RuntimeException("section-rhythm: {$label} has a malformed direct wp:cover opener");
            }
            $attrs = json_decode($tail);
            if (!$attrs instanceof \stdClass || json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException("section-rhythm: {$label} direct wp:cover attributes are invalid JSON");
            }
        }
        $before = self::encodeAttrs($attrs);
        $style = self::objectProperty($attrs, 'style', $label, 'cover style');
        $spacing = self::objectProperty($style, 'spacing', $label, 'cover style.spacing');
        $padding = self::objectProperty($spacing, 'padding', $label, 'cover style.spacing.padding');
        $padding->top = self::presetRef($preset);
        $padding->bottom = self::presetRef($preset);
        $margin = self::objectProperty($spacing, 'margin', $label, 'cover style.spacing.margin');
        $margin->top = '0';
        $margin->bottom = '0';

        $patched = self::patchWrapperStyle($markup, $offset + $length, [
            'margin-top'     => '0',
            'margin-bottom'  => '0',
            'padding-top'    => self::cssSpacingValue($padding->top),
            'padding-bottom' => self::cssSpacingValue($padding->bottom),
        ]);
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
     * preserved byte-for-byte. Owned declarations absent from the HTML are
     * NOT appended: the fixer adding CSS is never reported as a loss, and
     * appending in a different order than Gutenberg's serializer would make
     * this pass non-idempotent across fix-blocks.
     *
     * Markup whose first node after the opener is not an element, or whose
     * wrapper has no style attribute, is returned unchanged.
     *
     * @param array<string,string> $owned CSS property => owned CSS value
     */
    private static function patchWrapperStyle(string $markup, int $searchOffset, array $owned): string
    {
        $rest = substr($markup, $searchOffset);
        if (preg_match('/\A\s*<[a-zA-Z][a-zA-Z0-9-]*(?:\s[^>]*)?>/', $rest, $tag) !== 1) {
            return $markup;
        }
        $tagHtml = $tag[0];
        if (preg_match('/\bstyle\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $tagHtml, $style, PREG_OFFSET_CAPTURE) !== 1) {
            return $markup;
        }
        [$value, $valueOffset] = ($style[1][1] ?? -1) !== -1 ? $style[1] : $style[2];

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
