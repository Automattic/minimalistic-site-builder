<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Parser;

/** Top-level HTML/text outside named block delimiters. */
final class FreeformNode implements DocumentNode
{
    public function __construct(
        public string $content,
        public int $start,
        public int $end,
    ) {}

    public function sourceStart(): int
    {
        return $this->start;
    }

    public function sourceEnd(): int
    {
        return $this->end;
    }

    public function rawSource(): string
    {
        return $this->content;
    }
}
