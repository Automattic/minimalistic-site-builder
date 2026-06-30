<?php
declare(strict_types=1);

/**
 * Shared per-step model wiring. Every LLM step takes an optional model override
 * in its constructor (a `private ?string $model`); this attaches it to a payload
 * — an LLM opts array or a batch request — only when configured, so a step left
 * unset falls back to the client's default model. Replaces the identical
 * llmOpts()/req() helpers that were copied across the LLM steps.
 */
trait ModelOption
{
    /**
     * Return $payload with the configured model merged in, untouched when none
     * is set. Works for both a complete()/completeJson() opts array and a
     * completeBatch() request (both just carry an optional "model" key).
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function withModel(array $payload = []): array
    {
        if ($this->model !== null) {
            $payload['model'] = $this->model;
        }
        return $payload;
    }
}
