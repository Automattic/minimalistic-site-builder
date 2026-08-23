<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Grok CLI used as a subscription-backed completion transport. */
final class GrokCliLlm extends HarnessCliLlm
{
    public function __construct(
        string $model,
        string $binary = 'grok',
        int $cap = 4,
        int $timeoutSeconds = 300,
    ) {
        parent::__construct($binary, $model, $cap, $timeoutSeconds);
    }

    protected function jobFor(array $prepared, string $scratchDir): array
    {
        $model = $prepared['model'];
        $promptPath = $scratchDir . DIRECTORY_SEPARATOR . 'prompt.txt';
        $this->writeScratchFile($promptPath, $prepared['prompt'], 'prompt');

        $argv = [
            $this->binary,
            '--prompt-file',
            $promptPath,
            '--output-format',
            'json',
            '-m',
            $model,
        ];
        $schema = $prepared['request']['json_schema']['schema'] ?? null;
        if (is_array($schema)) {
            try {
                $schemaJson = json_encode(
                    $schema,
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                );
            } catch (\JsonException $e) {
                throw new LlmRequestRejected('Grok CLI json_schema could not be encoded: ' . $e->getMessage());
            }
            $argv[] = '--json-schema';
            $argv[] = $schemaJson;
        }

        // Omitting stdin makes ProcessPool connect /dev/null. The prompt exists
        // only in the private scratch file, never in world-readable argv.
        return ['argv' => $argv];
    }

    protected function parseResponse(
        string $stdout,
        string $stderr,
        int $exit,
        string $scratchDir,
    ): array {
        try {
            $envelope = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw $this->harnessFailure(
                'Grok stdout was not a JSON object: ' . $e->getMessage(),
                $exit,
                $stderr,
            );
        }
        if (!is_array($envelope)) {
            throw $this->harnessFailure('Grok JSON envelope was not an object', $exit, $stderr);
        }
        if (!array_key_exists('text', $envelope) || !is_string($envelope['text'])) {
            throw $this->harnessFailure('Grok JSON envelope has no string text', $exit, $stderr);
        }

        return [
            'text' => $envelope['text'],
            'stop_reason' => is_string($envelope['stopReason'] ?? null)
                ? $envelope['stopReason']
                : null,
            // Grok excludes cached input from usage.input_tokens. The base adds
            // cache read and creation tokens to produce total billed input.
            'usage' => is_array($envelope['usage'] ?? null) ? $envelope['usage'] : [],
        ];
    }

    private function writeScratchFile(string $path, string $contents, string $purpose): void
    {
        $written = @file_put_contents($path, $contents, LOCK_EX);
        if ($written !== strlen($contents)) {
            throw new \RuntimeException("Could not write Grok {$purpose} scratch file: {$path}");
        }
    }
}
