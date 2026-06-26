<?php
declare(strict_types=1);

/**
 * Transport-agnostic image-generation interface. Implementations turn a text
 * prompt into raw image bytes. Mirrors the Llm interface: steps depend on the
 * contract, tests inject a fake, production injects the real proxy transport.
 */
interface ImageClient
{
    /**
     * Generate one image from a prompt; return the raw image bytes (JPEG).
     *
     * @param array{aspect_ratio?:string} $opts aspect_ratio is one of the
     *        Imagen-supported ratios: "1:1", "16:9", "9:16", "4:3", "3:4".
     */
    public function generate(string $prompt, array $opts = []): string;
}
