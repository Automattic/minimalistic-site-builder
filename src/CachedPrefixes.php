<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * The `cached_prefixes` clause of the Llm contract, in one place.
 *
 * Both reference clients used to validate this inline, which let them drift:
 * AnthropicClient rejected a non-list, a non-string member and a fourth layer,
 * while OpenAiCompatibleClient iterated whatever it was handed — so
 * `cached_prefixes => null` was a silent drop in a shipped client, which is
 * this contract's own worst failure mode sitting in a reference implementation.
 *
 * Validating in one place also gives LlmConformance something honest to assert
 * against: both clients now refuse the same shapes with the same message and
 * the same exception type.
 */
final class CachedPrefixes
{
    /** Cache breakpoints a single request may carry. */
    public const MAX_LAYERS = 3;

    /**
     * Validate a request's `cached_prefixes` and return its non-blank layers.
     *
     * Blank layers are dropped rather than refused: the contract documents them
     * as ignored so that callers can build a layer conditionally without
     * branching at the call site.
     *
     * @param  mixed  $provided the raw option value, whatever the caller passed
     * @param  string $subject  request family named in the cap message, e.g. "Anthropic requests"
     * @return list<string> non-blank layers, in order
     * @throws LlmRequestRejected when the value is not a list of strings, or carries too many layers
     */
    public static function normalize(mixed $provided, string $subject): array
    {
        if (!is_array($provided) || !array_is_list($provided)) {
            throw new LlmRequestRejected('cached_prefixes must be a list of strings');
        }
        $layers = [];
        foreach ($provided as $index => $prefix) {
            if (!is_string($prefix)) {
                throw new LlmRequestRejected("cached_prefixes[{$index}] must be a string");
            }
            if (trim($prefix) !== '') {
                $layers[] = $prefix;
            }
        }
        if (count($layers) > self::MAX_LAYERS) {
            throw new LlmRequestRejected("{$subject} support at most three cached_prefixes");
        }
        return $layers;
    }
}
