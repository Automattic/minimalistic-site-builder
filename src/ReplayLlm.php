<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Network-free JSON transport for fixture builds and recorded-response replay.
 *
 * Responses are consumed in request order. It intentionally refuses raw-text
 * calls so a fixture cannot silently stand in for a graph it does not cover.
 */
final class ReplayLlm implements Llm
{
    /** @var list<array<mixed>> */
    private array $responses;

    /** @param list<array<mixed>> $responses */
    public function __construct(array $responses)
    {
        if (!array_is_list($responses)) {
            throw new \InvalidArgumentException('Replay responses must be a list');
        }
        foreach ($responses as $response) {
            if (!is_array($response)) {
                throw new \InvalidArgumentException('Each replay response must be a JSON object or array');
            }
        }
        $this->responses = $responses;
    }

    public function complete(string $prompt, array $opts = []): string
    {
        throw new \LogicException('ReplayLlm has no raw-text fixture response');
    }

    public function completeJson(string $prompt, array $opts = []): array
    {
        return $this->next();
    }

    public function completeJsonBatch(array $requests): array
    {
        $results = [];
        foreach (array_keys($requests) as $key) {
            $results[$key] = $this->next();
        }
        return $results;
    }

    public function completeBatch(array $requests): TextBatchResult
    {
        throw new \LogicException('ReplayLlm has no raw-text fixture responses');
    }

    public function remaining(): int
    {
        return count($this->responses);
    }

    /** @return array<mixed> */
    private function next(): array
    {
        if ($this->responses === []) {
            throw new \RuntimeException('ReplayLlm has no queued JSON response');
        }
        return array_shift($this->responses);
    }
}
