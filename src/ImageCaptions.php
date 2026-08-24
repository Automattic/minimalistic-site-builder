<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment;
use Automattic\SiteBuild\BlockSerializer\Html\HtmlNode;
use Automattic\SiteBuild\BlockSerializer\Serializer;

/**
 * Remove image captions outside galleries.
 *
 * Reviewed direction (BIGR-867): a caption under a card or standalone image
 * reads as clutter (audited: maxwelldemo7, 8 figcaptions, none in a gallery).
 * An image carries a caption only inside a core/gallery, where captions are
 * permitted but never required.
 *
 * BOTH removal channels are required, and neither is redundant. The registry
 * declares core/image's `caption` as `source: rich-text, selector: figcaption`,
 * so a parse re-derives the attribute from a surviving <figcaption>; and
 * CoreBlockRenderer::image() re-emits a <figcaption> from a surviving
 * attribute. The initial policy pass runs immediately before the block fixer's
 * full parse/serialize round trip, so removing one channel alone is simply
 * undone — the same dual-channel defect BIGR-861 shipped twice before its
 * third round fixed it by splicing the HTML "in the same pass as the comment
 * attr". FixBlocksStep retries the policy after structural repair as well.
 *
 * A document with mismatched delimiters is skipped whole. Not because the
 * ancestor walk would fail — it does not: BlockMarkup resolves parentage
 * through the open stack, so an image under an unclosed wp:gallery still
 * reports parent=gallery and is spared correctly. The reason is narrower:
 * in a structurally broken document the byte offsets this pass splices on
 * are not trustworthy, so it declines to mutate at all and notes the skip.
 */
final class ImageCaptions
{
    /**
     * @return array{markup:string, notes:list<string>, warnings:list<string>}
     */
    public static function stripOutsideGalleries(string $markup): array
    {
        $result = self::stripOutsideGalleriesDetailed($markup);
        return [
            'markup' => $result['markup'],
            'notes' => $result['notes'],
            'warnings' => array_map(self::formatRemoval(...), $result['removals']),
        ];
    }

    /**
     * Preserve raw authored values for callers that aggregate multiple passes.
     *
     * @return array{
     *     markup:string,
     *     notes:list<string>,
     *     removals:list<array{block:string, identity:string, values:list<mixed>, disposition:string}>,
     *     deferred:list<array{block:string, identity:string, values:list<mixed>, disposition:string}>,
     *     images:list<array{block:string, identity:string}>
     * }
     */
    public static function stripOutsideGalleriesDetailed(string $markup): array
    {
        try {
            return self::strip($markup);
        } catch (\Throwable $e) {
            return [
                'markup'   => $markup,
                'notes'    => ['skipped image-caption removal: ' . $e->getMessage()],
                'removals' => [],
                'deferred' => [],
                'images'   => [],
            ];
        }
    }

    /**
     * @return array{
     *     markup:string,
     *     notes:list<string>,
     *     removals:list<array{block:string, identity:string, values:list<mixed>, disposition:string}>,
     *     deferred:list<array{block:string, identity:string, values:list<mixed>, disposition:string}>,
     *     images:list<array{block:string, identity:string}>
     * }
     */
    private static function strip(string $markup): array
    {
        $document = BlockMarkup::parse($markup);
        $images = self::imageInventory($document);
        if ($document->hasMismatchedDelimiters()) {
            return [
                'markup'   => $markup,
                'notes'    => ['skipped image-caption removal: mismatched block delimiters'],
                'removals' => [],
                'deferred' => self::deferredMalformedAttributes($document),
                'images'   => $images,
            ];
        }

        $notes = [];
        $removals = [];
        $ordinal = 0;
        foreach ($document->indices() as $i) {
            if ($document->name($i) !== 'image') {
                continue;
            }
            $ordinal++;
            $block = "wp:image[{$ordinal}]";
            $identity = self::imageIdentity($document, $i);
            $insideGallery = self::insideGallery($document, $i);
            $malformed = self::removeMalformedAttribute($document, $i);
            if ($malformed['removed']) {
                $notes[] = "{$block}: removed malformed caption attribute";
            }
            if (!$document->isStructurallySafe($i)) {
                if ($malformed['removed']) {
                    $removals[] = [
                        'block' => $block,
                        'identity' => $identity,
                        'values' => $malformed['values'],
                        'disposition' => $insideGallery
                            ? 'removed a malformed image caption attribute'
                            : 'removed an image caption outside a gallery',
                    ];
                }
                $notes[] = "{$block}: left its caption in place (block is not structurally safe)";
                continue;
            }
            if ($insideGallery) {
                if ($malformed['removed']) {
                    $removals[] = [
                        'block' => $block,
                        'identity' => $identity,
                        'values' => $malformed['values'],
                        'disposition' => 'removed a malformed image caption attribute',
                    ];
                }
                continue;
            }
            $elements = self::removeElements($document, $i);
            $attribute = self::removeAttribute($document, $i);
            if (!$malformed['removed'] && !$elements['removed'] && !$attribute['removed']) {
                continue;
            }
            if ($elements['removed'] || $attribute['removed']) {
                $notes[] = "{$block}: removed caption";
            }
            $authored = self::uniqueValues(array_merge(
                $malformed['values'],
                $elements['values'],
                $attribute['values'],
            ));
            if ($authored === []) {
                // Only empty caption content was removed: it rendered nothing, so
                // this is AGENTS.md rung 2 and earns no warnings.json row.
                continue;
            }
            $removals[] = [
                'block' => $block,
                'identity' => $identity,
                'values' => $authored,
                'disposition' => 'removed an image caption outside a gallery',
            ];
        }

        return [
            'markup'   => $document->isMutated() ? $document->render() : $markup,
            'notes'    => $notes,
            'removals' => $removals,
            'deferred' => [],
            'images'   => $images,
        ];
    }

    /** @param array{block:string, identity:string, values:list<mixed>, disposition:string} $removal */
    public static function formatRemoval(array $removal): string
    {
        return $removal['block'] . ': authored value '
            . self::formatValues($removal['values'])
            . ' -> delivered removed; disposition: ' . $removal['disposition'];
    }

    /** @param array{block:string, identity:string, values:list<mixed>, disposition:string} $deferred */
    public static function formatDeferred(array $deferred): string
    {
        return $deferred['block'] . ': authored value '
            . self::formatValues($deferred['values'])
            . ' -> delivered unchanged; disposition: ' . $deferred['disposition'];
    }

    /** @return list<array{block:string, identity:string, values:list<mixed>, disposition:string}> */
    private static function deferredMalformedAttributes(BlockMarkup $document): array
    {
        $deferred = [];
        $ordinal = 0;
        foreach ($document->indices() as $i) {
            if ($document->name($i) !== 'image') {
                continue;
            }
            $ordinal++;
            $attrs = $document->attrs($i);
            if (!is_array($attrs)
                || !array_key_exists('caption', $attrs)
                || is_string($attrs['caption'])) {
                continue;
            }
            $deferred[] = [
                'block' => "wp:image[{$ordinal}]",
                'identity' => self::imageIdentity($document, $i),
                'values' => [$attrs['caption']],
                'disposition' => 'caption repair deferred because block delimiters remain mismatched',
            ];
        }
        return $deferred;
    }

    /**
     * Fingerprint the authored image independently of its document ordinal.
     * Caption is excluded because that is the value whose delivery we are
     * reconciling. Identity comes from canonical <img> attributes after an
     * isolated serializer pass. Only `src` and `alt` participate: deterministic
     * layout/shape normalization may legitimately change class/style/geometry
     * between the caption pre-pass and the fixer, while these content facts
     * remain stable. Identical content images are reconciled by occurrence.
     * Raw comment attributes are not stable: sourced keys such as `url` and
     * `alt` legitimately disappear after serialization.
     */
    private static function imageIdentity(BlockMarkup $document, int $i): string
    {
        $innerHtml = $document->innerHtml($i);
        $isolated = $document->openingComment($i) . $innerHtml;
        if (!$document->isVoid($i)) {
            $isolated .= '<!-- /wp:image -->';
        }
        try {
            $effective = (new Serializer())->transform($isolated)->html;
            $imageElements = self::imageElementFacts($effective);
        } catch (\Throwable) {
            $imageElements = [];
        }
        if ($imageElements === []) {
            $imageElements = self::imageElementFacts($innerHtml);
        }
        if ($imageElements !== []) {
            return hash('sha256', serialize($imageElements));
        }

        // A malformed image with no usable <img> still needs a deterministic
        // fail-closed identity for warning aggregation. These values are not
        // expected to survive serialization as an image.
        $attrs = $document->attrs($i) ?? [];
        unset($attrs['caption'], $attrs['url'], $attrs['alt'], $attrs['title']);
        return hash('sha256', serialize(self::canonicalIdentityValue($attrs)));
    }

    /** @return list<array<string,array{value:string,hasValue:bool}>> */
    private static function imageElementFacts(string $html): array
    {
        $imageElements = [];
        foreach (HtmlFragment::parse($html)->querySelectorAll('img') as $image) {
            if (self::hasCaptionAncestor($image)) {
                continue;
            }
            $elementAttrs = [];
            foreach (['src', 'alt'] as $name) {
                if (!$image->hasAttribute($name)) {
                    continue;
                }
                $elementAttrs[$name] = [
                    'value' => $image->attribute($name) ?? '',
                    'hasValue' => true,
                ];
            }
            $imageElements[] = $elementAttrs;
            // core/image owns one primary image (optionally inside a link).
            // Later images are caption descendants or invalid nested content,
            // neither of which can define the parent image's identity.
            break;
        }
        return $imageElements;
    }

    /** @return list<array{block:string, identity:string}> */
    private static function imageInventory(BlockMarkup $document): array
    {
        $images = [];
        $ordinal = 0;
        foreach ($document->indices() as $i) {
            if ($document->name($i) !== 'image') {
                continue;
            }
            $ordinal++;
            $images[] = [
                'block' => "wp:image[{$ordinal}]",
                'identity' => self::imageIdentity($document, $i),
            ];
        }
        return $images;
    }

    private static function canonicalIdentityValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalIdentityValue(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $child) {
            $value[$key] = self::canonicalIdentityValue($child);
        }
        return $value;
    }

    private static function insideGallery(BlockMarkup $document, int $i): bool
    {
        for ($p = $document->parent($i); $p !== null; $p = $document->parent($p)) {
            if ($document->name($p) === 'gallery') {
                return true;
            }
        }
        return false;
    }

    /**
     * Splice every actual <figcaption> element out of this image's owned HTML.
     * HtmlFragment supplies source spans, so tag-like text inside a quoted img
     * attribute cannot be mistaken for an element and recoverable closing-tag
     * spellings such as </figcaption > remain visible to the pass.
     *
     * @return array{removed:bool, values:list<string>}
     */
    private static function removeElements(BlockMarkup $document, int $i): array
    {
        $own = $document->ownHtml($i);
        $captions = HtmlFragment::parse($own)->querySelectorAll('figcaption');
        if ($captions === []) {
            return ['removed' => false, 'values' => []];
        }

        $values = [];
        foreach ($captions as $caption) {
            if (self::hasCaptionAncestor($caption)) {
                continue;
            }
            $document->spliceOwnHtml(
                $i,
                $caption->startOffset(),
                $caption->endOffset() - $caption->startOffset(),
                '',
            );
            $text = trim($caption->textContent());
            if ($text !== '') {
                $values[] = $text;
            }
        }

        return ['removed' => true, 'values' => $values];
    }

    private static function hasCaptionAncestor(HtmlNode $caption): bool
    {
        for ($parent = $caption->parent(); $parent !== null; $parent = $parent->parent()) {
            if ($parent->tagName() === 'figcaption') {
                return true;
            }
        }
        return false;
    }

    /**
     * Drop a non-empty or malformed `caption` comment attribute.
     *
     * An empty `caption` is left exactly as authored. It renders nothing —
     * CoreBlockRenderer::image() guards on richTextEmpty() — so it is an inert
     * value, and AGENTS.md rung 2 says leave harmless defects alone rather
     * than churn bytes and emit noise. Existing fixtures ship `"caption":""`
     * on images (tests/integration/shape_markup_test.php), and rewriting them
     * would be a change with no delivered effect.
     *
     * @return array{removed:bool, values:list<mixed>}
     */
    private static function removeAttribute(BlockMarkup $document, int $i): array
    {
        $attrs = $document->attrs($i);
        if (!is_array($attrs) || !array_key_exists('caption', $attrs)) {
            return ['removed' => false, 'values' => []];
        }
        $value = $attrs['caption'];
        if (is_string($value) && trim($value) === '') {
            return ['removed' => false, 'values' => []];
        }
        unset($attrs['caption']);
        $document->setAttrs($i, $attrs);
        return ['removed' => true, 'values' => [$value]];
    }

    /** @return array{removed:bool, values:list<mixed>} */
    private static function removeMalformedAttribute(BlockMarkup $document, int $i): array
    {
        $attrs = $document->attrs($i);
        if (!is_array($attrs)
            || !array_key_exists('caption', $attrs)
            || is_string($attrs['caption'])) {
            return ['removed' => false, 'values' => []];
        }
        $value = $attrs['caption'];
        unset($attrs['caption']);
        $document->setAttrs($i, $attrs);
        return ['removed' => true, 'values' => [$value]];
    }

    /** @param list<mixed> $values @return list<mixed> */
    private static function uniqueValues(array $values): array
    {
        $unique = [];
        $seen = [];
        foreach ($values as $value) {
            $key = serialize($value);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $value;
        }
        return $unique;
    }

    /** @param non-empty-list<mixed> $values */
    private static function formatValues(array $values): string
    {
        if (count($values) === 1) {
            return Warnings::value($values[0]);
        }
        $formatted = array_map(Warnings::value(...), $values);
        $counts = array_count_values($formatted);
        foreach ($formatted as $index => $value) {
            if ($counts[$value] > 1) {
                $formatted[$index] .= ' (fingerprint:'
                    . substr(hash('sha256', serialize($values[$index])), 0, 12) . ')';
            }
        }
        return '[' . implode(', ', $formatted) . ']';
    }
}
