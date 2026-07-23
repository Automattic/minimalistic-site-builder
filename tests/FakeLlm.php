<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Tests;

use Automattic\SiteBuild\Llm;

/**
 * Test double for Llm. Returns queued canned responses (FIFO) and records the
 * prompts/options it received so tests can assert on what a step sent.
 */
final class FakeLlm implements Llm
{
    /** @var string[] */
    private array $textQueue = [];
    /** @var array<int,array<mixed>> */
    private array $jsonQueue = [];
    /** @var array<int,array{prompt:string,opts:array<mixed>}> */
    public array $calls = [];
    public int $completeCalls = 0;
    public int $completeJsonCalls = 0;
    public int $completeBatchCalls = 0;
    public int $completeJsonBatchCalls = 0;

    /**
     * Prompt substrings that fail permanently. complete() throws for a matching
     * prompt; completeBatch() throws for the WHOLE batch when any request
     * matches — mirroring the real clients, whose retryTextBatch aborts the
     * batch on the first permanently-failed request.
     *
     * @var string[]
     */
    public array $failPromptSubstrings = [];

    public function queueText(string $text): void
    {
        $this->textQueue[] = $text;
    }

    /** @param array<mixed> $data */
    public function queueJson(array $data): void
    {
        $this->jsonQueue[] = $data;
    }

    public function complete(string $prompt, array $opts = []): string
    {
        $this->completeCalls++;
        $this->calls[] = ['prompt' => $prompt, 'opts' => $opts];
        if ($this->shouldFail($prompt)) {
            throw new \RuntimeException('FakeLlm: permanent failure');
        }
        if ($this->textQueue === []) {
            throw new \RuntimeException('FakeLlm: no queued text response');
        }
        return array_shift($this->textQueue);
    }

    public function completeJson(string $prompt, array $opts = []): array
    {
        $this->completeJsonCalls++;
        $this->calls[] = ['prompt' => $prompt, 'opts' => $opts];
        if ($this->jsonQueue === []) {
            throw new \RuntimeException('FakeLlm: no queued json response');
        }
        return array_shift($this->jsonQueue);
    }

    /**
     * Pull one queued JSON response per request, in the order the requests are
     * given, keyed back as the input. Each request's meta (model/max_tokens/…)
     * is recorded as that call's opts so model-wiring assertions still work.
     *
     * @param array<array-key,array{prompt:string,system?:string,model?:string,max_tokens?:int,json_schema?:array{name:string,schema:array<string,mixed>}}> $requests
     * @return array<array-key,array<mixed>>
     */
    public function completeJsonBatch(array $requests): array
    {
        $this->completeJsonBatchCalls++;
        $out = [];
        foreach ($requests as $key => $req) {
            $opts = $req;
            unset($opts['prompt']);
            $this->calls[] = ['prompt' => (string) $req['prompt'], 'opts' => $opts];
            if ($this->jsonQueue === []) {
                throw new \RuntimeException('FakeLlm: no queued json response');
            }
            $out[$key] = array_shift($this->jsonQueue);
        }
        return $out;
    }

    /**
     * Pull one queued TEXT response per request, in order, keyed back as the
     * input. Records each call's meta as opts so model-wiring assertions work.
     *
     * @param array<array-key,array{prompt:string,system?:string,model?:string,max_tokens?:int}> $requests
     * @return array<array-key,string>
     */
    public function completeBatch(array $requests): array
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
            $out[$key] = array_shift($this->textQueue);
        }
        return $out;
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
