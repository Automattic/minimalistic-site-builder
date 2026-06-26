<?php
declare(strict_types=1);

/**
 * Test double for ImageClient. Returns canned bytes and records the prompts and
 * aspect ratios it was asked for. Can be told to throw to exercise failure paths.
 */
final class FakeImageClient implements ImageClient
{
    /** @var array<int,array{prompt:string,opts:array<mixed>}> */
    public array $calls = [];

    public function __construct(
        private string $bytes = 'FAKEJPEGBYTES',
        private bool $fail = false,
    ) {}

    /** Batches issued via generateBatch(): one entry per call, each the list of specs. */
    public array $batches = [];

    /** Prompt substrings that should fail generation (for partial-failure tests). */
    public array $failPromptSubstrings = [];

    public function generate(string $prompt, array $opts = []): string
    {
        $this->calls[] = ['prompt' => $prompt, 'opts' => $opts];
        if ($this->fail) {
            throw new RuntimeException('fake image failure');
        }
        return $this->bytes;
    }

    public function generateBatch(array $specs): array
    {
        $this->batches[] = $specs;
        $results = [];
        foreach ($specs as $i => $spec) {
            // Record each spec as a call too, so existing assertions on ->calls
            // (prompt + aspect ratio) keep working under batching.
            $this->calls[] = ['prompt' => $spec['prompt'], 'opts' => ['aspect_ratio' => $spec['aspect_ratio'] ?? '16:9']];
            $results[$i] = ($this->fail || $this->shouldFail((string) $spec['prompt']))
                ? ['ok' => false, 'error' => 'fake image failure']
                : ['ok' => true, 'bytes' => $this->bytes];
        }
        return $results;
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
