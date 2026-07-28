<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer;

final class FileReport
{
    /**
     * 'failed' records a file whose transformation was abandoned: its
     * pre-fixer bytes were delivered untouched and $error says why. That is
     * an isolated per-file degradation, not a fixer crash — the step records
     * it in warnings.json and the build continues.
     *
     * @param list<DroppedValue> $dropped
     * @param list<Repair> $repairs
     */
    public function __construct(
        public readonly string $path,
        public readonly string $status,
        public readonly array $dropped = [],
        public readonly array $repairs = [],
        public readonly ?string $error = null,
    ) {
        if (!in_array($status, ['fixed', 'ok', 'skip', 'failed'], true)) {
            throw new \InvalidArgumentException("Invalid file status '{$status}'");
        }
        if (($status === 'failed') !== ($error !== null)) {
            throw new \InvalidArgumentException("File status '{$status}' and error detail must come together");
        }
    }

    /** @return array<string,mixed> */
    public function normalized(): array
    {
        $normalized = [
            'path' => $this->path,
            'status' => $this->status === 'fixed' ? 'FIXED' : $this->status,
            'dropped' => array_map(static fn (DroppedValue $drop): array => $drop->toArray(), $this->dropped),
        ];
        if ($this->error !== null) {
            $normalized['error'] = $this->error;
        }
        return $normalized;
    }
}
