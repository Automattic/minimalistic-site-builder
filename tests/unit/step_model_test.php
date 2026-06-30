<?php
declare(strict_types=1);

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
    $llm->queueJson(['name' => 'Demo']);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SiteSpecStep($llm, $renderer, 'claude-haiku-4-5'))->run($project);

    assert_eq('claude-haiku-4-5', $llm->calls[0]['opts']['model'] ?? null);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec sends no model key when none is configured', function () {
    [$project, $tmp] = sm_project('builder_sm_ssd_');
    $llm = new FakeLlm();
    $llm->queueJson(['name' => 'Demo']);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SiteSpecStep($llm, $renderer))->run($project);

    assert_true(!array_key_exists('model', $llm->calls[0]['opts']), 'no model key when default');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction passes the configured model into the LLM opts', function () {
    [$project, $tmp] = sm_project('builder_sm_dd_');
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $llm = new FakeLlm();
    $llm->queueText('Brutalist direction: raw concrete palette, mono type.');
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new DesignDirectionStep($llm, $renderer, 'claude-haiku-4-5'))->run($project);

    assert_eq('claude-haiku-4-5', $llm->calls[0]['opts']['model'] ?? null);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction sends no model key when none is configured', function () {
    [$project, $tmp] = sm_project('builder_sm_ddd_');
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $llm = new FakeLlm();
    $llm->queueText('Brutalist direction: raw concrete palette, mono type.');
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new DesignDirectionStep($llm, $renderer))->run($project);

    assert_true(!array_key_exists('model', $llm->calls[0]['opts']), 'no model key when default');
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

test('section-plan passes the configured model into every request', function () {
    [$project, $tmp] = sm_project('builder_sm_sp_');
    $project->writeJson('siteSpec.json', ['name' => 'Demo', 'sections' => ['Hero']]);
    $llm = new FakeLlm();
    $llm->queueJson(['sections' => [['slug' => 'hero', 'title' => 'Hero', 'type' => 'hero']]]);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SectionPlanStep($llm, $renderer, 'claude-haiku-4-5'))->run($project);

    assert_eq('claude-haiku-4-5', $llm->calls[0]['opts']['model'] ?? null);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections passes the configured model into every part request', function () {
    [$project, $tmp] = sm_project('builder_sm_sec_');
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $project->writeJson('theme/theme.json', ['version' => 3]);
    $project->writeJson('sections.json', ['sections' => [
        ['slug' => 'hero', 'title' => 'Hero', 'type' => 'hero'],
    ]]);
    $llm = new FakeLlm();
    // header, footer, one section — in requests() order.
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');
    $llm->queueText('<!-- wp:heading --><h2>Hero</h2><!-- /wp:heading -->');
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SectionsStep($llm, $renderer, 'claude-opus-4-8'))->run($project);

    assert_true(count($llm->calls) === 3, 'one request per part');
    foreach ($llm->calls as $call) {
        assert_eq('claude-opus-4-8', $call['opts']['model'] ?? null);
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections sends no model key when none is configured', function () {
    [$project, $tmp] = sm_project('builder_sm_secd_');
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $project->writeJson('theme/theme.json', ['version' => 3]);
    $project->writeJson('sections.json', ['sections' => [
        ['slug' => 'hero', 'title' => 'Hero', 'type' => 'hero'],
    ]]);
    $llm = new FakeLlm();
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');
    $llm->queueText('<!-- wp:heading --><h2>Hero</h2><!-- /wp:heading -->');
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SectionsStep($llm, $renderer))->run($project);

    assert_true(!array_key_exists('model', $llm->calls[0]['opts']), 'no model key when default');
    exec('rm -rf ' . escapeshellarg($tmp));
});
