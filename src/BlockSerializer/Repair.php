<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer;

final class Repair
{
    public function __construct(
        public readonly string $code,
        public readonly string $blockPath,
    ) {
        if ($code === '' || $blockPath === '') {
            throw new \InvalidArgumentException('Repair code and block path are required');
        }
    }

    public function key(string $file): string
    {
        return $file . "\0" . $this->blockPath . "\0" . $this->code;
    }

    /** @return array{code:string,blockPath:string} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'blockPath' => $this->blockPath];
    }
}
