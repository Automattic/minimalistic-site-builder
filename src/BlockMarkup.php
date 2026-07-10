<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Minimal Gutenberg block-comment parser and attribute rewriter.
 *
 * Parses a markup document into a flat node list (tree via parent indices),
 * exposing each block's comment JSON attributes for inspection, and lets a
 * caller replace a node's attributes. render() reproduces the document
 * byte-for-byte except for the rewritten opening comments — the HTML inside
 * blocks is never touched. Attribute edits therefore leave the saved HTML
 * out of sync with the comment JSON; callers rely on the block fixer
 * (re-serialization from attributes) running afterwards to re-sync it.
 *
 * The comment grammar (delimiter regex, attribute escaping) mirrors
 * WordPress core's WP_Block_Parser / serialize_block_attributes().
 */
final class BlockMarkup
{
    /**
     * Block-comment delimiter pattern (namespace + name merged). The attrs
     * are scanned lazily up to the first `}` that closes the comment — the
     * approach of the @wordpress/block-serialization-default-parser
     * tokenizer. Safe because serialized attributes can never contain `-->`
     * (serialize_block_attributes escapes `--`).
     */
    private const DELIMITER =
        '/<!--\s+(?<closer>\/)?wp:(?<name>[a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)\s+' .
        '(?:(?<attrs>\{.*?\})\s*)?(?<void>\/)?-->/s';

    /**
     * @param string $source the original document
     * @param list<array{name:string, attrs:?array<mixed>, void:bool, parent:?int,
     *                    children:list<int>, offset:int, length:int,
     *                    innerStart:int, innerEnd:int}> $nodes
     */
    private function __construct(
        private string $source,
        private array $nodes,
    ) {}

    /** @var array<int,array<mixed>> node index => replacement attrs */
    private array $mutations = [];

    /** @var list<array{start:int, end:int, search:string, replace:string}> */
    private array $innerEdits = [];

    public static function parse(string $source): self
    {
        $nodes = [];
        $stack = []; // node indices of currently open blocks

        if (preg_match_all(self::DELIMITER, $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $offset = $m[0][1];
                $length = strlen($m[0][0]);
                $name = $m['name'][0];
                $isCloser = ($m['closer'][0] ?? '') === '/';
                $isVoid = ($m['void'][0] ?? '') === '/';

                if ($isCloser) {
                    // Close the nearest open block with this name; tolerate
                    // stray closers in malformed LLM output by ignoring them.
                    for ($i = count($stack) - 1; $i >= 0; $i--) {
                        if ($nodes[$stack[$i]]['name'] === $name) {
                            $nodes[$stack[$i]]['innerEnd'] = $offset;
                            array_splice($stack, $i);
                            break;
                        }
                    }
                    continue;
                }

                $attrs = null;
                $rawAttrs = trim($m['attrs'][0] ?? '');
                if ($rawAttrs !== '') {
                    $decoded = json_decode($rawAttrs, true);
                    $attrs = is_array($decoded) ? $decoded : null;
                }

                $index = count($nodes);
                $parent = $stack === [] ? null : $stack[count($stack) - 1];
                $nodes[] = [
                    'name'       => $name,
                    'attrs'      => $attrs,
                    'void'       => $isVoid,
                    'parent'     => $parent,
                    'children'   => [],
                    'offset'     => $offset,
                    'length'     => $length,
                    'innerStart' => $offset + $length,
                    'innerEnd'   => $offset + $length, // stays for void / unclosed
                ];
                if ($parent !== null) {
                    $nodes[$parent]['children'][] = $index;
                }
                if (!$isVoid) {
                    $stack[] = $index;
                }
            }
        }

        // Unclosed blocks read to end of document.
        $end = strlen($source);
        foreach ($stack as $i) {
            $nodes[$i]['innerEnd'] = $end;
        }

        return new self($source, $nodes);
    }

    /** @return list<int> all node indices, in document order */
    public function indices(): array
    {
        return array_keys($this->nodes);
    }

    public function name(int $i): string
    {
        return $this->nodes[$i]['name'];
    }

    /** @return array<mixed>|null */
    public function attrs(int $i): ?array
    {
        return $this->mutations[$i] ?? $this->nodes[$i]['attrs'];
    }

    public function parent(int $i): ?int
    {
        return $this->nodes[$i]['parent'];
    }

    /** @return list<int> */
    public function children(int $i): array
    {
        return $this->nodes[$i]['children'];
    }

    /** Raw source between this block's delimiters (includes child blocks). */
    public function innerHtml(int $i): string
    {
        $n = $this->nodes[$i];
        return substr($this->source, $n['innerStart'], $n['innerEnd'] - $n['innerStart']);
    }

    /** Replace a node's attributes; render() writes the new opening comment. */
    public function setAttrs(int $i, array $attrs): void
    {
        $this->mutations[$i] = $attrs;
    }

    /**
     * String-replace inside the HTML this node itself owns: from its opening
     * comment up to its first child block (or its closing comment when it has
     * none) — i.e. the block's own root tag, untouched by descendants.
     *
     * Needed when an attribute edit obsoletes a class token in the saved
     * HTML (e.g. a textColor swap leaving `has-old-color` behind): the block
     * fixer's re-serialization would otherwise rescue the stale token into
     * `className` via @wordpress/blocks' fixCustomClassname, and WP's
     * !important preset rules can make the stale color win over the repair.
     */
    public function replaceInOwnHtml(int $i, string $search, string $replace): void
    {
        $n = $this->nodes[$i];
        $end = $n['children'] !== []
            ? $this->nodes[$n['children'][0]]['offset']
            : $n['innerEnd'];
        $this->innerEdits[] = [
            'start'   => $n['innerStart'],
            'end'     => $end,
            'search'  => $search,
            'replace' => $replace,
        ];
    }

    public function isMutated(): bool
    {
        return $this->mutations !== [] || $this->innerEdits !== [];
    }

    /** The document with every mutation applied at its original offsets. */
    public function render(): string
    {
        if (!$this->isMutated()) {
            return $this->source;
        }

        // Collect ops, then apply back-to-front so earlier offsets stay
        // valid. Ranges never overlap: a comment rewrite covers the
        // delimiter, an inner edit covers [innerStart, first child).
        $ops = [];
        foreach ($this->mutations as $i => $attrs) {
            $n = $this->nodes[$i];
            $ops[] = [
                'start'   => $n['offset'],
                'length'  => $n['length'],
                'content' => self::serializeComment($n['name'], $attrs, $n['void']),
            ];
        }
        // Multiple edits to the same region compose (grouped so the second
        // doesn't clobber the first when both rewrite the whole range).
        $regions = [];
        foreach ($this->innerEdits as $edit) {
            $key = $edit['start'] . '-' . $edit['end'];
            $regions[$key] ??= [
                'start'   => $edit['start'],
                'length'  => $edit['end'] - $edit['start'],
                'content' => substr($this->source, $edit['start'], $edit['end'] - $edit['start']),
            ];
            $regions[$key]['content'] = str_replace($edit['search'], $edit['replace'], $regions[$key]['content']);
        }
        foreach ($regions as $region) {
            $ops[] = $region;
        }
        usort($ops, static fn (array $a, array $b) => $b['start'] <=> $a['start']);

        $out = $this->source;
        foreach ($ops as $op) {
            $out = substr_replace($out, $op['content'], $op['start'], $op['length']);
        }
        return $out;
    }

    /** An opening block comment, escaped the way WP serialize_block_attributes() does. */
    public static function serializeComment(string $name, array $attrs, bool $void): string
    {
        $json = '';
        if ($attrs !== []) {
            $encoded = json_encode(
                $attrs,
                JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
                | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            $json = ' ' . str_replace('--', '\\u002d\\u002d', (string) $encoded);
        }
        return '<!-- wp:' . $name . $json . ' ' . ($void ? '/' : '') . '-->';
    }
}
