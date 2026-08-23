<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Shared transport contract for coding-agent CLI harnesses.
 *
 * Subclasses own only provider-specific argv construction and response parsing.
 * Prompt assembly, validation, pooling, usage, and degradation live here.
 */
abstract class HarnessCliLlm implements Llm, UsageReporting
{
    public function __construct(
        protected readonly string $binary,
        protected readonly string $model,
        protected readonly int $cap = 4,
        protected readonly int $timeoutSeconds = 300,
    ) {
    }

    /**
     * @param array<string,mixed> $request
     * @return list<string>
     */
    abstract protected function argvFor(array $request, string $model): array;

    /**
     * @return array<string,mixed>
     */
    abstract protected function parseResponse(string $stdout, string $stderr, int $exit): array;

    final public function usageTotals(): array
    {
        return [
            'requests' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'cache_creation_input_tokens' => 0,
            'cache_read_input_tokens' => 0,
        ];
    }

    public function complete(string $prompt, array $opts = []): string
    {
        throw new \LogicException('HarnessCliLlm implementation pending.');
    }

    public function completeJson(string $prompt, array $opts = []): array
    {
        throw new \LogicException('HarnessCliLlm implementation pending.');
    }

    public function completeJsonBatch(array $requests): array
    {
        throw new \LogicException('HarnessCliLlm implementation pending.');
    }

    public function completeBatch(array $requests): TextBatchResult
    {
        throw new \LogicException('HarnessCliLlm implementation pending.');
    }
}
