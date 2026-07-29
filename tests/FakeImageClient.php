<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Tests;

use Automattic\SiteBuild\ImageClient;

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

    /** Prompt substrings the fake safety filter rejects (`filtered` failures). */
    public array $filterPromptSubstrings = [];

    public function model(): string
    {
        return 'fake-image-model';
    }

    public function generate(string $prompt, array $opts = []): string
    {
        $this->calls[] = ['prompt' => $prompt, 'opts' => $opts];
        if ($this->fail) {
            throw new \RuntimeException('fake image failure');
        }
        return $this->bytes;
    }

    /** Test hook: runs after each onResult delivery, to observe mid-batch state. */
    public ?\Closure $afterEachResult = null;

    public function generateBatch(array $specs, ?callable $onResult = null): array
    {
        $this->batches[] = $specs;
        $results = [];
        foreach ($specs as $i => $spec) {
            // Record each spec as a call too, so existing assertions on ->calls
            // (prompt + aspect ratio) keep working under batching.
            $this->calls[] = ['prompt' => $spec['prompt'], 'opts' => [
                'aspect_ratio'      => $spec['aspect_ratio'] ?? '16:9',
                'sample_image_size' => $spec['sample_image_size'] ?? null,
                'mime'              => $spec['mime'] ?? null,
            ]];
            $prompt = (string) $spec['prompt'];
            if ($this->fail || $this->matches($prompt, $this->failPromptSubstrings)) {
                $results[$i] = ['ok' => false, 'error' => 'fake image failure'];
            } elseif ($this->matches($prompt, $this->filterPromptSubstrings)) {
                $results[$i] = ['ok' => false, 'error' => 'Image safety filter rejected the prompt: fake rai', 'filtered' => true];
            } else {
                $results[$i] = ['ok' => true, 'bytes' => $this->bytes];
            }
            if ($onResult !== null) {
                $onResult($i, $results[$i]);
                if ($this->afterEachResult !== null) {
                    ($this->afterEachResult)($i);
                }
            }
        }
        return $results;
    }

    private function matches(string $prompt, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($prompt, $needle)) {
                return true;
            }
        }
        return false;
    }
}
