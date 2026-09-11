<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Patterns;

use Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment;
use Automattic\SiteBuild\BlockSerializer\Json\JsJsonEncoder;
use Automattic\SiteBuild\BlockSerializer\Json\JsonString;
use Automattic\SiteBuild\BlockSerializer\Parser\BlockNode;
use Automattic\SiteBuild\BlockSerializer\Parser\DefaultParser;

/**
 * Compiles declared content slots into disjoint source spans. Serialization
 * replaces only those spans; wrappers, styles, comments and siblings retain
 * their exact approved bytes. No WordPress or provider runtime is required.
 *
 * This first extraction admits plain text, button URLs and image URLs/alt.
 * Rich text and blocks requiring a different save shape need a reviewed slot
 * adapter before they can be edited. Undeclared/custom blocks pass verbatim.
 */
final class ApprovedPattern
{
    /** @var array<string,array{definition:array,example:string,spans:list<array{start:int,length:int,encoding:string}>}> */
    private array $slots = [];

    public function __construct(public readonly string $markup, array $definitions)
    {
        $roots = array_values(array_filter(
            DefaultParser::parse($markup)->nodes(),
            static fn ($node): bool => $node instanceof BlockNode,
        ));
        foreach ($definitions as $definition) {
            if (!is_array($definition)) {
                throw new \InvalidArgumentException('A pattern slot must be an object');
            }
            $id = $definition['id'] ?? null;
            if (!is_string($id) || preg_match('/^[a-z][a-z0-9-]*$/D', $id) !== 1 || isset($this->slots[$id])) {
                throw new \InvalidArgumentException('Pattern slot IDs must be unique lowercase names');
            }
            $path = $definition['block_path'] ?? null;
            if (!is_array($path) || !array_is_list($path) || $path === []) {
                throw new \InvalidArgumentException("Slot {$id} needs a block_path");
            }
            $children = $roots;
            foreach ($path as $index) {
                if (!is_int($index) || $index < 0 || !isset($children[$index])) {
                    throw new \InvalidArgumentException("Slot {$id} has an unknown block_path");
                }
                $block = $children[$index];
                $attrs = json_decode($block->rawAttributes ?? '{}', true, flags: JSON_THROW_ON_ERROR);
                if (preg_match('/(?:^|\s)ai-ignore(?:\s|$)/', (string) ($attrs['className'] ?? ''))) {
                    throw new \InvalidArgumentException("Slot {$id} targets protected content");
                }
                if ($block->closingDelimiter === null || $block->attributes === null
                    || $block->mergedAttributeKeyPaths !== []
                    || !preg_match('~^<!--\s+/wp:' . preg_quote(preg_replace('~^core/~', '', $block->name), '~') . '\s+-->$~', $block->closingDelimiter)) {
                    throw new \InvalidArgumentException("Slot {$id} requires well-formed approved block markup");
                }
                $children = $block->innerBlocks;
            }
            if ($block->innerBlocks !== []) {
                throw new \InvalidArgumentException("Slot {$id} must target a leaf block");
            }
            $field = $definition['field'] ?? '';
            if ($field === 'url' && !isset($definition['binding'])) {
                throw new \InvalidArgumentException("URL slot {$id} requires an explicit binding");
            }
            $definition['block_name'] = $block->name;
            [$selector, $attribute, $commentKey] = match ($block->name . ':' . $field) {
                'core/paragraph:text' => ['p', null, 'content'],
                'core/heading:text' => ['h1,h2,h3,h4,h5,h6', null, 'content'],
                'core/list-item:text' => ['li', null, 'content'],
                'core/button:text' => ['a', null, 'text'],
                'core/button:url' => ['a', 'href', 'url'],
                'core/image:url' => ['img', 'src', 'url'],
                'core/image:alt' => ['img', 'alt', 'alt'],
                default => throw new \InvalidArgumentException("Unsupported slot {$id}: {$block->name}:{$field}"),
            };
            $body = substr($markup, $block->openingEnd, $block->closingStart - $block->openingEnd);
            $matches = HtmlFragment::parse($body)->querySelectorAll($selector);
            if (count($matches) !== 1) {
                throw new \InvalidArgumentException("Slot {$id} must match exactly one saved element");
            }
            $element = $matches[0];
            if ($attribute === null) {
                foreach ($element->children() as $child) {
                    if (!$child->isText()) {
                        throw new \InvalidArgumentException("Slot {$id} contains protected inline markup; plain-text edits cannot replace it");
                    }
                }
                if ($element->innerEndOffset() === $element->endOffset()) {
                    throw new \InvalidArgumentException("Slot {$id} requires an explicit closing HTML tag");
                }
                $start = $block->openingEnd + $element->innerStartOffset();
                $length = $element->innerEndOffset() - $element->innerStartOffset();
                $example = html_entity_decode($element->rawInnerHtml(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            } else {
                $tag = substr($body, $element->startOffset(), $element->innerStartOffset() - $element->startOffset());
                [$offset, $length, $example] = self::attributeSpan($tag, $attribute);
                $start = $block->openingEnd + $element->startOffset() + $offset;
                // An existing attachment ID/srcset would still point to the old
                // image. The proof accepts portable images without either.
                if ($block->name === 'core/image' && $field === 'url'
                    && (isset($attrs['id']) || preg_match('/\b(?:srcset|wp-image-\d+)\b/i', $body))) {
                    throw new \InvalidArgumentException("Slot {$id} needs a portable image without an attachment ID or srcset");
                }
            }
            $spans = [['start' => $start, 'length' => $length, 'encoding' => 'html']];
            // Legacy internal trees can mirror sourced content in comment JSON.
            // Keep that value synchronized without reserializing other keys.
            if (array_key_exists($commentKey, $attrs)) {
                [$offset, $length] = self::jsonStringSpan($block->rawAttributes, $commentKey);
                $jsonStart = strpos($block->openingDelimiter, $block->rawAttributes);
                $spans[] = ['start' => $block->openingStart + $jsonStart + $offset, 'length' => $length, 'encoding' => 'json'];
            }
            if (isset($definition['binding']) && (!is_string($definition['binding']) || $definition['binding'] === '')) {
                throw new \InvalidArgumentException("Slot {$id} has an invalid binding");
            }
            if (isset($definition['max_words']) && (!is_int($definition['max_words']) || $definition['max_words'] < 1)) {
                throw new \InvalidArgumentException("Slot {$id} has an invalid word limit");
            }
            $fallback = $definition['fallback'] ?? null;
            if (self::valueError($definition, $fallback) !== null) {
                throw new \InvalidArgumentException("Slot {$id} needs an explicit, valid, approved fallback");
            }
            $this->slots[$id] = ['definition' => $definition, 'example' => $example, 'spans' => $spans];
        }
        $spans = array_merge([], ...array_map(static fn ($slot) => $slot['spans'], array_values($this->slots)));
        usort($spans, static fn ($a, $b) => $a['start'] <=> $b['start']);
        $previousEnd = -1;
        $previousStart = -1;
        foreach ($spans as $span) {
            if ($span['start'] < $previousEnd || $span['start'] === $previousStart) {
                throw new \InvalidArgumentException('Pattern slots must not overlap');
            }
            $previousStart = $span['start'];
            $previousEnd = $span['start'] + $span['length'];
        }
    }

    /** Stable declared IDs replace Big Sky's random per-content IDs. */
    public function inventory(): array
    {
        return array_map(static fn ($slot) => $slot['definition'] + ['example' => $slot['example']], array_values($this->slots));
    }

    /** Values have already been validated; omitted slots preserve source bytes. */
    public function serialize(array $values): string
    {
        $edits = [];
        foreach ($values as $id => $value) {
            if (!isset($this->slots[$id])) {
                throw new \InvalidArgumentException("Undeclared pattern slot: {$id}");
            }
            $slot = $this->slots[$id];
            if (($error = self::valueError($slot['definition'], $value)) !== null) {
                throw new \InvalidArgumentException("Slot {$id}: {$error}");
            }
            foreach ($slot['spans'] as $span) {
                $html = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
                // Text/content comment attributes store rich-text HTML; URL/alt
                // attributes store their decoded scalar value.
                $commentValue = $slot['definition']['field'] === 'text' ? $html : $value;
                $replacement = $span['encoding'] === 'html'
                    ? $html
                    : JsJsonEncoder::serializeCommentValue(new JsonString($commentValue));
                $edits[] = $span + ['replacement' => $replacement];
            }
        }
        usort($edits, static fn ($a, $b) => $b['start'] <=> $a['start']);
        $result = $this->markup;
        foreach ($edits as $edit) {
            $result = substr_replace($result, $edit['replacement'], $edit['start'], $edit['length']);
        }
        return $result;
    }

    public static function valueError(array $slot, mixed $value): ?string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            return 'expected a UTF-8 string without control characters';
        }
        if ($slot['field'] !== 'alt' && trim($value) === '') {
            return 'empty content';
        }
        if (preg_match('/<[^>]*>/', $value)) {
            return 'expected a scalar value, not HTML';
        }
        if (isset($slot['max_words']) && count(preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY)) > $slot['max_words']) {
            return 'word limit exceeded';
        }
        if ($slot['field'] === 'url') {
            if (preg_match('/[\s\\\\<>"\x27]/u', $value) || str_starts_with($value, '//')) {
                return 'invalid URL';
            }
            if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $value, $scheme)
                && !in_array(strtolower($scheme[0]), ['https:', 'http:', 'mailto:', 'tel:'], true)) {
                return 'unsupported URL scheme';
            }
            if (($slot['block_name'] ?? '') === 'core/image' && isset($scheme[0])
                && !in_array(strtolower($scheme[0]), ['https:', 'http:'], true)) {
                return 'unsupported image URL scheme';
            }
            if (preg_match('~^https?://~i', $value) && filter_var($value, FILTER_VALIDATE_URL) === false) {
                return 'invalid absolute URL';
            }
        }
        return null;
    }

    /** @return array{int,int,string} span of an existing quoted HTML attribute */
    private static function attributeSpan(string $tag, string $wanted): array
    {
        preg_match_all('/\s+([^\s=<>\/]+)\s*=\s*("[^"]*"|\x27[^\x27]*\x27|[^\s>]+)/s', $tag, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $found = [];
        foreach ($matches as $match) {
            if (strtolower($match[1][0]) !== $wanted) {
                continue;
            }
            $raw = $match[2][0];
            if ($raw[0] !== '"' && $raw[0] !== "'") {
                throw new \InvalidArgumentException("Editable {$wanted} must be quoted");
            }
            $found[] = [$match[2][1] + 1, strlen($raw) - 2, html_entity_decode(substr($raw, 1, -1), ENT_QUOTES | ENT_HTML5, 'UTF-8')];
        }
        if (count($found) !== 1) {
            throw new \InvalidArgumentException("Editable {$wanted} must occur exactly once");
        }
        return $found[0];
    }

    /** Locate a top-level string value without changing other JSON bytes. */
    private static function jsonStringSpan(string $json, string $key): array
    {
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\],:]|[^\s{}\[\],:]+/s', $json, $matches, PREG_OFFSET_CAPTURE);
        $tokens = $matches[0];
        $depth = 0;
        $found = [];
        foreach ($tokens as $i => [$token, $offset]) {
            if ($token === '{' || $token === '[') {
                $depth++;
            } elseif ($token === '}' || $token === ']') {
                $depth--;
            } elseif ($depth === 1 && $token[0] === '"' && ($tokens[$i + 1][0] ?? '') === ':'
                && json_decode($token, flags: JSON_THROW_ON_ERROR) === $key) {
                [$raw, $start] = $tokens[$i + 2];
                if ($raw[0] !== '"') {
                    throw new \InvalidArgumentException("Sourced comment attribute {$key} must be a string");
                }
                $found[] = [$start, strlen($raw)];
            }
        }
        if (count($found) !== 1) {
            throw new \InvalidArgumentException("Ambiguous sourced comment attribute {$key}");
        }
        return $found[0];
    }
}
