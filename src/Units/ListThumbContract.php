<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\BlockSerializer\Json\JsonDecoder;
use Automattic\SiteBuild\BlockSerializer\Json\JsonObject;
use Automattic\SiteBuild\BlockSerializer\Json\JsonValue;
use Automattic\SiteBuild\MarkupSanitizer;
use Automattic\SiteBuild\Warnings;

/**
 * Normalize the generated list-thumb row invariants at the section boundary.
 *
 * A list-thumb row is deliberately recognized only when one of exactly two
 * direct wp:column children contains a direct wp:image with the documented
 * `card-media-thumb` hook. The row stays horizontal on Core's mobile stacking
 * breakpoint and its text column uses the tight, build-owned `xs` rhythm.
 *
 * Each row is transformed as an isolated byte snapshot. An ambiguous or
 * uninspectable row is delivered unchanged with an actionable warning, while
 * healthy sibling rows remain eligible for repair. Generated defects never
 * escape enforce().
 */
final class ListThumbContract
{
    private const THUMB_CLASS = 'card-media-thumb';
    private const NON_STACKING_CLASS = 'is-not-stacked-on-mobile';
    private const TEXT_GAP = 'var:preset|spacing|xs';

    /**
     * @param null|callable(string):BlockMarkup $parser injectable only so the
     *        parser failure boundary remains regression-testable
     * @return array{markup:string,repairs:list<array<string,mixed>>,warnings:list<string>}
     */
    public static function enforce(
        string $markup,
        string $part,
        ?callable $parser = null,
    ): array {
        try {
            $document = $parser === null ? BlockMarkup::parse($markup) : $parser($markup);
            if (!$document instanceof BlockMarkup) {
                throw new \RuntimeException('list-thumb contract parser returned no block document');
            }
        } catch (\Throwable $error) {
            return [
                'markup' => $markup,
                'repairs' => [],
                'warnings' => [self::documentWarning($markup, $part, $error)],
            ];
        }

        $candidates = [];
        $recognized = [];
        $warnings = [];
        foreach ($document->indices() as $index) {
            if (self::blockName($document->name($index)) !== 'columns') {
                continue;
            }

            try {
                $inspection = self::inspect($document, $index);
            } catch (\Throwable $error) {
                $warnings[] = self::rowWarning(
                    $part,
                    self::safePath($document, $index),
                    'row inspection failed: ' . self::oneLine($error->getMessage()),
                    'leave this list-thumb row unchanged and repair its two-column anatomy',
                );
                continue;
            }
            if (!$inspection['hasThumb']) {
                continue;
            }
            $recognized[] = $inspection;
            if ($inspection['issue'] !== null) {
                $warnings[] = self::rowWarning(
                    $part,
                    $inspection['path'],
                    $inspection['issue'],
                    'leave this ambiguous list-thumb row unchanged and restore one media column plus one text column',
                );
                continue;
            }
            $candidates[] = $inspection;
        }

        // A nested recognized row would make byte-range replacement violate
        // an ambiguous outer row's unchanged transaction. Compare healthy
        // candidates with every recognized row, not just other candidates,
        // so a valid inner row cannot rewrite bytes owned by a quarantined
        // outer row.
        $overlapping = [];
        foreach ($candidates as $left => $candidate) {
            foreach ($recognized as $other) {
                if ($candidate['index'] === $other['index']) {
                    continue;
                }
                if (self::isAncestor($document, $candidate['index'], $other['index'])
                    || self::isAncestor($document, $other['index'], $candidate['index'])
                    || self::rangesOverlap($candidate, $other)
                ) {
                    $overlapping[$left] = true;
                    break;
                }
            }
        }

        $operations = [];
        $repairs = [];
        foreach ($candidates as $ordinal => $candidate) {
            if (isset($overlapping[$ordinal])) {
                $warnings[] = self::rowWarning(
                    $part,
                    $candidate['path'],
                    'a list-thumb row overlaps another recognized list-thumb row',
                    'leave the nested row transaction unchanged and flatten the generated list anatomy',
                );
                continue;
            }

            $snapshot = substr($markup, $candidate['start'], $candidate['end'] - $candidate['start']);
            try {
                $normalized = self::normalizeSnapshot($snapshot, $part, $candidate['path']);
            } catch (\Throwable $error) {
                $warnings[] = self::rowWarning(
                    $part,
                    $candidate['path'],
                    'row normalization failed: ' . self::oneLine($error->getMessage()),
                    'deliver this row\'s pre-normalization bytes and repair it independently',
                );
                continue;
            }
            array_push($warnings, ...$normalized['warnings']);
            if ($normalized['markup'] === $snapshot) {
                continue;
            }
            $operations[] = [
                'start' => $candidate['start'],
                'length' => $candidate['end'] - $candidate['start'],
                'authored' => $snapshot,
                'delivered' => $normalized['markup'],
            ];
            array_push($repairs, ...$normalized['repairs']);
        }

        usort($operations, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);
        $delivered = $markup;
        foreach ($operations as $operation) {
            if (substr($delivered, $operation['start'], $operation['length']) !== $operation['authored']) {
                return [
                    'markup' => $markup,
                    'repairs' => [],
                    'warnings' => array_merge($warnings, [self::rowWarning(
                        $part,
                        'generated section',
                        'a list-thumb row transaction no longer matched its source bytes',
                        'deliver the section\'s pre-normalization bytes and retry each row independently',
                    )]),
                ];
            }
            $delivered = substr_replace(
                $delivered,
                $operation['delivered'],
                $operation['start'],
                $operation['length'],
            );
        }

        return ['markup' => $delivered, 'repairs' => $repairs, 'warnings' => $warnings];
    }

    /**
     * @return array{
     *   index:int,hasThumb:bool,issue:?string,path:string,start:int,end:int,
     *   mediaColumn:?int,textColumn:?int
     * }
     */
    private static function inspect(BlockMarkup $document, int $row): array
    {
        $path = self::path($document, $row);
        $start = $document->openingOffset($row);
        $end = $document->endOffset($row);
        $children = $document->children($row);
        $columns = array_values(array_filter(
            $children,
            static fn (int $child): bool => self::blockName($document->name($child)) === 'column',
        ));
        $mediaColumns = [];
        foreach ($columns as $column) {
            foreach ($document->children($column) as $child) {
                if (self::blockName($document->name($child)) === 'image'
                    && self::hasClass($document, $child, self::THUMB_CLASS)
                ) {
                    $mediaColumns[] = $column;
                    break;
                }
            }
        }
        $mediaColumns = array_values(array_unique($mediaColumns));
        $hasThumb = $mediaColumns !== [];
        $issue = null;
        if ($hasThumb && (!$document->isStructurallySafe($row) || $end === null)) {
            $issue = 'the list-thumb row has no complete structurally safe block boundary';
        } elseif ($hasThumb && (count($columns) !== 2 || count($children) !== 2)) {
            $issue = 'the list-thumb row has ' . count($columns) . ' direct columns and '
                . count($children) . ' direct blocks instead of exactly two columns';
        } elseif ($hasThumb && count($mediaColumns) !== 1) {
            $issue = 'the list-thumb row has ' . count($mediaColumns) . ' direct thumbnail columns instead of one';
        } elseif ($hasThumb && $mediaColumns[0] !== ($columns[0] ?? null)) {
            $issue = 'the thumbnail is not in the first direct column';
        } elseif ($hasThumb) {
            $mediaChildren = $document->children($columns[0]);
            if (count($mediaChildren) !== 1
                || self::blockName($document->name($mediaChildren[0])) !== 'image'
                || !self::hasClass($document, $mediaChildren[0], self::THUMB_CLASS)
            ) {
                $issue = 'the media column must contain only one direct card-media-thumb image';
            } else {
                $textChildren = $document->children($columns[1]);
                $textNames = array_map(
                    static fn (int $child): string => self::blockName($document->name($child)),
                    $textChildren,
                );
                $unsupported = array_values(array_diff($textNames, ['heading', 'paragraph']));
                if (!in_array('heading', $textNames, true)
                    || !in_array('paragraph', $textNames, true)
                    || $unsupported !== []
                ) {
                    $issue = 'the text column must contain direct heading and paragraph blocks only';
                }
            }
        }

        return [
            'index' => $row,
            'hasThumb' => $hasThumb,
            'issue' => $issue,
            'path' => $path,
            'start' => $start,
            'end' => $end ?? $start,
            'mediaColumn' => $issue === null && $hasThumb ? $mediaColumns[0] : null,
            'textColumn' => $issue === null && $hasThumb ? $columns[1] : null,
        ];
    }

    /**
     * @return array{markup:string,repairs:list<array<string,mixed>>,warnings:list<string>}
     */
    private static function normalizeSnapshot(string $markup, string $part, string $path): array
    {
        $document = BlockMarkup::parse($markup);
        $row = $document->topLevel();
        if ($row === null || self::blockName($document->name($row)) !== 'columns') {
            throw new \RuntimeException('isolated row snapshot lost its wp:columns root');
        }
        $inspection = self::inspect($document, $row);
        if (!$inspection['hasThumb'] || $inspection['issue'] !== null
            || !is_int($inspection['textColumn'])) {
            throw new \RuntimeException($inspection['issue'] ?? 'isolated row no longer matches list-thumb anatomy');
        }

        $warnings = [];
        $authored = [];
        $delivered = [];
        $mergedPaths = [];
        $rowDecoded = self::typedBlockAttributes($document->openingComment($row));
        $rowAttrs = $rowDecoded['attrs'];
        $rowMergedPaths = $rowDecoded['mergedPaths'];
        $authoredStacking = $rowAttrs->has('isStackedOnMobile')
            ? $rowAttrs->get('isStackedOnMobile')?->toNative()
            : 'default(true)';
        $htmlClasses = self::rootHtmlClasses($document, $row);
        if (!self::hasColumnsRootTag($document, $row) || $htmlClasses === null) {
            return [
                'markup' => $markup,
                'repairs' => [],
                'warnings' => [self::rowWarning(
                    $part,
                    $path,
                    'the saved wp:columns root class attribute could not be safely inspected',
                    'retain this row\'s pre-normalization bytes and repair its root wrapper independently',
                )],
            ];
        }
        $htmlHasNonStackingClass = in_array(self::NON_STACKING_CLASS, $htmlClasses, true);
        if ($authoredStacking !== false) {
            $rowAttrs->set('isStackedOnMobile', JsonValue::fromNative(false));
            $document->setTypedAttrs($row, $rowAttrs);
            array_push($mergedPaths, ...$rowMergedPaths);
            $authored['isStackedOnMobile'] = $authoredStacking;
            $delivered['isStackedOnMobile'] = false;
        }

        $textColumn = $inspection['textColumn'];
        $textDecoded = self::typedBlockAttributes($document->openingComment($textColumn));
        $textAttrs = $textDecoded['attrs'];
        $textMergedPaths = array_map(
            static fn (string $merged): string => 'textColumn.' . $merged,
            $textDecoded['mergedPaths'],
        );
        $style = $textAttrs->get('style');
        $style = $style === null ? new JsonObject() : $style;
        $spacing = $style instanceof JsonObject ? $style->get('spacing') : null;
        $spacing = $spacing === null ? new JsonObject() : $spacing;
        if (!$style instanceof JsonObject || !$spacing instanceof JsonObject) {
            $warnings[] = self::rowWarning(
                $part,
                $path . ' > wp:column[1]',
                'text style.spacing=' . Warnings::value(
                    $style instanceof JsonObject ? $spacing->toNative() : $style->toNative(),
                ),
                'leave the malformed text-column style unchanged; install blockGap=' . self::TEXT_GAP,
            );
        } else {
            $authoredGap = $spacing->has('blockGap')
                ? $spacing->get('blockGap')?->toNative()
                : 'inherited';
            if ($authoredGap !== self::TEXT_GAP) {
                $spacing->set('blockGap', JsonValue::fromNative(self::TEXT_GAP));
                $style->set('spacing', $spacing);
                $textAttrs->set('style', $style);
                $document->setTypedAttrs($textColumn, $textAttrs);
                array_push($mergedPaths, ...$textMergedPaths);
                $authored['textBlockGap'] = $authoredGap;
                $delivered['textBlockGap'] = self::TEXT_GAP;
            }
        }

        $normalized = $document->render();
        if (!$htmlHasNonStackingClass) {
            $normalizedDocument = BlockMarkup::parse($normalized);
            $normalizedRow = $normalizedDocument->topLevel();
            $withClass = is_int($normalizedRow)
                ? self::addRootHtmlClass(
                    $normalized,
                    $normalizedDocument,
                    $normalizedRow,
                    self::NON_STACKING_CLASS,
                )
                : null;
            if ($withClass === null) {
                return [
                    'markup' => $markup,
                    'repairs' => [],
                    'warnings' => [self::rowWarning(
                        $part,
                        $path,
                        'the saved wp:columns root class could not be synchronized',
                        'retain this row\'s pre-normalization bytes and repair its root wrapper independently',
                    )],
                ];
            }
            $normalized = $withClass;
            $authored['savedClass'] = 'missing ' . self::NON_STACKING_CLASS;
            $delivered['savedClass'] = self::NON_STACKING_CLASS;
        }
        $repairs = [];
        if ($normalized !== $markup) {
            $facets = [];
            if (array_key_exists('isStackedOnMobile', $delivered)) {
                $facets[] = 'mobile non-stacking attribute';
            }
            if (array_key_exists('savedClass', $delivered)) {
                $facets[] = 'saved wrapper behavior hook';
            }
            if (array_key_exists('textBlockGap', $delivered)) {
                $facets[] = 'tight intra-row text rhythm';
            }
            $repairs[] = [
                'code' => 'list-thumb-row-normalized',
                'part' => $part,
                'path' => $path,
                'authored' => $authored,
                'delivered' => $delivered,
                'disposition' => 'repaired ' . implode(', ', $facets),
            ];
            if ($mergedPaths !== []) {
                $repairs[] = [
                    'code' => 'duplicate-block-attribute-keys-merged',
                    'part' => $part,
                    'path' => $path,
                    'paths' => array_values(array_unique($mergedPaths)),
                    'authored' => 'duplicate object keys in the generated list-thumb row',
                    'delivered' => 'one deep-merged typed JSON object retaining non-conflicting members',
                    'disposition' => 'repaired',
                ];
            }
        }
        return ['markup' => $normalized, 'repairs' => $repairs, 'warnings' => $warnings];
    }

    /** @return array{attrs:JsonObject,mergedPaths:list<string>} */
    private static function typedBlockAttributes(string $opening): array
    {
        if (preg_match(
            '~\A<!--\s+wp:[a-z0-9-]+(?:/[a-z0-9-]+)?(?:\s+(?<attrs>\{.*\}))?\s+/?-->\z~is',
            $opening,
            $match,
        ) !== 1) {
            throw new \InvalidArgumentException('block comment attributes could not be safely inspected');
        }
        if (!isset($match['attrs']) || $match['attrs'] === '') {
            return ['attrs' => new JsonObject(), 'mergedPaths' => []];
        }
        $decoder = new JsonDecoder($match['attrs'], mergeDuplicateObjectKeys: true);
        $attrs = $decoder->decode();
        if (!$attrs instanceof JsonObject) {
            throw new \InvalidArgumentException('block comment attributes must decode to an object');
        }
        return ['attrs' => $attrs, 'mergedPaths' => $decoder->mergedDuplicateKeyPaths()];
    }

    private static function blockName(string $name): string
    {
        return str_starts_with($name, 'core/') ? substr($name, 5) : $name;
    }

    private static function hasClass(BlockMarkup $document, int $index, string $class): bool
    {
        $attrs = $document->attrs($index) ?? [];
        $attrClasses = is_string($attrs['className'] ?? null)
            ? preg_split('/\s+/', trim($attrs['className']), -1, PREG_SPLIT_NO_EMPTY) ?: []
            : [];
        if (in_array($class, $attrClasses, true)) {
            return true;
        }
        $htmlClasses = self::rootHtmlClasses($document, $index);
        return $htmlClasses !== null && in_array($class, $htmlClasses, true);
    }

    /** @return list<string>|null null means a missing root or malformed/duplicate class attribute */
    private static function rootHtmlClasses(BlockMarkup $document, int $index): ?array
    {
        $root = self::rootOpeningTag($document, $index);
        if ($root === null) {
            return null;
        }
        $classAttributes = array_values(array_filter(
            MarkupSanitizer::openingTagAttributes($root['tag']),
            static fn (array $attribute): bool => $attribute['name'] === 'class',
        ));
        if (count($classAttributes) > 1) {
            return null;
        }
        if ($classAttributes === []) {
            return [];
        }
        $attribute = $classAttributes[0];
        if ($attribute['valueStart'] === null || $attribute['valueEnd'] === null) {
            return null;
        }
        $value = substr(
            $root['tag'],
            $attribute['valueStart'],
            $attribute['valueEnd'] - $attribute['valueStart'],
        );
        return preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private static function hasColumnsRootTag(BlockMarkup $document, int $index): bool
    {
        $root = self::rootOpeningTag($document, $index);
        return $root !== null
            && preg_match('~\A<div(?=[\s>])~i', $root['tag']) === 1
            && preg_match('~/\s*>\z~', $root['tag']) !== 1;
    }

    private static function addRootHtmlClass(
        string $markup,
        BlockMarkup $document,
        int $index,
        string $class,
    ): ?string
    {
        $root = self::rootOpeningTag($document, $index);
        $classes = self::rootHtmlClasses($document, $index);
        if ($root === null || $classes === null) {
            return null;
        }
        if (in_array($class, $classes, true)) {
            return $markup;
        }
        $rewritten = self::tagWithClass($root['tag'], $class);
        if ($rewritten === null) {
            return null;
        }
        return substr_replace($markup, $rewritten, $root['offset'], strlen($root['tag']));
    }

    /** @return array{tag:string,offset:int}|null */
    private static function rootOpeningTag(BlockMarkup $document, int $index): ?array
    {
        if (preg_match(
            '~\A(?:(?:\s+)|(?:<!--(?:(?!-->).)*-->))*(?<tag><[a-z][a-z0-9:-]*(?=[\s>])'
                . '(?:[^>"\']+|"[^"]*"|\'[^\']*\')*>)~is',
            $document->ownHtml($index),
            $match,
            PREG_OFFSET_CAPTURE,
        ) !== 1) {
            return null;
        }
        return [
            'tag' => $match['tag'][0],
            'offset' => $document->openingOffset($index)
                + $document->openingLength($index)
                + $match['tag'][1],
        ];
    }

    private static function tagWithClass(string $tag, string $class): ?string
    {
        if (preg_match('~/\s*>\z~', $tag) === 1) {
            return null;
        }
        $classAttributes = array_values(array_filter(
            MarkupSanitizer::openingTagAttributes($tag),
            static fn (array $attribute): bool => $attribute['name'] === 'class',
        ));
        if (count($classAttributes) > 1) {
            return null;
        }
        if ($classAttributes === []) {
            $at = strrpos($tag, '>');
            return $at === false ? null : substr_replace($tag, ' class="' . $class . '"', $at, 0);
        }
        $attribute = $classAttributes[0];
        if ($attribute['valueStart'] === null || $attribute['valueEnd'] === null) {
            return null;
        }
        $valueStart = $attribute['valueStart'];
        $valueEnd = $attribute['valueEnd'];
        $separator = $valueEnd === $valueStart ? '' : ' ';
        $quote = $valueStart > 0 ? $tag[$valueStart - 1] : '';
        if (($quote === '"' || $quote === "'") && ($tag[$valueEnd] ?? '') === $quote) {
            return substr_replace($tag, $separator . $class, $valueEnd, 0);
        }
        $value = substr($tag, $valueStart, $valueEnd - $valueStart);
        return substr_replace(
            $tag,
            ' class="' . $value . $separator . $class . '"',
            $attribute['start'],
            $attribute['end'] - $attribute['start'],
        );
    }

    private static function isAncestor(BlockMarkup $document, int $ancestor, int $descendant): bool
    {
        for ($current = $document->parent($descendant); $current !== null; $current = $document->parent($current)) {
            if ($current === $ancestor) {
                return true;
            }
        }
        return false;
    }

    /** @param array{start:int,end:int} $left @param array{start:int,end:int} $right */
    private static function rangesOverlap(array $left, array $right): bool
    {
        return $left['end'] > $left['start']
            && $right['end'] > $right['start']
            && $left['start'] < $right['end']
            && $right['start'] < $left['end'];
    }

    private static function path(BlockMarkup $document, int $index): string
    {
        $parts = [];
        for ($current = $index; $current !== null; $current = $document->parent($current)) {
            $name = self::blockName($document->name($current));
            $parent = $document->parent($current);
            $siblings = $parent === null
                ? array_values(array_filter(
                    $document->indices(),
                    static fn (int $candidate): bool => $document->parent($candidate) === null,
                ))
                : $document->children($parent);
            $ordinal = 0;
            foreach ($siblings as $sibling) {
                if ($sibling === $current) {
                    break;
                }
                if (self::blockName($document->name($sibling)) === $name) {
                    $ordinal++;
                }
            }
            array_unshift($parts, "wp:{$name}[{$ordinal}]");
        }
        return implode(' > ', $parts);
    }

    private static function safePath(BlockMarkup $document, int $index): string
    {
        try {
            return self::path($document, $index);
        } catch (\Throwable) {
            return "wp:columns[{$index}]";
        }
    }

    private static function documentWarning(string $markup, string $part, \Throwable $error): string
    {
        return "file='theme/parts/{$part}.html'; block='generated section document'; authored="
            . Warnings::value($markup)
            . '; delivered=original section markup; disposition=list-thumb normalization could not parse the '
            . 'section, so its pre-transformation bytes were retained; repair list-thumb rows independently; '
            . 'inspection_error=' . Warnings::value(self::oneLine($error->getMessage()));
    }

    private static function rowWarning(
        string $part,
        string $path,
        string $authored,
        string $disposition,
    ): string {
        return "file='theme/parts/{$part}.html'; block='{$path}'; authored="
            . Warnings::value($authored)
            . '; delivered=unchanged; disposition=' . $disposition;
    }

    private static function oneLine(string $value): string
    {
        return str_replace(["\r", "\n"], ' ', $value);
    }
}
