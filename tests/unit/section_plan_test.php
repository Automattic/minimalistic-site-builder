<?php
declare(strict_types=1);

/**
 * Unit tests for SectionPlanStep: normalization (unique file-safe slugs,
 * defaults), the art-direction field validation (archetype/background enums,
 * adjacency rule, card-grid cap), and the end-to-end write of sections.json.
 */

/** A valid planned section; override fields per test. */
function plan_section(array $overrides = []): array
{
    return array_merge([
        'slug'             => 'hero',
        'title'            => 'Hero',
        'type'             => 'hero',
        'layout_archetype' => 'full-bleed-cover',
        'background'       => 'image',
        'handoff'          => 'Sits between the site header above and the base-background about split below.',
    ], $overrides);
}

test('SectionPlanStep::normalize forces unique, file-safe slugs and fills defaults', function () {
    $sections = SectionPlanStep::normalize([
        plan_section(['slug' => null]),                  // slug derived from title
        plan_section(['slug' => 'Our Story!', 'title' => 'About', 'layout_archetype' => 'asymmetric-split', 'background' => 'base']),
        plan_section(['title' => 'Another Hero', 'layout_archetype' => 'centered-stack', 'background' => 'contrast']), // duplicate slug -> hero-2
        'not-an-array',                                  // skipped
    ]);

    $slugs = array_column($sections, 'slug');
    assert_eq(['hero', 'our-story', 'hero-2'], $slugs);
    assert_eq('hero', $sections[0]['type'], 'type preserved');
});

test('SectionPlanStep::normalize keeps the art-direction fields on a valid plan', function () {
    $sections = SectionPlanStep::normalize([
        plan_section(),
        plan_section(['slug' => 'work', 'title' => 'Work', 'type' => 'gallery', 'layout_archetype' => 'offset-grid', 'background' => 'base', 'handoff' => 'Between the image hero above and the contrast CTA below.']),
        plan_section(['slug' => 'cta', 'title' => 'CTA', 'type' => 'cta', 'layout_archetype' => 'centered-stack', 'background' => 'contrast', 'handoff' => 'Between the base offset grid above and the footer below.']),
    ]);

    assert_eq(3, count($sections));
    assert_eq('full-bleed-cover', $sections[0]['layout_archetype']);
    assert_eq('image', $sections[0]['background']);
    assert_contains('site header', $sections[0]['handoff']);
});

test('SectionPlanStep::normalize rejects an unknown layout_archetype', function () {
    assert_throws(function () {
        SectionPlanStep::normalize([plan_section(['layout_archetype' => 'fancy-mosaic'])]);
    }, 'invalid layout_archetype');
});

test('SectionPlanStep::normalize rejects an unknown background', function () {
    assert_throws(function () {
        SectionPlanStep::normalize([plan_section(['background' => 'plaid'])]);
    }, 'invalid background');
});

test('SectionPlanStep::normalize rejects a missing handoff', function () {
    assert_throws(function () {
        SectionPlanStep::normalize([plan_section(['handoff' => '  '])]);
    }, 'handoff');
});

test('SectionPlanStep::normalize rejects adjacent duplicate archetypes', function () {
    assert_throws(function () {
        SectionPlanStep::normalize([
            plan_section(),
            plan_section(['slug' => 'work', 'layout_archetype' => 'equal-card-grid', 'background' => 'base']),
            plan_section(['slug' => 'team', 'layout_archetype' => 'equal-card-grid', 'background' => 'tinted']),
        ]);
    }, 'adjacent');
});

test('SectionPlanStep::normalize allows a repeated archetype when not adjacent', function () {
    $sections = SectionPlanStep::normalize([
        plan_section(['layout_archetype' => 'equal-card-grid', 'background' => 'base']),
        plan_section(['slug' => 'story', 'layout_archetype' => 'centered-stack', 'background' => 'tinted']),
        plan_section(['slug' => 'team', 'layout_archetype' => 'equal-card-grid', 'background' => 'base']),
    ]);
    assert_eq(3, count($sections));
});

test('SectionPlanStep::normalize reports every violation in one rejection', function () {
    try {
        SectionPlanStep::normalize([
            plan_section(['background' => 'plaid']),
            plan_section(['slug' => 'work', 'layout_archetype' => 'centered-stack', 'handoff' => '']),
            plan_section(['slug' => 'team', 'layout_archetype' => 'centered-stack']),
        ]);
        assert_true(false, 'expected the plan to be rejected');
    } catch (RuntimeException $e) {
        assert_contains("invalid background 'plaid'", $e->getMessage());
        assert_contains("missing 'handoff'", $e->getMessage());
        assert_contains('adjacent sections', $e->getMessage());
    }
});

test('SectionPlanStep::normalize does not report adjacency between invalid archetypes', function () {
    try {
        SectionPlanStep::normalize([
            plan_section(['layout_archetype' => 'fancy-mosaic']),
            plan_section(['slug' => 'work', 'layout_archetype' => 'fancy-mosaic']),
        ]);
        assert_true(false, 'expected the plan to be rejected');
    } catch (RuntimeException $e) {
        assert_contains('invalid layout_archetype', $e->getMessage());
        assert_true(!str_contains($e->getMessage(), 'adjacent'), 'enum error must not cascade into an adjacency error');
    }
});

test('SectionPlanStep::normalize caps equal-card-grid at twice per page', function () {
    assert_throws(function () {
        SectionPlanStep::normalize([
            plan_section(['layout_archetype' => 'equal-card-grid']),
            plan_section(['slug' => 'a', 'layout_archetype' => 'centered-stack']),
            plan_section(['slug' => 'b', 'layout_archetype' => 'equal-card-grid']),
            plan_section(['slug' => 'c', 'layout_archetype' => 'offset-grid']),
            plan_section(['slug' => 'd', 'layout_archetype' => 'equal-card-grid']),
        ]);
    }, 'equal-card-grid');
});

test('section-plan wires the spec language into its prompt', function () {
    $tmp = sys_get_temp_dir() . '/builder_spl_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo', 'language' => 'es-AR']);
    $renderer = new PromptRenderer(repo_path('prompts'));

    $reqs = (new SectionPlanStep(new FakeLlm(), $renderer))->requests($project);
    assert_contains('in es-AR', $reqs['section-plan']['prompt']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('section-plan writes sections.json', function () {
    $tmp = sys_get_temp_dir() . '/builder_sp_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo', 'sections' => ['Hero', 'About']]);

    $llm = new FakeLlm();
    $llm->queueJson(['sections' => [
        plan_section(),
        plan_section(['slug' => 'about', 'title' => 'About', 'type' => 'about', 'layout_archetype' => 'asymmetric-split', 'background' => 'base', 'handoff' => 'Between the image hero above and the footer below.']),
    ]]);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SectionPlanStep($llm, $renderer))->run($project);

    $plan = $project->readJson('sections.json');
    assert_eq(2, count($plan['sections']));
    assert_eq('hero', $plan['sections'][0]['slug']);
    assert_eq('asymmetric-split', $plan['sections'][1]['layout_archetype']);
    assert_eq('base', $plan['sections'][1]['background']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('section-plan repairs an invalid plan with one follow-up call', function () {
    $tmp = sys_get_temp_dir() . '/builder_spr_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);

    $llm = new FakeLlm();
    // First plan violates the adjacency rule…
    $llm->queueJson(['sections' => [
        plan_section(['layout_archetype' => 'centered-stack', 'background' => 'base']),
        plan_section(['slug' => 'cta', 'title' => 'CTA', 'type' => 'cta', 'layout_archetype' => 'centered-stack', 'background' => 'contrast']),
    ]]);
    // …the repair call returns a fixed one.
    $llm->queueJson(['sections' => [
        plan_section(['layout_archetype' => 'full-bleed-cover', 'background' => 'image']),
        plan_section(['slug' => 'cta', 'title' => 'CTA', 'type' => 'cta', 'layout_archetype' => 'centered-stack', 'background' => 'contrast']),
    ]]);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SectionPlanStep($llm, $renderer))->run($project);

    $plan = $project->readJson('sections.json');
    assert_eq('full-bleed-cover', $plan['sections'][0]['layout_archetype']);
    // The repair prompt carries the rejected plan and the specific error,
    // and the repair call is identifiable in the LLM logs.
    $repairPrompt = $llm->calls[1]['prompt'];
    assert_contains('IT WAS REJECTED', $repairPrompt);
    assert_contains('adjacent sections', $repairPrompt);
    assert_contains('also update its content_notes', $repairPrompt);
    assert_contains('affected neighbor handoffs', $repairPrompt);
    assert_eq('section-plan-repair', $llm->calls[1]['opts']['log_label'] ?? null);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('section-plan throws when the repair is still invalid', function () {
    $tmp = sys_get_temp_dir() . '/builder_spr2_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);

    $bad = ['sections' => [plan_section(['background' => 'plaid'])]];
    $llm = new FakeLlm();
    $llm->queueJson($bad);
    $llm->queueJson($bad);
    $renderer = new PromptRenderer(repo_path('prompts'));

    assert_throws(function () use ($llm, $renderer, $project) {
        (new SectionPlanStep($llm, $renderer))->run($project);
    });
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('section-plan repairs an empty plan and throws when the repair is empty too', function () {
    $tmp = sys_get_temp_dir() . '/builder_sp0_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);

    $llm = new FakeLlm();
    $llm->queueJson(['sections' => []]);
    $llm->queueJson(['sections' => []]);
    $renderer = new PromptRenderer(repo_path('prompts'));

    assert_throws(function () use ($llm, $renderer, $project) {
        (new SectionPlanStep($llm, $renderer))->run($project);
    }, 'no sections');
    // The empty plan went through the repair path before aborting.
    assert_eq(2, count($llm->calls));
    assert_contains('has no sections', $llm->calls[1]['prompt']);
    exec('rm -rf ' . escapeshellarg($tmp));
});
