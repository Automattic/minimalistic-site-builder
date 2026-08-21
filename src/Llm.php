<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Transport-agnostic LLM interface. Implementations call a single prompt and
 * return the model's text. Keeping this an interface lets steps depend on the
 * contract while tests inject a fake and production injects the real transport
 * (AnthropicClient or OpenAiCompatibleClient via make_llm() / LLM_PROVIDER).
 *
 * Two obligations bind every implementation, not just its callers.
 *
 * REFUSE WHAT YOU CANNOT HONOUR. An option documented below that arrives in a
 * shape this contract does not allow must raise LlmRequestRejected before any
 * transport call — never be coerced, truncated or ignored. The cost of the
 * alternative is on record: a host that accepted `cached_prefixes` and dropped
 * them produced a whole site whose sections never saw the theme, and nothing
 * failed or warned (BIGR-842). Silence is the expensive failure here, so the
 * cap and the shape below are the implementation's to enforce, and
 * CachedPrefixes::normalize() enforces them for both reference clients.
 *
 * REPORT WHAT YOU BILLED. Implementations that also implement UsageReporting
 * must count cached input in `input_tokens` — see that interface, which
 * documents the convention and why providers disagree about it.
 *
 * LlmConformance is the executable form of both, runnable by any host against
 * its own adapter: `php bin/llm-conformance.php`.
 */
interface Llm
{
    /**
     * Send one prompt, return the assistant's text.
     *
     * @param array{system?:string,model?:string,max_tokens?:int,temperature?:float,json_schema?:array{name:string,schema:array<string,mixed>},cached_prefixes?:list<string>,tolerate_empty?:bool} $opts
     *        cached_prefixes are ordered reusable text layers prepended before
     *        the varying prompt; blank layers are ignored and callers may
     *        provide at most three non-blank layers. Anthropic clients mark each
     *        layer as an explicit ephemeral cache breakpoint; OpenAI-compatible
     *        clients join the layers and rely on provider-managed prefix caching.
     *        Cache reuse requires the same model and an identical prefix,
     *        including tools, system content, and all preceding message content,
     *        through the reused boundary. On Anthropic Sonnet, the cumulative
     *        prefix through a breakpoint must meet the model's minimum cacheable size (1,024 tokens on Sonnet-tier; higher on some tiers) or it silently will not cache. For cross-provider byte-equality, callers append their own separator (e.g. a trailing blank line) to each prefix — Anthropic joins blocks verbatim while OpenAI-compatible providers insert "\n\n".
     *        tolerate_empty defaults to false. When true, a successful response
     *        containing only whitespace returns '' without an empty-response
     *        transient retry; this narrow opt is for throwaway cache-warm probes.
     */
    public function complete(string $prompt, array $opts = []): string;

    /**
     * Send one prompt that must return a JSON value, decode and return it.
     * Tolerates ```json fenced blocks.
     *
     * @param array{system?:string,model?:string,max_tokens?:int,temperature?:float,json_schema?:array{name:string,schema:array<string,mixed>},cached_prefixes?:list<string>} $opts
     *        cached_prefixes are ordered reusable text layers prepended before
     *        the varying prompt; blank layers are ignored and callers may
     *        provide at most three non-blank layers. Anthropic clients mark each
     *        layer as an explicit ephemeral cache breakpoint; OpenAI-compatible
     *        clients join the layers and rely on provider-managed prefix caching.
     *        Cache reuse requires the same model and an identical prefix,
     *        including tools, system content, and all preceding message content,
     *        through the reused boundary. On Anthropic Sonnet, the cumulative
     *        prefix through a breakpoint must meet the model's minimum cacheable size (1,024 tokens on Sonnet-tier; higher on some tiers) or it silently will not cache. For cross-provider byte-equality, callers append their own separator (e.g. a trailing blank line) to each prefix — Anthropic joins blocks verbatim while OpenAI-compatible providers insert "\n\n".
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
     * @param array<array-key,array{prompt:string,system?:string,model?:string,max_tokens?:int,temperature?:float,json_schema?:array{name:string,schema:array<string,mixed>},cached_prefixes?:list<string>}> $requests
     *        cached_prefixes are ordered reusable text layers prepended before
     *        the varying prompt; blank layers are ignored and callers may
     *        provide at most three non-blank layers per request. Anthropic
     *        clients mark each layer as an explicit ephemeral cache breakpoint;
     *        OpenAI-compatible clients join the layers and rely on
     *        provider-managed prefix caching. Cache reuse requires the same
     *        model and an identical prefix, including tools, system content, and
     *        all preceding message content, through the reused boundary. On
     *        Anthropic Sonnet, the cumulative prefix through a breakpoint must
     *        meet the model's minimum cacheable size (1,024 tokens on Sonnet-tier; higher on some tiers) or it silently will not cache.
     * @return array<array-key,array<mixed>> decoded JSON keyed as the input
     */
    public function completeJsonBatch(array $requests): array;

    /**
     * Send several prompts CONCURRENTLY and return their raw text, keyed by the
     * same keys as the input. Unlike completeJsonBatch this does NOT ask for or
     * decode JSON — it is for prompts whose answer IS the payload (e.g. block
     * markup), so the model returns it verbatim instead of escaping it inside a
     * JSON string (which is brittle and wastes tokens).
     *
     * Implementations detect abnormal termination (max_tokens truncation, a
     * refusal) per member and regenerate only that member — a truncation with
     * a doubled output budget (TextBatchRecovery). A member that still
     * terminates abnormally after the retry is returned as-is (best effort)
     * rather than aborting the batch. Its keyed degradation note lets callers
     * persist a warning only if that member survives structural salvage.
     *
     * @param array<array-key,array{prompt:string,system?:string,model?:string,max_tokens?:int,temperature?:float,json_schema?:array{name:string,schema:array<string,mixed>},cached_prefixes?:list<string>,tolerate_empty?:bool}> $requests
     *        cached_prefixes are ordered reusable text layers prepended before
     *        the varying prompt; blank layers are ignored and callers may
     *        provide at most three non-blank layers per request. Anthropic
     *        clients mark each layer as an explicit ephemeral cache breakpoint;
     *        OpenAI-compatible clients join the layers and rely on
     *        provider-managed prefix caching. Cache reuse requires the same
     *        model and an identical prefix, including tools, system content, and
     *        all preceding message content, through the reused boundary. On
     *        Anthropic Sonnet, the cumulative prefix through a breakpoint must
     *        meet the model's minimum cacheable size (1,024 tokens on Sonnet-tier; higher on some tiers) or it silently will not cache.
     *        tolerate_empty is reserved for one-token cache-warm probes: an
     *        output-limit response is accepted without regeneration because
     *        only the input usage is observed.
     * @return TextBatchResult raw assistant text and keyed degradation notes
     */
    public function completeBatch(array $requests): TextBatchResult;
}
