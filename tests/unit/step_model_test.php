<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\DesignDirectionStep;
use Automattic\SiteBuild\Steps\PagePlanStep;
use Automattic\SiteBuild\Steps\SectionsStep;
use Automattic\SiteBuild\Steps\SiteSpecStep;
use Automattic\SiteBuild\Steps\ThemeJsonStep;
use Automattic\SiteBuild\Tests\FakeLlm;

/**
 * Guards the per-step model wiring: the optional model arg each LLM step takes
 * must reach the LLM call's opts, and stay absent when unset so the client
 * falls back to its default. Without these, a step could silently drop the
 * override and every other test would still pass.
 */

function sm_project(string $prefix): array
{
    $tmp = sys_get_temp_dir() . '/' . $prefix . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy neighborhood bakery']);
    return [$project, $tmp];
}


test('site-spec passes the configured model into the LLM opts', function () {
    [$project, $tmp] = sm_project('builder_sm_ss_');
    $llm = new FakeLlm();
    $llm->queueJson(['name' => 'Demo', 'language' => 'en']);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SiteSpecStep($llm, $renderer, 'claude-haiku-4-5'))->run($project);

    assert_eq('claude-haiku-4-5', $llm->calls[0]['opts']['model'] ?? null);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec sends no model key when none is configured', function () {
    [$project, $tmp] = sm_project('builder_sm_ssd_');
    $llm = new FakeLlm();
    $llm->queueJson(['name' => 'Demo', 'language' => 'en']);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SiteSpecStep($llm, $renderer))->run($project);

    assert_true(!array_key_exists('model', $llm->calls[0]['opts']), 'no model key when default');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction passes the configured model into the LLM opts', function () {
    [$project, $tmp] = sm_project('builder_sm_dd_');
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $llm = new FakeLlm();
    $llm->queueJson(['seeds' => ['Brut']]);
    $llm->queueJson(['direction' => ['title' => 'Brut', 'description' => 'Brutalist direction: raw concrete palette, mono type.']]);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new DesignDirectionStep($llm, $renderer, 'claude-haiku-4-5'))->run($project);

    assert_true(!array_key_exists('model', $llm->calls[0]['opts']), 'seed call has no model key when no seed model is set');
    assert_eq('claude-haiku-4-5', $llm->calls[1]['opts']['model'] ?? null);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction sends no model key when none is configured', function () {
    [$project, $tmp] = sm_project('builder_sm_ddd_');
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $llm = new FakeLlm();
    $llm->queueJson(['seeds' => ['Brut']]);
    $llm->queueJson(['direction' => ['title' => 'Brut', 'description' => 'Brutalist direction: raw concrete palette, mono type.']]);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new DesignDirectionStep($llm, $renderer))->run($project);

    assert_true(!array_key_exists('model', $llm->calls[1]['opts']), 'no model key when default');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json passes the configured model into the LLM opts', function () {
    [$project, $tmp] = sm_project('builder_sm_tj_');
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $llm = new FakeLlm();
    $llm->queueJson(valid_theme_payload());
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new ThemeJsonStep($llm, $renderer, 'claude-sonnet-4-6'))->run($project);

    assert_eq('claude-sonnet-4-6', $llm->calls[0]['opts']['model'] ?? null);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('page-plan passes the configured model into every request', function () {
    [$project, $tmp] = sm_project('builder_sm_sp_');
    $project->writeJson('siteSpec.json', ['name' => 'Demo', 'sections' => ['Hero']]);
    $llm = new FakeLlm();
    $llm->queueJson(['sections' => [['slug' => 'hero', 'title' => 'Hero', 'role' => 'hero', 'type' => 'hero', 'layout_archetype' => 'full-bleed-cover', 'background' => 'image', 'vertical_density' => 'standard', 'handoff' => 'Between the header above and the footer below.']]]);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new PagePlanStep($llm, $renderer, 'claude-haiku-4-5'))->run($project);

    assert_eq('claude-haiku-4-5', $llm->calls[0]['opts']['model'] ?? null);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections passes the configured model into every part request', function () {
    [$project, $tmp] = sm_project('builder_sm_sec_');
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $project->writeJson('theme/theme.json', ['version' => 3]);
    $project->writeJson('pages.json', ['pages' => [[
        'slug' => 'home', 'title' => 'Home', 'path' => '/', 'front' => true, 'parent' => null, 'menu_order' => 0, 'purpose' => 'Welcome',
        'sections' => [
            ['slug' => 'hero', 'title' => 'Hero', 'role' => 'hero', 'type' => 'hero', 'layout_archetype' => 'full-bleed-cover', 'background' => 'image', 'vertical_density' => 'standard', 'handoff' => 'Between the header above and the footer below.'],
        ],
    ]]]);
    $llm = new FakeLlm();
    // Cache probe, then header, footer, one section in requests() order.
    $llm->queueText('OK');
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');
    $llm->queueText('<!-- wp:heading --><h2>Hero</h2><!-- /wp:heading -->');
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SectionsStep($llm, $renderer, 'claude-opus-4-8'))->run($project);

    assert_true(count($llm->calls) === 4, 'one cache probe plus one request per part');
    foreach ($llm->calls as $call) {
        assert_eq('claude-opus-4-8', $call['opts']['model'] ?? null);
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections sends no model key when none is configured', function () {
    [$project, $tmp] = sm_project('builder_sm_secd_');
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $project->writeJson('theme/theme.json', ['version' => 3]);
    $project->writeJson('pages.json', ['pages' => [[
        'slug' => 'home', 'title' => 'Home', 'path' => '/', 'front' => true, 'parent' => null, 'menu_order' => 0, 'purpose' => 'Welcome',
        'sections' => [
            ['slug' => 'hero', 'title' => 'Hero', 'role' => 'hero', 'type' => 'hero', 'layout_archetype' => 'full-bleed-cover', 'background' => 'image', 'vertical_density' => 'standard', 'handoff' => 'Between the header above and the footer below.'],
        ],
    ]]]);
    $llm = new FakeLlm();
    $llm->queueText('OK');
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');
    $llm->queueText('<!-- wp:heading --><h2>Hero</h2><!-- /wp:heading -->');
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SectionsStep($llm, $renderer))->run($project);

    assert_true(!array_key_exists('model', $llm->calls[0]['opts']), 'no model key when default');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction passes the configured temperature into the LLM opts', function () {
    [$project, $tmp] = sm_project('builder_sm_ddt_');
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $llm = new FakeLlm();
    $llm->queueJson(['seeds' => ['Brut']]);
    $llm->queueJson(['direction' => ['title' => 'Brut', 'description' => 'Brutalist direction.']]);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new DesignDirectionStep($llm, $renderer, null, 1.0))->run($project);

    assert_eq(1.0, $llm->calls[0]['opts']['temperature'] ?? null, 'seed call runs at the step temperature');
    assert_eq(1.0, $llm->calls[1]['opts']['temperature'] ?? null, 'expansion call runs at the step temperature');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction sends no temperature key when none is configured', function () {
    [$project, $tmp] = sm_project('builder_sm_ddtd_');
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $llm = new FakeLlm();
    $llm->queueJson(['seeds' => ['Brut']]);
    $llm->queueJson(['direction' => ['title' => 'Brut', 'description' => 'Brutalist direction.']]);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new DesignDirectionStep($llm, $renderer))->run($project);

    assert_true(!array_key_exists('temperature', $llm->calls[0]['opts']), 'no temperature key on the seed call when default');
    assert_true(!array_key_exists('temperature', $llm->calls[1]['opts']), 'no temperature key when default');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections passes the configured temperature into every part request', function () {
    [$project, $tmp] = sm_project('builder_sm_sect_');
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $project->writeJson('theme/theme.json', ['version' => 3]);
    $project->writeJson('pages.json', ['pages' => [[
        'slug' => 'home', 'title' => 'Home', 'path' => '/', 'front' => true, 'parent' => null, 'menu_order' => 0, 'purpose' => 'Welcome',
        'sections' => [
            ['slug' => 'hero', 'title' => 'Hero', 'role' => 'hero', 'type' => 'hero', 'layout_archetype' => 'full-bleed-cover', 'background' => 'image', 'vertical_density' => 'standard', 'handoff' => 'Between the site header above and the footer below.'],
        ],
    ]]]);
    $llm = new FakeLlm();
    $llm->queueText('OK');
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');
    $llm->queueText('<!-- wp:heading --><h2>Hero</h2><!-- /wp:heading -->');
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SectionsStep($llm, $renderer, null, 0.9))->run($project);

    assert_true(count($llm->calls) === 4, 'one cache probe plus one request per part');
    foreach ($llm->calls as $call) {
        assert_eq(0.9, $call['opts']['temperature'] ?? null);
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json passes the configured temperature into its request', function () {
    [$project, $tmp] = sm_project('builder_sm_tjt_');
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $llm = new FakeLlm();
    $llm->queueJson(valid_theme_payload());
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new ThemeJsonStep($llm, $renderer, null, 0.8))->run($project);

    assert_eq(0.8, $llm->calls[0]['opts']['temperature'] ?? null);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('step_temperatures defaults: hot design-direction and sections, env override wins', function () {
    $temps = step_temperatures();
    assert_eq(1.0, $temps['design-direction']);
    assert_eq(0.9, $temps['sections']);
    assert_eq(null, $temps['theme-json']);

    putenv('LLM_TEMPERATURE_SECTIONS=0.5');
    putenv('LLM_TEMPERATURE=0.3');
    try {
        $temps = step_temperatures();
        assert_eq(0.5, $temps['sections'], 'per-step env wins');
        assert_eq(0.3, $temps['design-direction'], 'global env replaces the code default');
        assert_eq(0.3, $temps['theme-json'], 'global env applies to unset steps');
    } finally {
        putenv('LLM_TEMPERATURE_SECTIONS');
        putenv('LLM_TEMPERATURE');
    }

    putenv('LLM_TEMPERATURE=hot'); // non-numeric → ignored
    try {
        assert_eq(1.0, step_temperatures()['design-direction']);
    } finally {
        putenv('LLM_TEMPERATURE');
    }
});
