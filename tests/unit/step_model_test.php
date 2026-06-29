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

function sm_landing_payload(): array
{
    $part = '<!-- wp:template-part {"slug":"header"} /-->';
    return [
        'parts/header.html'         => '<!-- wp:group --><!-- /wp:group -->',
        'parts/footer.html'         => '<!-- wp:group --><!-- /wp:group -->',
        'templates/index.html'      => $part,
        'templates/front-page.html' => $part,
    ];
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

test('landing-page passes the model while preserving the max_tokens budget', function () {
    [$project, $tmp] = sm_project('builder_sm_lp_');
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $project->writeJson('theme/theme.json', ['version' => 3]);
    $llm = new FakeLlm();
    $llm->queueJson(sm_landing_payload());
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new LandingPageStep($llm, $renderer, 'claude-opus-4-8'))->run($project);

    $opts = $llm->calls[0]['opts'];
    assert_eq('claude-opus-4-8', $opts['model'] ?? null);
    assert_eq(32000, $opts['max_tokens'] ?? null, 'token budget kept alongside model');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('landing-page keeps the max_tokens budget and no model when unset', function () {
    [$project, $tmp] = sm_project('builder_sm_lpd_');
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $project->writeJson('theme/theme.json', ['version' => 3]);
    $llm = new FakeLlm();
    $llm->queueJson(sm_landing_payload());
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new LandingPageStep($llm, $renderer))->run($project);

    $opts = $llm->calls[0]['opts'];
    assert_eq(32000, $opts['max_tokens'] ?? null);
    assert_true(!array_key_exists('model', $opts), 'no model key when default');
    exec('rm -rf ' . escapeshellarg($tmp));
});
