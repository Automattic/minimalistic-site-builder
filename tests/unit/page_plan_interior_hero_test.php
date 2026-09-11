<?php
declare(strict_types=1);

use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\SectionComposition;
use Automattic\SiteBuild\Steps\PagePlanStep;
use Automattic\SiteBuild\Tests\FakeLlm;

function interior_cover_plan(): array
{
    return json_decode((string) file_get_contents(repo_path('tests/fixtures/page-plan-interior-cover.json')), true, 512, JSON_THROW_ON_ERROR);
}

test('interior hero repair preserves the recorded Contact text and images', function () {
    $raw = interior_cover_plan()['sections'];
    $warnings = [];
    $out = PagePlanStep::normalize($raw, false, null, [], $warnings, 'contact');
    assert_eq('asymmetric-split', $out[0]['layout_archetype']);
    foreach (['slug', 'title', 'type', 'purpose', 'image_count', 'background', 'vertical_density', 'text_placement'] as $field) {
        assert_eq($raw[0][$field], $out[0][$field], $field);
    }
    assert_true(str_starts_with($out[0]['content_notes'], $raw[0]['content_notes']));
    assert_true(str_starts_with($out[0]['handoff'], $raw[0]['handoff']));
    assert_contains('compact asymmetric-split', $out[0]['content_notes']);
    assert_contains('copper serving vessels', $out[0]['content_notes']);
    assert_contains('stone walls', $out[0]['content_notes']);
    assert_contains("file='pages.json'", implode("\n", $warnings));
    assert_contains('authored="full-bleed-cover"; delivered="asymmetric-split"', implode("\n", $warnings));
    assert_contains('disposition=', implode("\n", $warnings));
    $againWarnings = [];
    assert_eq($out, PagePlanStep::normalize($out, false, null, [], $againWarnings, 'contact'));
    assert_eq([], $againWarnings);
});

test('compact hero selection preserves valid sibling sections byte for byte', function () {
    $raw = interior_cover_plan()['sections'];
    $raw[1]['primary_action'] = null;
    $raw[2]['primary_action'] = null;
    $raw[0]['layout_archetype'] = 'asymmetric-split';
    $raw = PagePlanStep::normalize($raw, false);
    $raw[0]['layout_archetype'] = 'full-bleed-cover';
    $warnings = [];
    $out = PagePlanStep::normalize($raw, false, null, [], $warnings, 'contact');
    assert_eq(array_slice($raw, 1), array_slice($out, 1));
    assert_eq(1, count($warnings));
});

test('compact hero selection avoids a neighbor and preserves every supported image count', function () {
    foreach (range(0, 12) as $count) {
        $raw = interior_cover_plan()['sections'];
        $raw[0]['image_count'] = $count;
        $raw[1]['layout_archetype'] = 'asymmetric-split';
        $raw[1]['item_pattern'] = null;
        $raw[2]['primary_action'] = null;
        $warnings = [];
        $out = PagePlanStep::normalize($raw, false, null, [], $warnings, 'contact');
        assert_eq('equal-card-grid', $out[0]['layout_archetype']);
        assert_eq($count, $out[0]['image_count']);
        assert_eq('image', $out[0]['background']);
        assert_true(SectionComposition::metadata($out[0]['layout_archetype'])['max_images'] >= $count);
        assert_eq('asymmetric-split', $out[1]['layout_archetype']);
        assert_eq('cta-panel', $out[2]['layout_archetype']);
        $againWarnings = [];
        assert_eq($out, PagePlanStep::normalize($out, false, null, [], $againWarnings, 'contact'));
        assert_eq([], $againWarnings);
    }
});

test('an interior cover type reaches a fixed point after compact hero repair', function () {
    $raw = interior_cover_plan()['sections'];
    $raw[0]['type'] = 'full-bleed-cover';
    $warnings = [];
    $out = PagePlanStep::normalize($raw, false, null, [], $warnings, 'contact');
    assert_eq('hero', $out[0]['type']);
    $againWarnings = [];
    assert_eq($out, PagePlanStep::normalize($out, false, null, [], $againWarnings, 'contact'));
    assert_eq([], $againWarnings);
    assert_contains('.type', implode("\n", $warnings));
});

test('the recorded Contact plan needs no model repair and records the changed layout', function () {
    with_project('interior_cover_replay_', function ($project) {
        seed_test_design_direction($project);
        $project->writeJson('meta.json', ['prompt' => 'A Georgian restaurant.']);
        $project->writeJson('siteSpec.json', [
            'name' => 'Tbilisi Tavern', 'language' => 'en',
            'pages' => [
                ['slug' => 'home', 'title' => 'Home', 'purpose' => 'Welcome'],
                ['slug' => 'contact', 'title' => 'Contact', 'purpose' => 'Contact facts'],
            ],
        ]);
        $llm = new FakeLlm();
        $step = new PagePlanStep($llm, new PromptRenderer(repo_path('prompts')));
        $step->consume($project, ['contact' => interior_cover_plan()]);
        assert_eq([], $llm->calls, 'the saved Contact response needs no repair request');
        $pages = $project->readJson('pages.json')['pages'];
        $contact = array_values(array_filter($pages, fn ($page) => $page['slug'] === 'contact'))[0];
        assert_eq(['hero', 'contact-details', 'closing-cta'], array_column($contact['sections'], 'slug'));
        assert_eq('asymmetric-split', $contact['sections'][0]['layout_archetype']);
        assert_eq(1, $contact['sections'][0]['image_count']);
        $warnings = implode("\n", $project->readJson('warnings.json')['page-plan']);
        assert_contains("pages[slug='contact'].sections[0].layout_archetype", $warnings);
        assert_contains('authored="full-bleed-cover"; delivered="asymmetric-split"', $warnings);
    });
});

test('contact page briefs state the compact hero rule with and without a form host', function () {
    foreach ([false, true] as $forms) {
        $brief = PagePlanStep::emphasisFor(['slug' => 'contact', 'title' => 'Contact'], $forms);
        assert_contains('Never use full-bleed-cover for the first section.', $brief);
        assert_contains('image background with a compact archetype', $brief);
    }
});
