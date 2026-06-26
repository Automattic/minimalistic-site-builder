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

    public function generate(string $prompt, array $opts = []): string
    {
        $this->calls[] = ['prompt' => $prompt, 'opts' => $opts];
        if ($this->fail) {
            throw new RuntimeException('fake image failure');
        }
        return $this->bytes;
    }
}
