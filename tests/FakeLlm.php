<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Tests;

use Automattic\SiteBuild\FinishReasonAwareLlm;
use Automattic\SiteBuild\UsageReporting;

/**
 * Test double for Llm. Returns queued canned responses (FIFO) and records the
 * prompts/options it received so tests can assert on what a step sent.
 */
final class FakeLlm implements FinishReasonAwareLlm, UsageReporting
{
    /** @var list<array{text:string,finish_reason:?string}> */
    private array $textQueue = [];
    /** @var array<int,array<mixed>> */
    private array $jsonQueue = [];
    /** @var array<int,array{prompt:string,opts:array<mixed>}> */
    public array $calls = [];
    public int $completeCalls = 0;
    public int $completeJsonCalls = 0;
    public int $completeBatchCalls = 0;
    public int $completeJsonBatchCalls = 0;
    /** Set false to model a host that accepts but drops cached_prefixes. */
    public bool $billCachedPrefixes = true;
    private int $usageRequests = 0;
    private int $usageInputTokens = 0;
    private int $usageOutputTokens = 0;
    private ?string $lastFinishReason = null;
    /** @var array<array-key,list<string>> keyed notes returned with the next raw-text batch */
    public array $batchNotes = [];

    /**
     * Prompt substrings that fail permanently. complete()/completeJson() throw
     * for a matching prompt; completeBatch()/completeJsonBatch() throw for the
     * WHOLE batch when any request matches — mirroring the real clients, whose
     * batch retry aborts on the first permanently-failed request.
     *
     * @var string[]
     */
    public array $failPromptSubstrings = [];

    public function queueText(string $text, ?string $finishReason = null): void
    {
        $this->textQueue[] = ['text' => $text, 'finish_reason' => $finishReason];
    }

    /** @param array<mixed> $data */
    public function queueJson(array $data): void
    {
        $this->jsonQueue[] = $data;
    }

    /** @return array{requests:int,input_tokens:int,output_tokens:int,total_tokens:int} */
    public function usageTotals(): array
    {
        return [
            'requests' => $this->usageRequests,
            'input_tokens' => $this->usageInputTokens,
            'output_tokens' => $this->usageOutputTokens,
            'total_tokens' => $this->usageInputTokens + $this->usageOutputTokens,
        ];
    }

    public function complete(string $prompt, array $opts = []): string
    {
        $this->lastFinishReason = null;
        $this->completeCalls++;
        $this->calls[] = ['prompt' => $prompt, 'opts' => $opts];
        if ($this->shouldFail($prompt)) {
            throw new \RuntimeException('FakeLlm: permanent failure');
        }
        if ($this->textQueue === []) {
            throw new \RuntimeException('FakeLlm: no queued text response');
        }
        $response = array_shift($this->textQueue);
        $this->lastFinishReason = $response['finish_reason'];
        $this->recordUsage($prompt, $response['text'], $opts);
        return $response['text'];
    }

    public function lastFinishReason(): ?string
    {
        return $this->lastFinishReason;
    }

    public function completeJson(string $prompt, array $opts = []): array
    {
        $this->completeJsonCalls++;
        $this->calls[] = ['prompt' => $prompt, 'opts' => $opts];
        if ($this->shouldFail($prompt)) {
            throw new \RuntimeException('FakeLlm: permanent failure');
        }
        if ($this->jsonQueue === []) {
            throw new \RuntimeException('FakeLlm: no queued json response');
        }
        $response = array_shift($this->jsonQueue);
        $this->recordUsage($prompt, self::jsonUsageText($response), $opts);
        return $response;
    }

    /**
     * Pull one queued JSON response per request, in the order the requests are
     * given, keyed back as the input. Each request's meta (model/max_tokens/…)
     * is recorded as that call's opts so model-wiring assertions still work.
     *
     * @param array<array-key,array{prompt:string,system?:string,model?:string,max_tokens?:int,temperature?:float,json_schema?:array{name:string,schema:array<string,mixed>},cached_prefixes?:list<string>}> $requests
     * @return array<array-key,array<mixed>>
     */
    public function completeJsonBatch(array $requests): array
    {
        $this->completeJsonBatchCalls++;
        foreach ($requests as $key => $req) {
            if ($this->shouldFail((string) $req['prompt'])) {
                throw new \RuntimeException("FakeLlm: batch request '{$key}' failed");
            }
        }
        $out = [];
        foreach ($requests as $key => $req) {
            $opts = $req;
            unset($opts['prompt']);
            $this->calls[] = ['prompt' => (string) $req['prompt'], 'opts' => $opts];
            if ($this->jsonQueue === []) {
                throw new \RuntimeException('FakeLlm: no queued json response');
            }
            $response = array_shift($this->jsonQueue);
            $this->recordUsage((string) $req['prompt'], self::jsonUsageText($response), $opts);
            $out[$key] = $response;
        }
        return $out;
    }

    /**
     * Pull one queued TEXT response per request, in order, keyed back as the
     * input. Records each call's meta as opts so model-wiring assertions work.
     *
     * @param array<array-key,array{prompt:string,system?:string,model?:string,max_tokens?:int,temperature?:float,cached_prefixes?:list<string>}> $requests
     * @return TextBatchResult
     */
    public function completeBatch(array $requests): \Automattic\SiteBuild\TextBatchResult
    {
        $this->completeBatchCalls++;
        // All-or-nothing like the real clients: one failing request aborts
        // the batch before any result is returned.
        foreach ($requests as $key => $req) {
            if ($this->shouldFail((string) $req['prompt'])) {
                throw new \RuntimeException("FakeLlm: batch request '{$key}' failed");
            }
        }
        $out = [];
        foreach ($requests as $key => $req) {
            $opts = $req;
            unset($opts['prompt']);
            $this->calls[] = ['prompt' => (string) $req['prompt'], 'opts' => $opts];
            if ($this->textQueue === []) {
                throw new \RuntimeException('FakeLlm: no queued text response');
            }
            $response = array_shift($this->textQueue)['text'];
            $this->recordUsage((string) $req['prompt'], $response, $opts);
            $out[$key] = $response;
        }
        $notes = $this->batchNotes;
        $this->batchNotes = [];
        return new \Automattic\SiteBuild\TextBatchResult($out, $notes);
    }

    /**
     * Bill a call the way a conformant host would: `cached_prefixes` are part
     * of the input the model was handed, so they are part of the input usage.
     * Counting only `prompt` would model a host that discards the layers, and
     * SectionsStep's context-loss guard reads exactly this figure — so every
     * fixture build would carry a spurious warning, and the guard's silent path
     * would never be exercised.
     *
     * @param array<string,mixed> $opts the request's options, minus the prompt
     */
    private function recordUsage(string $prompt, string $response, array $opts = []): void
    {
        $this->usageRequests++;
        $this->usageInputTokens += self::syntheticTokenCount($prompt);
        if ($this->billCachedPrefixes) {
            foreach ($opts['cached_prefixes'] ?? [] as $prefix) {
                $this->usageInputTokens += self::syntheticTokenCount((string) $prefix);
            }
        }
        $this->usageOutputTokens += self::syntheticTokenCount($response);
    }

    private static function syntheticTokenCount(string $text): int
    {
        return max(1, (int) ceil(strlen($text) / 4));
    }

    /** @param array<mixed> $response */
    private static function jsonUsageText(array $response): string
    {
        $encoded = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($encoded) ? $encoded : '';
    }

    /** Unconsumed queued responses (text + json), so tests can assert a drained queue. */
    public function remaining(): int
    {
        return count($this->textQueue) + count($this->jsonQueue);
    }

    private function shouldFail(string $prompt): bool
    {
        foreach ($this->failPromptSubstrings as $needle) {
            if (str_contains($prompt, $needle)) {
                return true;
            }
        }
        return false;
    }
}
