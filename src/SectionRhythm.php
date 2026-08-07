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

    /**
     * Serializer-preserved state for an image section that had to use solid
     * outer spacing. FixBlocks may repair an unusable cover opener into a valid
     * cover, so markup shape alone cannot reproduce the original decision.
     */
    private const DEGRADED_IMAGE_CLASS = 'site-build-section-rhythm-degraded-image';

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
     * @return array{
     *     markups:list<string>,
     *     notes:list<string>,
     *     degradations:list<array{
     *         section:string,code:string,reason:string,message:string,newlyDetected:bool
     *     }>
     * }
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
        $degradations = [];
        foreach ($normalized as $i => $entry) {
            $preset = self::DENSITY_PRESETS[$entry['density']];
            $topPreset = $i === 0 ? self::openingTopPreset($entry['markup'], $preset) : $preset;
            $next = $normalized[$i + 1] ?? null;
            $nextBackground = $next['background'] ?? ($i === count($normalized) - 1 ? $followingBackground : null);
            $sharedSeam = is_string($nextBackground)
                && self::sharesContinuousSurface($entry['background'], $nextBackground);
            // The opening hero never fully collapses its bottom seam
            // (BIGR-775 follow-up): with a shared surface the section below
            // owned the whole gap, and its compact top read as the hero
            // crowding the next band (lumen9/atlas9). The hero keeps a `lg`
            // floor; every other shared seam still collapses to 0.
            $bottomFloor = $sharedSeam && $i === 0 && self::isHeroRoot($entry['markup']) ? 'lg' : null;

            [$markup, $changed, $degradation] = self::rewriteOne(
                $entry['markup'],
                $preset,
                $sharedSeam,
                $entry['background'],
                $entry['label'],
                $topPreset,
                $bottomFloor,
            );
            $markups[] = $markup;

            $bottom = $sharedSeam ? ($bottomFloor ?? '0') : $preset;
            $degradationRecord = null;
            if ($degradation !== null) {
                $message = "{$entry['label']}: planned image background degraded to solid-band rhythm"
                    . " ({$degradation['code']}: {$degradation['reason']});"
                    . " outer padding top={$preset}, bottom={$bottom}; outer margins=0";
                $degradationRecord = [
                    'section' => $entry['label'],
                    'code' => $degradation['code'],
                    'reason' => $degradation['reason'],
                    'message' => $message,
                    'newlyDetected' => $degradation['newlyDetected'],
                ];
                $degradations[] = $degradationRecord;
            }

            if ($changed) {
                $note = match (true) {
                    $degradationRecord !== null => $degradationRecord['message'],
                    $entry['background'] === 'image' =>
                        "{$entry['label']}: set root padding=0 and image-cover padding top={$preset}, bottom={$preset}; outer margins=0",
                    default => "{$entry['label']}: set outer padding top={$topPreset}, bottom={$bottom}; outer margins=0",
                };
                if ($topPreset !== $preset) {
                    $note .= " (opening hero top capped from {$preset})";
                }
                if ($sharedSeam) {
                    $owner = $next['label'] ?? 'the footer';
                    $note .= " (shared {$entry['background']} seam is owned by {$owner})";
                }
                $notes[] = $note;
            }
        }

        return ['markups' => $markups, 'notes' => $notes, 'degradations' => $degradations];
    }

    /** Base and contrast are exact solid surfaces; tinted/image are not. */
    private static function sharesContinuousSurface(string $current, string $next): bool
    {
        return $current === $next && in_array($current, self::COLLAPSIBLE_SURFACES, true);
    }

    /**
     * The top preset for a page's OPENING section. A hero that leads with its
     * media sits tight under the header (hero.md caps it at `sm`; audited
     * density-mapped `xl` tops opened a dead band that pushed the rail or
     * copy below the fold), and a copy-led hero keeps breathing room but
     * never more than `lg` (BIGR-775 follow-up: the earlier `md` cap sat
     * solid split heroes ~1.5rem under the header — lumen9/atlas9 read as
     * cramped, not tight). Non-hero openers keep the plain density map.
     * The bottom edge and every later section are untouched, so the page's
     * internal rhythm stays exactly as planned.
     */
    private static function openingTopPreset(string $markup, string $preset): string
    {
        if (!self::isHeroRoot($markup)) {
            return $preset;
        }
        if (self::heroLeadsWithMedia($markup)) {
            return 'sm';
        }
        return in_array($preset, ['xl', 'xxl'], true) ? 'lg' : $preset;
    }

    /** Whether a section root carries a hero recipe marker. */
    private static function isHeroRoot(string $markup): bool
    {
        try {
            $document = BlockMarkup::parse($markup);
        } catch (\Throwable) {
            return false;
        }
        $root = $document->topLevel();
        if ($root === null) {
            return false;
        }
        return str_contains(
            (string) (($document->attrs($root) ?? [])['className'] ?? ''),
            'hero-composition--'
        );
    }

    /** Whether a hero root's first visual child is its media band/cover. */
    private static function heroLeadsWithMedia(string $markup): bool
    {
        try {
            $document = BlockMarkup::parse($markup);
        } catch (\Throwable) {
            return false;
        }
        $root = $document->topLevel();
        if ($root === null) {
            return false;
        }
        $children = $document->children($root);
        return $children !== []
            && in_array($document->name($children[0]), ['image', 'cover'], true)
            && str_contains(
                (string) (($document->attrs($children[0]) ?? [])['className'] ?? ''),
                'hero-composition__media'
            );
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

    /**
     * @return array{
     *     string,
     *     bool,
     *     array{code:string,reason:string,newlyDetected:bool}|null
     * } rewritten markup, whether it changed, and image degradation metadata
     */
    private static function rewriteOne(
        string $markup,
        string $preset,
        bool $sharedSeam,
        string $background,
        string $label,
        ?string $topPreset = null,
        ?string $bottomFloor = null,
    ): array {
        $topPreset ??= $preset;
        $originalMarkup = $markup;
        [$attrs, $openingOffset, $openingLength] = self::rootGroup($markup, $label);
        $degradation = $background === 'image' && self::hasClassToken($attrs, self::DEGRADED_IMAGE_CLASS)
            ? [
                'code' => 'persisted-fallback',
                'reason' => 'a prior rhythm pass marked this section for stable solid-band fallback',
                'newlyDetected' => false,
            ]
            : null;
        $spacingBackground = $degradation === null ? $background : 'base';
        // $topPreset diverges from $preset only for the page-opening hero
        // (BIGR-755's top cap: sm media-led / md copy-led) — the shared
        // wrapper rewrite must honor it on the top edge.
        $rewritten = self::rewriteWrapperBlock(
            $markup,
            $attrs,
            $openingOffset,
            $openingLength,
            'wp:group',
            $spacingBackground === 'image' ? '0' : self::presetRef($topPreset),
            match (true) {
                $spacingBackground === 'image' => '0',
                $sharedSeam => $bottomFloor === null ? '0' : self::presetRef($bottomFloor),
                default => self::presetRef($preset),
            },
            $label,
            $label,
            'style',
        );

        if ($background === 'image' && $degradation === null) {
            $coverResult = self::rewriteImageCover($rewritten, $preset, $label);
            if ($coverResult['degradation'] !== null) {
                // The plan promised an image band but the markup cannot honor
                // it (zero or multiple direct covers, or a cover opener this
                // pass cannot safely edit). Degrade to the solid-band
                // treatment over the untouched markup instead of rejecting
                // the theme: the root gets the same density edges an opaque
                // background would. Seam semantics stay 'image' — adjacency
                // was already decided against 'image', which never shares a
                // continuous surface, so this section's $sharedSeam and its
                // neighbours' bottom edges remain exactly as planned. Mark
                // the root before applying base spacing so FixBlocks cannot
                // repair the cover into a different validator decision.
                // 'base' below only selects solid padding placement.
                $marked = self::markDegradedImage($coverResult['markup'], $label);
                [$fallback] = self::rewriteOne($marked, $preset, $sharedSeam, 'base', $label);
                return [$fallback, $fallback !== $originalMarkup, $coverResult['degradation']];
            }
            $rewritten = $coverResult['markup'];
        }

        return [$rewritten, $rewritten !== $originalMarkup, $degradation];
    }

    /**
     * Put image-band breathing room inside its one direct cover, never outside
     * it. Cover-local failures are returned as structured degradation metadata
     * so the caller can safely fall back without swallowing root/plan errors.
     *
     * @return array{
     *     markup:string,
     *     degradation:array{code:string,reason:string,newlyDetected:bool}|null
     * }
     */
    private static function rewriteImageCover(string $markup, string $preset, string $label): array
    {
        $originalMarkup = $markup;
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
        if ($covers === []) {
            return self::imageCoverFailure($markup, 'missing-direct-cover', 'root contains no direct wp:cover');
        }
        if (count($covers) !== 1) {
            // Delivery still passes through FixBlocks. Even though multiple
            // direct covers already force solid-band fallback, sanitize every
            // parseable opener so one serializer-fatal cover cannot abort that
            // fallback before the persisted marker reaches validation.
            $coverIssuesByPosition = [];
            foreach (array_reverse($covers, true) as $coverPosition => $coverIndex) {
                $coverOffset = $doc->openingOffset($coverIndex);
                $coverLength = $doc->openingLength($coverIndex);
                $rawOpening = substr($markup, $coverOffset, $coverLength);
                if (preg_match('/\A<!--\s+wp:cover(?<tail>(?:(?!-->).)*)-->\z/s', $rawOpening, $opening) !== 1) {
                    continue;
                }
                $tail = trim((string) ($opening['tail'] ?? ''));
                if ($tail === '') {
                    $coverAttrs = new \stdClass();
                } else {
                    $coverAttrs = json_decode($tail);
                    if (!$coverAttrs instanceof \stdClass || json_last_error() !== JSON_ERROR_NONE) {
                        continue;
                    }
                }

                $before = self::encodeAttrs($coverAttrs);
                foreach (self::sanitizeCoverFallbackAttributes($coverAttrs) as $issue) {
                    $coverIssuesByPosition[$coverPosition][] = $issue;
                }
                if (self::encodeAttrs($coverAttrs) !== $before) {
                    $newOpening = '<!-- wp:cover ' . self::encodeAttrs($coverAttrs) . ' -->';
                    $markup = substr_replace($markup, $newOpening, $coverOffset, $coverLength);
                }
            }
            ksort($coverIssuesByPosition);
            $coverIssues = [];
            foreach ($coverIssuesByPosition as $coverPosition => $issues) {
                foreach ($issues as $issue) {
                    $coverIssues[] = 'direct cover ' . ($coverPosition + 1) . ": {$issue}";
                }
            }
            $reason = 'root contains ' . count($covers) . ' direct wp:cover blocks';
            if ($coverIssues !== []) {
                $reason .= '; ' . implode('; ', $coverIssues);
            }
            return self::imageCoverFailure(
                $markup,
                'multiple-direct-covers',
                $reason,
            );
        }

        $cover = $covers[0];
        $offset = $doc->openingOffset($cover);
        $length = $doc->openingLength($cover);
        $rawOpening = substr($markup, $offset, $length);
        if (preg_match('/\A<!--\s+wp:cover(?<tail>(?:(?!-->).)*)-->\z/s', $rawOpening, $opening) !== 1) {
            return self::imageCoverFailure(
                $markup,
                'unusable-cover-opener',
                'direct wp:cover opener is malformed',
            );
        }
        $tail = trim((string) ($opening['tail'] ?? ''));
        if ($tail === '') {
            $attrs = new \stdClass();
        } else {
            if (!str_starts_with($tail, '{') || !str_ends_with($tail, '}')) {
                return self::imageCoverFailure(
                    $markup,
                    'unusable-cover-opener',
                    'direct wp:cover opener does not contain an attribute object',
                );
            }
            $attrs = json_decode($tail);
            if (!$attrs instanceof \stdClass || json_last_error() !== JSON_ERROR_NONE) {
                return self::imageCoverFailure(
                    $markup,
                    'invalid-cover-attributes',
                    'direct wp:cover attributes are not valid object JSON',
                );
            }
        }

        $beforeSanitation = self::encodeAttrs($attrs);
        $sanitized = self::sanitizeCoverFallbackAttributes($attrs);
        if ($sanitized !== []) {
            $fallbackMarkup = $originalMarkup;
            if (self::encodeAttrs($attrs) !== $beforeSanitation) {
                $newOpening = '<!-- wp:cover ' . self::encodeAttrs($attrs) . ' -->';
                $fallbackMarkup = substr_replace($originalMarkup, $newOpening, $offset, $length);
            }
            return self::imageCoverFailure(
                $fallbackMarkup,
                'unusable-cover-attributes',
                implode('; ', $sanitized),
            );
        }

        try {
            $patched = self::rewriteWrapperBlock(
                $markup,
                $attrs,
                $offset,
                $length,
                'wp:cover',
                self::presetRef($preset),
                self::presetRef($preset),
                $label,
                $label . ' direct cover',
                'cover style',
            );
        } catch (\RuntimeException $error) {
            return self::imageCoverFailure(
                $originalMarkup,
                'unusable-cover-attributes',
                preg_replace('/\Asection-rhythm:\s*/', '', $error->getMessage()) ?? $error->getMessage(),
            );
        }
        return ['markup' => $patched, 'degradation' => null];
    }

    /**
     * Take ownership of one wrapper's vertical rhythm: strip owned classes,
     * mirror horizontal shorthands into the attrs, pin the vertical
     * padding/margin, and splice the updated opener back into the markup.
     *
     * @param string $wrapperLabel label for saved-HTML edits (strip/preserve)
     * @param string $stylePath attribute path prefix used in error messages
     */
    private static function rewriteWrapperBlock(
        string $markup,
        \stdClass $attrs,
        int $offset,
        int $length,
        string $blockName,
        string $paddingTop,
        string $paddingBottom,
        string $label,
        string $wrapperLabel,
        string $stylePath,
    ): string {
        $before = self::encodeAttrs($attrs);
        $markup = self::stripOwnedWrapperClasses(
            $attrs,
            $markup,
            $offset + $length,
            $wrapperLabel,
        );

        $style = self::objectProperty($attrs, 'style', $label, $stylePath);
        $spacing = self::objectProperty($style, 'spacing', $label, "{$stylePath}.spacing");
        $padding = self::boxProperty($spacing, 'padding', $label, "{$stylePath}.spacing.padding");
        $margin = self::boxProperty($spacing, 'margin', $label, "{$stylePath}.spacing.margin");
        $shorthandProperties = self::preserveWrapperHorizontalSpacing(
            $markup,
            $offset + $length,
            $padding,
            $margin,
            $wrapperLabel,
        );
        $padding->top = $paddingTop;
        $padding->bottom = $paddingBottom;
        $margin->top = '0';
        $margin->bottom = '0';

        // Patch the wrapper HTML first: it sits after the opener, so the
        // opener's offsets stay valid for the substr_replace below.
        $patched = self::patchWrapperStyle($markup, $offset + $length, [
            'margin-top'     => '0',
            'margin-bottom'  => '0',
            'padding-top'    => self::cssSpacingValue($paddingTop),
            'padding-bottom' => self::cssSpacingValue($paddingBottom),
        ], $shorthandProperties);
        if (self::encodeAttrs($attrs) !== $before) {
            $opening = "<!-- {$blockName} " . self::encodeAttrs($attrs) . ' -->';
            $patched = substr_replace($patched, $opening, $offset, $length);
        }
        return $patched;
    }

    /**
     * Inspect cover-local state this rhythm pass cannot safely edit, removing
     * only shapes that would also make the block serializer abort. Returning
     * issues makes either an unsafe-but-preserved value or actual sanitation a
     * degradation trigger; fully valid cover state stays on the image path.
     *
     * @return list<string> descriptions of unsafe state and whether it was
     *                      removed or preserved
     */
    private static function sanitizeCoverFallbackAttributes(\stdClass $attrs): array
    {
        $issues = [];
        if (property_exists($attrs, 'className') && !is_string($attrs->className)) {
            $issues[] = 'direct wp:cover className was not a string and was preserved for FixBlocks';
        }

        if (!property_exists($attrs, 'style')) {
            return $issues;
        }
        if (!$attrs->style instanceof \stdClass) {
            if (is_array($attrs->style) && $attrs->style === []) {
                $issues[] = 'direct wp:cover style was an empty list and was preserved for FixBlocks';
            } else {
                unset($attrs->style);
                $issues[] = 'direct wp:cover style was a serializer-fatal non-object and was removed';
            }
            return $issues;
        }

        $style = $attrs->style;
        if (!property_exists($style, 'spacing')) {
            return $issues;
        }
        if (!$style->spacing instanceof \stdClass) {
            if (is_array($style->spacing) && $style->spacing === []) {
                $issues[] = 'direct wp:cover style.spacing was an empty list and was preserved for FixBlocks';
            } else {
                unset($style->spacing);
                $issues[] = 'direct wp:cover style.spacing was a serializer-fatal non-object and was removed';
            }
            return $issues;
        }

        $spacing = $style->spacing;
        foreach (['padding', 'margin'] as $property) {
            if (!property_exists($spacing, $property)) {
                continue;
            }
            $value = $spacing->{$property};
            if (is_string($value)) {
                if (self::expandBoxShorthand($value, $property, true) === null) {
                    $issues[] = "direct wp:cover style.spacing.{$property}"
                        . ' was an unparseable shorthand and was preserved for FixBlocks';
                }
                continue;
            }
            if (!$value instanceof \stdClass) {
                if (is_array($value) && $value !== []) {
                    unset($spacing->{$property});
                    $issues[] = "direct wp:cover style.spacing.{$property}"
                        . ' was a serializer-fatal nonempty list and was removed';
                } else {
                    $issues[] = "direct wp:cover style.spacing.{$property}"
                        . ' was not an object and was preserved for FixBlocks';
                }
                continue;
            }
            foreach (['top', 'right', 'bottom', 'left'] as $side) {
                if (property_exists($value, $side)
                    && (is_array($value->{$side}) || $value->{$side} instanceof \stdClass)
                ) {
                    unset($value->{$side});
                    $issues[] = "direct wp:cover style.spacing.{$property}.{$side}"
                        . ' was a serializer-fatal object/list and was removed';
                }
            }
        }
        return $issues;
    }

    /**
     * @return array{
     *     markup:string,
     *     degradation:array{code:string,reason:string,newlyDetected:bool}
     * }
     */
    private static function imageCoverFailure(string $markup, string $code, string $reason): array
    {
        return [
            'markup' => $markup,
            'degradation' => ['code' => $code, 'reason' => $reason, 'newlyDetected' => true],
        ];
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
        $tagHtml = MarkupScan::wrapperTag($markup, $searchOffset);
        if ($tagHtml === null) {
            return $markup;
        }
        $style = MarkupScan::tagAttribute($tagHtml, 'style');
        if ($style === null) {
            return $markup;
        }
        [$value, $valueOffset] = $style;

        $seen = [];
        $out = [];
        foreach (MarkupScan::parseInlineStyle($value) as $declaration) {
            $property = $declaration['property'];
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
            $out[] = $declaration['segment'];
        }

        $newValue = implode(';', $out);
        if ($newValue === $value) {
            return $markup;
        }
        $newTag = substr_replace($tagHtml, $newValue, $valueOffset, strlen($value));
        return substr_replace($markup, $newTag, $searchOffset, strlen($tagHtml));
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

        $tagHtml = MarkupScan::wrapperTag($markup, $searchOffset);
        $classAttr = $tagHtml === null ? null : MarkupScan::tagAttribute($tagHtml, 'class');
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

    /** Whether a valid string className contains one exact token. */
    private static function hasClassToken(\stdClass $attrs, string $token): bool
    {
        if (!property_exists($attrs, 'className') || !is_string($attrs->className)) {
            return false;
        }
        $tokens = preg_split('/[\x20\t\r\n\f]+/', trim($attrs->className), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return in_array($token, $tokens, true);
    }

    /**
     * Persist the fallback decision in the root group's supported className
     * attribute. Gutenberg carries custom classes through re-serialization.
     */
    private static function markDegradedImage(string $markup, string $label): string
    {
        [$attrs, $openingOffset, $openingLength] = self::rootGroup($markup, $label);
        if (property_exists($attrs, 'className') && !is_string($attrs->className)) {
            throw new \RuntimeException("section-rhythm: {$label} has a non-string className attribute");
        }
        if (self::hasClassToken($attrs, self::DEGRADED_IMAGE_CLASS)) {
            return $markup;
        }

        $className = property_exists($attrs, 'className') ? trim($attrs->className) : '';
        $attrs->className = trim($className . ' ' . self::DEGRADED_IMAGE_CLASS);
        $opening = '<!-- wp:group ' . self::encodeAttrs($attrs) . ' -->';
        return substr_replace($markup, $opening, $openingOffset, $openingLength);
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
        $tagHtml = MarkupScan::wrapperTag($markup, $searchOffset);
        $style = $tagHtml === null ? null : MarkupScan::tagAttribute($tagHtml, 'style');
        if ($style === null) {
            return [];
        }

        $states = [
            'padding' => ['right' => null, 'left' => null],
            'margin'  => ['right' => null, 'left' => null],
        ];
        $shorthandProperties = [];
        foreach (MarkupScan::parseInlineStyle($style[0]) as $declaration) {
            if ($declaration['value'] === null) {
                continue;
            }
            $property = $declaration['property'];
            $rawValue = $declaration['value'];

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
            : MarkupScan::blockSpacingValue($value['value']);
        return $raw . ($value['important'] ? ' !important' : '');
    }

    /** @param array{value:string,important:bool} $value */
    private static function formatComparableSpacingValue(array $value): string
    {
        $raw = self::cssSpacingValue($value['value']);
        return $raw . ($value['important'] ? ' !important' : '');
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
        if ($doc->hasMismatchedDelimiters() || $doc->unclosedIndices() !== []) {
            throw new \RuntimeException(
                "section-rhythm: {$label} has unbalanced or mismatched block delimiters"
            );
        }
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
