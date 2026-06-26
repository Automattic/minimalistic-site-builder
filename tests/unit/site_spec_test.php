<?php
declare(strict_types=1);

/** @return array{0:Project,1:FakeLlm,2:string} */
function make_sitespec_fixture(): array
{
    $tmp = sys_get_temp_dir() . '/builder_sitespec_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy neighborhood bakery']);
    return [$project, new FakeLlm(), $tmp];
}

test('site-spec writes a factual, normalized siteSpec.json', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $llm->queueJson([
        'name' => 'Hearth & Crumb',
        // slug intentionally omitted -> derived from name
        'site_type' => 'bakery storefront',
        'topic' => 'artisan sourdough and pastries',
        'area' => 'bakery',
        'audience' => 'neighborhood locals',
        'visual_vibe' => 'warm and rustic',
        'sections' => ['Hero', 'Menu', 'About', 'Visit'],
        // An extra factual field the user stated — must pass through.
        'hours' => 'Tue–Sun 7am–3pm',
    ]);

    $renderer = new PromptRenderer(repo_path('prompts'));
    (new SiteSpecStep($llm, $renderer))->run($project);

    $spec = $project->readJson('siteSpec.json');
    assert_eq('Hearth & Crumb', $spec['name']);
    assert_eq('hearth-crumb', $spec['slug']);            // derived + slugified
    assert_eq('Hearth & Crumb', $spec['title']);         // title falls back to name
    assert_eq('warm and rustic', $spec['visual_vibe']);
    assert_true(is_array($spec['sections']));
    assert_eq('Hero', $spec['sections'][0]);
    assert_eq('Tue–Sun 7am–3pm', $spec['hours']);        // arbitrary fact preserved

    // No design fields should be invented/filled.
    assert_true(!isset($spec['colors']), 'no colors in factual spec');
    assert_true(!isset($spec['typography']), 'no typography in factual spec');
    assert_true(!isset($spec['layout']), 'no layout in factual spec');

    // The rendered prompt must carry the user's words.
    assert_contains('cozy neighborhood bakery', $llm->calls[0]['prompt']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec fills missing fixed properties with empty strings', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $llm->queueJson(['name' => 'Solo']); // only a name
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SiteSpecStep($llm, $renderer))->run($project);

    $spec = $project->readJson('siteSpec.json');
    assert_eq('Solo', $spec['name']);
    foreach (['title', 'site_type', 'topic', 'area', 'audience', 'visual_vibe'] as $key) {
        assert_true(array_key_exists($key, $spec), "{$key} key present");
    }
    assert_eq([], $spec['sections']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec throws when name missing', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $llm->queueJson(['topic' => 'no name here']);
    $renderer = new PromptRenderer(repo_path('prompts'));
    assert_throws(function () use ($llm, $renderer, $project) {
        (new SiteSpecStep($llm, $renderer))->run($project);
    });
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec throws when meta prompt missing', function () {
    $tmp = sys_get_temp_dir() . '/builder_sitespec_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => '']);
    $renderer = new PromptRenderer(repo_path('prompts'));
    assert_throws(function () use ($renderer, $project) {
        (new SiteSpecStep(new FakeLlm(), $renderer))->run($project);
    });
    exec('rm -rf ' . escapeshellarg($tmp));
});
