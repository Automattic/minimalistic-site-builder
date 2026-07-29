<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Transport-agnostic image-generation interface. Implementations turn a text
 * prompt into raw image bytes. Mirrors the Llm interface: steps depend on the
 * contract, tests inject a fake, production injects the real proxy transport.
 */
interface ImageClient
{
    /**
     * Generate one image from a prompt; return the raw image bytes (JPEG by
     * default, PNG when `mime` asks for it).
     *
     * @param array{aspect_ratio?:string,sample_image_size?:?string,mime?:?string} $opts
     *        aspect_ratio is one of the Imagen-supported ratios: "1:1", "16:9",
     *        "9:16", "4:3", "3:4"; sample_image_size is "1K" (default) or "2K";
     *        mime is "image/jpeg" (default) or "image/png" (for assets that
     *        need a transparent background — JPEG has no alpha channel).
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
     * The optional $onResult fires once per spec index when that image's
     * result is FINAL (success, or failure with retries exhausted) — never for
     * an intermediate attempt — so callers can persist progress while the rest
     * of the batch is still generating.
     *
     * @param array<int,array{prompt:string,aspect_ratio?:string,sample_image_size?:?string,mime?:?string}> $specs
     * @param callable(int,array{ok:bool,bytes?:string,error?:string,filtered?:bool}):void|null $onResult
     * @return array<int,array{ok:bool,bytes?:string,error?:string,filtered?:bool}>
     *         keyed by the same index as $specs (one result per spec, order
     *         preserved); `filtered` marks a failure caused by the endpoint's
     *         safety filter rejecting the prompt — repairable by rewording it,
     *         unlike a transport failure
     */
    public function generateBatch(array $specs, ?callable $onResult = null): array;
}
