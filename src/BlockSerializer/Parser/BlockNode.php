<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Parser;

use Automattic\SiteBuild\BlockSerializer\Json\JsonObject;

/** A named Gutenberg block with lossless delimiter and content provenance. */
final class BlockNode implements DocumentNode
{
    /** @var list<BlockNode> */
    public array $innerBlocks = [];

    /** @var list<string|null> Strings interleaved with null child placeholders. */
    public array $innerContent = [];

    public string $innerHTML = '';
    public string $rawSource = '';

    /**
     * Dotted comment-attribute key paths whose duplicate JSON declarations
     * were deep-merged during tokenization (see JsonDecoder's opt-in
     * duplicate-key merge). Empty for well-formed authored JSON.
     *
     * @var list<string>
     */
    public array $mergedAttributeKeyPaths = [];
    public ?string $closingDelimiter = null;
    public ?int $closingStart = null;
    public ?int $closingEnd = null;
    public int $sourceEnd;

    public function __construct(
        public string $name,
        public ?JsonObject $attributes,
        public bool $void,
        public string $openingDelimiter,
        public ?string $rawAttributes,
        public int $openingStart,
        public int $openingEnd,
        public int $sourceStart,
    ) {
        $this->sourceEnd = $openingEnd;
    }

    public function sourceStart(): int
    {
        return $this->sourceStart;
    }

    public function sourceEnd(): int
    {
        return $this->sourceEnd;
    }

    public function rawSource(): string
    {
        return $this->rawSource;
    }

    public function blockName(): string
    {
        return $this->name;
    }

    public function attrs(): ?JsonObject
    {
        return $this->attributes;
    }
}
