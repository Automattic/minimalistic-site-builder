<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\TreeGraph;

use Automattic\SiteBuild\GeneratedJsonException;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\StepDefaults;

/**
 * The tree graph's one metered generative lane (port of x-pipeline's
 * lib/llm.mjs): budget check -> render -> completeJson -> contract
 * validation -> at most ONE metered retry carrying the exact failure list ->
 * a ledger entry per attempt. No free-text output exists anywhere in this
 * graph; every call returns JSON judged by a validate() gate.
 *
 * The meter and ledger are file-backed (budget.json + logs/tree-ledger.jsonl)
 * so a --from resume continues the SAME bill instead of restarting at zero.
 */
final class TreeLlm
{
    /**
     * @param array<string,string> $models       task type => model id
     * @param array<string,?float> $temperatures task type => temperature
     */
    public function __construct(
        private readonly Llm $llm,
        private readonly string $promptsDir,
        private readonly BudgetMeter $budget,
        private readonly Ledger $ledger,
        private readonly array $models = [],
        private readonly array $temperatures = [],
    ) {}

    /**
     * A lane wired to this project's recorded budget and ledger: the ceiling
     * comes from budget.json when the brief has fixed it, and the meter is
     * rehydrated from the ledger so resumes keep counting where they stopped.
     *
     * @param array<string,string> $models       task type => model id
     * @param array<string,?float> $temperatures task type => temperature
     */
    public static function forProject(
        Llm $llm,
        Project $project,
        array $models = [],
        array $temperatures = [],
    ): self {
        $meter = new BudgetMeter();
        $ledger = new Ledger($project);
        $meter->rehydrate($ledger->count());
        if ($project->exists('budget.json')) {
            $ceiling = (int) ($project->readJson('budget.json')['ceiling'] ?? 0);
            if ($ceiling > 0) {
                $meter->setCeiling($ceiling);
            }
        }
        $promptsDir = \repo_path('prompts/tree');
        return new self($llm, $promptsDir, $meter, $ledger, $models, $temperatures);
    }

    public function budget(): BudgetMeter
    {
        return $this->budget;
    }

    public function ledger(): Ledger
    {
        return $this->ledger;
    }

    /**
     * One generative task: render, call, validate, retry once with the
     * failure list, throw contract_failed on exhaustion.
     *
     * @param array<string,mixed> $payload
     * @param callable(array<mixed>):array $validate returns [{path, message}]
     * @return array<mixed> the validated JSON value
     */
    public function generate(
        string $taskType,
        string $label,
        array $payload,
        callable $validate,
        ?string $template = null,
        int $maxAttempts = 2,
    ): array {
        $basePrompt = $this->render($template ?? $taskType, $payload);
        $prompt = $basePrompt;
        $lastIssues = [];

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $outcome = $this->attempt($taskType, $label, $prompt, $payload, $validate, $attempt);
            if ($outcome['ok']) {
                return $outcome['value'];
            }
            $lastIssues = $outcome['issues'];
            $prompt = self::retryPrompt($basePrompt, $lastIssues);
        }

        throw new TreeGraphException(
            'contract_failed',
            "task {$taskType}:{$label} failed its contract after {$maxAttempts} attempt(s)",
            'The artifact is dead unless the repair stage saves it; diagnostics are attached.',
            ['task_type' => $taskType, 'label' => $label, 'issues' => $lastIssues],
        );
    }

    /**
     * The concurrent lane: attempt 1 for every item through one
     * completeJsonBatch (a member whose JSON stays unusable after the
     * transport's own repair is an attempt-1 contract failure, not a fatal),
     * then one metered retry per failing item through the single lane.
     * Contract exhaustion here is a RESULT ({value: null, issues}) — the
     * section step turns it into a failed gate for the repair stage — never a
     * thrown error, so one bad section cannot kill its siblings.
     *
     * @param array<string,array{task_type: string, label: string, payload: array<string,mixed>, validate: callable, template?: ?string}> $items
     * @return array<string,array{value: ?array, issues: array, attempts: int}>
     */
    public function generateBatch(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $requests = [];
        $prompts = [];
        $started = self::now();
        foreach ($items as $key => $item) {
            $prompt = $this->render($item['template'] ?? $item['task_type'], $item['payload']);
            $prompts[$key] = $prompt;
            $this->budget->spend($item['task_type'], $item['label']);
            $request = ['prompt' => $prompt];
            $model = $this->models[$item['task_type']] ?? null;
            if ($model !== null) {
                $request['model'] = $model;
            }
            $temperature = $this->temperatures[$item['task_type']] ?? null;
            if ($temperature !== null) {
                $request['temperature'] = $temperature;
            }
            $requests[$key] = $request;
        }
        Narrator::write('  [tree-llm] ' . count($requests) . ' concurrent call(s), '
            . "budget {$this->budget->spent()}" . ($this->budget->ceiling() !== null ? "/{$this->budget->ceiling()}" : '') . "\n");

        $decoded = [];
        $jsonFailures = [];
        try {
            $decoded = $this->llm->completeJsonBatch($requests);
        } catch (GeneratedJsonException $e) {
            $decoded = $e->partialResults;
            $jsonFailures = $e->failures;
        }
        $ms = self::now() - $started;

        $out = [];
        foreach ($items as $key => $item) {
            $issues = [];
            $value = $decoded[$key] ?? null;
            if ($value === null) {
                $issues = [['path' => '', 'message' => 'output is not valid JSON: ' . (string) ($jsonFailures[$key] ?? 'no result')]];
            } else {
                $issues = ($item['validate'])($value);
            }
            $this->ledger->record($this->entry(
                $item['task_type'],
                $item['label'],
                $prompts[$key],
                $item['payload'],
                1,
                $issues === [] ? 'ok' : ($value === null ? 'invalid_json' : 'schema_failed'),
                $started,
                $ms,
            ));
            if ($issues === []) {
                $out[$key] = ['value' => $value, 'issues' => [], 'attempts' => 1];
                continue;
            }

            // The one metered retry, with the exact failure list appended.
            $retryPrompt = self::retryPrompt($prompts[$key], $issues);
            $this->budget->spend($item['task_type'], $item['label']);
            $retry = $this->attemptPrompt($item['task_type'], $item['label'], $retryPrompt, $item['payload'], $item['validate'], 2);
            $out[$key] = $retry['ok']
                ? ['value' => $retry['value'], 'issues' => [], 'attempts' => 2]
                : ['value' => null, 'issues' => $retry['issues'], 'attempts' => 2];
        }
        return $out;
    }

    /** One spent, ledgered attempt through completeJson. */
    private function attempt(
        string $taskType,
        string $label,
        string $prompt,
        array $payload,
        callable $validate,
        int $attempt,
    ): array {
        $this->budget->spend($taskType, $label);
        $callNo = 'call ' . $this->budget->spent() . ($this->budget->ceiling() !== null ? '/' . $this->budget->ceiling() : '');
        Narrator::write("  [tree-llm] {$callNo} · {$taskType} {$label}" . ($attempt > 1 ? ' (schema retry)' : '') . "\n");
        return $this->attemptPrompt($taskType, $label, $prompt, $payload, $validate, $attempt);
    }

    /** The unmetered core of one attempt (the caller has already spent). */
    private function attemptPrompt(
        string $taskType,
        string $label,
        string $prompt,
        array $payload,
        callable $validate,
        int $attempt,
    ): array {
        $started = self::now();
        $value = null;
        $issues = [];
        $outcome = 'ok';
        try {
            $opts = ['log_label' => "tree-{$taskType}-" . preg_replace('/[^a-z0-9-]+/i', '-', $label)];
            $model = $this->models[$taskType] ?? null;
            if ($model !== null) {
                $opts['model'] = $model;
            }
            $temperature = $this->temperatures[$taskType] ?? null;
            if ($temperature !== null) {
                $opts['temperature'] = $temperature;
            }
            $value = $this->llm->completeJson($prompt, $opts);
        } catch (GeneratedJsonException $e) {
            $outcome = 'invalid_json';
            $issues = [['path' => '', 'message' => 'output is not valid JSON: ' . $e->getMessage()]];
        }
        if ($outcome === 'ok') {
            $issues = $validate($value);
            if ($issues !== []) {
                $outcome = 'schema_failed';
            }
        }
        $this->ledger->record($this->entry($taskType, $label, $prompt, $payload, $attempt, $outcome, $started, self::now() - $started));
        return $outcome === 'ok'
            ? ['ok' => true, 'value' => $value, 'issues' => []]
            : ['ok' => false, 'value' => null, 'issues' => $issues];
    }

    /** @param array<int,array{path: string, message: string}> $issues */
    public static function retryPrompt(string $basePrompt, array $issues): string
    {
        $lines = array_map(
            static fn (array $i): string => (string) ($i['path'] ?? '') . ': ' . (string) ($i['message'] ?? ''),
            $issues,
        );
        return $basePrompt
            . "\n\nCONTRACT FAILURE — your previous output did not satisfy the contract:\n"
            . implode("\n", $lines)
            . "\nReturn ONLY corrected JSON.";
    }

    private function render(string $template, array $payload): string
    {
        return TreePrompts::render(TreePrompts::loadTemplate($this->promptsDir, $template), $payload);
    }

    /** @return array<string,mixed> */
    private function entry(
        string $taskType,
        string $label,
        string $prompt,
        array $payload,
        int $attempt,
        string $outcome,
        int $startedAt,
        int $ms,
    ): array {
        return [
            'task_type'    => $taskType,
            'label'        => $label,
            'provider'     => StepDefaults::provider(),
            'model'        => $this->models[$taskType] ?? '',
            'prompt_hash'  => hash('sha256', $prompt),
            'payload_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            // Per-member usage is not observable through the batch transport;
            // build-stats.json remains the run-level token accounting.
            'usage'        => ['input_tokens' => 0, 'output_tokens' => 0],
            'attempt'      => $attempt,
            'outcome'      => $outcome,
            'started_at'   => $startedAt,
            'ms'           => $ms,
        ];
    }

    private static function now(): int
    {
        return (int) round(microtime(true) * 1000);
    }
}
