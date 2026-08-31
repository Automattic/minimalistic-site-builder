<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Claude Code used as a subscription-backed completion transport. */
final class ClaudeCliLlm extends HarnessCliLlm
{
    public function __construct(
        string $model,
        string $binary = 'claude',
        int $cap = self::DEFAULT_CONCURRENCY,
        int $timeoutSeconds = 300,
    ) {
        parent::__construct($binary, $model, $cap, $timeoutSeconds);
    }

    protected function jobFor(array $prepared, string $scratchDir): array
    {
        $request = $prepared['request'];
        $model = $prepared['model'];
        $argv = [
            $this->binary,
            '-p',
            // --safe-mode preserves subscription/keychain auth while isolating
            // project configuration. --bare would require ANTHROPIC_API_KEY and
            // silently cross the billing boundary this transport protects.
            '--safe-mode',
            '--output-format',
            'json',
            '--model',
            $model,
            '--max-turns',
            '2',
            '--effort',
            self::REASONING_EFFORT,
            '--tools',
            '',
        ];

        if (self::THINKING_OFF) {
            // --effort is dropped for models older than Claude 5 (Haiku 4.5
            // records effort:null and thinks anyway); this env lever is not.
            $argv[] = '--settings';
            $argv[] = json_encode(
                ['env' => ['MAX_THINKING_TOKENS' => '0']],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        }

        $system = $request['system'] ?? '';
        if (is_string($system) && trim($system) !== '') {
            $argv[] = '--system-prompt';
            $argv[] = $system;
        }
        if (isset($request['json_schema']['schema']) && is_array($request['json_schema']['schema'])) {
            try {
                $schema = json_encode(
                    $request['json_schema']['schema'],
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                );
            } catch (\JsonException $e) {
                throw new LlmRequestRejected('Claude CLI json_schema could not be encoded: ' . $e->getMessage());
            }
            $argv[] = '--json-schema';
            $argv[] = $schema;
        }
        return ['argv' => $argv, 'stdin' => $prepared['prompt']];
    }

    protected function honorsSystemOption(): bool
    {
        return true;
    }

    protected function parseResponse(
        string $stdout,
        string $stderr,
        int $exit,
        string $scratchDir,
    ): array
    {
        try {
            $envelope = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $snippet = substr(trim($stdout), 0, 200);
            throw $this->harnessFailure(
                "Claude stdout was not a JSON envelope: {$e->getMessage()}"
                    . ($snippet === '' ? '' : "; stdout: {$snippet}"),
                $exit,
                $stderr,
            );
        }
        if (!is_array($envelope)) {
            throw $this->harnessFailure('Claude JSON envelope was not an object', $exit, $stderr);
        }
        if (($envelope['is_error'] ?? false) === true
            || ($envelope['terminal_reason'] ?? null) === 'api_error'
        ) {
            $reason = (string) ($envelope['terminal_reason'] ?? $envelope['subtype'] ?? 'unknown');
            throw $this->harnessFailure(
                "Claude returned is_error:true (terminal reason: {$reason})",
                $exit,
                $stderr,
            );
        }
        if (!array_key_exists('result', $envelope) || !is_string($envelope['result'])) {
            throw $this->harnessFailure('Claude JSON envelope has no string result', $exit, $stderr);
        }

        return [
            'text' => $envelope['result'],
            'stop_reason' => is_string($envelope['stop_reason'] ?? null)
                ? $envelope['stop_reason']
                : null,
            'usage' => is_array($envelope['usage'] ?? null) ? $envelope['usage'] : [],
        ];
    }
}
