<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

/**
 * Project-free outcome of one markup generation unit.
 *
 * Successful, semantics-preserving repairs are kept separate from durable
 * warnings about delivered-value loss. The ordinary-array representation is
 * the wire contract used by stateless HTTP hosts.
 */
final class MarkupResult implements \JsonSerializable
{
    /**
     * @param list<array<string,mixed>> $repairs
     * @param list<string>              $warnings
     */
    public function __construct(
        public readonly string $markup,
        public readonly array $repairs = [],
        public readonly array $warnings = [],
    ) {
        foreach ($repairs as $repair) {
            if (!is_array($repair)) {
                throw new \InvalidArgumentException('markup result repairs must be arrays');
            }
        }
        foreach ($warnings as $warning) {
            if (!is_string($warning)) {
                throw new \InvalidArgumentException('markup result warnings must be strings');
            }
        }
    }

    /** @return array{markup:string,repairs:list<array<string,mixed>>,warnings:list<string>} */
    public function toArray(): array
    {
        return [
            'markup' => $this->markup,
            'repairs' => $this->repairs,
            'warnings' => $this->warnings,
        ];
    }

    /** @return array{markup:string,repairs:list<array<string,mixed>>,warnings:list<string>} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
