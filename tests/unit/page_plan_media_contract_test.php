<?php
declare(strict_types=1);

use Automattic\SiteBuild\Steps\PagePlanStep;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Tests\FakeLlm;

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
