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
                $content = $this->jsTrim($node->content);
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
        return new TransformResult($effectiveHtml, $this->uniqueRepairs($repairs));
    }

    /** @return array{html:string,repairs:list<Repair>} */
    private function serializeBlock(BlockNode $node, string $path): array
    {
        if (!$this->registry->isRegistered($node->name)) {
            return ['html' => $this->serializeRawBlock($node), 'repairs' => []];
        }
        // strategy() is an intentional fail-closed supported-domain guard.
        $this->registry->strategy($node->name);

        $inner = [];
        $repairs = [];
        foreach ($node->innerBlocks as $index => $child) {
            $result = $this->serializeBlock($child, $path . '/' . $index);
            $inner[] = $result['html'];
            $repairs = array_merge($repairs, $result['repairs']);
        }
        $innerHtml = implode("\n\n", $inner);
        try {
            $block = $this->normalizer->normalize($node, $innerHtml, $path);
            $content = $this->saves->save($node->name, $block->attributes, $innerHtml, $node->innerHTML);
            $html = $this->comments->delimit($node->name, $this->comments->attributes($block), $content);
        } catch (\RuntimeException $error) {
            // Deliberately \RuntimeException and nothing wider. Every guard
            // that means "this markup is unsupported" throws that type; a
            // TypeError, LogicException or InvalidArgumentException means a bug
            // in this code, and swallowing those would turn our own defects
            // into silently unprocessed pages. During development a deleted
            // variable did exactly that under a catch-all: 39 files passed
            // through untouched and the run reported clean.
            //
            // This one block cannot be canonicalized. Keeping its authored
            // bytes costs the page that block; abandoning the file costs every
            // other block in it the fix it would have had. The fixer's job is
            // to fix what it can, so the loss is scoped to the block that
            // caused it and reported with the reason.
            //
            // rawSource is the block's verbatim original, inner blocks
            // included, so the subtree stays internally consistent — a
            // half-fixed block whose attributes and HTML disagree would be
            // worse than an untouched one.
            if (trim($node->rawSource) === '') {
                throw $error;
            }
            return [
                'html' => $node->rawSource,
                'repairs' => array_merge($repairs, [new Repair(
                    'block-kept-as-authored:' . self::failureNote($node->name, $error),
                    $path,
                )]),
            ];
        }
        return [
            'html' => $html,
            'repairs' => array_merge($repairs, $block->repairs),
        ];
    }

    /**
     * A one-line, JSON-encoded note naming the block and why it could not be
     * canonicalized, safe to embed in a repair code the report reads back.
     */
    private static function failureNote(string $name, \Throwable $error): string
    {
        $reason = (string) preg_replace('/\s+/', ' ', $error->getMessage());
        $reason = trim((string) preg_replace('/[\p{C}`]+/u', '', $reason));
        return (string) json_encode(
            [
                'block'  => $name,
                'reason' => strlen($reason) > 160 ? substr($reason, 0, 157) . '...' : $reason,
            ],
            JSON_UNESCAPED_SLASHES,
        );
    }

    /** Pinned serializeRawBlock() behavior used by core/missing. */
    private function serializeRawBlock(BlockNode $node, bool $delimited = true): string
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
                if ($this->registry->isRegistered($inner->name)) {
                    $this->registry->strategy($inner->name);
                }
                $content[] = $this->serializeRawBlock($inner, $delimited);
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

    /** @param list<Repair> $repairs @return list<Repair> */
    private function uniqueRepairs(array $repairs): array
    {
        $unique = [];
        foreach ($repairs as $repair) {
            $unique[$repair->blockPath . "\0" . $repair->code] = $repair;
        }
        return array_values($unique);
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

    private function jsTrim(string $value): string
    {
        return preg_replace(
            '/^[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+|[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+$/u',
            '',
            $value,
        ) ?? trim($value);
    }
}
