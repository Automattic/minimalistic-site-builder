<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer;

final class FileReport
{
    /**
     * @param list<DroppedValue> $dropped
     * @param list<Repair> $repairs
     * @param ?string $error why the file kept its authored bytes ('failed' only)
     */
    public function __construct(
        public readonly string $path,
        public readonly string $status,
        public readonly array $dropped = [],
        public readonly array $repairs = [],
        public readonly ?string $error = null,
    ) {
        // 'failed' means this one file kept the bytes the generator wrote,
        // because the serializer could not process it. That markup still
        // renders — it just was not canonicalized. The run continues, so one
        // unprocessable section cannot cost a site every other section.
        if (!in_array($status, ['fixed', 'ok', 'skip', 'failed'], true)) {
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
