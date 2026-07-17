<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer;

final class DroppedValue
{
    public function __construct(
        public readonly string $kind,
        public readonly string $value,
        public readonly int $lost = 1,
    ) {
        if (!in_array($kind, ['style', 'class'], true) || $lost < 1) {
            throw new \InvalidArgumentException('Invalid dropped-content record');
        }
    }

    public function line(): string
    {
        return 'DROPPED ' . $this->kind . ' `' . $this->value . '`'
            . ($this->lost > 1 ? ' (x' . $this->lost . ')' : '')
            . ' — not mirrored in the block comment JSON attributes';
    }

    /** @return array{kind:string,value:string,lost:int,line:string} */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'value' => $this->value,
            'lost' => $this->lost,
            'line' => $this->line(),
        ];
    }
}
