<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * One Llm that dispatches each request to a different transport by model id.
 *
 * The pipeline already hands every step its own model (StepComposition passes
 * $models['<step>'] into the step, which LlmOptions attaches to the request),
 * so the model id is the only routing key needed: no step has to know that more
 * than one provider is in play. That is what lets an LLM_MODEL_<STEP> override
 * move one step to another provider without touching a single step.
 *
 * A request naming no model at all is given $defaultModel before it is routed.
 * That is not a corner case — design-preview and transform-site are constructed
 * with a null model unless the active provider pins one, and LlmOptions omits
 * the key entirely rather than sending null. Naming the model here rather than
 * leaving it to whichever client happens to be picked is what keeps
 * `LLM_MODEL=<a model from another provider>` honest: those steps follow it
 * instead of asking the default transport for a model it has never heard of.
 *
 * Batches are split by transport and each group is sent whole, so a batch whose
 * members all share one model — every batch the pipeline actually issues, since
 * a step has one model — is passed straight through with its concurrency
 * intact. Only a genuinely mixed batch is serialized across transports, and it
 * still comes back keyed and ordered exactly as it went in.
 */
final class RoutingLlm implements FinishReasonAwareLlm, UsageReporting
{
    /** Transport that most recently served a single completion. */
    private ?Llm $lastUsed = null;

    /**
     * @param array<string,Llm>    $transports     transport name => client
     * @param array<string,string> $modelTransport lowercased model id => transport name
     * @param string               $default        transport for a request that ends up with no model at all
     * @param ?string              $defaultModel   model given to a request that names none
     */
    public function __construct(
        private array $transports,
        private array $modelTransport = [],
        private string $default = '',
        private ?string $defaultModel = null,
    ) {
        if ($this->transports === []) {
            throw new \InvalidArgumentException('RoutingLlm needs at least one transport.');
        }
        if ($this->default === '') {
            $this->default = array_key_first($this->transports);
        }
        $named = array_values($this->modelTransport);
        $named[] = $this->default;
        foreach ($named as $name) {
            if (!isset($this->transports[$name])) {
                throw new \InvalidArgumentException(
                    "RoutingLlm has no transport named '{$name}'. Known: " . implode(', ', array_keys($this->transports))
                );
            }
        }
    }

    /**
     * Transport name that would serve $model (null = a request naming none).
     *
     * Applies $defaultModel to a nameless request itself, so this answers with
     * the transport that would ACTUALLY serve it rather than a nominal default
     * the request never reaches.
     */
    public function transportFor(?string $model): string
    {
        $key = strtolower(trim((string) $model));
        if ($key === '' && $this->defaultModel !== null) {
            $key = strtolower(trim($this->defaultModel));
        }
        if ($key === '') {
            return $this->default;
        }
        if (isset($this->modelTransport[$key])) {
            return $this->modelTransport[$key];
        }
        // Safety net for the env overrides, which accept any model id: only an
        // Anthropic transport can serve an Anthropic model, so sending
        // LLM_MODEL_SECTIONS=claude-haiku-4-5 to the default would just 404.
        // Every other id is the default's to accept or reject on its own.
        if (str_starts_with($key, 'claude-') && isset($this->transports['anthropic'])) {
            return 'anthropic';
        }
        return $this->default;
    }

    /**
     * Name the model on a request that left it out, so the transport receives
     * the same model this router used to choose it.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    private function withDefaultModel(array $request): array
    {
        if ($this->defaultModel !== null && trim((string) ($request['model'] ?? '')) === '') {
            $request['model'] = $this->defaultModel;
        }
        return $request;
    }

    /** @param array<string,mixed> $opts */
    private function llmFor(array $opts): Llm
    {
        return $this->transports[$this->transportFor($opts['model'] ?? null)];
    }

    public function complete(string $prompt, array $opts = []): string
    {
        $opts = $this->withDefaultModel($opts);
        $llm = $this->llmFor($opts);
        $this->lastUsed = $llm;
        return $llm->complete($prompt, $opts);
    }

    /** @return array<mixed> */
    public function completeJson(string $prompt, array $opts = []): array
    {
        $opts = $this->withDefaultModel($opts);
        $llm = $this->llmFor($opts);
        $this->lastUsed = $llm;
        return $llm->completeJson($prompt, $opts);
    }

    /**
     * @param array<array-key,array<string,mixed>> $requests
     * @return array<array-key,array<mixed>>
     */
    public function completeJsonBatch(array $requests): array
    {
        $merged = [];
        foreach ($this->groupByTransport($requests) as $name => $group) {
            foreach ($this->transports[$name]->completeJsonBatch($group) as $key => $value) {
                $merged[$key] = $value;
            }
        }
        return $this->reorderLike($requests, $merged, 'completeJsonBatch');
    }

    /** @param array<array-key,array<string,mixed>> $requests */
    public function completeBatch(array $requests): TextBatchResult
    {
        $texts = [];
        $notes = [];
        foreach ($this->groupByTransport($requests) as $name => $group) {
            $result = $this->transports[$name]->completeBatch($group);
            foreach ($result->texts as $key => $text) {
                $texts[$key] = $text;
            }
            foreach ($result->notes as $key => $messages) {
                $notes[$key] = $messages;
            }
        }
        $texts = $this->reorderLike($requests, $texts, 'completeBatch');
        // Notes are keyed by result, so they follow the texts rather than the
        // requests; a member that earned none simply has no entry.
        $ordered = [];
        foreach ($texts as $key => $_) {
            if (isset($notes[$key])) {
                $ordered[$key] = $notes[$key];
            }
        }
        return new TextBatchResult($texts, $ordered);
    }

    /**
     * Split requests into per-transport batches, each keeping its original keys.
     *
     * @param array<array-key,array<string,mixed>> $requests
     * @return array<string,array<array-key,array<string,mixed>>>
     */
    private function groupByTransport(array $requests): array
    {
        $groups = [];
        foreach ($requests as $key => $request) {
            $request = is_array($request) ? $this->withDefaultModel($request) : $request;
            $name = $this->transportFor(is_array($request) ? ($request['model'] ?? null) : null);
            $groups[$name][$key] = $request;
        }
        return $groups;
    }

    /**
     * Put a merged result back into request order, and refuse to return one
     * that lost or invented a member.
     *
     * A dropped key here would be a routing bug silently costing the build a
     * whole page or section, which is exactly the failure the rest of this
     * codebase spends its effort making impossible to ship quietly.
     *
     * @param array<array-key,mixed> $requests
     * @param array<array-key,mixed> $merged
     * @return array<array-key,mixed>
     */
    private function reorderLike(array $requests, array $merged, string $method): array
    {
        $ordered = [];
        foreach ($requests as $key => $_) {
            if (!array_key_exists($key, $merged)) {
                throw new \RuntimeException(
                    "RoutingLlm::{$method} lost request '{$key}' while splitting across transports."
                );
            }
            $ordered[$key] = $merged[$key];
        }
        if (count($merged) !== count($ordered)) {
            throw new \RuntimeException(
                "RoutingLlm::{$method} returned keys that were never requested."
            );
        }
        return $ordered;
    }

    public function lastFinishReason(): ?string
    {
        return $this->lastUsed instanceof FinishReasonAwareLlm
            ? $this->lastUsed->lastFinishReason()
            : null;
    }

    /**
     * Usage summed across every transport that reports it.
     *
     * The optional cache fields are only present when at least one transport
     * supplied them, so a total of zero is never confused with "not reported".
     *
     * @return array{requests:int,input_tokens:int,output_tokens:int,total_tokens:int,cache_read_input_tokens?:int,cache_creation_input_tokens?:int}
     */
    public function usageTotals(): array
    {
        $totals = ['requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0];
        $cache = [];
        foreach ($this->transports as $llm) {
            if (!$llm instanceof UsageReporting) {
                continue;
            }
            $one = $llm->usageTotals();
            foreach ($totals as $field => $value) {
                $totals[$field] = $value + (int) ($one[$field] ?? 0);
            }
            foreach (['cache_read_input_tokens', 'cache_creation_input_tokens'] as $field) {
                if (array_key_exists($field, $one)) {
                    $cache[$field] = ($cache[$field] ?? 0) + (int) $one[$field];
                }
            }
        }
        return $totals + $cache;
    }

    /** Transport names in routing order (for tests / diagnostics). @return list<string> */
    public function transportNames(): array
    {
        return array_keys($this->transports);
    }
}
