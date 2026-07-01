<?php
declare(strict_types=1);

/**
 * Unit tests for SectionPlanStep: normalization (unique file-safe slugs, defaults)
 * and the end-to-end write of sections.json.
 */

test('SectionPlanStep::normalize forces unique, file-safe slugs and fills defaults', function () {
    $sections = SectionPlanStep::normalize([
        ['title' => 'Hero', 'type' => 'hero'],          // slug derived from title
        ['slug' => 'Our Story!', 'title' => 'About'],    // slugified
        ['slug' => 'hero', 'title' => 'Another Hero'],   // duplicate -> hero-2
        'not-an-array',                                  // skipped
    ]);

    $slugs = array_column($sections, 'slug');
    assert_eq(['hero', 'our-story', 'hero-2'], $slugs);
    assert_eq('hero', $sections[0]['type'], 'type preserved');
});

test('section-plan writes sections.json', function () {
    $tmp = sys_get_temp_dir() . '/builder_sp_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo', 'sections' => ['Hero', 'About']]);

    $llm = new FakeLlm();
    $llm->queueJson(['sections' => [
        ['slug' => 'hero', 'title' => 'Hero', 'type' => 'hero'],
        ['slug' => 'about', 'title' => 'About', 'type' => 'about'],
    ]]);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SectionPlanStep($llm, $renderer))->run($project);

    $plan = $project->readJson('sections.json');
    assert_eq(2, count($plan['sections']));
    assert_eq('hero', $plan['sections'][0]['slug']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('section-plan throws when the model returns no sections', function () {
    $tmp = sys_get_temp_dir() . '/builder_sp0_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);

    $llm = new FakeLlm();
    $llm->queueJson(['sections' => []]);
    $renderer = new PromptRenderer(repo_path('prompts'));

    assert_throws(function () use ($llm, $renderer, $project) {
        (new SectionPlanStep($llm, $renderer))->run($project);
    });
    exec('rm -rf ' . escapeshellarg($tmp));
});
