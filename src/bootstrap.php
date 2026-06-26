<?php
declare(strict_types=1);

/**
 * Loads all source files and env. No composer/autoloader — the source set is
 * small and explicit. require_once keeps it safe to include more than once.
 */

$src = __DIR__;
require_once $src . '/Env.php';
require_once $src . '/Llm.php';
require_once $src . '/AnthropicClient.php';
require_once $src . '/Project.php';
require_once $src . '/ProjectStore.php';
require_once $src . '/PromptRenderer.php';
require_once $src . '/Step.php';
require_once $src . '/Pipeline.php';

// Steps.
foreach (glob($src . '/steps/*.php') ?: [] as $stepFile) {
    require_once $stepFile;
}

Env::load(dirname($src) . '/.env');

/** Build the production LLM transport from environment configuration. */
function make_llm(): Llm
{
    return new AnthropicClient(
        apiKey: Env::require('ANTHROPIC_API_KEY'),
        model:  Env::get('LLM_MODEL', 'claude-opus-4-8'),
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
    return new Pipeline([
        new ScaffoldThemeStep(),
        new SiteSpecStep($llm, $renderer),
        new ApplyIdentityStep(),
        new DesignDirectionStep($llm, $renderer),
    ]);
}
