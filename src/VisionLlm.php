<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * An Llm that can also look at one image. The Llm contract is text-only on
 * purpose: every transport can carry a prompt, but only some can carry
 * pixels. A step that needs to inspect a delivered image asks for this
 * capability with `instanceof` and skips the inspection when the transport
 * lacks it, so a text-only host never fails a build over a check it cannot
 * run.
 */
interface VisionLlm extends Llm
{
    /**
     * Send one prompt together with one image and return the assistant's text.
     *
     * @param string $imageBytes raw encoded image bytes (not base64)
     * @param string $mime       the image MIME type, e.g. image/jpeg
     * @param array{system?:string,model?:string,max_tokens?:int,temperature?:float,json_schema?:array{name:string,schema:array<string,mixed>},log_label?:string} $opts
     *        cached_prefixes are not accepted here; an implementation must
     *        raise LlmRequestRejected for one rather than drop it.
     */
    public function completeWithImage(string $prompt, string $imageBytes, string $mime, array $opts = []): string;
}
