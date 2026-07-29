<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer;

use Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment;
use Automattic\SiteBuild\BlockSerializer\Html\HtmlNode;
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
 * the same named block before and after the complete FixBlocksStep transaction.
 * BlockNode::innerHTML excludes nested block bytes, so classes in a block's
 * save markup (including button links and table cells) are attributed once.
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
        } catch (\InvalidArgumentException | \RuntimeException) {
            // Generated markup can be malformed. Without a stable parse tree
            // there is no safe per-block attribution, so leave it to the
            // structural/fixer warning path and keep the build moving.
            return [];
        }

        if ($this->treeSignature($before) !== $this->treeSignature($after)) {
            // A path can now refer to a different same-named sibling after a
            // structural edit. Do not claim a localized loss unless the whole
            // named-block tree stayed stable.
            return [];
        }

        $losses = [];

        foreach ($before as $key => $block) {
            $final = $after[$key] ?? null;
            if ($final === null || !$block['comparable'] || !$final['comparable']) {
                continue;
            }

            foreach ($block['classes'] as $class => $family) {
                if (isset($final['classes'][$class])) {
                    continue;
                }
                $replacement = [];
                foreach ($final['classes'] as $deliveredClass => $deliveredFamily) {
                    if ($deliveredFamily === $family) {
                        $replacement[] = $deliveredClass;
                    }
                }
                $losses[] = new AlignmentClassLoss(
                    blockPath: $block['path'],
                    blockName: $block['name'],
                    authoredClass: $class,
                    deliveredClasses: $replacement,
                );
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
     *     classes:array<string,string>
     * }>
     */
    private function blocks(Document $document): array
    {
        $blocks = [];
        $index = 0;
        foreach ($document->nodes() as $node) {
            if ($node instanceof FreeformNode) {
                if ($this->jsTrim($node->content) !== '') {
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
     *     classes:array<string,string>
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
            'classes' => $snapshot['classes'],
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
     * @return array{comparable:bool,classes:array<string,string>}
     */
    private function alignmentSnapshot(BlockNode $block): array
    {
        $classes = [];
        if ($block->void) {
            return ['comparable' => false, 'classes' => $classes];
        }
        $fragment = HtmlFragment::parse($block->innerHTML);
        if ($fragment->root()->elementChildren() === []) {
            return ['comparable' => false, 'classes' => $classes];
        }
        $visit = function (HtmlNode $node) use (&$visit, &$classes): void {
            if ($node->isElement()) {
                foreach (preg_split(
                    '/[\x20\t\r\n\f]+/',
                    $node->attribute('class') ?? '',
                    -1,
                    PREG_SPLIT_NO_EMPTY,
                ) ?: [] as $class) {
                    $family = self::family($class);
                    if ($family !== null && !isset($classes[$class])) {
                        $classes[$class] = $family;
                    }
                }
            }
            foreach ($node->children() as $child) {
                $visit($child);
            }
        };
        $visit($fragment->root());
        return ['comparable' => true, 'classes' => $classes];
    }

    /**
     * @param array<string,array{
     *     path:string,
     *     name:string,
     *     comparable:bool,
     *     classes:array<string,string>
     * }> $blocks
     * @return list<array{0:string,1:string}>
     */
    private function treeSignature(array $blocks): array
    {
        return array_map(
            static fn (array $block): array => [$block['path'], $block['name']],
            array_values($blocks),
        );
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

    /** JavaScript trim semantics used by Serializer for top-level freeform. */
    private function jsTrim(string $value): string
    {
        return preg_replace(
            '/^[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+|[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+$/u',
            '',
            $value,
        ) ?? trim($value);
    }
}
