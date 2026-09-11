<?php
declare(strict_types=1);

use Automattic\SiteBuild\HeroComposition;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\SectionComposition;
use Automattic\SiteBuild\Steps\PagePlanStep;
use Automattic\SiteBuild\Tests\FakeLlm;

/** Return a complete section with an explicit image count. */
function image_bounds_section(string $slug, string $layout, int $count): array
{
    return [
        'slug' => $slug, 'title' => ucfirst($slug), 'type' => 'content',
        'purpose' => 'Keep this section.', 'content_notes' => "Keep the text for {$slug}.",
        'image_count' => $count, 'layout_archetype' => $layout, 'background' => 'base',
        'vertical_density' => 'standard', 'text_placement' => 'left-column',
        'handoff' => 'Keep the adjacent sections.', 'primary_action' => null, 'item_pattern' => null,
    ];
}

/** Check both bounds without a copy of the selection rule. */
function assert_image_bounds(array $sections): void
{
    foreach ($sections as $section) {
        $metadata = SectionComposition::metadata($section['layout_archetype']);
        assert_true($metadata['min_images'] <= $section['image_count'], $section['slug'] . ' meets the minimum');
        assert_true($section['image_count'] <= $metadata['max_images'], $section['slug'] . ' meets the maximum');
    }
}

test('normal and fallback layout choices preserve zero and one image', function () {
    foreach ([0, 1] as $count) {
        $raw = [
            image_bounds_section('hero', 'asymmetric-split', $count),
            image_bounds_section('story', 'asymmetric-split', $count),
            image_bounds_section('closing', 'cta-panel', 0),
        ];
        foreach (['normalize', 'repairVariety'] as $method) {
            $warnings = [];
            $out = $method === 'normalize'
                ? PagePlanStep::normalize($raw, false, null, [], $warnings, 'about')
                : PagePlanStep::repairVariety($raw, false, null, $warnings, 'about');
            assert_image_bounds($out);
            assert_eq(array_column($raw, 'image_count'), array_column($out, 'image_count'));
            assert_eq(array_column($raw, 'slug'), array_column($out, 'slug'));
            foreach ($out as $index => $section) {
                assert_contains($raw[$index]['content_notes'], $section['content_notes']);
            }
            assert_true($out[0]['layout_archetype'] !== $out[1]['layout_archetype']);
            $againWarnings = [];
            $again = $method === 'normalize'
                ? PagePlanStep::normalize($out, false, null, [], $againWarnings, 'about')
                : PagePlanStep::repairVariety($out, false, null, $againWarnings, 'about');
            assert_eq($out, $again);
            assert_eq([], $againWarnings);
        }
    }
});

test('media repair replaces layouts below their minimum and preserves sibling bytes', function () {
    foreach (['offset-grid', 'project-grid-2x2'] as $layout) {
        foreach ([0, 1] as $count) {
            $raw = [
                image_bounds_section('before', 'asymmetric-split', 0),
                image_bounds_section('collection', $layout, $count),
                image_bounds_section('after', 'cta-panel', 0),
            ];
            $warnings = [];
            $out = PagePlanStep::reconcileMediaAssignments($raw, false, $warnings, 'menu');
            assert_image_bounds($out);
            assert_eq($raw[0], $out[0]);
            assert_eq($raw[2], $out[2]);
            assert_eq($count, $out[1]['image_count']);
            assert_contains($raw[1]['content_notes'], $out[1]['content_notes']);
            assert_eq(1, count($warnings));
            foreach (['pages.json', "pages[slug='menu'].sections[1].layout_archetype", 'authored=', 'delivered=', 'disposition='] as $value) {
                assert_contains($value, $warnings[0]);
            }
            $againWarnings = [];
            assert_eq($out, PagePlanStep::reconcileMediaAssignments($out, false, $againWarnings, 'menu'));
            assert_eq([], $againWarnings);
        }
    }
});

test('a layout at its positive image minimum remains unchanged', function () {
    foreach (['offset-grid', 'project-grid-2x2'] as $layout) {
        $raw = [image_bounds_section('collection', $layout, 2)];
        $warnings = [];
        assert_eq($raw, PagePlanStep::reconcileMediaAssignments($raw, false, $warnings, 'menu'));
        assert_eq([], $warnings);
    }
    $warnings = [];
    $raw = [image_bounds_section('hero', 'asymmetric-split', 2), image_bounds_section('collection', 'asymmetric-split', 2)];
    $out = PagePlanStep::repairVariety($raw, false, null, $warnings, 'menu');
    assert_eq('offset-grid', $out[1]['layout_archetype']);
    assert_image_bounds($out);
});

test('an exhausted layout choice retains compatible sections and warns on each pass', function () {
    $raw = [];
    foreach (['asymmetric-split', 'equal-card-grid', 'asymmetric-split', 'equal-card-grid', 'asymmetric-split', 'equal-card-grid'] as $index => $layout) {
        $raw[] = image_bounds_section('gallery-' . $index, $layout, 12);
    }
    $raw[0]['background'] = 'tinted';
    foreach (['normalize', 'repairVariety'] as $method) {
        $warnings = [];
        $out = $method === 'normalize'
            ? PagePlanStep::normalize($raw, false, null, [], $warnings, 'gallery', allowOffsetGrid: false)
            : PagePlanStep::repairVariety($raw, false, null, $warnings, 'gallery', allowOffsetGrid: false);
        assert_image_bounds($out);
        assert_eq(array_column($raw, 'layout_archetype'), array_column($out, 'layout_archetype'));
        assert_eq(array_column($raw, 'content_notes'), array_column($out, 'content_notes'));
        assert_eq(array_column($raw, 'image_count'), array_column($out, 'image_count'));
        if ($method === 'repairVariety') {
            assert_eq($raw, $out);
        }
        $warning = implode("\n", $warnings);
        foreach (['pages.json', "pages[slug='gallery'].sections[0].layout_archetype", 'authored=', 'delivered=', 'disposition=', 'retained repeated layouts'] as $value) {
            assert_contains($value, $warning);
        }
        $againWarnings = [];
        $again = $method === 'normalize'
            ? PagePlanStep::normalize($out, false, null, [], $againWarnings, 'gallery', allowOffsetGrid: false)
            : PagePlanStep::repairVariety($out, false, null, $againWarnings, 'gallery', allowOffsetGrid: false);
        assert_eq($out, $again);
        assert_eq($warnings, $againWarnings);
    }
});

test('saved Tbilisi Home and About plans retain their text and image counts without a model repair', function () {
    $fixture = json_decode((string) file_get_contents(repo_path('tests/fixtures/page-plan-tbilisi6-media.json')), true, 512, JSON_THROW_ON_ERROR);
    with_project('image_bounds_saved_', function ($project) use ($fixture) {
        $project->writeJson('meta.json', ['prompt' => 'A traditional Georgian restaurant in Tbilisi Old Town.']);
        $project->writeJson('siteSpec.json', ['name' => 'Tbilisi Tavern', 'language' => 'en', 'pages' => [
            ['slug' => 'home', 'title' => 'Home', 'purpose' => 'Introduce the restaurant.'],
            ['slug' => 'about', 'title' => 'About', 'purpose' => 'Explain the restaurant traditions.'],
        ]]);
        seed_test_design_direction($project, overrides: ['rhythm' => 'offset', 'hero_blueprint' => $fixture['hero_blueprint']]);
        $llm = new FakeLlm();
        $step = new PagePlanStep($llm, new PromptRenderer(repo_path('prompts')));
        $step->consume($project, $fixture['pages']);
        $pages = $project->readJson('pages.json');
        foreach ($pages['pages'] as $page) {
            $raw = $fixture['pages'][$page['slug']]['sections'];
            assert_image_bounds($page['sections']);
            assert_eq(array_column($raw, 'image_count'), array_column($page['sections'], 'image_count'));
            assert_eq(array_column($raw, 'slug'), array_column($page['sections'], 'slug'));
            foreach ($page['sections'] as $index => $section) {
                assert_contains($raw[$index]['content_notes'], $section['content_notes']);
            }
            $projection = $page['front'] ? HeroComposition::planProjection($fixture['hero_blueprint']) : null;
            $warnings = [];
            assert_eq($page['sections'], PagePlanStep::normalize($page['sections'], $page['front'], $projection, [], $warnings, $page['slug']));
        }
        $warnings = implode("\n", $project->readJson('warnings.json')['page-plan']);
        assert_contains("pages[slug='home'].sections[1].layout_archetype", $warnings);
        assert_contains("pages[slug='about'].sections[0].layout_archetype", $warnings);
        $step->consume($project, $fixture['pages']);
        assert_eq($pages, $project->readJson('pages.json'));
        assert_eq([], $llm->calls);
    });
});
