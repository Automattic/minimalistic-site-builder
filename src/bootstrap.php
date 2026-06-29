<?php
declare(strict_types=1);

/**
 * Loads all source files and env. No composer/autoloader — the source set is
 * small and explicit. require_once keeps it safe to include more than once.
 */

$src = __DIR__;
require_once $src . '/Env.php';
require_once $src . '/Llm.php';
require_once $src . '/TransientApiException.php';
require_once $src . '/AnthropicClient.php';
require_once $src . '/ImageClient.php';
require_once $src . '/WpcomImageClient.php';
require_once $src . '/Project.php';
require_once $src . '/ProjectStore.php';
require_once $src . '/PromptRenderer.php';
require_once $src . '/Step.php';
require_once $src . '/Pipeline.php';
require_once $src . '/ThemeValidator.php';

// Steps.
foreach (glob($src . '/steps/*.php') ?: [] as $stepFile) {
    require_once $stepFile;
}

Env::load(dirname($src) . '/.env');

/** The model used by any LLM step that isn't given a more specific one. */
function default_llm_model(): string
{
    return Env::get('LLM_MODEL', 'claude-opus-4-8');
}

/**
 * Per-step model selection — the single, explicit place to choose which Claude
 * model each LLM step runs on. Change a value here to A/B model quality, cost
 * and speed for one step in isolation. Only the three LLM steps appear; the
 * deterministic steps (scaffold, apply-identity, collect-images, fix-blocks,
 * finalize) make no LLM calls.
 *
 * To pin a step in code, replace `$default` with a literal, e.g.
 *   'site-spec' => 'claude-haiku-4-5',
 * Or override any one step from the environment without touching code:
 *   LLM_MODEL_SITE_SPEC, LLM_MODEL_THEME_JSON, LLM_MODEL_LANDING_PAGE
 * LLM_MODEL sets the fallback for every step left at the default.
 *
 * @return array<string,string> step id => model id
 */
function step_models(): array
{
    $default = default_llm_model();
    return [
        'site-spec'    => Env::get('LLM_MODEL_SITE_SPEC',    'claude-haiku-4-5'),
        'theme-json'   => Env::get('LLM_MODEL_THEME_JSON',   $default),
        'landing-page' => Env::get('LLM_MODEL_LANDING_PAGE', $default),
    ];
}

/** Build the production LLM transport from environment configuration. */
function make_llm(): AnthropicClient
{
    return new AnthropicClient(
        apiKey: Env::require('ANTHROPIC_API_KEY'),
        model:  default_llm_model(),
    );
}

/** Build the image-generation transport (WPCOM AI proxy → Google Vertex Imagen). */
function make_image_client(): ImageClient
{
    return new WpcomImageClient(
        apiToken: Env::require('GOOGLE_VERTEX_API_TOKEN'),
        model:    Env::get('IMAGE_MODEL', 'imagen-4.0-generate-001'),
    );
}

/** Project root path helper. */
function repo_path(string $rel = ''): string
{
    $root = dirname(__DIR__);
    return $rel === '' ? $root : $root . '/' . ltrim($rel, '/');
}

/**
 * Assemble the full site-creation pipeline in order. Steps are added here as
 * they are implemented; this is the single source of step ordering.
 */
function build_pipeline(Llm $llm): Pipeline
{
    $renderer = new PromptRenderer(repo_path('prompts'));
    $models = step_models();
    return new Pipeline([
        new ScaffoldThemeStep(),
        new SiteSpecStep($llm, $renderer, $models['site-spec']),
        new ApplyIdentityStep(),
        // No design-doc step: theme.json and the landing page are generated
        // directly from the prompt + siteSpec, with design decisions made inline.
        new ThemeJsonStep($llm, $renderer, $models['theme-json']),
        new LandingPageStep($llm, $renderer, $models['landing-page']),
        // Collect image placeholders BEFORE fix-blocks: the block re-serializer
        // strips the alt from wp:cover background images (core cover save()
        // resets it to ""), which would lose every hero's AI_IMAGE spec.
        new CollectImagesStep(),
        new FixBlocksStep(),
        new FinalizeThemeStep(),
    ]);
}
