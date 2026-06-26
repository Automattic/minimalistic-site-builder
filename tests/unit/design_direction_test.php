<?php
declare(strict_types=1);

test('design-direction writes designDirection.json and injects spec', function () {
    $tmp = sys_get_temp_dir() . '/builder_dd_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('siteSpec.json', [
        'name' => 'Hearth & Crumb',
        'slug' => 'hearth-crumb',
        'colors' => ['primary' => '#8a5a2b'],
    ]);

    $llm = new FakeLlm();
    $llm->queueJson(['concept' => 'Warm artisanal bakery feel', 'do' => ['use cream backgrounds']]);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new DesignDirectionStep($llm, $renderer))->run($project);

    $dd = $project->readJson('designDirection.json');
    assert_contains('Warm artisanal', $dd['concept']);
    // The spec must be injected into the prompt.
    assert_contains('Hearth & Crumb', $llm->calls[0]['prompt']);
    assert_contains('#8a5a2b', $llm->calls[0]['prompt']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction throws when concept missing', function () {
    $tmp = sys_get_temp_dir() . '/builder_dd_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('siteSpec.json', ['name' => 'X']);
    $llm = new FakeLlm();
    $llm->queueJson(['palette' => []]);
    $renderer = new PromptRenderer(repo_path('prompts'));
    assert_throws(function () use ($llm, $renderer, $project) {
        (new DesignDirectionStep($llm, $renderer))->run($project);
    });
    exec('rm -rf ' . escapeshellarg($tmp));
});
