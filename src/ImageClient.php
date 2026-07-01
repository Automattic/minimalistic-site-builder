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

    /**
     * The model identifier this client generates with (e.g.
     * "imagen-4.0-generate-001"). Used for request logging.
     */
    public function model(): string;

    /**
     * Generate several images concurrently. Implementations should issue the
     * requests together (not one-at-a-time) and tolerate partial failure: one
     * image failing must not abort the others.
     *
     * @param array<int,array{prompt:string,aspect_ratio?:string}> $specs
     * @return array<int,array{ok:bool,bytes?:string,error?:string}> keyed by the
     *         same index as $specs (one result per spec, order preserved)
     */
    public function generateBatch(array $specs): array;
}
