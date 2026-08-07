<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Shared transactional removal of one media asset reference from block markup.
 *
 * Extracted from GenerateImagesStep so every step that must retire an
 * undeliverable asset reference degrades identically (BIGR-787): the innermost
 * structurally safe image/cover/media-text block is the isolation unit; a
 * cover keeps its wrapper and copy and loses only the image layer; media-text
 * unwraps and retains its inner blocks byte-for-byte; a bare rendered img with
 * no owning block loses just its tag. Markup whose reference sits in an
 * unsafe/unclosed span is left byte-identical — callers detect the residual
 * via position() and record it instead of half-mutating the file.
 */
final class MediaReferenceRemoval
{
    /** Remove every safely isolated occurrence of one asset source. */
    public static function removeSource(string $markup, string $source): string
    {
        while (($position = self::position($markup, $source)) !== null) {
            $document = BlockMarkup::parse($markup);
            $best = null;
            foreach ($document->indices() as $index) {
                if (!in_array($document->name($index), ['image', 'cover', 'media-text'], true)) {
                    continue;
                }
                $start = $document->openingOffset($index);
                $end = $document->endOffset($index);
                if ($end === null || $position < $start || $position >= $end) {
                    continue;
                }
                $length = $end - $start;
                if ($best === null || $length < $best['length']) {
                    $best = ['index' => $index, 'start' => $start, 'length' => $length];
                }
            }
            if ($best !== null) {
                $name = $document->name($best['index']);
                if ($name === 'cover') {
                    // A cover's image is only one visual layer. Strip that
                    // layer while retaining the cover wrapper and every byte
                    // of its headline, copy, buttons, and freeform content.
                    $coverMarkup = substr($markup, $best['start'], $best['length']);
                    $beforeCoverCleanup = $coverMarkup;
                    $coverDocument = BlockMarkup::parse($coverMarkup);
                    $coverIndex = $coverDocument->topLevel();
                    if ($coverIndex === null || $coverDocument->name($coverIndex) !== 'cover') {
                        break;
                    }
                    $attrs = $coverDocument->attrs($coverIndex);
                    if (is_array($attrs)) {
                        $changedAttrs = false;
                        foreach (['url', 'src'] as $key) {
                            if (($attrs[$key] ?? null) === $source) {
                                unset($attrs[$key]);
                                $changedAttrs = true;
                            }
                        }
                        if ($changedAttrs) {
                            unset($attrs['id'], $attrs['focalPoint']);
                            $coverDocument->setAttrs($coverIndex, $attrs);
                            $coverMarkup = $coverDocument->render();
                        }
                    }
                    $coverMarkup = self::removeMatchingImageTags($coverMarkup, $source);
                    $quotedSource = preg_quote($source, '~');
                    $coverMarkup = (string) preg_replace(
                        '~background-image\s*:\s*url\(\s*(["\']?)' . $quotedSource . '\1\s*\)\s*;?~i',
                        '',
                        $coverMarkup,
                    );
                    if ($coverMarkup === $beforeCoverCleanup) {
                        break;
                    }
                    $markup = substr_replace(
                        $markup,
                        $coverMarkup,
                        $best['start'],
                        $best['length'],
                    );
                    continue;
                }

                $replacement = '';
                if ($name === 'media-text') {
                    // Unwrap media-text and retain every authored inner block
                    // byte-for-byte; keeping its grid with no media would leave
                    // a large empty column beside the surviving copy.
                    foreach ($document->children($best['index']) as $child) {
                        $childStart = $document->openingOffset($child);
                        $childEnd = $document->endOffset($child);
                        if ($childEnd === null) {
                            $replacement = null;
                            break;
                        }
                        $replacement .= substr($markup, $childStart, $childEnd - $childStart);
                    }
                }
                if ($replacement === null) {
                    break;
                }
                if ($name === 'image') {
                    // A caption-styled paragraph directly under the removed
                    // image describes that image; without it the caption is an
                    // orphaned line about a photo the visitor cannot see.
                    $best['length'] = self::spanWithTrailingCaption($document, $markup, $best);
                }
                $markup = substr_replace(
                    $markup,
                    $replacement,
                    $best['start'],
                    $best['length'],
                );
                continue;
            }

            // Recovered placeholders can be bare HTML with no Gutenberg block.
            // Remove only an img whose src attribute is this exact source.
            $withoutImage = self::removeMatchingImageTags($markup, $source);
            if ($withoutImage === $markup) {
                break; // unsafe/unrecognized context: preserve the file bytes
            }
            $markup = $withoutImage;
        }
        return $markup;
    }

    /**
     * The removal length for an image block, extended over an immediately
     * following caption-styled sibling paragraph (only whitespace between
     * them, same parent). Ordinary prose siblings keep their bytes.
     *
     * @param array{index:int,start:int,length:int} $best
     */
    private static function spanWithTrailingCaption(BlockMarkup $document, string $markup, array $best): int
    {
        $end = $best['start'] + $best['length'];
        foreach ($document->indices() as $index) {
            $start = $document->openingOffset($index);
            if ($start < $end || $document->name($index) !== 'paragraph') {
                continue;
            }
            if ($document->parent($index) !== $document->parent($best['index'])) {
                continue;
            }
            if (trim(substr($markup, $end, $start - $end)) !== '') {
                continue;
            }
            $captionEnd = $document->endOffset($index);
            $opening = $document->openingComment($index);
            $isCaption = str_contains($opening, '"fontSize":"caption"')
                || str_contains($document->ownHtml($index), 'has-caption-font-size');
            return $captionEnd !== null && $isCaption
                ? $captionEnd - $best['start']
                : $best['length'];
        }
        return $best['length'];
    }

    /** Remove bare/rendered img tags whose src is this exact source. */
    public static function removeMatchingImageTags(string $markup, string $source): string
    {
        return (string) preg_replace_callback(
            '/<img\b[^>]*>/is',
            static function (array $match) use ($source): string {
                if (!preg_match(
                    '/\bsrc\s*=\s*(?:(["\'])' . preg_quote($source, '/') . '\1|'
                    . preg_quote($source, '/') . '(?=\s|\/?>))/i',
                    $match[0],
                )) {
                    return $match[0];
                }
                return '';
            },
            $markup,
        );
    }

    /** Byte position of an exact block-JSON url/src or HTML src value. */
    public static function position(string $markup, string $source): ?int
    {
        $quoted = preg_quote($source, '/');
        $patterns = [
            '/"(?:url|src)"\s*:\s*"' . $quoted . '"/i',
            '/\bsrc\s*=\s*(?:(["\'])' . $quoted . '\1|' . $quoted . '(?=\s|\/?>))/i',
        ];
        $positions = [];
        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $markup, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches[0] as [$match, $offset]) {
                $within = strpos($match, $source);
                if ($within !== false) {
                    $positions[] = $offset + $within;
                }
            }
        }
        return $positions === [] ? null : min($positions);
    }
}
