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
     * @param array{system?:string,model?:string,max_tokens?:int} $opts
     */
    public function complete(string $prompt, array $opts = []): string;

    /**
     * Send one prompt that must return a JSON value, decode and return it.
     * Tolerates ```json fenced blocks.
     *
     * @param array{system?:string,model?:string,max_tokens?:int} $opts
     * @return array<mixed>
     */
    public function completeJson(string $prompt, array $opts = []): array;
}
