<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Parses the value of an `LLM_MODEL_<STEP>` override, which may name a
 * transport as well as a model:
 *
 *     LLM_MODEL_THEME_JSON=claude-opus-5             the active provider's transport
 *     LLM_MODEL_SECTIONS=baseten:zai-org/GLM-5.3-Flash   that step runs on Baseten
 *
 * The prefix is optional, so every override written before this existed keeps
 * working unchanged.
 *
 * A colon alone cannot mean "transport", because model ids contain colons:
 * `moonshotai/kimi-k2.5:nitro` is an ordinary OpenRouter id and is already the
 * openrouter small tier. So the text before the FIRST colon is treated as a
 * transport only when it IS one. Anything else — including a leading segment
 * that merely looks like a vendor, e.g. `openai/gpt-oss-120b` — is left alone
 * as part of the model id.
 *
 * The model is passed through verbatim, never case-folded: Baseten's ids are
 * case-sensitive (`zai-org/GLM-5.3-Flash`).
 */
final class ModelSpec
{
    /**
     * The prefixes accepted here: one canonical name per wire client. Not the
     * same as the provider list — a provider is a whole model set in
     * config/models.json, a transport is a wire client — and narrower than what
     * make_llm_transport() will build, which also answers to the aliases 'grok'
     * and 'openai-compatible'. Those are deliberately not prefixes: one name
     * per client keeps RoutingLlm from holding two entries pointed at the same
     * endpoint. Write 'xai' and 'openai'.
     */
    public const TRANSPORTS = ['anthropic', 'xai', 'openai', 'openrouter', 'baseten'];

    /**
     * @param string $value   Raw env value.
     * @param string $context Var name, for the error message.
     * @return array{transport:?string,model:string} transport null = the active provider's
     */
    public static function parse(string $value, string $context = 'LLM_MODEL_<STEP>'): array
    {
        $value = trim($value);
        $colon = strpos($value, ':');

        if ($colon !== false) {
            // Trimmed before the comparison: "baseten : model" is the same
            // assignment as "baseten:model", and a prefix that missed the
            // match by a space would be swallowed into the model id silently.
            $prefix = strtolower(trim(substr($value, 0, $colon)));
            if (in_array($prefix, self::TRANSPORTS, true)) {
                $model = trim(substr($value, $colon + 1));
                if ($model === '') {
                    throw new \RuntimeException(
                        "{$context} names the transport '{$prefix}' with no model after it. "
                        . "Write it as \"{$prefix}:<model-id>\", or drop the prefix to use the active provider."
                    );
                }
                return ['transport' => $prefix, 'model' => $model];
            }
        }

        if ($value === '') {
            throw new \RuntimeException("{$context} is empty.");
        }
        return ['transport' => null, 'model' => $value];
    }
}
