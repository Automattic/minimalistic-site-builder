<?php
declare(strict_types=1);

/**
 * Shared per-step LLM option wiring. Every LLM step takes optional model and
 * temperature overrides in its constructor; this attaches them to a payload —
 * an LLM opts array or a batch request — only when configured, so a step left
 * unset falls back to the client's defaults. Replaces the identical
 * llmOpts()/req() helpers that were copied across the LLM steps.
 */
trait ModelOption
{
    /**
     * Return $payload with configured LLM options merged in, untouched when none
     * are set. Works for both a complete()/completeJson() opts array and a
     * completeBatch() request (both just carry optional scalar metadata).
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function withModel(array $payload = []): array
    {
        if ($this->model !== null) {
            $payload['model'] = $this->model;
        }
        if (property_exists($this, 'temperature') && $this->temperature !== null) {
            $payload['temperature'] = $this->temperature;
        }
        return $payload;
    }
}
