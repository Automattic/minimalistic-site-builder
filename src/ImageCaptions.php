<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

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
 * attribute. The block fixer runs a full parse/serialize round trip right
 * after this pass, so removing one channel alone is simply undone — the same
 * dual-channel defect BIGR-861 shipped twice before its third round fixed it
 * by splicing the HTML "in the same pass as the comment attr".
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
    /** Attribute scan tolerates `>` inside quoted attribute values. */
    private const FIGCAPTION = '/<figcaption\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>.*?<\/figcaption>/is';

    /**
     * @return array{markup:string, notes:list<string>, warnings:list<string>}
     */
    public static function stripOutsideGalleries(string $markup): array
    {
        try {
            return self::strip($markup);
        } catch (\Throwable) {
            return ['markup' => $markup, 'notes' => [], 'warnings' => []];
        }
    }

    /**
     * @return array{markup:string, notes:list<string>, warnings:list<string>}
     */
    private static function strip(string $markup): array
    {
        $document = BlockMarkup::parse($markup);
        if ($document->hasMismatchedDelimiters()) {
            return [
                'markup'   => $markup,
                'notes'    => ['skipped image-caption removal: mismatched block delimiters'],
                'warnings' => [],
            ];
        }

        $notes = [];
        $warnings = [];
        $ordinal = 0;
        foreach ($document->indices() as $i) {
            if ($document->name($i) !== 'image') {
                continue;
            }
            $ordinal++;
            if (!$document->isStructurallySafe($i)) {
                $notes[] = "wp:image[{$ordinal}]: left its caption in place (block is not structurally safe)";
                continue;
            }
            if (self::insideGallery($document, $i)) {
                continue;
            }
            $text = self::removeElement($document, $i);
            $attrText = self::removeAttribute($document, $i);
            if ($text === null && $attrText === null) {
                continue;
            }
            $notes[] = "wp:image[{$ordinal}]: removed caption";
            $authored = null;
            foreach ([$text, $attrText] as $candidate) {
                if (is_string($candidate) && $candidate !== '') {
                    $authored = $candidate;
                    break;
                }
            }
            if ($authored === null) {
                // Only empty caption content was removed: it rendered nothing, so
                // this is AGENTS.md rung 2 and earns no warnings.json row.
                continue;
            }
            $warnings[] = "wp:image[{$ordinal}]: authored value "
                . Warnings::value($authored)
                . ' -> delivered removed; disposition: removed an image caption outside a gallery';
        }

        return [
            'markup'   => $document->isMutated() ? $document->render() : $markup,
            'notes'    => $notes,
            'warnings' => $warnings,
        ];
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
     * Splice the <figcaption> element out. Returns its text ('' when the
     * element was empty), or null when there was no element at all.
     */
    private static function removeElement(BlockMarkup $document, int $i): ?string
    {
        $own = $document->ownHtml($i);
        if (preg_match(self::FIGCAPTION, $own, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $element = $match[0][0];
        $document->spliceOwnHtml($i, $match[0][1], strlen($element), '');
        return trim(html_entity_decode(strip_tags($element), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Drop a non-empty `caption` comment attribute. Returns its text, or null
     * when there is nothing to do.
     *
     * An empty `caption` is left exactly as authored. It renders nothing —
     * CoreBlockRenderer::image() guards on richTextEmpty() — so it is an inert
     * value, and AGENTS.md rung 2 says leave harmless defects alone rather
     * than churn bytes and emit noise. Existing fixtures ship `"caption":""`
     * on images (tests/integration/shape_markup_test.php), and rewriting them
     * would be a change with no delivered effect.
     */
    private static function removeAttribute(BlockMarkup $document, int $i): ?string
    {
        $attrs = $document->attrs($i);
        if (!is_array($attrs) || !array_key_exists('caption', $attrs)) {
            return null;
        }
        $value = $attrs['caption'];
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        unset($attrs['caption']);
        $document->setAttrs($i, $attrs);
        return $value;
    }
}
