<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer;

use Automattic\SiteBuild\BlockSerializer\Attributes\AttributeNormalizer;
use Automattic\SiteBuild\BlockSerializer\Html\WpAutop;
use Automattic\SiteBuild\BlockSerializer\Json\JsonObject;
use Automattic\SiteBuild\BlockSerializer\Parser\BlockNode;
use Automattic\SiteBuild\BlockSerializer\Parser\DefaultParser;
use Automattic\SiteBuild\BlockSerializer\Parser\FreeformNode;
use Automattic\SiteBuild\BlockSerializer\Registry\BlockRegistry;
use Automattic\SiteBuild\BlockSerializer\Save\SaveStrategyRegistry;

/** One complete PHP transformation pass, including both paragraph repairs. */
final class Serializer implements TemplateTransformer
{
    private SaveStrategyRegistry $saves;
    private AttributeNormalizer $normalizer;
    private CommentSerializer $comments;

    public function __construct(
        private ?BlockRegistry $registry = null,
        private ?ParagraphFixer $paragraphs = null,
        private ?WpAutop $autop = null,
    ) {
        $this->registry ??= new BlockRegistry();
        $this->paragraphs ??= new ParagraphFixer();
        $this->autop ??= new WpAutop();
        $this->saves = new SaveStrategyRegistry($this->registry);
        $this->normalizer = new AttributeNormalizer($this->registry, $this->saves);
        $this->comments = new CommentSerializer($this->registry);
    }

    public function transform(string $html): TransformResult
    {
        $repairs = [];
        $pre = $this->paragraphs->fix($html);
        $document = DefaultParser::parse($pre->html);
        if ($pre->count > 0) {
            $paragraphPaths = $this->paragraphPaths($document);
            foreach ($pre->repairedParagraphOrdinals as $ordinal) {
                $repairs[] = new Repair('nested-paragraph', $paragraphPaths[$ordinal] ?? 'document');
            }
            // Preserve a fail-closed report row if malformed delimiters kept
            // a repaired paragraph from the parsed grammar tree.
            if ($pre->repairedParagraphOrdinals === []) {
                $repairs[] = new Repair('nested-paragraph', 'document');
            }
        }
        $output = [];
        $blockIndex = 0;
        foreach ($document->nodes() as $node) {
            if ($node instanceof FreeformNode) {
                // No freeform handler is registered in the pinned runtime, so
                // parse() routes it through core/missing: trim, but do not
                // wpautop or wrap the authored bytes.
                $content = JsString::trim($node->content);
                if ($content === '') {
                    continue;
                }
                $output[] = $content;
                $blockIndex++;
                continue;
            }
            if (!$node instanceof BlockNode) {
                throw new \RuntimeException('Unknown parsed document node');
            }
            $result = $this->serializeBlock($node, (string) $blockIndex);
            $output[] = $result['html'];
            $repairs = array_merge($repairs, $result['repairs']);
            $blockIndex++;
        }
        $serialized = implode("\n\n", $output);

        $post = $this->paragraphs->fix($serialized);
        if ($post->count > 0) {
            $repairs[] = new Repair('nested-paragraph', 'document');
        }

        // The pinned Node transform computes `changed` against the pre-parse
        // paragraph-repaired bytes, and the fixed-point wrapper treats that
        // flag as authoritative. In the narrow case where pre-repair alone
        // changes the input and parse/serialize reproduces those repaired
        // bytes exactly, Node returns repaired HTML with changed=false; its
        // caller therefore retains the original bytes. Preserve that quirk so
        // PHP and the canonical fixed point agree on bytes, N, and K.
        $effectiveHtml = $post->html === $pre->html ? $html : $post->html;
        return new TransformResult($effectiveHtml, Repair::dedupe($repairs));
    }

    /** @return array{html:string,repairs:list<Repair>} */
    private function serializeBlock(BlockNode $node, string $path): array
    {
        if (!$this->registry->isRegistered($node->name)) {
            try {
                return ['html' => $this->serializeRawBlock($node), 'repairs' => []];
            } catch (\Throwable $error) {
                // The raw path's child guard found a registered descendant it
                // must not tunnel around. Preserve this subtree instead of
                // failing the whole file.
                return $this->preserveBlock($node, $path, $error);
            }
        }
        try {
            // strategy() is an intentional fail-closed supported-domain guard.
            $this->registry->strategy($node->name);

            if ($node->name === 'core/html' && $node->innerBlocks !== []) {
                return $this->serializeHtmlBlock($node, $path);
            }

            $inner = [];
            $repairs = [];
            foreach ($node->innerBlocks as $index => $child) {
                $result = $this->serializeBlock($child, $path . '/' . $index);
                $inner[] = $result['html'];
                $repairs = array_merge($repairs, $result['repairs']);
            }
            $innerHtml = implode("\n\n", $inner);
            $block = $this->normalizer->normalize($node, $innerHtml, $path);
            $content = $this->saves->save($node->name, $block->attributes, $innerHtml, $node->innerHTML);
            $html = $this->comments->delimit($node->name, $this->comments->attributes($block), $content);
            return [
                'html' => $html,
                'repairs' => array_merge($repairs, $block->repairs),
            ];
        } catch (\Throwable $error) {
            return $this->preserveBlock($node, $path, $error);
        }
    }

    /**
     * Block-level isolation: an unsupported or irreparable block keeps its
     * authored bytes and reports the smallest affected unit, instead of the
     * whole file reverting to pre-fixer bytes and stripping every sibling of
     * its generated classes. Children of the preserved subtree are not
     * re-validated — the subtree is delivered verbatim, never re-saved.
     *
     * @return array{html:string,repairs:list<Repair>}
     */
    private function preserveBlock(BlockNode $node, string $path, \Throwable $error): array
    {
        $reason = str_replace(["\r", "\n"], ' ', $error->getMessage());
        return [
            'html' => $this->serializeRawBlock($node, validateChildren: false),
            'repairs' => [new Repair(Repair::PRESERVED_PREFIX . "{$node->name} ({$reason})", $path)],
        ];
    }

    /**
     * WordPress 7.1 lets core/html interleave static markup with inner blocks.
     * Save reconstructs from innerContent so nested heading/paragraph comments
     * are not dropped the way sourced `content` (bytes between children only) would.
     *
     * @return array{html:string,repairs:list<Repair>}
     */
    private function serializeHtmlBlock(BlockNode $node, string $path): array
    {
        $child = 0;
        $parts = [];
        $repairs = [];
        foreach ($node->innerContent as $part) {
            if ($part !== null) {
                $parts[] = $part;
                continue;
            }
            $inner = $node->innerBlocks[$child] ?? null;
            if ($inner === null) {
                throw new \RuntimeException("Missing html inner block for {$path}");
            }
            $result = $this->serializeBlock($inner, $path . '/' . $child);
            $parts[] = $result['html'];
            $repairs = array_merge($repairs, $result['repairs']);
            $child++;
        }
        $innerHtml = implode('', $parts);
        $block = $this->normalizer->normalize($node, $innerHtml, $path);
        return [
            'html' => $this->comments->delimit(
                $node->name,
                $this->comments->attributes($block),
                trim($innerHtml),
            ),
            'repairs' => array_merge($repairs, $block->repairs),
        ];
    }

    /** Pinned serializeRawBlock() behavior used by core/missing. */
    private function serializeRawBlock(BlockNode $node, bool $delimited = true, bool $validateChildren = true): string
    {
        $child = 0;
        $content = [];
        foreach ($node->innerContent as $part) {
            if ($part !== null) {
                $content[] = $part;
            } else {
                $inner = $node->innerBlocks[$child++] ?? null;
                if ($inner === null) {
                    throw new \RuntimeException("Missing raw child for {$node->name}");
                }
                // A missing/unregistered parent keeps its fallback bytes, but
                // it must not become a tunnel around the supported-domain
                // guard for a registered child. Validate the child's strategy
                // before recursively preserving its raw representation.
                // (Skipped on the preserveBlock path, which delivers an
                // already-condemned subtree verbatim.)
                if ($validateChildren && $this->registry->isRegistered($inner->name)) {
                    $this->registry->strategy($inner->name);
                }
                $content[] = $this->serializeRawBlock($inner, $delimited, $validateChildren);
            }
        }
        $inner = implode("\n", $content);
        $inner = preg_replace('/\n+/', "\n", $inner) ?? $inner;
        $inner = trim($inner);
        if (!$delimited) {
            return $inner;
        }
        return $this->comments->delimit($node->name, $node->attributes ?? new JsonObject(), $inner);
    }

    /** @return list<string> Paragraph paths in opening-delimiter order. */
    private function paragraphPaths(\Automattic\SiteBuild\BlockSerializer\Parser\Document $document): array
    {
        $paths = [];
        foreach ($document->nodes() as $index => $node) {
            if ($node instanceof BlockNode) {
                $this->collectParagraphPaths($node, (string) $index, $paths);
            }
        }
        return $paths;
    }

    /** @param list<string> $paths */
    private function collectParagraphPaths(BlockNode $node, string $path, array &$paths): void
    {
        if ($node->name === 'core/paragraph') {
            $paths[] = $path;
        }
        foreach ($node->innerBlocks as $index => $child) {
            $this->collectParagraphPaths($child, $path . '/' . $index, $paths);
        }
    }
}
