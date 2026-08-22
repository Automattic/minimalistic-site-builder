<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer;

final class Repair
{
    /** Code prefix for a block delivered verbatim by block-level isolation. */
    public const PRESERVED_PREFIX = 'preserved ';

    public function __construct(
        public readonly string $code,
        public readonly string $blockPath,
    ) {
        if ($code === '' || $blockPath === '') {
            throw new \InvalidArgumentException('Repair code and block path are required');
        }
    }

    public function key(): string
    {
        return $this->blockPath . "\0" . $this->code;
    }

    /** @param list<Repair> $repairs @return list<Repair> */
    public static function dedupe(array $repairs): array
    {
        $unique = [];
        foreach ($repairs as $repair) {
            $unique[$repair->key()] = $repair;
        }
        return array_values($unique);
    }
}
