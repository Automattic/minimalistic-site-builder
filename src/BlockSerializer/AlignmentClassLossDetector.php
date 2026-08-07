<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer;

use Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment;
use Automattic\SiteBuild\BlockSerializer\Html\HtmlNode;
use Automattic\SiteBuild\BlockSerializer\Json\JsJsonEncoder;
use Automattic\SiteBuild\BlockSerializer\Json\JsonArray;
use Automattic\SiteBuild\BlockSerializer\Json\JsonObject;
use Automattic\SiteBuild\BlockSerializer\Json\JsonString;
use Automattic\SiteBuild\BlockSerializer\Json\JsonValue;
use Automattic\SiteBuild\BlockSerializer\Parser\BlockNode;
use Automattic\SiteBuild\BlockSerializer\Parser\DefaultParser;
use Automattic\SiteBuild\BlockSerializer\Parser\Document;
use Automattic\SiteBuild\BlockSerializer\Parser\FreeformNode;

/**
 * Compare authored and final markup at Gutenberg block-path granularity.
 *
 * DroppedContentDetector intentionally reports whole-file occurrence deltas
 * for oracle parity. That is useful evidence, but it cannot prove that one
 * particular block lost alignment: a class gained by one block can cancel a
 * loss on another, while collapsing a duplicate can look like a visual loss.
 * This detector instead compares the set of known alignment classes owned by
 * the same semantic block before and after the complete FixBlocksStep
 * transaction. Path alone is insufficient: inserting or reordering same-name
 * siblings can make a path refer to different content. BlockNode::innerHTML
 * excludes nested block bytes, so classes in a block's save markup (including
 * button links and table cells) are attributed once.
 */
final class AlignmentClassLossDetector
{
    /** @return list<AlignmentClassLoss> */
    public function detect(string $authored, string $delivered): array
    {
        if ($authored === $delivered) {
            return [];
        }

        try {
            $before = $this->blocks(DefaultParser::parse($authored));
            $after = $this->blocks(DefaultParser::parse($delivered));
        } catch (\Throwable) {
            // Generated markup can be malformed. Without a stable parse tree
            // there is no safe per-block attribution, so leave it to the
            // structural/fixer warning path and keep the build moving.
            return [];
        }

        $losses = [];
        $beforeIdentityCounts = self::identityCounts($before);
        $uniformBeforeProvenance = self::uniformIdentityProvenance($before);
        $afterByIdentity = self::blocksByIdentity($after);

        foreach ($before as $key => $block) {
            if (!$block['comparable']) {
                continue;
            }

            $samePath = $after[$key] ?? null;
            $identityKey = self::identityKey($block);
            $identityMatches = $afterByIdentity[$identityKey] ?? [];
            $attributionStable = false;
            $final = null;

            if ($samePath !== null
                && $samePath['name'] === $block['name']
                && $samePath['identity'] === $block['identity']
            ) {
                // The same semantic block still owns the same path. Repair
                // safety additionally requires unique identity on both sides,
                // or an unchanged-size duplicate cohort whose authored
                // alignment provenance is identical. In the latter case a
                // swap cannot change which alignment belongs at this path.
                $final = $samePath;
                $beforeCount = $beforeIdentityCounts[$identityKey] ?? 0;
                $afterCount = count($identityMatches);
                $attributionStable = ($beforeCount === 1 && $afterCount === 1)
                    || ($beforeCount > 1
                        && $beforeCount === $afterCount
                        && ($uniformBeforeProvenance[$identityKey] ?? false));
            } elseif (($beforeIdentityCounts[$identityKey] ?? 0) === 1
                && count($identityMatches) === 1
            ) {
                // Uniquely identifiable content may move without becoming a
                // false loss. A moved path is still not eligible for repair:
                // the repairer addresses final paths and this DTO retains the
                // authored path for cross-baseline warning provenance.
                $final = $identityMatches[0];
            } elseif ($samePath !== null
                && $samePath['name'] === $block['name']
                && ($beforeIdentityCounts[self::identityKey($samePath)] ?? 0) === 0
            ) {
                // Content may have been rewritten in place, or this path may
                // contain genuinely new content. Its delivered classes remain
                // useful warning evidence, but attribution is deliberately too
                // weak for deterministic repair. A same-path identity known to
                // belong to another authored block is never cross-matched.
                $final = $samePath;
            }

            foreach ([true, false] as $authoredOnRoot) {
                $authoredOccurrences = $authoredOnRoot
                    ? ['root' => $block['rootClasses']]
                    : $block['descendantClassOccurrences'];
                $deliveredOccurrences = $final === null
                    ? []
                    : ($authoredOnRoot
                        ? ['root' => ($final['comparable']
                            ? $final['rootClasses']
                            : $final['commentClasses'])]
                        : ($final['comparable'] ? $final['descendantClassOccurrences'] : []));
                foreach ($authoredOccurrences as $elementPath => $authoredAtScope) {
                    $deliveredAtSameScope = $deliveredOccurrences[$elementPath] ?? [];
                    foreach ($authoredAtScope as $class => $family) {
                        if (isset($deliveredAtSameScope[$class])) {
                            continue;
                        }
                        if ($family === 'text'
                            && $authoredOnRoot
                            && $final !== null
                            && $block['rootInlineTextAlignmentFingerprint'] !== null
                            && $block['rootInlineTextAlignmentFingerprint']
                                === $final['rootInlineTextAlignmentFingerprint']
                        ) {
                            // The removed class was not the effective alignment:
                            // the same root inline value won before and after.
                            // This is not repair-queue work.
                            continue;
                        }
                        $replacement = [];
                        foreach ($deliveredAtSameScope as $deliveredClass => $deliveredFamily) {
                            if ($deliveredFamily === $family) {
                                $replacement[] = $deliveredClass;
                            }
                        }
                        $losses[] = new AlignmentClassLoss(
                            blockPath: $block['path'],
                            blockName: $block['name'],
                            authoredClass: $class,
                            deliveredClasses: $replacement,
                            authoredClassOnSavedRoot: $authoredOnRoot,
                            authoredClassIsSafeRootTextAlignment: $attributionStable
                                && $final !== null
                                && $final['comparable']
                                && $authoredOnRoot
                                && isset($block['repairableRootTextClasses'][$class]),
                            deliveredBlockPath: $final['path'] ?? null,
                            authoredElementPath: $authoredOnRoot ? null : (string) $elementPath,
                        );
                    }
                }
            }
        }

        return $losses;
    }

    /**
     * Paths mirror Serializer: blank top-level freeform does not consume an
     * index, nonblank freeform does, and nested block indices are local to the
     * parent's innerBlocks list.
     *
     * @return array<string,array{
     *     path:string,
     *     name:string,
     *     comparable:bool,
     *     rootClasses:array<string,string>,
     *     descendantClassOccurrences:array<string,array<string,string>>,
     *     repairableRootTextClasses:array<string,true>,
     *     rootInlineTextAlignmentFingerprint:?string,
     *     commentClasses:array<string,string>,
     *     identity:string
     * }>
     */
    private function blocks(Document $document): array
    {
        $blocks = [];
        $index = 0;
        foreach ($document->nodes() as $node) {
            if ($node instanceof FreeformNode) {
                if (JsString::trim($node->content) !== '') {
                    $index++;
                }
                continue;
            }
            if (!$node instanceof BlockNode) {
                continue;
            }
            $this->collectBlock($node, (string) $index, $blocks);
            $index++;
        }
        return $blocks;
    }

    /**
     * @param array<string,array{
     *     path:string,
     *     name:string,
     *     comparable:bool,
     *     rootClasses:array<string,string>,
     *     descendantClassOccurrences:array<string,array<string,string>>,
     *     repairableRootTextClasses:array<string,true>,
     *     rootInlineTextAlignmentFingerprint:?string,
     *     commentClasses:array<string,string>,
     *     identity:string
     * }> $blocks
     */
    private function collectBlock(BlockNode $block, string $path, array &$blocks): void
    {
        // Numeric-string keys such as top-level path "0" are coerced to ints
        // by PHP. Prefix the lookup key and retain the real path in the value
        // so the DTO's strict string contract is preserved.
        $snapshot = $this->alignmentSnapshot($block);
        $blocks['path:' . $path] = [
            'path' => $path,
            'name' => $block->name,
            'comparable' => $snapshot['comparable'],
            'rootClasses' => $snapshot['rootClasses'],
            'descendantClassOccurrences' => $snapshot['descendantClassOccurrences'],
            'repairableRootTextClasses' => $snapshot['repairableRootTextClasses'],
            'rootInlineTextAlignmentFingerprint' => $snapshot['rootInlineTextAlignmentFingerprint'],
            'commentClasses' => self::commentAlignmentClasses($block),
            'identity' => $this->semanticIdentity($block),
        ];
        foreach ($block->innerBlocks as $index => $child) {
            $this->collectBlock($child, $path . '/' . $index, $blocks);
        }
    }

    /**
     * Dynamic/self-closing blocks have no visitor-facing saved element to
     * compare. Their comment attributes may still carry renderer state, so an
     * absent HTML class is not evidence of a delivered alignment loss.
     *
     * @return array{
     *     comparable:bool,
     *     rootClasses:array<string,string>,
     *     descendantClassOccurrences:array<string,array<string,string>>,
     *     repairableRootTextClasses:array<string,true>,
     *     rootInlineTextAlignmentFingerprint:?string
     * }
     */
    private function alignmentSnapshot(BlockNode $block): array
    {
        $rootClasses = [];
        $descendantClassOccurrences = [];
        $repairableRootTextClasses = [];
        $rootInlineTextAlignmentFingerprint = null;
        if ($block->void) {
            return [
                'comparable' => false,
                'rootClasses' => $rootClasses,
                'descendantClassOccurrences' => $descendantClassOccurrences,
                'repairableRootTextClasses' => $repairableRootTextClasses,
                'rootInlineTextAlignmentFingerprint' => $rootInlineTextAlignmentFingerprint,
            ];
        }
        $fragment = HtmlFragment::parse($block->innerHTML);
        if ($fragment->root()->elementChildren() === []) {
            return [
                'comparable' => false,
                'rootClasses' => $rootClasses,
                'descendantClassOccurrences' => $descendantClassOccurrences,
                'repairableRootTextClasses' => $repairableRootTextClasses,
                'rootInlineTextAlignmentFingerprint' => $rootInlineTextAlignmentFingerprint,
            ];
        }
        $savedRoot = null;
        foreach ($fragment->root()->children() as $child) {
            if ($child->isComment()
                || ($child->isText() && JsString::trim($child->textContent()) === '')
            ) {
                continue;
            }
            if (!$child->isElement() || $savedRoot !== null) {
                $savedRoot = null;
                break;
            }
            $savedRoot = $child;
        }
        if ($savedRoot !== null) {
            foreach (preg_split(
                '/[\x20\t\r\n\f]+/',
                $savedRoot->attribute('class') ?? '',
                -1,
                PREG_SPLIT_NO_EMPTY,
            ) ?: [] as $class) {
                $family = self::family($class);
                if ($family !== null) {
                    $rootClasses[$class] = $family;
                }
            }
            $repairableRootTextClasses = self::repairableRootTextClasses(
                $savedRoot,
                $rootClasses,
            );
            $inline = TextAlignmentCss::effectiveInline($savedRoot);
            if (($inline['safe'] ?? false) === true) {
                $rootInlineTextAlignmentFingerprint = $inline['value']
                    . ($inline['important'] ? "\0important" : "\0normal");
            }
        }
        $visit = function (HtmlNode $node) use (
            &$visit,
            &$descendantClassOccurrences,
            $savedRoot,
            &$elementOrdinal,
        ): void {
            $path = $node->isDocument() ? '' : $elementOrdinal;
            if ($node->isElement()) {
                foreach (preg_split(
                    '/[\x20\t\r\n\f]+/',
                    $node->attribute('class') ?? '',
                    -1,
                    PREG_SPLIT_NO_EMPTY,
                ) ?: [] as $class) {
                    $family = self::family($class);
                    if ($family !== null
                        && $node !== $savedRoot
                    ) {
                        $descendantClassOccurrences[$path][$class] = $family;
                    }
                }
            }
            $childElementIndex = 0;
            foreach ($node->children() as $child) {
                if (!$child->isElement()) {
                    continue;
                }
                $previousOrdinal = $elementOrdinal;
                $elementOrdinal = $path === ''
                    ? (string) $childElementIndex
                    : $path . '/' . $childElementIndex;
                $visit($child);
                $elementOrdinal = $previousOrdinal;
                $childElementIndex++;
            }
        };
        $elementOrdinal = '';
        $visit($fragment->root());
        return [
            'comparable' => true,
            'rootClasses' => $rootClasses,
            'descendantClassOccurrences' => $descendantClassOccurrences,
            'repairableRootTextClasses' => $repairableRootTextClasses,
            'rootInlineTextAlignmentFingerprint' => $rootInlineTextAlignmentFingerprint,
        ];
    }

    /**
     * Identity is based on content/structure plus the identities of nested
     * blocks. Presentational class and style attributes are omitted so the
     * transformation under review does not itself sever attribution. Stable
     * attributes such as ids, links and media sources still distinguish
     * otherwise similar siblings. Ambiguous duplicate identities are never
     * repair proof.
     */
    private function semanticIdentity(BlockNode $block): string
    {
        $fragment = HtmlFragment::parse($block->innerHTML);
        $children = array_map(
            fn (BlockNode $child): string => $this->semanticIdentity($child),
            $block->innerBlocks,
        );
        return hash('sha256', serialize([
            self::semanticCommentAttributes($block),
            self::semanticNode($fragment->root()),
            $children,
        ]));
    }

    /**
     * Retain comment-only ownership data (notably metadata) in block identity
     * while removing representations the reviewed serializer legitimately
     * migrates during this repair. Presentation-only className is already
     * represented by saved HTML and heading level is represented by its tag.
     *
     * @return array<mixed>
     */
    private static function semanticCommentAttributes(BlockNode $block): array
    {
        if ($block->attributes === null) {
            return ['malformed', $block->rawAttributes];
        }
        $encoded = JsJsonEncoder::stringify($block->attributes);
        $attributes = $encoded === null ? null : JsonValue::tryParse($encoded);
        if (!$attributes instanceof JsonObject) {
            return ['malformed', $block->rawAttributes];
        }

        $attributes->remove('className');
        $attributes->remove('textAlign');
        if ($block->name === 'core/heading') {
            $attributes->remove('level');
        }
        if ($block->name === 'core/paragraph') {
            $align = $attributes->get('align');
            if ($align instanceof JsonString
                && in_array($align->toNative(), ['', 'left', 'center', 'right'], true)
            ) {
                $attributes->remove('align');
            }
        }

        $style = $attributes->get('style');
        $typography = $style instanceof JsonObject ? $style->get('typography') : null;
        if ($typography instanceof JsonObject) {
            $typography->remove('textAlign');
            if (count($typography) === 0) {
                $style->remove('typography');
            }
        }
        if ($style instanceof JsonObject && count($style) === 0) {
            $attributes->remove('style');
        }
        return self::semanticJsonValue($attributes);
    }

    /** @return array<mixed> */
    private static function semanticJsonValue(JsonValue $value): array
    {
        if ($value instanceof JsonObject) {
            $members = [];
            foreach ($value->entries() as $entry) {
                $members[$entry['key']] = self::semanticJsonValue($entry['value']);
            }
            ksort($members, SORT_STRING);
            return ['object', $members];
        }
        if ($value instanceof JsonArray) {
            return ['array', array_map(self::semanticJsonValue(...), $value->items())];
        }
        return [$value::class, $value->toNative()];
    }

    /** @return array<string,string> */
    private static function commentAlignmentClasses(BlockNode $block): array
    {
        $classes = [];
        $className = $block->attributes?->get('className');
        if (!$className instanceof JsonString) {
            return $classes;
        }
        foreach (preg_split(
            '/[\x20\t\r\n\f]+/',
            $className->toNative(),
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [] as $class) {
            $family = self::family($class);
            if ($family !== null) {
                $classes[$class] = $family;
            }
        }
        return $classes;
    }

    /** @return array<mixed>|string|null */
    private static function semanticNode(HtmlNode $node): array|string|null
    {
        if ($node->isComment()) {
            return null;
        }
        if ($node->isText()) {
            $text = preg_replace('/[\x20\t\r\n\f]+/u', ' ', $node->textContent());
            return ['text', $text ?? $node->textContent()];
        }

        $attributes = [];
        if ($node->isElement()) {
            foreach ($node->attributes() as $attribute) {
                if (in_array($attribute['name'], ['class', 'style'], true)) {
                    continue;
                }
                $attributes[$attribute['name']] = [
                    $attribute['hasValue'],
                    $attribute['value'],
                ];
            }
            ksort($attributes, SORT_STRING);
        }

        $children = [];
        foreach ($node->children() as $child) {
            if ($node->isDocument()
                && $child->isText()
                && JsString::trim($child->textContent()) === ''
            ) {
                // Serializer formatting around the sole saved root is inert
                // and must not make otherwise identical content look moved.
                continue;
            }
            $semantic = self::semanticNode($child);
            if ($semantic !== null) {
                $children[] = $semantic;
            }
        }
        return [
            $node->isDocument() ? 'document' : 'element',
            $node->tagName(),
            $attributes,
            $children,
        ];
    }

    /**
     * @param array<string,array{name:string,identity:string}> $blocks
     * @return array<string,int>
     */
    private static function identityCounts(array $blocks): array
    {
        $counts = [];
        foreach ($blocks as $block) {
            $key = self::identityKey($block);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        return $counts;
    }

    /**
     * A duplicate semantic cohort is still safe when every authored member
     * owns exactly the same alignment state. Reordering those members cannot
     * change the repair requested at any surviving same-identity path.
     *
     * @param array<string,array{
     *     name:string,
     *     identity:string,
     *     rootClasses:array<string,string>,
     *     descendantClassOccurrences:array<string,array<string,string>>,
     *     repairableRootTextClasses:array<string,true>,
     *     rootInlineTextAlignmentFingerprint:?string
     * }> $blocks
     * @return array<string,bool>
     */
    private static function uniformIdentityProvenance(array $blocks): array
    {
        $provenance = [];
        foreach ($blocks as $block) {
            $rootClasses = $block['rootClasses'];
            $descendantClassOccurrences = $block['descendantClassOccurrences'];
            $repairable = $block['repairableRootTextClasses'];
            ksort($rootClasses, SORT_STRING);
            ksort($descendantClassOccurrences, SORT_STRING);
            foreach ($descendantClassOccurrences as &$classes) {
                ksort($classes, SORT_STRING);
            }
            unset($classes);
            ksort($repairable, SORT_STRING);
            $provenance[self::identityKey($block)][hash('sha256', serialize([
                $rootClasses,
                $descendantClassOccurrences,
                $repairable,
                $block['rootInlineTextAlignmentFingerprint'],
            ]))] = true;
        }
        return array_map(
            static fn (array $states): bool => count($states) === 1,
            $provenance,
        );
    }

    /**
     * @template T of array{name:string,identity:string}
     * @param array<string,T> $blocks
     * @return array<string,list<T>>
     */
    private static function blocksByIdentity(array $blocks): array
    {
        $indexed = [];
        foreach ($blocks as $block) {
            $indexed[self::identityKey($block)][] = $block;
        }
        return $indexed;
    }

    /** @param array{name:string,identity:string} $block */
    private static function identityKey(array $block): string
    {
        return $block['name'] . "\0" . $block['identity'];
    }

    private static function family(string $class): ?string
    {
        return match (true) {
            preg_match('/^has-text-align-(?:left|center|right)$/', $class) === 1
                => 'text',
            preg_match('/^align(?:full|wide|left|right|center|none)$/', $class) === 1
                => 'width',
            preg_match('/^is-vertically-aligned-(?:top|center|bottom|stretch)$/', $class) === 1
                => 'vertical-item',
            preg_match('/^are-vertically-aligned-(?:top|center|bottom)$/', $class) === 1
                => 'vertical-container',
            default => null,
        };
    }

    /**
     * @param array<string,string> $rootClasses
     * @return array<string,true>
     */
    private static function repairableRootTextClasses(HtmlNode $root, array $rootClasses): array
    {
        $textClasses = array_values(array_filter(
            array_keys($rootClasses),
            static fn (string $class): bool => self::family($class) === 'text',
        ));
        if (count($textClasses) !== 1
            || preg_match('/^has-text-align-(left|center|right)$/', $textClasses[0], $match) !== 1
        ) {
            return [];
        }
        $inline = TextAlignmentCss::effectiveInline($root);
        if ($inline !== null && (!$inline['safe'] || $inline['value'] !== $match[1])) {
            return [];
        }
        return [$textClasses[0] => true];
    }
}
