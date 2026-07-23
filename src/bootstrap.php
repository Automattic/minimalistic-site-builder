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
use Automattic\SiteBuild\ModelConfig;
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
 * LLM_PROVIDER selects the wire client (default from config/models.json):
 *   - anthropic — Anthropic Messages API (ANTHROPIC_API_KEY)
 *   - xai       — OpenAI-compatible client pointed at api.x.ai (XAI_API_KEY)
 *   - openai    — OpenAI-compatible client (OPENAI_API_KEY, optional OPENAI_BASE_URL)
 *
 * Model IDs come from the provider's tiers in config/models.json (StepDefaults),
 * overridable per step via LLM_MODEL_* — so `--provider=openai` swaps the whole
 * model set without extra flags.
 */
function make_llm(): Llm
{
    $provider = strtolower((string) Env::get('LLM_PROVIDER', ModelConfig::defaultProvider()));

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

/**
 * Stop one Playground boot: the php wrapper, its Playground/node subtree, and
 * the reparented node server (once the launcher exits it reparents to init and
 * escapes the tree walk — but it keeps the blueprint path in its argv).
 */
function teardown_playground($proc, int $pid, string $blueprintPath): void
{
    if ($pid > 0) {
        kill_tree($pid);
    }
    @exec('pkill -f ' . escapeshellarg(preg_quote($blueprintPath, '~')) . ' 2>/dev/null');
    @unlink($blueprintPath);
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
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
