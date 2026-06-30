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
    assert_eq(false, $sections[0]['wants_image'], 'wants_image defaults to false');
    assert_eq('centered', $sections[0]['layout'], 'layout defaults to centered when omitted');
});

test('SectionPlanStep::layout keeps known treatments and falls back to centered', function () {
    assert_eq('image-left', SectionPlanStep::layout('image-left'));
    assert_eq('full-bleed', SectionPlanStep::layout('Full-Bleed'), 'case-insensitive');
    assert_eq('split-screen', SectionPlanStep::layout('  split-screen  '), 'trimmed');
    assert_eq('centered', SectionPlanStep::layout('diagonal-collage'), 'unknown -> centered');
    assert_eq('centered', SectionPlanStep::layout(null), 'missing -> centered');
});

test('SectionPlanStep::normalize preserves a valid requested layout', function () {
    $sections = SectionPlanStep::normalize([
        ['slug' => 'hero', 'title' => 'Hero', 'type' => 'hero', 'layout' => 'full-bleed'],
        ['slug' => 'work', 'title' => 'Work', 'type' => 'gallery', 'layout' => 'asymmetric-grid'],
    ]);
    assert_eq('full-bleed', $sections[0]['layout']);
    assert_eq('asymmetric-grid', $sections[1]['layout']);
});

test('section-plan writes sections.json', function () {
    $tmp = sys_get_temp_dir() . '/builder_sp_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo', 'sections' => ['Hero', 'About']]);

    $llm = new FakeLlm();
    $llm->queueJson(['sections' => [
        ['slug' => 'hero', 'title' => 'Hero', 'type' => 'hero', 'wants_image' => true],
        ['slug' => 'about', 'title' => 'About', 'type' => 'about'],
    ]]);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SectionPlanStep($llm, $renderer))->run($project);

    $plan = $project->readJson('sections.json');
    assert_eq(2, count($plan['sections']));
    assert_eq('hero', $plan['sections'][0]['slug']);
    assert_eq(true, $plan['sections'][0]['wants_image']);

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
