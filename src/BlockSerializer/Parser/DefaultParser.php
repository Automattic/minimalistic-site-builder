<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Parser;

use Automattic\SiteBuild\BlockSerializer\Json\JsonObject;
use Automattic\SiteBuild\BlockSerializer\Json\JsonValue;

/**
 * Port of the pinned @wordpress/block-serialization-default-parser state
 * machine. This is intentionally separate from BlockMarkup's editing parser.
 */
final class DefaultParser
{
    /**
     * The attribute body is lazy but cannot cross a comment close. Requiring the
     * delimiter suffix after the candidate `}` makes nested object braces land
     * on the same final brace as the pinned JavaScript tokenizer.
     */
    private const TOKENIZER =
        '~<!--\s+(?<closer>/)?wp:(?:(?<namespace>[a-z][a-z0-9_-]*/))?'
        . '(?<name>[a-z][a-z0-9_-]*)\s+'
        . '(?:(?<attrs>\{(?:(?!-->)[\s\S])*?\})\s+)?(?<void>/)?-->~';

    private string $source = '';
    private int $offset = 0;

    /** @var list<DocumentNode> */
    private array $output = [];

    /** @var list<array{block:BlockNode,tokenStart:int,tokenLength:int,prevOffset:int,leadingHtmlStart:?int}> */
    private array $stack = [];

    public static function parse(string $source): Document
    {
        $parser = new self();
        return $parser->run($source);
    }

    private function run(string $source): Document
    {
        $this->source = $source;
        $this->offset = 0;
        $this->output = [];
        $this->stack = [];

        while ($this->proceed()) {
            // All work happens in proceed(), mirroring the pinned parser loop.
        }

        return new Document($source, $this->output);
    }

    private function proceed(): bool
    {
        $stackDepth = count($this->stack);
        $token = $this->nextToken();
        $leadingHtmlStart = $token['start'] > $this->offset ? $this->offset : null;

        switch ($token['type']) {
            case 'no-more-tokens':
                if ($stackDepth === 0) {
                    $this->addFreeform();
                    return false;
                }
                if ($stackDepth === 1) {
                    $this->addBlockFromStack();
                    return false;
                }
                // This surprising recovery is pinned behavior: missing nested
                // closers collapse every frame independently into top-level
                // output rather than inventing parent/child relationships.
                while ($this->stack !== []) {
                    $this->addBlockFromStack();
                }
                return false;

            case 'void-block':
                $block = $this->blockFromToken($token, true);
                $block->sourceEnd = $token['start'] + $token['length'];
                $block->rawSource = $token['raw'];
                if ($stackDepth === 0) {
                    if ($leadingHtmlStart !== null) {
                        $this->pushFreeform($leadingHtmlStart, $token['start']);
                    }
                    $this->output[] = $block;
                } else {
                    $this->addInnerBlock($block, $token['start'], $token['length']);
                }
                $this->offset = $token['start'] + $token['length'];
                return true;

            case 'block-opener':
                $block = $this->blockFromToken($token, false);
                $this->stack[] = [
                    'block' => $block,
                    'tokenStart' => $token['start'],
                    'tokenLength' => $token['length'],
                    'prevOffset' => $token['start'] + $token['length'],
                    'leadingHtmlStart' => $leadingHtmlStart,
                ];
                $this->offset = $token['start'] + $token['length'];
                return true;

            case 'block-closer':
                if ($stackDepth === 0) {
                    // A stray closer terminates parsing; the closer and all
                    // following bytes become one freeform node.
                    $this->addFreeform();
                    return false;
                }
                if ($stackDepth === 1) {
                    $this->addBlockFromStack($token['start'], $token);
                    $this->offset = $token['start'] + $token['length'];
                    return true;
                }

                $frame = array_pop($this->stack);
                $html = substr($this->source, $frame['prevOffset'], $token['start'] - $frame['prevOffset']);
                $frame['block']->innerHTML .= $html;
                // Pinned nested-close behavior appends even an empty chunk.
                $frame['block']->innerContent[] = $html;
                $this->closeBlock($frame['block'], $token);
                $this->addInnerBlock(
                    $frame['block'],
                    $frame['tokenStart'],
                    $frame['tokenLength'],
                    $token['start'] + $token['length']
                );
                $this->offset = $token['start'] + $token['length'];
                return true;
        }

        $this->addFreeform();
        return false;
    }

    /**
     * @return array{type:string,name:string,attributes:?JsonObject,rawAttributes:?string,start:int,length:int,raw:string}
     */
    private function nextToken(): array
    {
        if (preg_match(
            self::TOKENIZER,
            $this->source,
            $matches,
            PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL,
            $this->offset
        ) !== 1) {
            return [
                'type' => 'no-more-tokens',
                'name' => '',
                'attributes' => null,
                'rawAttributes' => null,
                'start' => 0,
                'length' => 0,
                'raw' => '',
            ];
        }

        $raw = $matches[0][0];
        $start = $matches[0][1];
        $closer = ($matches['closer'][1] ?? -1) >= 0;
        $void = ($matches['void'][1] ?? -1) >= 0;
        $namespace = ($matches['namespace'][1] ?? -1) >= 0
            ? $matches['namespace'][0]
            : 'core/';
        $name = $namespace . $matches['name'][0];
        $rawAttributes = ($matches['attrs'][1] ?? -1) >= 0 ? $matches['attrs'][0] : null;
        $attributes = new JsonObject();
        if ($rawAttributes !== null) {
            $parsed = JsonValue::tryParse($rawAttributes);
            $attributes = $parsed instanceof JsonObject ? $parsed : null;
        }

        // nextToken() gives void precedence even for the malformed `/wp:x /-->
        // shape, exactly as the pinned implementation does.
        $type = $void ? 'void-block' : ($closer ? 'block-closer' : 'block-opener');
        if ($type === 'block-closer') {
            $attributes = null;
        }

        return [
            'type' => $type,
            'name' => $name,
            'attributes' => $attributes,
            'rawAttributes' => $rawAttributes,
            'start' => $start,
            'length' => strlen($raw),
            'raw' => $raw,
        ];
    }

    /** @param array{type:string,name:string,attributes:?JsonObject,rawAttributes:?string,start:int,length:int,raw:string} $token */
    private function blockFromToken(array $token, bool $void): BlockNode
    {
        return new BlockNode(
            name: $token['name'],
            attributes: $token['attributes'],
            void: $void,
            openingDelimiter: $token['raw'],
            rawAttributes: $token['rawAttributes'],
            openingStart: $token['start'],
            openingEnd: $token['start'] + $token['length'],
            sourceStart: $token['start'],
        );
    }

    private function addFreeform(?int $rawLength = null): void
    {
        $length = $rawLength ?? (strlen($this->source) - $this->offset);
        if ($length === 0) {
            return;
        }
        $this->pushFreeform($this->offset, $this->offset + $length);
    }

    private function pushFreeform(int $start, int $end): void
    {
        if ($end <= $start) {
            return;
        }
        $this->output[] = new FreeformNode(substr($this->source, $start, $end - $start), $start, $end);
    }

    private function addInnerBlock(
        BlockNode $block,
        int $tokenStart,
        int $tokenLength,
        ?int $lastOffset = null,
    ): void {
        $parentIndex = count($this->stack) - 1;
        $parent = &$this->stack[$parentIndex];
        $parent['block']->innerBlocks[] = $block;
        $html = substr($this->source, $parent['prevOffset'], $tokenStart - $parent['prevOffset']);
        if ($html !== '') {
            $parent['block']->innerHTML .= $html;
            $parent['block']->innerContent[] = $html;
        }
        $parent['block']->innerContent[] = null;
        $parent['prevOffset'] = $lastOffset ?? ($tokenStart + $tokenLength);
        unset($parent);
    }

    /**
     * @param array{type:string,name:string,attributes:?JsonObject,rawAttributes:?string,start:int,length:int,raw:string}|null $closer
     */
    private function addBlockFromStack(?int $endOffset = null, ?array $closer = null): void
    {
        $frame = array_pop($this->stack);
        $end = $endOffset ?? strlen($this->source);
        $html = substr($this->source, $frame['prevOffset'], $end - $frame['prevOffset']);
        if ($html !== '') {
            $frame['block']->innerHTML .= $html;
            $frame['block']->innerContent[] = $html;
        }
        if ($closer !== null) {
            $this->closeBlock($frame['block'], $closer);
        } else {
            $frame['block']->sourceEnd = strlen($this->source);
            $frame['block']->rawSource = substr(
                $this->source,
                $frame['block']->sourceStart,
                $frame['block']->sourceEnd - $frame['block']->sourceStart
            );
        }
        if ($frame['leadingHtmlStart'] !== null) {
            $this->pushFreeform($frame['leadingHtmlStart'], $frame['tokenStart']);
        }
        $this->output[] = $frame['block'];
    }

    /** @param array{type:string,name:string,attributes:?JsonObject,rawAttributes:?string,start:int,length:int,raw:string} $closer */
    private function closeBlock(BlockNode $block, array $closer): void
    {
        $block->closingDelimiter = $closer['raw'];
        $block->closingStart = $closer['start'];
        $block->closingEnd = $closer['start'] + $closer['length'];
        $block->sourceEnd = $block->closingEnd;
        $block->rawSource = substr(
            $this->source,
            $block->sourceStart,
            $block->sourceEnd - $block->sourceStart
        );
    }
}
