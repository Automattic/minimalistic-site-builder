<?php
declare(strict_types=1);

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
        $this->calls[] = ['prompt' => $prompt, 'opts' => $opts];
        if ($this->textQueue === []) {
            throw new RuntimeException('FakeLlm: no queued text response');
        }
        return array_shift($this->textQueue);
    }

    public function completeJson(string $prompt, array $opts = []): array
    {
        $this->calls[] = ['prompt' => $prompt, 'opts' => $opts];
        if ($this->jsonQueue === []) {
            throw new RuntimeException('FakeLlm: no queued json response');
        }
        return array_shift($this->jsonQueue);
    }
}
