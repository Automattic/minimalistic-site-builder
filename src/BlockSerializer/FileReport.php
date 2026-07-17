<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer;

final class FileReport
{
    /**
     * @param list<DroppedValue> $dropped
     * @param list<Repair> $repairs
     */
    public function __construct(
        public readonly string $path,
        public readonly string $status,
        public readonly array $dropped = [],
        public readonly array $repairs = [],
    ) {
        if (!in_array($status, ['fixed', 'ok', 'skip'], true)) {
            throw new \InvalidArgumentException("Invalid file status '{$status}'");
        }
    }

    /** @return array<string,mixed> */
    public function normalized(): array
    {
        return [
            'path' => $this->path,
            'status' => $this->status === 'fixed' ? 'FIXED' : $this->status,
            'dropped' => array_map(static fn (DroppedValue $drop): array => $drop->toArray(), $this->dropped),
        ];
    }
}
