<?php
declare(strict_types=1);

/**
 * Loads the package autoloader and env. CLI factory helpers (make_llm,
 * step_models, …) stay here; pipeline assembly lives on SiteBuilder.
 */

use Automattic\SiteBuild\AnthropicClient;
use Automattic\SiteBuild\Env;
use Automattic\SiteBuild\ImageClient;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\OpenAiCompatibleClient;
use Automattic\SiteBuild\StepDefaults;
use Automattic\SiteBuild\WpcomImageClient;

require_once dirname(__DIR__) . '/autoload.php';

Env::load(dirname(__DIR__) . '/.env');

/** The model used by any LLM step that isn't given a more specific one. */
function default_llm_model(): string
{
    return StepDefaults::model();
}

/** @return array<string,string> step id => model id */
function step_models(): array
{
    return StepDefaults::models();
}

/** @return array<string,?float> step id => temperature (null = API default) */
function step_temperatures(): array
{
    return StepDefaults::temperatures();
}

/** Resolve one step's temperature (delegates to StepDefaults). */
function llm_temperature(string $envSuffix, ?float $default): ?float
{
    return StepDefaults::temperature($envSuffix, $default);
}

/**
 * Build the production LLM transport from environment configuration.
 *
 * LLM_PROVIDER selects the wire client (default anthropic):
 *   - anthropic — Anthropic Messages API (ANTHROPIC_API_KEY)
 *   - xai       — OpenAI-compatible client pointed at api.x.ai (XAI_API_KEY)
 *   - openai    — OpenAI-compatible client (OPENAI_API_KEY, optional OPENAI_BASE_URL)
 *
 * Model IDs stay in LLM_MODEL / LLM_MODEL_* (StepDefaults). For xAI set e.g.
 * LLM_MODEL=grok-4.5 and per-step overrides as needed.
 */
function make_llm(): Llm
{
    $provider = strtolower((string) Env::get('LLM_PROVIDER', 'anthropic'));

    return match ($provider) {
        'anthropic', '' => new AnthropicClient(
            apiKey: Env::getRequired('ANTHROPIC_API_KEY'),
            model:  default_llm_model(),
        ),
        'xai', 'grok' => new OpenAiCompatibleClient(
            apiKey:   Env::getRequired('XAI_API_KEY'),
            model:    default_llm_model(),
            baseUrl:  Env::get('OPENAI_BASE_URL', 'https://api.x.ai/v1') ?? 'https://api.x.ai/v1',
            provider: 'xai',
        ),
        'openai', 'openai-compatible' => new OpenAiCompatibleClient(
            apiKey:   Env::getRequired('OPENAI_API_KEY'),
            model:    default_llm_model(),
            baseUrl:  Env::get('OPENAI_BASE_URL', 'https://api.openai.com/v1') ?? 'https://api.openai.com/v1',
            provider: 'openai',
        ),
        default => throw new RuntimeException(
            "Unknown LLM_PROVIDER '{$provider}'. Use anthropic, xai, or openai."
        ),
    };
}

/** Build the image-generation transport (WPCOM AI proxy → Google Vertex Imagen). */
function make_image_client(): ImageClient
{
    return new WpcomImageClient(
        apiToken: Env::getRequired('GOOGLE_VERTEX_API_TOKEN'),
        model:    Env::get('IMAGE_MODEL', 'imagen-4.0-generate-001'),
    );
}

/** Project root path helper. */
function repo_path(string $rel = ''): string
{
    $root = dirname(__DIR__);
    return $rel === '' ? $root : $root . '/' . ltrim($rel, '/');
}
