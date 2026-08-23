<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Codex CLI used as a subscription-backed completion transport.
 *
 * Unlike Claude, Codex has no first-class system channel that keeps text out
 * of argv. Non-blank system text is disclosed as unsupported and never sent.
 */
final class CodexCliLlm extends HarnessCliLlm
{
    public function __construct(
        string $model,
        string $binary = 'codex',
        int $cap = self::DEFAULT_CONCURRENCY,
        int $timeoutSeconds = 300,
    ) {
        parent::__construct($binary, $model, $cap, $timeoutSeconds);
    }

    protected function jobFor(array $prepared, string $scratchDir): array
    {
        $model = $prepared['model'];
        $outputPath = $scratchDir . DIRECTORY_SEPARATOR . 'answer.txt';
        $argv = [
            $this->binary,
            'exec',
            '--ignore-user-config',
            '--skip-git-repo-check',
            '--json',
            '-o',
            $outputPath,
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
                throw new LlmRequestRejected('Codex CLI json_schema could not be encoded: ' . $e->getMessage());
            }
            $schemaPath = $scratchDir . DIRECTORY_SEPARATOR . 'schema.json';
            $this->writeScratchFile($schemaPath, $schemaJson, 'JSON schema');
            $argv[] = '--output-schema';
            $argv[] = $schemaPath;
        }

        return ['argv' => $argv, 'stdin' => $prepared['prompt']];
    }

    protected function parseResponse(
        string $stdout,
        string $stderr,
        int $exit,
        string $scratchDir,
    ): array {
        $turnUsage = null;
        foreach (preg_split('/\R/', trim($stdout)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            try {
                $event = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw $this->harnessFailure(
                    'Codex stdout was not valid JSONL: ' . $e->getMessage(),
                    $exit,
                    $stderr,
                );
            }
            if (!is_array($event)) {
                throw $this->harnessFailure('Codex JSONL event was not an object', $exit, $stderr);
            }
            if (($event['type'] ?? null) === 'turn.completed' && is_array($event['usage'] ?? null)) {
                $turnUsage = $event['usage'];
            }
        }
        if ($turnUsage === null) {
            throw $this->harnessFailure(
                'Codex stdout had no turn.completed usage event',
                $exit,
                $stderr,
            );
        }

        $outputPath = $scratchDir . DIRECTORY_SEPARATOR . 'answer.txt';
        $answerFromFile = @file_get_contents($outputPath);
        if (!is_string($answerFromFile)) {
            throw $this->harnessFailure('Codex final output file was missing or unreadable', $exit, $stderr);
        }

        return [
            'text' => $answerFromFile,
            'stop_reason' => null,
            // Codex follows the OpenAI Responses convention: cached input is a
            // subset of input_tokens, so it must not be added a second time.
            'input' => (int) ($turnUsage['input_tokens'] ?? 0),
            'output' => (int) ($turnUsage['output_tokens'] ?? 0),
            'cache_read_input_tokens' => (int) ($turnUsage['cached_input_tokens'] ?? 0),
            'cache_creation_input_tokens' => (int) ($turnUsage['cache_write_input_tokens'] ?? 0),
        ];
    }

    private function writeScratchFile(string $path, string $contents, string $purpose): void
    {
        $written = @file_put_contents($path, $contents, LOCK_EX);
        if ($written !== strlen($contents)) {
            throw new \RuntimeException("Could not write Codex {$purpose} scratch file: {$path}");
        }
    }
}
