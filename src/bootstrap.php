<?php
declare(strict_types=1);

/**
 * Loads the package autoloader and env. CLI factory helpers (make_llm,
 * step_models, …) stay here; pipeline assembly lives on SiteBuilder.
 */

use Automattic\SiteBuild\AnthropicClient;
use Automattic\SiteBuild\BlockFixers;
use Automattic\SiteBuild\Env;
use Automattic\SiteBuild\ImageClient;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\ModelConfig;
use Automattic\SiteBuild\ModelSpec;
use Automattic\SiteBuild\OpenAiCompatibleClient;
use Automattic\SiteBuild\Package;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\RoutingLlm;
use Automattic\SiteBuild\SiteBuilder;
use Automattic\SiteBuild\StepDefaults;
use Automattic\SiteBuild\Steps\GenerateImagesStep;
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
 * Split a comma-separated CLI flag value into its trimmed, non-blank items.
 *
 * Blanks left by a trailing or doubled comma are dropped and the keys are
 * re-indexed, so position stays meaningful (--pages' first title is the
 * homepage).
 *
 * @return list<string>
 */
function split_csv_flag(string $value): array
{
    return array_values(array_filter(
        array_map('trim', explode(',', $value)),
        static fn (string $item): bool => $item !== '',
    ));
}

/**
 * Reject a `--pages` list handed over without `--multi-page`.
 *
 * --pages fixes WHICH pages get built; --multi-page owns WHETHER inner pages
 * exist at all, so a list without the flag is a contradiction — it throws
 * rather than let either flag be silently ignored.
 */
function require_multi_page_for_pages(?string $pagesArg, bool $multiPage): void
{
    if ($pagesArg !== null && !$multiPage) {
        throw new InvalidArgumentException('--pages requires --multi-page.');
    }
}

/**
 * Validate a `--provider` flag against config/models.json, returning it
 * lowercased and trimmed (null when the flag was not given).
 *
 * Validating only: it throws so every entry point gives the same friendly early
 * error instead of failing later, deep in the transport.
 */
function normalize_provider(?string $provider): ?string
{
    if ($provider === null) {
        return null;
    }

    $provider = strtolower(trim($provider));
    if (!ModelConfig::hasProvider($provider)) {
        throw new InvalidArgumentException("Unknown --provider '{$provider}'. Known: "
            . implode(', ', ModelConfig::providerNames()));
    }

    return $provider;
}

/** Prefer OpenRouter's canonical key name while accepting the earlier alias. */
function openrouter_api_key(): string
{
    foreach (['OPENROUTER_API_KEY', 'OPEN_ROUTER_API_KEY'] as $key) {
        $value = Env::get($key);
        if ($value !== null && trim($value) !== '') {
            return trim($value);
        }
    }
    return Env::getRequired('OPENROUTER_API_KEY');
}

/**
 * Build the production LLM transport from environment configuration.
 *
 * LLM_PROVIDER selects the wire client (default from config/models.json):
 *   - anthropic — Anthropic Messages API (ANTHROPIC_API_KEY)
 *   - xai       — OpenAI-compatible client pointed at api.x.ai (XAI_API_KEY)
 *   - openai    — OpenAI-compatible client (OPENAI_API_KEY, optional OPENAI_BASE_URL)
 *   - openrouter — OpenAI-compatible client → https://openrouter.ai/api/v1 (OPENROUTER_API_KEY)
 *   - baseten   — OpenAI-compatible client → Baseten's open-weight models
 *                 (Kimi, GLM, DeepSeek) via the wpcom AI proxy (BASETEN_API_KEY;
 *                 BASETEN_BASE_URL to reach Baseten directly instead)
 *   - hybrid    — Baseten for the run, OpenAI for the steps config/models.json
 *                 pins to it, dispatched per request by RoutingLlm
 *
 * Model IDs come from the provider's tiers in config/models.json (StepDefaults),
 * overridable per step via LLM_MODEL_* — so `--provider=openai` swaps the whole
 * model set without extra flags.
 *
 * A run where some LLM_MODEL_<STEP> carries a `transport:` prefix gets a
 * RoutingLlm over one client per transport instead of a single client. A run
 * with no such override keeps exactly the client it had before.
 */
function make_llm(): Llm
{
    // StepDefaults owns provider resolution. Recomputing it here would let the
    // transport this builds disagree with the models the steps are given, which
    // is how a Baseten model ends up addressed to an Anthropic client.
    $provider = StepDefaults::provider();
    $routes = ModelConfig::hasProvider($provider) ? StepDefaults::modelTransports() : [];

    if ($routes === []) {
        return make_llm_transport($provider, default_llm_model());
    }

    // One client per distinct transport. Each is given a default model its own
    // transport can actually serve — the provider's large TIER for the default
    // client, not default_llm_model(), which LLM_MODEL may have pointed at
    // another provider entirely. RoutingLlm names the model on every request
    // anyway, so these are a fallback that should never be reached.
    $clients = [$provider => make_llm_transport($provider, ModelConfig::tierModel($provider, 'large'))];
    foreach ($routes as $model => $transport) {
        $clients[$transport] ??= make_llm_transport($transport, $model);
    }

    return new RoutingLlm($clients, $routes, $provider, default_llm_model());
}

/**
 * Build ONE wire client by transport name, with $model as its default model.
 *
 * Split out of make_llm() so a mixed-transport provider can build several.
 * $model is what a request that names none will use.
 */
function make_llm_transport(string $transport, string $model): Llm
{
    return match ($transport) {
        'anthropic', '' => new AnthropicClient(
            apiKey: Env::getRequired('ANTHROPIC_API_KEY'),
            model:  $model,
        ),
        'xai', 'grok' => new OpenAiCompatibleClient(
            apiKey:   Env::getRequired('XAI_API_KEY'),
            model:    $model,
            baseUrl:  Env::get('OPENAI_BASE_URL', 'https://api.x.ai/v1'),
            provider: 'xai',
        ),
        'openai', 'openai-compatible' => new OpenAiCompatibleClient(
            apiKey:   Env::getRequired('OPENAI_API_KEY'),
            model:    $model,
            baseUrl:  Env::get('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            provider: 'openai',
        ),
        'openrouter' => new OpenAiCompatibleClient(
            apiKey:   openrouter_api_key(),
            model:    $model,
            baseUrl:  'https://openrouter.ai/api/v1',
            provider: 'openrouter',
            // Kimi K3 currently defaults to maximum-effort reasoning. Those
            // reasoning tokens share the completion budget with the visible
            // answer, and long generations need more than the generic timeout.
            // The client applies K3's larger token ceiling only to that model;
            // K2.5 structural calls retain the normal 16k default.
            timeoutSeconds: 1200,
            // Demo processes run concurrently. Keep each site's markup fan-out
            // bounded so three sites produce at most 12 simultaneous requests.
            maxConcurrency: 4,
        ),
        'baseten' => new OpenAiCompatibleClient(
            apiKey:   Env::getRequired('BASETEN_API_KEY'),
            model:    $model,
            // The wpcom AI proxy fronts Baseten and is what the Automattic
            // key opens; Studio's hosted-model family uses this same route.
            // Set BASETEN_BASE_URL=https://inference.baseten.co/v1 to go direct
            // with a real Baseten key. Deliberately its own variable rather
            // than OPENAI_BASE_URL, which openai/xai already share — reusing it
            // would point a Baseten run at whichever proxy OpenAI was given.
            baseUrl:  Env::get('BASETEN_BASE_URL', 'https://public-api.wordpress.com/wpcom/v2/ai-api-proxy/v1'),
            provider: 'baseten',
            // These are large open-weight models generating whole pages of
            // block markup, and the proxy adds a hop in front of them. Reasoning
            // is switched off per model in OpenAiCompatibleClient, so this is
            // headroom for a long generation, not for hidden thinking.
            timeoutSeconds: 1200,
            // The proxy bills and authorises per feature slug. Studio's
            // `studio-assistant-*` slugs are session-scoped and answer this
            // key with 403 ai_feature_not_permitted; `site-builder` is ours.
            // Harmless when BASETEN_BASE_URL points straight at Baseten.
            extraHeaders: ['X-WPCOM-AI-Feature' => 'site-builder'],
        ),
        // Transports, not providers: a provider is a whole model set in
        // config/models.json, a transport is a wire client. They happen to be
        // one-to-one today, but the prefix accepts transports.
        default => throw new RuntimeException(
            "Unknown LLM transport '{$transport}'. Known: " . implode(', ', ModelSpec::TRANSPORTS) . '.'
        ),
    };
}

/**
 * Build the production SiteBuilder: this package's prompts, the repo's
 * projects/ directory as the output root, the default block fixer and the
 * configured per-step models.
 */
function make_site_builder(Llm $llm): SiteBuilder
{
    return new SiteBuilder(
        llm: $llm,
        promptsDir: Package::promptsDir(),
        outputRoot: repo_path('projects'),
        blockFixer: BlockFixers::default(),
        models: step_models(),
    );
}

/** Build the image-generation transport (WPCOM AI proxy → Google Vertex Gemini). */
function make_image_client(): ImageClient
{
    return new WpcomImageClient(
        apiToken: Env::getRequired('GOOGLE_VERTEX_API_TOKEN'),
        model:    Env::get('IMAGE_MODEL', 'gemini-3.1-flash-image'),
    );
}

/**
 * Wire the opt-in image-generation step: the Vertex transport, the Llm that
 * rewrites prompts the safety filter rejects, and that repair's model.
 *
 * A null $llm still generates images, minus the prompt repair.
 */
function make_generate_images_step(?Llm $llm): GenerateImagesStep
{
    return new GenerateImagesStep(make_image_client(), $llm, step_models()['image-prompt-repair'] ?? null);
}

/** Project root path helper. */
function repo_path(string $rel = ''): string
{
    $root = dirname(__DIR__);
    return $rel === '' ? $root : $root . '/' . ltrim($rel, '/');
}

/**
 * Parse a bin/ script's arguments against a spec of the flags it accepts.
 *
 * $spec maps a flag token — written exactly as it is typed, dashes included —
 * to how it carries its value:
 *   'value'  `--name=x`, recorded as the string x (`--name=` records '').
 *   'bool'   `--name`, recorded as true.
 *   'toggle' `--name` or `--no-name`, recorded under `--name` as true or
 *            false; the last spelling on the line wins.
 *
 * An argument that does not start with `--` fills the next positional slot
 * while slots remain; $maxPositionals is how many the script takes (the prompt
 * for build.php, a slug for playground.php, none for build-demos.php).
 *
 * The first argument that fits nothing — an undeclared flag, a value flag
 * written without its `=value`, a positional past the last slot — comes back
 * as 'unknown' so the caller can print ITS OWN usage text and exit: the shape
 * of the line is this function's business, what to say about a bad one is the
 * script's. Parsing STOPS at that argument rather than running to the end, so
 * the results describe exactly what was understood BEFORE it and nothing
 * after: a script that acts on a flag before reporting the bad one still reads
 * the line strictly left to right.
 *
 * @param list<string>                          $argv Raw $argv; element 0, the script path, is skipped.
 * @param array<string,'value'|'bool'|'toggle'> $spec
 * @return array{flags: array<string,string|bool>, positionals: list<string>, unknown: ?string}
 */
function parse_cli_args(array $argv, array $spec, int $maxPositionals = 0): array
{
    $flags = [];
    $positionals = [];

    foreach (array_slice($argv, 1) as $arg) {
        $kind = $spec[$arg] ?? null;
        if ($kind === 'bool' || $kind === 'toggle') {
            $flags[$arg] = true;
            continue;
        }

        if (str_starts_with($arg, '--no-')) {
            $positive = '--' . substr($arg, 5);
            if (($spec[$positive] ?? null) === 'toggle') {
                $flags[$positive] = false;
                continue;
            }
        }

        if (str_contains($arg, '=')) {
            // The limit of 2 splits on the first '=' only, so the value keeps
            // any of its own (--out=/tmp/a=b.png).
            [$name, $value] = explode('=', $arg, 2);
            if (($spec[$name] ?? null) === 'value') {
                $flags[$name] = $value;
                continue;
            }
        }

        if (!str_starts_with($arg, '--') && count($positionals) < $maxPositionals) {
            $positionals[] = $arg;
            continue;
        }

        return ['flags' => $flags, 'positionals' => $positionals, 'unknown' => $arg];
    }

    return ['flags' => $flags, 'positionals' => $positionals, 'unknown' => null];
}

/**
 * Resolved path of an executable on PATH, or null when it isn't installed.
 *
 * Memoized per process: a single run can ask about the same binary several
 * times (publish-playground checks `gh` before resolving the repo and again
 * before pushing), and each miss costs a fork. A CLI process is short-lived,
 * so PATH changing under us is not a concern.
 */
function command_path(string $bin): ?string
{
    static $cache = [];
    if (!array_key_exists($bin, $cache)) {
        $path = trim((string) shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null'));
        $cache[$bin] = $path === '' ? null : $path;
    }
    return $cache[$bin];
}

/** Is this external tool available to shell out to? */
function command_exists(string $bin): bool
{
    return command_path($bin) !== null;
}

/**
 * Print the "available projects" list CLI usage messages end with — every
 * project in this repo that has a built theme. Prints nothing at all when
 * there is none, so a fresh checkout does not advertise an empty list.
 *
 * @param resource $stream
 */
function print_built_projects($stream, string $header = 'Available projects:'): void
{
    $slugs = ProjectStore::builtSlugs(repo_path('projects'));
    if ($slugs === []) {
        return;
    }
    fwrite($stream, $header . "\n");
    foreach ($slugs as $slug) {
        fwrite($stream, "  - {$slug}\n");
    }
}

/**
 * Build a shell command for a PHP child with this executable and temp dir.
 *
 * A fresh `php` command does not inherit CLI `-d` overrides, and PATH may
 * resolve it to a different PHP installation. Playground spawners need the
 * same sys_temp_dir on both sides so they derive the same blueprint path.
 * The leading `exec` also makes proc_open() report the PHP child's own pid.
 *
 * @param list<string> $args
 */
function php_child_command(string $script, array $args = []): string
{
    $command = 'exec ' . escapeshellarg(PHP_BINARY)
        . ' -d ' . escapeshellarg('sys_temp_dir=' . sys_get_temp_dir())
        . ' ' . escapeshellarg($script);
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }
    return $command;
}

/**
 * Blueprint path for one Playground boot, unique per server instance.
 *
 * teardown_playground() stops the reparented node server by pkill-matching
 * this path in its argv, so the name must identify ONE instance: a fixed
 * per-project name would take down every running server of the project — a
 * sibling preview included. The pid is bin/playground.php's own; its spawners
 * (bin/screenshot.php, bin/build-demos.php) use php_child_command(), whose
 * `exec` makes proc_open report that same pid and whose explicit sys_temp_dir
 * keeps both processes on the same path. Living under the OS temp dir, a file
 * leaked by a signal death (signals skip PHP shutdown handlers) is the OS's to
 * clean, not the repo's.
 */
function playground_blueprint_path(string $slug, int $pid): string
{
    return sys_get_temp_dir() . "/playground-blueprint-{$slug}.{$pid}.json";
}

// Wrapper: spawners still call this name; body lives on PlaygroundRunner.
function playground_ready_url(string $log): ?string
{
    return \Automattic\SiteBuild\PlaygroundRunner::readyUrl($log);
}

// Wrapper: spawners still call this name; body lives on PlaygroundRunner.
function teardown_playground($proc, int $pid, string $blueprintPath): void
{
    \Automattic\SiteBuild\PlaygroundRunner::teardown($proc, $pid, $blueprintPath);
}

/** Recursively SIGTERM a process and all its descendants (leaves first). */
function kill_tree(int $pid): void
{
    $children = [];
    @exec('pgrep -P ' . $pid . ' 2>/dev/null', $children);
    foreach ($children as $child) {
        kill_tree((int) $child);
    }
    @exec('kill -TERM ' . $pid . ' 2>/dev/null');
}
