<?php
declare(strict_types=1);

use Automattic\SiteBuild\Steps\PagePlanStep;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Tests\FakeLlm;

test('the page plan schema uses provider-supported bounds', function () {
    $schema = PagePlanStep::jsonSchema();
    $count = $schema['properties']['sections']['items']['properties']['image_count'];
    assert_eq('integer', $count['type']);
    assert_eq(range(0, 12), $count['enum']);
    $check = function (array $node) use (&$check): void {
        foreach ($node as $key => $value) {
            assert_true(!in_array($key, ['minimum', 'maximum', 'minLength', 'maxLength'], true));
            if (is_array($value)) {
                $check($value);
            }
        }
    };
    $check($schema);
});

test('a product gallery preserves six photographs before markup generation', function () {
    with_project('builder_media_contract_', function ($project) {
        $project->writeJson('meta.json', ['prompt' => 'A lamp studio with six product photographs.']);
        $project->writeJson('siteSpec.json', ['name' => 'Lumen', 'pages' => [
            ['slug' => 'home', 'title' => 'Home', 'purpose' => 'Show the collection'],
        ]]);
        seed_test_design_direction($project);
        $collection = [
            'slug' => 'collection', 'title' => 'Collection', 'type' => 'gallery',
            'purpose' => 'Show the current lamps.',
            'content_notes' => 'Show six lamp photographs, each with its name and dimensions.',
            'image_count' => 6,
            'layout_archetype' => 'feature-row-hairlines', 'background' => 'tinted',
            'vertical_density' => 'standard', 'text_placement' => 'left-column',
            'item_pattern' => 'rule-row', 'primary_action' => null,
            'handoff' => 'The collection follows the hero and precedes the process.',
        ];
        $hero = array_replace($collection, [
            'slug' => 'hero', 'type' => 'hero', 'image_count' => 1,
            'layout_archetype' => 'full-bleed-cover', 'background' => 'image', 'item_pattern' => null,
        ]);
        $closing = array_replace($collection, [
            'slug' => 'contact', 'type' => 'contact', 'image_count' => 0,
            'layout_archetype' => 'asymmetric-split', 'background' => 'base', 'item_pattern' => null,
        ]);
        $llm = new FakeLlm();
        $llm->queueJson(['sections' => [$hero, $collection, $closing]]);
        (new PagePlanStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);
        assert_eq(1, count($llm->calls));
        $section = $project->readJson('pages.json')['pages'][0]['sections'][1];
        assert_eq('equal-card-grid', $section['layout_archetype']);
        assert_eq('card', $section['item_pattern']);
        assert_eq(6, $section['image_count']);
        assert_contains($collection['content_notes'], $section['content_notes']);
        assert_contains('preserve all 6 requested images', $section['content_notes']);
        $warnings = implode("\n", $project->readJson('warnings.json')['page-plan']);
        assert_contains('pages.json', $warnings);
        assert_contains('feature-row-hairlines', $warnings);
        assert_contains('equal-card-grid', $warnings);
        assert_contains('sections[1].layout_archetype', $warnings);
    });
});

test('media repair keeps siblings intact and reaches a fixed point', function () {
    $sibling = ['slug' => 'story', 'layout_archetype' => 'asymmetric-split', 'content_notes' => 'Keep these bytes.'];
    $sections = [$sibling, [
        'slug' => 'collection', 'layout_archetype' => 'feature-row-hairlines',
        'image_count' => 8, 'content_notes' => 'Eight different product photographs.', 'handoff' => 'After the story.',
    ], $sibling];
    $warnings = [];
    $out = PagePlanStep::reconcileMediaAssignments($sections, false, $warnings, 'home');
    assert_eq($sibling, $out[0]);
    assert_eq($sibling, $out[2]);
    assert_eq(1, count($warnings));
    $nextWarnings = [];
    assert_eq($out, PagePlanStep::reconcileMediaAssignments($out, false, $nextWarnings, 'home'));
    assert_eq([], $nextWarnings);
    $textOnly = [$sections[1] + ['unused' => true]];
    $textOnly[0]['image_count'] = 0;
    assert_eq($textOnly, PagePlanStep::reconcileMediaAssignments($textOnly));
});

test('the media contract supports every count through twelve images', function () {
    foreach (range(9, 12) as $count) {
        $warnings = [];
        $sections = [['slug' => 'collection', 'layout_archetype' => 'feature-row-hairlines', 'image_count' => $count]];
        $out = PagePlanStep::reconcileMediaAssignments($sections, false, $warnings, 'home', false);
        assert_eq('equal-card-grid', $out[0]['layout_archetype']);
        assert_eq($count, $out[0]['image_count']);
        assert_contains("preserve all {$count} requested images", $out[0]['content_notes']);
    }
});

test('an out-of-schema image count records its delivered limit', function () {
    $warnings = [];
    $out = PagePlanStep::normalize([[
        'slug' => 'collection', 'title' => 'Collection', 'type' => 'gallery', 'image_count' => 13,
        'layout_archetype' => 'feature-row-hairlines', 'background' => 'base',
        'vertical_density' => 'standard', 'text_placement' => 'left-column', 'handoff' => 'After the header.',
    ]], false, null, [], $warnings, 'collection');
    assert_eq(12, $out[0]['image_count']);
    assert_eq('equal-card-grid', $out[0]['layout_archetype']);
    $warning = implode("\n", $warnings);
    assert_contains('.image_count', $warning);
    assert_contains('authored=13', $warning);
    assert_contains('delivered=12', $warning);
});

/** Return a complete section for the media boundary tests. */
function media_boundary_section(string $slug, string $layout, int $count): array
{
    return ['slug' => $slug, 'title' => ucfirst($slug), 'type' => $count > 0 ? 'gallery' : 'content',
        'purpose' => 'Show the restaurant.', 'content_notes' => "Keep the content for {$slug}.", 'image_count' => $count,
        'layout_archetype' => $layout, 'background' => $slug === 'intro' ? 'tinted' : 'base',
        'vertical_density' => 'standard', 'text_placement' => 'left-column', 'handoff' => 'After the previous section.',
        'primary_action' => null, 'item_pattern' => null];
}

test('adjacent eight-photo galleries retain all sections through recovery', function () {
    $raw = [media_boundary_section('intro', 'asymmetric-split', 0),
        media_boundary_section('food', 'feature-row-hairlines', 8),
        media_boundary_section('rooms', 'stat-ledger', 8),
        media_boundary_section('visit', 'asymmetric-split', 0)];
    $warnings = [];
    $repairs = [];
    $out = PagePlanStep::normalize($raw, false, null, [], $warnings, 'gallery', $repairs, false);
    assert_eq(array_column($raw, 'slug'), array_column($out, 'slug'));
    assert_eq([0, 8, 8, 0], array_column($out, 'image_count'));
    foreach ($out as $index => $section) {
        assert_contains($raw[$index]['content_notes'], $section['content_notes']);
    }
    assert_eq($out, PagePlanStep::normalize($out, false, null, [], $warnings, 'gallery', $repairs, false));
    $recovered = PagePlanStep::recoverSections($raw, false, $warnings, 'gallery', null, [], $repairs, false);
    assert_eq(array_column($raw, 'slug'), array_column($recovered, 'slug'));
    assert_eq([0, 8, 8, 0], array_column($recovered, 'image_count'));
    assert_contains('layout_archetype', implode("\n", $warnings));
});

test('an impossible gallery layout budget retains all content', function () {
    $raw = [];
    foreach (range(1, 6) as $index) {
        $raw[] = media_boundary_section('gallery-' . $index, 'equal-card-grid', 12);
    }
    $warnings = [];
    $repairs = [];
    $out = PagePlanStep::normalize($raw, false, null, [], $warnings, 'photos', $repairs, false);
    assert_eq(array_column($raw, 'slug'), array_column($out, 'slug'));
    assert_eq(72, array_sum(array_column($out, 'image_count')));
    assert_contains('retained repeated layouts', implode("\n", $warnings));
    assert_eq($out, PagePlanStep::normalize($out, false, null, [], $warnings, 'photos', $repairs, false));
});
