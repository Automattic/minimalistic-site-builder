<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Shared transport contract for coding-agent CLI harnesses.
 *
 * Subclasses own only provider-specific argv construction and response parsing.
 * Prompt assembly, validation, pooling, usage, and degradation live here.
 */
abstract class HarnessCliLlm implements Llm, UsageReporting
{
    public const DEFAULT_CONCURRENCY = 10;

    /** Child environment is replacement, not inherited; provider keys never enter it. */
    private const ENV_ALLOWLIST = [
        'PATH',
        'HOME',
        'USER',
        'LOGNAME',
        'SHELL',
        'TERM',
        'LANG',
        'LC_ALL',
        'TMPDIR',
    ];

    /** @var array<string,true> process-wide narration suppression */
    private static array $disclosedUnsupportedOptions = [];

    private int $requests = 0;
    private int $inputTokens = 0;
    private int $outputTokens = 0;
    private int $cacheCreationInputTokens = 0;
    private int $cacheReadInputTokens = 0;

    public function __construct(
        protected readonly string $binary,
        protected readonly string $model,
        protected readonly int $cap = self::DEFAULT_CONCURRENCY,
        protected readonly int $timeoutSeconds = 300,
    ) {
        if (trim($this->binary) === '') {
            throw new \InvalidArgumentException('Harness binary must not be blank.');
        }
        if (trim($this->model) === '') {
            throw new \InvalidArgumentException('Harness model must not be blank.');
        }
        if ($this->cap < 1) {
            throw new \InvalidArgumentException('Harness concurrency cap must be at least one.');
        }
    }

    /**
     * @param array{
     *     prompt:string,
     *     model:string,
     *     request:array<string,mixed>,
     *     degradation_notes:list<string>
     * } $prepared
     * @return array{argv:list<string>,stdin?:string}
     */
    abstract protected function jobFor(array $prepared, string $scratchDir): array;

    /**
     * @return array{
     *     text:string,
     *     stop_reason:?string,
     *     input?:int,
     *     output?:int,
     *     cache_creation_input_tokens?:int,
     *     cache_read_input_tokens?:int,
     *     usage?:array<string,mixed>
     * }
     */
    abstract protected function parseResponse(
        string $stdout,
        string $stderr,
        int $exit,
        string $scratchDir,
    ): array;

    final public function usageTotals(): array
    {
        return [
            'requests' => $this->requests,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens' => $this->inputTokens + $this->outputTokens,
            'cache_creation_input_tokens' => $this->cacheCreationInputTokens,
            'cache_read_input_tokens' => $this->cacheReadInputTokens,
        ];
    }

    public function complete(string $prompt, array $opts = []): string
    {
        $responses = $this->responseBatch(['request' => ['prompt' => $prompt] + $opts]);
        return $responses['request']['text'];
    }

    public function completeJson(string $prompt, array $opts = []): array
    {
        $responses = $this->completeJsonBatch(['request' => ['prompt' => $prompt] + $opts]);
        return $responses['request'];
    }

    public function completeJsonBatch(array $requests): array
    {
        return JsonBatchRecovery::run(
            $requests,
            fn (array $subset): array => $this->responseBatch($subset),
        );
    }

    public function completeBatch(array $requests): TextBatchResult
    {
        /** @var array<array-key,list<string>> $notes */
        $notes = [];
        $result = TextBatchRecovery::run(
            $requests,
            function (array $subset) use (&$notes): array {
                $responses = $this->responseBatch($subset, isolateFailures: true);
                $usableResponses = 0;
                $firstFailure = null;
                foreach ($responses as $key => $response) {
                    $failure = $response['transport_failure'] ?? null;
                    if ($failure instanceof HarnessCallFailed) {
                        if ($firstFailure === null) {
                            $firstFailure = $failure;
                        }
                    } else {
                        $usableResponses++;
                    }
                    unset($responses[$key]['transport_failure']);
                    foreach ($response['degradation_notes'] as $note) {
                        $notes[$key][] = $note;
                    }
                }
                if ($responses !== [] && $usableResponses === 0 && $firstFailure !== null) {
                    throw $firstFailure;
                }
                return $responses;
            },
            maxRetries: 0,
        );

        foreach ($result->notes as $key => $messages) {
            foreach ($messages as $message) {
                $notes[$key][] = $message;
            }
        }
        return new TextBatchResult($result->texts, $notes);
    }

    /**
     * Build every job before spawning any member, then preserve input key order.
     *
     * @param array<array-key,array<string,mixed>> $requests
     * @return array<array-key,array<string,mixed>>
     */
    private function responseBatch(array $requests, bool $isolateFailures = false): array
    {
        if ($requests === []) {
            return [];
        }

        $prepared = [];
        $jobs = [];
        $scratchDirs = [];
        try {
            foreach ($requests as $key => $request) {
                $prepared[$key] = $this->prepareRequest($request);
                $scratchDirs[$key] = $this->createScratchDir();
                $jobs[$key] = $this->jobFor($prepared[$key], $scratchDirs[$key]);
                $jobs[$key]['env'] = $this->childEnv();
            }

            $raw = ProcessPool::run($jobs, $this->cap, $this->timeoutSeconds);
            $out = [];
            foreach ($raw as $key => $result) {
                try {
                    $out[$key] = $this->interpret($result, $prepared[$key], $scratchDirs[$key]);
                } catch (HarnessCallFailed $e) {
                    if (!$isolateFailures) {
                        throw $e;
                    }
                    $out[$key] = [
                        'text' => '',
                        'stop_reason' => null,
                        'input' => 0,
                        'output' => 0,
                        'cache_creation_input_tokens' => 0,
                        'cache_read_input_tokens' => 0,
                        'model' => $prepared[$key]['model'],
                        'transport_failure' => $e,
                        'degradation_notes' => array_merge(
                            $prepared[$key]['degradation_notes'],
                            [$e->getMessage()],
                        ),
                    ];
                }
            }
            return $out;
        } finally {
            $cleanupFailure = null;
            foreach (array_reverse($scratchDirs, true) as $scratchDir) {
                try {
                    $this->removeScratchPath($scratchDir);
                } catch (\Throwable $e) {
                    $cleanupFailure ??= $e;
                }
            }
            if ($cleanupFailure !== null) {
                throw $cleanupFailure;
            }
        }
    }

    /**
     * @param array<string,mixed> $request
     * @return array{prompt:string,model:string,request:array<string,mixed>,degradation_notes:list<string>}
     */
    private function prepareRequest(array $request): array
    {
        if (!array_key_exists('prompt', $request) || !is_string($request['prompt'])) {
            throw new LlmRequestRejected('Harness CLI request prompt must be a string');
        }

        $layers = CachedPrefixes::normalize(
            array_key_exists('cached_prefixes', $request) ? $request['cached_prefixes'] : [],
            'Harness CLI requests',
        );
        $model = $request['model'] ?? $this->model;
        if (!is_string($model) || trim($model) === '') {
            throw new LlmRequestRejected('Harness CLI request model must be a non-blank string');
        }
        if (array_key_exists('system', $request) && !is_string($request['system'])) {
            throw new LlmRequestRejected('Harness CLI request system must be a string');
        }
        if (array_key_exists('max_tokens', $request)
            && (!is_int($request['max_tokens']) || $request['max_tokens'] < 1)
        ) {
            throw new LlmRequestRejected('Harness CLI request max_tokens must be a positive integer');
        }
        if (array_key_exists('temperature', $request)
            && !is_int($request['temperature'])
            && !is_float($request['temperature'])
        ) {
            throw new LlmRequestRejected('Harness CLI request temperature must be numeric');
        }
        if (array_key_exists('tolerate_empty', $request) && !is_bool($request['tolerate_empty'])) {
            throw new LlmRequestRejected('Harness CLI request tolerate_empty must be boolean');
        }
        if (array_key_exists('json_schema', $request)) {
            $schema = $request['json_schema'];
            if (!is_array($schema)
                || !is_string($schema['name'] ?? null)
                || trim($schema['name']) === ''
                || !is_array($schema['schema'] ?? null)
            ) {
                throw new LlmRequestRejected(
                    'Harness CLI request json_schema must contain a non-blank name and schema array'
                );
            }
        }

        return [
            'prompt' => implode('', $layers) . $request['prompt'],
            'model' => $model,
            'request' => $request,
            'degradation_notes' => $this->unsupportedOptionNotes($request),
        ];
    }

    /** @param array<string,mixed> $request @return list<string> */
    private function unsupportedOptionNotes(array $request): array
    {
        $notes = [];
        $options = ['temperature', 'max_tokens'];
        if (!$this->honorsSystemOption()
            && is_string($request['system'] ?? null)
            && trim($request['system']) !== ''
        ) {
            $options[] = 'system';
        }
        foreach ($options as $option) {
            if (!array_key_exists($option, $request)) {
                continue;
            }
            $note = "Harness CLI cannot honor option '{$option}'; transport default was used.";
            $notes[] = $note;
            if (!isset(self::$disclosedUnsupportedOptions[$option])) {
                Narrator::write("    ({$note})\n");
                self::$disclosedUnsupportedOptions[$option] = true;
            }
        }
        return $notes;
    }

    /** Whether this transport has a first-class, non-leaking system channel. */
    protected function honorsSystemOption(): bool
    {
        return false;
    }

    /** @return array<string,string> */
    private function childEnv(): array
    {
        $env = [];
        foreach (self::ENV_ALLOWLIST as $name) {
            $value = getenv($name);
            if ($value !== false && $value !== '') {
                $env[$name] = $value;
            }
        }
        return $env;
    }

    /**
     * @param array{exit:int,stdout:string,stderr:string,timedOut:bool,truncated:bool} $result
     * @param array{prompt:string,model:string,request:array<string,mixed>,degradation_notes:list<string>} $prepared
     * @return array<string,mixed>
     */
    private function interpret(array $result, array $prepared, string $scratchDir): array
    {
        if ($result['timedOut']) {
            throw $this->harnessFailure(
                "timed out after {$this->timeoutSeconds} seconds",
                $result['exit'],
                $result['stderr'],
            );
        }
        if ($result['truncated']) {
            throw $this->harnessFailure(
                'captured stdout or stderr exceeded the ProcessPool limit',
                $result['exit'],
                $result['stderr'],
            );
        }
        if ($result['exit'] !== 0) {
            throw $this->harnessFailure(
                'subprocess exited non-zero',
                $result['exit'],
                $result['stderr'],
                $result['stdout'],
            );
        }

        try {
            $parsed = $this->parseResponse(
                $result['stdout'],
                $result['stderr'],
                $result['exit'],
                $scratchDir,
            );
        } catch (HarnessCallFailed $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw $this->harnessFailure(
                'response parsing failed: ' . $e->getMessage(),
                $result['exit'],
                $result['stderr'],
            );
        }

        $normalized = $this->normalizeResponse($parsed);
        $this->recordUsage($normalized);
        if (trim($normalized['text']) === '' && ($prepared['request']['tolerate_empty'] ?? false) !== true) {
            throw $this->harnessFailure('subprocess returned an empty response', $result['exit'], $result['stderr']);
        }

        $normalized['model'] = $prepared['model'];
        $normalized['degradation_notes'] = $prepared['degradation_notes'];
        return $normalized;
    }

    /** @param array<string,mixed> $parsed @return array<string,mixed> */
    private function normalizeResponse(array $parsed): array
    {
        if (!array_key_exists('text', $parsed) || !is_string($parsed['text'])) {
            throw new \UnexpectedValueException('parsed harness response has no string text field');
        }
        $usage = is_array($parsed['usage'] ?? null) ? $parsed['usage'] : [];
        $cacheCreation = (int) (
            $parsed['cache_creation_input_tokens']
            ?? $usage['cache_creation_input_tokens']
            ?? 0
        );
        $cacheRead = (int) (
            $parsed['cache_read_input_tokens']
            ?? $usage['cache_read_input_tokens']
            ?? 0
        );
        $input = array_key_exists('input', $parsed)
            ? (int) $parsed['input']
            : (int) ($usage['input_tokens'] ?? 0) + $cacheCreation + $cacheRead;

        return [
            'text' => $parsed['text'],
            'stop_reason' => is_string($parsed['stop_reason'] ?? null)
                && trim($parsed['stop_reason']) !== ''
                ? trim($parsed['stop_reason'])
                : null,
            'input' => $input,
            'output' => (int) ($parsed['output'] ?? $usage['output_tokens'] ?? 0),
            'cache_creation_input_tokens' => $cacheCreation,
            'cache_read_input_tokens' => $cacheRead,
        ];
    }

    /** @param array<string,mixed> $response */
    private function recordUsage(array $response): void
    {
        $this->requests++;
        $this->inputTokens += (int) $response['input'];
        $this->outputTokens += (int) $response['output'];
        $this->cacheCreationInputTokens += (int) $response['cache_creation_input_tokens'];
        $this->cacheReadInputTokens += (int) $response['cache_read_input_tokens'];
    }

    final protected function harnessFailure(
        string $reason,
        int $exit,
        string $stderr,
        string $stdout = '',
    ): HarnessCallFailed
    {
        $diagnostic = trim($stderr);
        $channel = 'stderr';
        if ($diagnostic === '' && trim($stdout) !== '') {
            $diagnostic = trim($stdout);
            $channel = 'stdout';
        }
        return new HarnessCallFailed(
            "Harness '{$this->binary}' failed with exit {$exit}: {$reason}; {$channel}: "
                . ($diagnostic === '' ? '(empty)' : $diagnostic)
        );
    }

    private function createScratchDir(): string
    {
        $base = getenv('TMPDIR');
        if (!is_string($base) || trim($base) === '') {
            $base = sys_get_temp_dir();
        }
        $base = rtrim($base, DIRECTORY_SEPARATOR);
        if ($base === '' || !is_dir($base) || !is_writable($base)) {
            throw new \RuntimeException("Harness scratch root is not a writable directory: {$base}");
        }

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $path = $base . DIRECTORY_SEPARATOR . 'site-build-harness-' . bin2hex(random_bytes(16));
            if (@mkdir($path, 0700)) {
                return $path;
            }
        }
        throw new \RuntimeException("Could not create a unique harness scratch directory under {$base}");
    }

    private function removeScratchPath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (!@unlink($path)) {
                throw new \RuntimeException("Could not remove harness scratch file: {$path}");
            }
            return;
        }
        if (!file_exists($path)) {
            return;
        }
        if (!is_dir($path)) {
            throw new \RuntimeException("Harness scratch path has an unsupported type: {$path}");
        }
        $entries = scandir($path);
        if ($entries === false) {
            throw new \RuntimeException("Could not read harness scratch directory: {$path}");
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeScratchPath($path . DIRECTORY_SEPARATOR . $entry);
        }
        if (!@rmdir($path)) {
            throw new \RuntimeException("Could not remove harness scratch directory: {$path}");
        }
    }
}
