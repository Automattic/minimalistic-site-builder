<?php
declare(strict_types=1);

/**
 * Loads Composer autoloader and env. Global factory helpers for the CLI remain
 * here until consumers fully construct SiteBuilder themselves.
 */

use Automattic\SiteBuild\AnthropicClient;
use Automattic\SiteBuild\ConcurrentGroup;
use Automattic\SiteBuild\Env;
use Automattic\SiteBuild\ImageClient;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\NodeBlockFixer;
use Automattic\SiteBuild\Pipeline;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\ApplyIdentityStep;
use Automattic\SiteBuild\Steps\AssembleLandingPageStep;
use Automattic\SiteBuild\Steps\CollectImagesStep;
use Automattic\SiteBuild\Steps\DesignDirectionStep;
use Automattic\SiteBuild\Steps\FinalizeThemeStep;
use Automattic\SiteBuild\Steps\FixBlocksStep;
use Automattic\SiteBuild\Steps\FontsPhpStep;
use Automattic\SiteBuild\Steps\PageStylesStep;
use Automattic\SiteBuild\Steps\RefinePromptStep;
use Automattic\SiteBuild\Steps\ScaffoldThemeStep;
use Automattic\SiteBuild\Steps\SectionPlanStep;
use Automattic\SiteBuild\Steps\SectionsStep;
use Automattic\SiteBuild\Steps\SiteSpecStep;
use Automattic\SiteBuild\Steps\ThemeJsonStep;
use Automattic\SiteBuild\WpcomImageClient;

require_once dirname(__DIR__) . '/vendor/autoload.php';

Env::load(dirname(__DIR__) . '/.env');

/** The model used by any LLM step that isn't given a more specific one. */
function default_llm_model(): string
{
    return Env::get('LLM_MODEL', 'claude-opus-4-8');
}

/**
 * Per-step model selection — the single, explicit place to choose which Claude
 * model each LLM step runs on. Change a value here to A/B model quality, cost
 * and speed for one step in isolation. Only the LLM steps appear; the
 * deterministic steps (scaffold, apply-identity, collect-images, fix-blocks,
 * finalize) make no LLM calls.
 *
 * To pin a step in code, replace `$default` with a literal, e.g.
 *   'site-spec' => 'claude-haiku-4-5',
 * Or override any one step from the environment without touching code:
 *   LLM_MODEL_REFINE_PROMPT, LLM_MODEL_SITE_SPEC, LLM_MODEL_THEME_JSON,
 *   LLM_MODEL_SECTION_PLAN, LLM_MODEL_SECTIONS, LLM_MODEL_PAGE_STYLES,
 *   LLM_MODEL_FONTS_PHP
 * LLM_MODEL sets the fallback for every step left at the default.
 *
 * @return array<string,string> step id => model id
 */
function step_models(): array
{
    $default = default_llm_model();
    return [
        // Fast, cheap prompt clean-up at the very start — small model by default.
        'refine-prompt' => Env::get('LLM_MODEL_REFINE_PROMPT', 'claude-haiku-4-5'),
        'site-spec'    => Env::get('LLM_MODEL_SITE_SPEC',    'claude-haiku-4-5'),
        // Design direction is the creative seed every later step builds on, so
        // it runs on the best model by default; override to trade cost/quality.
        'design-direction' => Env::get('LLM_MODEL_DESIGN_DIRECTION', $default),
        // Brainstorming four compact concept seeds (one is picked at random and
        // expanded by design-direction) is cheap divergence work — small model.
        'design-direction-seeds' => Env::get('LLM_MODEL_DESIGN_DIRECTION_SEEDS', 'claude-haiku-4-5'),
        'theme-json'   => Env::get('LLM_MODEL_THEME_JSON',   $default),
        // Planning is light and structural — cheap/fast model by default.
        'section-plan' => Env::get('LLM_MODEL_SECTION_PLAN', 'claude-haiku-4-5'),
        // Section markup is the quality-critical work — best model by default.
        'sections'     => Env::get('LLM_MODEL_SECTIONS',     $default),
        // One small CSS appendix, but it must satisfy a strict validator and
        // carry the direction's mood — best model by default; cheap to override.
        'page-styles'  => Env::get('LLM_MODEL_PAGE_STYLES',  $default),
        // One small PHP module behind a strict validator with a deterministic
        // fallback; the model's value-add is design-led weight/axis choices.
        'fonts-php'    => Env::get('LLM_MODEL_FONTS_PHP',    $default),
    ];
}

/**
 * Per-step sampling temperature — the counterpart of step_models() for the
 * other quality/diversity knob. A null means "don't send temperature" so the
 * API default applies. The two creative steps get an explicit default: the
 * design direction runs hot (its seed spread is the pipeline's variety source,
 * and repeated builds of one brief must not converge — the same temperature is
 * applied to the seed call, where the small model still supports sampling),
 * and sections run slightly hot for compositional range while staying reliable
 * at emitting valid block markup.
 *
 * Caveat: the API REMOVED sampling parameters on Claude Opus 4.7/4.8 and
 * Fable (a request carrying temperature 400s), so these values only take
 * effect on models that still support sampling (Haiku 4.5, Sonnet 4.6,
 * Opus <= 4.6). AnthropicClient omits temperature for the sampling-less
 * models — see AnthropicClient::supportsSampling() — so a step left on the
 * Opus 4.8 default gets its diversity from the prompt-level mechanisms
 * (seed spread + random pick) instead.
 *
 * Override any one step from the environment without touching code:
 *   LLM_TEMPERATURE_DESIGN_DIRECTION=0.7, LLM_TEMPERATURE_SECTIONS=1.0, …
 * LLM_TEMPERATURE sets the value for every step without a per-step override.
 *
 * @return array<string,?float> step id => temperature (null = API default)
 */
function step_temperatures(): array
{
    return [
        'refine-prompt'    => llm_temperature('REFINE_PROMPT', null),
        'site-spec'        => llm_temperature('SITE_SPEC', null),
        'design-direction' => llm_temperature('DESIGN_DIRECTION', 1.0),
        'theme-json'       => llm_temperature('THEME_JSON', null),
        'section-plan'     => llm_temperature('SECTION_PLAN', null),
        'sections'         => llm_temperature('SECTIONS', 0.9),
        'page-styles'      => llm_temperature('PAGE_STYLES', null),
        'fonts-php'        => llm_temperature('FONTS_PHP', null),
    ];
}

/**
 * Resolve one step's temperature: LLM_TEMPERATURE_<STEP> wins, then the global
 * LLM_TEMPERATURE, then the step's code default. A non-numeric env value is
 * ignored (falls through to the default) rather than sent to the API.
 */
function llm_temperature(string $envSuffix, ?float $default): ?float
{
    $raw = Env::get('LLM_TEMPERATURE_' . $envSuffix) ?? Env::get('LLM_TEMPERATURE');
    return is_numeric($raw) ? (float) $raw : $default;
}

/** Build the production LLM transport from environment configuration. */
function make_llm(): AnthropicClient
{
    return new AnthropicClient(
        apiKey: Env::getRequired('ANTHROPIC_API_KEY'),
        model:  default_llm_model(),
    );
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

/**
 * Assemble the full site-creation pipeline in order. Steps are added here as
 * they are implemented; this is the single source of step ordering.
 */
function build_pipeline(Llm $llm): Pipeline
{
    $renderer = new PromptRenderer(repo_path('prompts'));
    $models = step_models();
    $temps = step_temperatures();
    return new Pipeline([
        new ScaffoldThemeStep(),
        // Cheap, fast first pass on a small model: expand short/vague prompts and
        // normalize the brief before any expensive step reads it. Rewrites the
        // `prompt` in meta.json (original kept as `original_prompt`), so every
        // step below benefits with no further wiring.
        new RefinePromptStep($llm, $renderer, $models['refine-prompt'], $temps['refine-prompt']),
        new SiteSpecStep($llm, $renderer, $models['site-spec'], $temps['site-spec']),
        new ApplyIdentityStep(),
        // Commit to ONE creative concept BEFORE theme.json / the section plan, so
        // both derive from a strong, specific direction instead of converging on
        // safe defaults. Writes designDirection.json, read by the steps below.
        // Tradeoff: this is an extra serial LLM round-trip on the critical path
        // (the concurrent group now depends on its output) — a deliberate cost
        // we pay for design variety; tune via LLM_MODEL_DESIGN_DIRECTION.
        new DesignDirectionStep($llm, $renderer, $models['design-direction'], $temps['design-direction'], $models['design-direction-seeds']),
        // theme.json and the section plan both derive from the prompt + siteSpec +
        // the design direction, so run them concurrently. Design decisions are
        // made inline, steered by designDirection.json.
        new ConcurrentGroup($llm, [
            new ThemeJsonStep($llm, $renderer, $models['theme-json'], $temps['theme-json']),
            new SectionPlanStep($llm, $renderer, $models['section-plan'], $temps['section-plan']),
        ]),
        // Generate the header, footer, and every section part in one concurrent
        // batch, then stitch them into the page deterministically.
        new SectionsStep($llm, $renderer, $models['sections'], $temps['sections']),
        new AssembleLandingPageStep(),
        // Collect image placeholders BEFORE fix-blocks: the block re-serializer
        // strips the alt from wp:cover background images (core cover save()
        // resets it to ""), which would lose every hero's AI_IMAGE spec.
        new CollectImagesStep(),
        new FixBlocksStep(NodeBlockFixer::default()),
        // AFTER fix-blocks: reads the final (re-serialized) markup for which
        // layout utility classes survived, and appends their CSS to style.css —
        // a file the fixer never touches, so nothing here can be stripped.
        new PageStylesStep($llm, $renderer, $models['page-styles'], $temps['page-styles']),
        // Also after fix-blocks: writes fonts.php from the design direction,
        // validated against a deterministic scan of the final theme.json +
        // markup (every family/weight/italic the build uses MUST be requested;
        // scan-built fallback otherwise).
        new FontsPhpStep($llm, $renderer, $models['fonts-php'], $temps['fonts-php']),
        // Sole owner of functions.php: the deterministic loader that enqueues
        // style.css and require_once's the generated fonts.php.
        new FinalizeThemeStep(),
    ]);
}
