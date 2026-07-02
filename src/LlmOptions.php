<?php
declare(strict_types=1);

/**
 * Shared per-step LLM option wiring. Every LLM step takes an optional model
 * override and an optional sampling temperature in its constructor (a
 * `private ?string $model` and `private ?float $temperature`); this attaches
 * them to a payload — an LLM opts array or a batch request — only when
 * configured, so a step left unset falls back to the client's default model
 * and the API's default sampling. Replaces the identical llmOpts()/req()
 * helpers that were copied across the LLM steps.
 */
trait LlmOptions
{
    /**
     * Return $payload with the configured model and temperature merged in,
     * untouched when neither is set. Works for both a complete()/completeJson()
     * opts array and a completeBatch() request (both just carry the optional
     * "model"/"temperature" keys).
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function withOptions(array $payload = []): array
    {
        if ($this->model !== null) {
            $payload['model'] = $this->model;
        }
        if ($this->temperature !== null) {
            $payload['temperature'] = $this->temperature;
        }
        return $payload;
    }
}
