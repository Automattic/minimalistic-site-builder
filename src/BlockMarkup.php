<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\BlockSerializer\Json\JsonObject;
use Automattic\SiteBuild\BlockSerializer\Json\JsonValue;
use Automattic\SiteBuild\BlockSerializer\Json\JsJsonEncoder;

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
     * tokenizer. Serialized attributes can never contain `-->`
     * (serialize_block_attributes escapes `--`), so the scan refuses to
     * cross one: malformed LLM attributes (a missing `}`) make only that
     * comment unparseable instead of swallowing every block up to the next
     * stray `}` in the document.
     */
    private const DELIMITER =
        '/<!--\s+(?<closer>\/)?wp:(?<name>[a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)\s+' .
        '(?:(?<attrs>\{(?:(?!-->).)*?\})\s+)?(?<void>\/)?-->/s';

    /**
     * @param string $source the original document
     * @param list<array{name:string, attrs:?array<mixed>, void:bool, unsafe:bool, parent:?int,
     *                    children:list<int>, offset:int, length:int,
     *                    innerStart:int, innerEnd:int, end:?int}> $nodes
     * @param list<int> $unclosed indices of blocks still open at end of document
     * @param bool $mismatchedDelimiters whether a closer crossed an open block
     *                                  or had no matching opener
     * @param list<int> $mismatchedDelimiterOffsets offsets of crossed, stray,
     *                                                or malformed closers
     * @param list<int> $malformedDelimiterOffsets offsets of Gutenberg-looking
     *                                               comments the grammar rejected
     */
    private function __construct(
        private string $source,
        private array $nodes,
        private array $unclosed = [],
        private bool $mismatchedDelimiters = false,
        private array $mismatchedDelimiterOffsets = [],
        private array $malformedDelimiterOffsets = [],
    ) {}

    /** @var array<int,array<mixed>> node index => replacement attrs */
    private array $mutations = [];

    /**
     * @var list<array{start:int, end:int, search:string, replace:string, token:?string}>
     *      a non-null token means "remove this class token"; search/replace are
     *      then unused, and vice versa
     */
    private array $innerEdits = [];

    /**
     * @param string|null $delimiterView same-length lexical view used only to
     *                                  locate block comments; offsets and HTML
     *                                  are always read from $source
     */
    public static function parse(string $source, ?string $delimiterView = null): self
    {
        $delimiterView ??= $source;
        if (strlen($delimiterView) !== strlen($source)) {
            throw new \InvalidArgumentException('delimiter view must preserve source byte length');
        }

        $nodes = [];
        $stack = []; // node indices of currently open blocks
        $mismatchedDelimiters = false;
        $mismatchedDelimiterOffsets = [];
        $malformedDelimiterOffsets = [];
        $validDelimiterRanges = [];

        if (preg_match_all(self::DELIMITER, $delimiterView, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $offset = $m[0][1];
                $length = strlen($m[0][0]);
                $name = $m['name'][0];
                $isCloser = ($m['closer'][0] ?? '') === '/';
                $isVoid = ($m['void'][0] ?? '') === '/';
                $validDelimiterRanges[] = [$offset, $offset + $length];
                $attrs = null;
                $rawAttrs = trim($m['attrs'][0] ?? '');
                $attrsMalformed = false;
                if ($rawAttrs !== '') {
                    // Structural validity must match JSON.parse(), which the
                    // pinned Gutenberg parser uses. Native json_decode()
                    // rejects valid JS strings containing lone surrogates;
                    // retain it only as the legacy PHP-array projection.
                    $typedAttrs = JsonValue::tryParse($rawAttrs);
                    $attrsMalformed = !($typedAttrs instanceof JsonObject);
                    $decoded = json_decode($rawAttrs, true);
                    $attrs = is_array($decoded)
                        ? $decoded
                        : ($typedAttrs instanceof JsonObject
                            ? self::typedObjectToArray($typedAttrs)
                            : null);
                    if ($attrsMalformed) {
                        $malformedDelimiterOffsets[] = $offset;
                        foreach ($stack as $openIdx) {
                            $nodes[$openIdx]['unsafe'] = true;
                            $nodes[$openIdx]['end'] = null;
                        }
                    }
                }

                // The pinned Gutenberg parser gives `/-->` void precedence.
                // `/wp:name /-->` is therefore not a closer and must never
                // complete an existing frame in this stricter parser.
                if ($isCloser && $isVoid) {
                    $mismatchedDelimiters = true;
                    $mismatchedDelimiterOffsets[] = $offset;
                    $malformedDelimiterOffsets[] = $offset;
                    foreach ($stack as $openIdx) {
                        $nodes[$openIdx]['unsafe'] = true;
                        $nodes[$openIdx]['end'] = null;
                    }
                    continue;
                }

                if ($isCloser) {
                    // Close the nearest open block with this name; tolerate
                    // malformed LLM output so editing callers can still
                    // inspect its healthy blocks. Record crossed and stray
                    // closers so strict callers do not mistake it for a
                    // balanced document.
                    $matched = false;
                    for ($i = count($stack) - 1; $i >= 0; $i--) {
                        if ($nodes[$stack[$i]]['name'] === $name) {
                            $crossed = $i !== count($stack) - 1;
                            if ($crossed) {
                                $mismatchedDelimiters = true;
                                $mismatchedDelimiterOffsets[] = $offset;
                                foreach ($stack as $openIdx) {
                                    $nodes[$openIdx]['unsafe'] = true;
                                    $nodes[$openIdx]['end'] = null;
                                }
                            }
                            $nodes[$stack[$i]]['innerEnd'] = $offset;
                            if (!$crossed && !$nodes[$stack[$i]]['unsafe']) {
                                $nodes[$stack[$i]]['end'] = $offset + $length;
                            }
                            array_splice($stack, $i);
                            $matched = true;
                            break;
                        }
                    }
                    if (!$matched) {
                        $mismatchedDelimiters = true;
                        $mismatchedDelimiterOffsets[] = $offset;
                        foreach ($stack as $openIdx) {
                            $nodes[$openIdx]['unsafe'] = true;
                            $nodes[$openIdx]['end'] = null;
                        }
                    }
                    continue;
                }

                $index = count($nodes);
                $parent = $stack === [] ? null : $stack[count($stack) - 1];
                $nodes[] = [
                    'name'       => $name,
                    'attrs'      => $attrs,
                    'void'       => $isVoid,
                    'unsafe'     => $attrsMalformed,
                    'parent'     => $parent,
                    'children'   => [],
                    'offset'     => $offset,
                    'length'     => $length,
                    'innerStart' => $offset + $length,
                    'innerEnd'   => $offset + $length, // stays for void / unclosed
                    'end'        => $isVoid && !$attrsMalformed ? $offset + $length : null,
                ];
                if ($parent !== null) {
                    $nodes[$parent]['children'][] = $index;
                }
                if (!$isVoid) {
                    $stack[] = $index;
                }
            }
        }

        // Report wp:-looking comments that the delimiter grammar could not
        // consume (for example, truncated JSON or missing required whitespace
        // after attributes). A malformed marker inside an otherwise closed
        // block makes that whole subtree unsafe.
        if (preg_match_all('/<!--\s*\/?wp:/', $delimiterView, $markers, PREG_OFFSET_CAPTURE)) {
            foreach ($markers[0] as $marker) {
                $offset = $marker[1];
                if (!self::offsetIsInsideRanges($offset, $validDelimiterRanges)) {
                    $malformedDelimiterOffsets[] = $offset;
                }
            }
        }
        $mismatchedDelimiterOffsets = self::sortedUnique($mismatchedDelimiterOffsets);
        $malformedDelimiterOffsets = self::sortedUnique($malformedDelimiterOffsets);
        foreach ($nodes as &$node) {
            if ($node['end'] === null) {
                continue;
            }
            foreach ($malformedDelimiterOffsets as $offset) {
                if ($offset >= $node['offset'] && $offset < $node['end']) {
                    $node['unsafe'] = true;
                    $node['end'] = null;
                    break;
                }
            }
        }
        unset($node);

        // Unclosed blocks read to end of document.
        $end = strlen($source);
        foreach ($stack as $i) {
            $nodes[$i]['innerEnd'] = $end;
        }

        return new self(
            $source,
            $nodes,
            array_values($stack),
            $mismatchedDelimiters,
            $mismatchedDelimiterOffsets,
            $malformedDelimiterOffsets,
        );
    }

    /** @param list<int> $offsets @return list<int> */
    private static function sortedUnique(array $offsets): array
    {
        $offsets = array_values(array_unique($offsets));
        sort($offsets);
        return $offsets;
    }

    /** @param list<array{0:int,1:int}> $ranges */
    private static function offsetIsInsideRanges(int $offset, array $ranges): bool
    {
        foreach ($ranges as [$start, $end]) {
            if ($offset < $start) {
                return false;
            }
            if ($offset < $end) {
                return true;
            }
        }
        return false;
    }

    /** @return array<mixed> */
    private static function typedObjectToArray(JsonObject $object): array
    {
        $value = self::objectsToArrays($object->toNative());
        return is_array($value) ? $value : [];
    }

    private static function objectsToArrays(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $out = [];
            foreach (get_object_vars($value) as $key => $entry) {
                $out[$key] = self::objectsToArrays($entry);
            }
            return $out;
        }
        if (is_array($value)) {
            return array_map(self::objectsToArrays(...), $value);
        }
        return $value;
    }

    /**
     * Indices of the blocks left open at the end of the document (a truncated
     * generation cuts off before their closers), outermost first. Empty for a
     * well-formed document.
     *
     * @return list<int>
     */
    public function unclosedIndices(): array
    {
        return $this->unclosed;
    }

    /**
     * Whether a closing delimiter failed to match the innermost open block.
     *
     * Parsing remains intentionally tolerant for editing callers, but a
     * crossed or stray closer means the source is not structurally balanced
     * even when unclosedIndices() is empty.
     */
    public function hasMismatchedDelimiters(): bool
    {
        return $this->mismatchedDelimiters;
    }

    /** @return list<int> byte offsets of crossed, stray, or malformed closers */
    public function mismatchedDelimiterOffsets(): array
    {
        return $this->mismatchedDelimiterOffsets;
    }

    /** Whether a Gutenberg-looking comment was not a valid block delimiter. */
    public function hasMalformedDelimiters(): bool
    {
        return $this->malformedDelimiterOffsets !== [];
    }

    /** @return list<int> byte offsets of malformed Gutenberg-looking comments */
    public function malformedDelimiterOffsets(): array
    {
        return $this->malformedDelimiterOffsets;
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

    /**
     * The part's first root node, or null when it has none.
     *
     * Parts are single-rooted in practice, so callers that need "the block this
     * file is about" want this rather than a hand-written scan over indices().
     */
    public function topLevel(): ?int
    {
        foreach ($this->indices() as $i) {
            if ($this->parent($i) === null) {
                return $i;
            }
        }
        return null;
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

    /** Whether the block is self-closing (`<!-- wp:name /-->`). */
    public function isVoid(int $i): bool
    {
        return $this->nodes[$i]['void'];
    }

    /** @return list<int> */
    public function children(int $i): array
    {
        return $this->nodes[$i]['children'];
    }

    /** The block's raw opening comment as it appears in the source. */
    public function openingComment(int $i): string
    {
        $n = $this->nodes[$i];
        return substr($this->source, $n['offset'], $n['length']);
    }

    /** Byte offset and length of this block's opening delimiter in the source. */
    public function openingOffset(int $i): int
    {
        return $this->nodes[$i]['offset'];
    }

    public function openingLength(int $i): int
    {
        return $this->nodes[$i]['length'];
    }

    /**
     * Exclusive end offset of a structurally safe closed block, including its
     * closing delimiter. Self-closing blocks end after their opener; unclosed,
     * crossed, or malformed subtrees have no safe endpoint.
     */
    public function endOffset(int $i): ?int
    {
        return $this->nodes[$i]['end'];
    }

    /** Closing-delimiter start for a closed block; EOF for an open block. */
    public function innerEndOffset(int $i): int
    {
        return $this->nodes[$i]['innerEnd'];
    }

    /** Raw source between this block's delimiters (includes child blocks). */
    public function innerHtml(int $i): string
    {
        $n = $this->nodes[$i];
        return substr($this->source, $n['innerStart'], $n['innerEnd'] - $n['innerStart']);
    }

    /**
     * The HTML this node itself owns: from its opening comment up to its
     * first child block (or its closing comment when it has none) — i.e. the
     * block's own root tag, untouched by descendants.
     */
    public function ownHtml(int $i): string
    {
        $n = $this->nodes[$i];
        $end = $n['children'] !== []
            ? $this->nodes[$n['children'][0]]['offset']
            : $n['innerEnd'];
        return substr($this->source, $n['innerStart'], $end - $n['innerStart']);
    }

    /** Replace a node's attributes; render() writes the new opening comment. */
    public function setAttrs(int $i, array $attrs): void
    {
        $this->mutations[$i] = $attrs;
    }

    /**
     * String-replace inside `class="…"` attribute values of the HTML this
     * node itself owns: from its opening comment up to its first child block
     * (or its closing comment when it has none) — i.e. the block's own root
     * tag, untouched by descendants. Text content is never rewritten: a
     * paragraph that *mentions* `has-primary-color` must survive a repair.
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
            'token'   => null,
        ];
    }

    /**
     * Remove one class token from `class` attribute values in the HTML this
     * node itself owns. Unlike replaceInOwnHtml() this TOKENIZES the value —
     * tab/newline separators (valid HTML) can't shelter a token from removal
     * — and never eats into a longer token ('reveal' leaves 'reveal-up'
     * alone). Remaining tokens are rejoined with single spaces.
     */
    public function removeClassTokenInOwnHtml(int $i, string $token): void
    {
        $n = $this->nodes[$i];
        $end = $n['children'] !== []
            ? $this->nodes[$n['children'][0]]['offset']
            : $n['innerEnd'];
        $this->innerEdits[] = [
            'start'   => $n['innerStart'],
            'end'     => $end,
            'search'  => '',
            'replace' => '',
            'token'   => $token,
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
            $regions[$key]['content'] = (string) preg_replace_callback(
                '/\bclass\s*=\s*(["\'])(.*?)\1/is',
                static function (array $m) use ($edit): string {
                    if ($edit['token'] === null) {
                        return str_replace($edit['search'], $edit['replace'], $m[0]);
                    }
                    $kept = array_filter(
                        preg_split('/\s+/', $m[2], -1, PREG_SPLIT_NO_EMPTY) ?: [],
                        static fn (string $t): bool => $t !== $edit['token']
                    );
                    return substr($m[0], 0, strlen($m[0]) - strlen($m[2]) - 1)
                        . implode(' ', $kept) . $m[1];
                },
                $regions[$key]['content']
            );
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
            if ($encoded === false) {
                // Native json_encode() rejects the WTF-8 spelling used to
                // retain JavaScript lone surrogates. Fall back to the pinned
                // JSON.stringify-compatible encoder for that valid case.
                $object = new JsonObject();
                foreach ($attrs as $key => $value) {
                    $object->set((string) $key, JsonValue::fromNative($value));
                }
                $encoded = JsJsonEncoder::serializeAttributes($object);
            }
            $json = ' ' . str_replace('--', '\\u002d\\u002d', (string) $encoded);
        }
        return '<!-- wp:' . $name . $json . ' ' . ($void ? '/' : '') . '-->';
    }
}
