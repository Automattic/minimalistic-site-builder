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
    assert_eq('none', $sections[0]['pattern'], 'pattern defaults to none');
});

test('SectionPlanStep::normalize accepts known CSS-catalog patterns and rejects others', function () {
    $sections = SectionPlanStep::normalize([
        ['slug' => 'a', 'pattern' => 'scroll-row'],
        ['slug' => 'b', 'pattern' => 'scroll_row'],   // underscore tolerated
        ['slug' => 'c', 'pattern' => 'Stacked Cards'], // spaces + case tolerated
        ['slug' => 'd', 'pattern' => 'rainbow'],       // unknown -> none
        ['slug' => 'e'],                               // missing -> none
    ]);
    assert_eq('scroll-row', $sections[0]['pattern']);
    assert_eq('scroll-row', $sections[1]['pattern']);
    assert_eq('stacked-cards', $sections[2]['pattern']);
    assert_eq('none', $sections[3]['pattern']);
    assert_eq('none', $sections[4]['pattern']);
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
