<?php
declare(strict_types=1);

/**
 * Transport-agnostic LLM interface. Implementations call a single prompt and
 * return the model's text. Keeping this an interface lets steps depend on the
 * contract while tests inject a fake and production injects the real transport
 * (today Anthropic-direct; a wpcom-proxy transport could be swapped in later).
 */
interface Llm
{
    /**
     * Send one prompt, return the assistant's text.
     *
     * @param array{system?:string,model?:string,max_tokens?:int,temperature?:float} $opts
     */
    public function complete(string $prompt, array $opts = []): string;

    /**
     * Send one prompt that must return a JSON value, decode and return it.
     * Tolerates ```json fenced blocks.
     *
     * @param array{system?:string,model?:string,max_tokens?:int,temperature?:float} $opts
     * @return array<mixed>
     */
    public function completeJson(string $prompt, array $opts = []): array;

    /**
     * Send several JSON prompts CONCURRENTLY and return their decoded values,
     * keyed by the same keys as the input. Each request carries its own prompt
     * and (optionally) model/max_tokens/system, so a batch may mix models. This
     * is how the pipeline parallelises independent LLM work (theme.json beside
     * the section plan; every landing-page section at once).
     *
     * @param array<string,array{prompt:string,system?:string,model?:string,max_tokens?:int,temperature?:float}> $requests
     * @return array<string,array<mixed>> decoded JSON keyed as the input
     */
    public function completeJsonBatch(array $requests): array;

    /**
     * Send several prompts CONCURRENTLY and return their raw text, keyed by the
     * same keys as the input. Unlike completeJsonBatch this does NOT ask for or
     * decode JSON — it is for prompts whose answer IS the payload (e.g. block
     * markup), so the model returns it verbatim instead of escaping it inside a
     * JSON string (which is brittle and wastes tokens).
     *
     * @param array<string,array{prompt:string,system?:string,model?:string,max_tokens?:int,temperature?:float}> $requests
     * @return array<string,string> raw assistant text keyed as the input
     */
    public function completeBatch(array $requests): array;
}
