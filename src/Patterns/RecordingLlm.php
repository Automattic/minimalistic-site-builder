<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Patterns;

use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\TextBatchResult;

/**
 * Wraps a live model and keeps every answer in the shape `ReplayLlm` reads,
 * so one live run of a fixture becomes its recording.
 *
 * Slot answers are kept by id, so a retry's answer lands beside the first
 * call's rather than over it, and a replay fills every slot the build finally
 * used in one call.
 */
final class RecordingLlm implements Llm
{
    /** @var array<string, string> */
    private array $slots = [];

    /** @var array<string, mixed>|null */
    private ?array $plan = null;

    public function __construct(private Llm $inner)
    {
    }

    public function complete(string $prompt, array $opts = []): string
    {
        return $this->inner->complete($prompt, $opts);
    }

    public function completeJson(string $prompt, array $opts = []): array
    {
        $response = $this->inner->completeJson($prompt, $opts);
        $this->keep($prompt, $opts, $response);

        return $response;
    }

    public function completeJsonBatch(array $requests): array
    {
        $results = $this->inner->completeJsonBatch($requests);
        foreach ($results as $key => $response) {
            $request = $requests[$key] ?? [];
            $this->keep((string) ($request['prompt'] ?? ''), $request, $response);
        }

        return $results;
    }

    public function completeBatch(array $requests): TextBatchResult
    {
        return $this->inner->completeBatch($requests);
    }

    /** @return array<string, mixed> */
    public function recording(): array
    {
        $recording = ['version' => ReplayLlm::VERSION, 'slots' => $this->slots];
        if ($this->plan !== null) {
            $recording['plan'] = $this->plan;
        }

        return $recording;
    }

    /** @param array<mixed> $response */
    private function keep(string $prompt, array $opts, array $response): void
    {
        [$name] = ReplayLlm::keyFor($prompt, $opts);

        if ($name === 'site_plan') {
            $this->plan = $response;
            return;
        }

        if ($name === 'page_content') {
            foreach ((array) ($response['content'] ?? []) as $item) {
                if (is_array($item) && isset($item['id'], $item['text'])) {
                    $this->slots[(string) $item['id']] = (string) $item['text'];
                }
            }
        }
    }
}
