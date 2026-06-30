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
require_once $src . '/LlmLogger.php';
require_once $src . '/AnthropicClient.php';
require_once $src . '/ImageClient.php';
require_once $src . '/WpcomImageClient.php';
require_once $src . '/ImagePromptComposer.php';
require_once $src . '/Project.php';
require_once $src . '/ProjectStore.php';
require_once $src . '/PromptRenderer.php';
require_once $src . '/ModelOption.php';
require_once $src . '/Step.php';
require_once $src . '/ConcurrentStep.php';
require_once $src . '/ConcurrentGroup.php';
require_once $src . '/Pipeline.php';
require_once $src . '/BuildReport.php';
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

/** The default sampling temperature for creative LLM steps. */
function default_llm_temperature(): float
{
    return env_float('LLM_TEMPERATURE', 1.0);
}

/**
 * Per-step model selection — the single, explicit place to choose which Claude
 * model each LLM step runs on. Change a value here to A/B model quality, cost
 * and speed for one step in isolation. Only LLM-backed steps appear; the
 * deterministic steps (scaffold, apply-identity, collect-images, fix-blocks,
 * finalize) make no LLM calls.
 *
 * To pin a step in code, replace `$default` with a literal, e.g.
 *   'site-spec' => 'claude-haiku-4-5',
 * Or override any one step from the environment without touching code:
 *   LLM_MODEL_SITE_SPEC, LLM_MODEL_THEME_JSON, LLM_MODEL_SECTION_PLAN, LLM_MODEL_SECTIONS
 * LLM_MODEL sets the fallback for every step left at the default.
 *
 * @return array<string,string> step id => model id
 */
function step_models(): array
{
    $default = default_llm_model();
    return [
        'site-spec'    => Env::get('LLM_MODEL_SITE_SPEC',    'claude-haiku-4-5'),
        // Design direction is the creative seed every later step builds on, so
        // it runs on the best model by default; override to trade cost/quality.
        'design-direction' => Env::get('LLM_MODEL_DESIGN_DIRECTION', $default),
        'theme-json'   => Env::get('LLM_MODEL_THEME_JSON',   $default),
        // Planning is light and structural — cheap/fast model by default.
        'section-plan' => Env::get('LLM_MODEL_SECTION_PLAN', 'claude-haiku-4-5'),
        // Section markup is the quality-critical work — best model by default.
        'sections'     => Env::get('LLM_MODEL_SECTIONS',     $default),
    ];
}

/**
 * Per-step sampling temperatures. Keep factual extraction steadier, but make
 * design steps explicit and high-variance so palette/composition evidence is
 * comparable across runs instead of relying on an API default.
 *
 * @return array<string,float> step id => temperature
 */
function step_temperatures(): array
{
    $creative = default_llm_temperature();
    return [
        'site-spec'         => env_float('LLM_TEMPERATURE_SITE_SPEC', 0.2),
        'design-direction'  => env_float('LLM_TEMPERATURE_DESIGN_DIRECTION', $creative),
        'theme-json'        => env_float('LLM_TEMPERATURE_THEME_JSON', $creative),
        'section-plan'      => env_float('LLM_TEMPERATURE_SECTION_PLAN', 0.7),
        'sections'          => env_float('LLM_TEMPERATURE_SECTIONS', $creative),
    ];
}

function env_float(string $key, float $default): float
{
    $raw = Env::get($key);
    if ($raw === null || $raw === '') {
        return $default;
    }
    if (!is_numeric($raw)) {
        throw new RuntimeException("{$key} must be a number");
    }
    $value = (float) $raw;
    if ($value < 0.0 || $value > 1.0) {
        throw new RuntimeException("{$key} must be between 0.0 and 1.0");
    }
    return $value;
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
    $temperatures = step_temperatures();
    return new Pipeline([
        new ScaffoldThemeStep(),
        new SiteSpecStep($llm, $renderer, $models['site-spec'], $temperatures['site-spec']),
        new ApplyIdentityStep(),
        // Commit to ONE creative concept BEFORE theme.json / the section plan, so
        // both derive from a strong, specific direction instead of converging on
        // safe defaults. Writes designDirection.md, read by the steps below.
        // Tradeoff: this is an extra serial LLM round-trip on the critical path
        // (the concurrent group now depends on its output) — a deliberate cost
        // we pay for design variety; tune via LLM_MODEL_DESIGN_DIRECTION.
        new DesignDirectionStep($llm, $renderer, $models['design-direction'], $temperatures['design-direction']),
        // theme.json and the section plan both derive from the prompt + siteSpec +
        // the design direction, so run them concurrently. Design decisions are
        // made inline, steered by designDirection.md.
        new ConcurrentGroup($llm, [
            new ThemeJsonStep($llm, $renderer, $models['theme-json'], $temperatures['theme-json']),
            new SectionPlanStep($llm, $renderer, $models['section-plan'], $temperatures['section-plan']),
        ]),
        // Generate the header, footer, and every section part in one concurrent
        // batch, then stitch them into the page deterministically.
        new SectionsStep($llm, $renderer, $models['sections'], $temperatures['sections']),
        new AssembleLandingPageStep(),
        // Collect image placeholders BEFORE fix-blocks: the block re-serializer
        // strips the alt from wp:cover background images (core cover save()
        // resets it to ""), which would lose every hero's AI_IMAGE spec.
        new CollectImagesStep(),
        new FixBlocksStep(),
        new FinalizeThemeStep(),
    ]);
}
