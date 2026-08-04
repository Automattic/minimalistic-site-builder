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
    private const JPEG_FIXTURE = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AVN//2Q==';

    private const PNG_FIXTURE = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

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

    /** Prompt substring => exact bytes, for mixed-delivery boundary tests. */
    public array $bytesByPromptSubstring = [];

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
        return $this->bytesForMime((string) ($opts['mime'] ?? 'image/jpeg'));
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
                $results[$i] = [
                    'ok' => true,
                    'bytes' => $this->bytesForPrompt(
                        $prompt,
                        (string) ($spec['mime'] ?? 'image/jpeg'),
                    ),
                ];
            }
            if ($onResult !== null) {
                $onResult($i, $results[$i]);
                // Mirror the production contract: with a callback, the
                // returned records omit bytes.
                unset($results[$i]['bytes']);
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

    /**
     * The legacy readable sentinels become real image fixtures so tests cross
     * the same byte-validation boundary as production. Any other value remains
     * verbatim, allowing individual tests to inject malformed or mismatched
     * payloads deliberately.
     */
    private function bytesForMime(string $mime): string
    {
        if (!in_array($this->bytes, ['FAKEJPEGBYTES', 'JPEGDATA', 'PNGDATA'], true)) {
            return $this->bytes;
        }
        $fixture = $mime === 'image/png' ? self::PNG_FIXTURE : self::JPEG_FIXTURE;
        return (string) base64_decode($fixture, true);
    }

    private function bytesForPrompt(string $prompt, string $mime): string
    {
        foreach ($this->bytesByPromptSubstring as $needle => $bytes) {
            if (str_contains($prompt, (string) $needle)) {
                return (string) $bytes;
            }
        }
        return $this->bytesForMime($mime);
    }
}
