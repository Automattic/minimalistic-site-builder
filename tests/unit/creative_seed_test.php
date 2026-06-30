<?php
declare(strict_types=1);

/**
 * Unit tests for CreativeSeed and the per-build seed wiring in DesignDirectionStep.
 */

test('CreativeSeed::sample always returns a curated, non-empty seed', function () {
    $all = CreativeSeed::all();
    assert_true(count($all) > 1, 'more than one seed to choose from');
    for ($i = 0; $i < 30; $i++) {
        $seed = CreativeSeed::sample();
        assert_true($seed !== '', 'seed is non-empty');
        assert_true(in_array($seed, $all, true), 'seed comes from the curated list');
    }
});

test('design-direction samples a creative seed, records it in meta.json, and injects it', function () {
    $tmp = sys_get_temp_dir() . '/builder_seed_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy neighborhood bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Hearth & Crumb']);

    $llm = new FakeLlm();
    $llm->queueText('Brutalist-raw direction with one electric accent.');
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new DesignDirectionStep($llm, $renderer))->run($project);

    $seed = $project->readJson('meta.json')['creative_seed'] ?? '';
    assert_true($seed !== '', 'creative_seed persisted to meta.json');
    assert_true(in_array($seed, CreativeSeed::all(), true), 'persisted seed is a curated value');
    // The sampled seed must actually reach the rendered prompt.
    assert_contains($seed, $llm->calls[0]['prompt']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction honors a creative seed already set in meta.json', function () {
    $tmp = sys_get_temp_dir() . '/builder_seed_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy bakery', 'creative_seed' => 'sun-bleached and faded']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);

    $llm = new FakeLlm();
    $llm->queueText('A faded, sun-bleached direction.');
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new DesignDirectionStep($llm, $renderer))->run($project);

    assert_eq('sun-bleached and faded', $project->readJson('meta.json')['creative_seed'], 'pre-set seed kept');
    assert_contains('sun-bleached and faded', $llm->calls[0]['prompt']);

    exec('rm -rf ' . escapeshellarg($tmp));
});
