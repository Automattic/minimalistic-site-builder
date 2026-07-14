<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\PagePlanStep;
use Automattic\SiteBuild\Tests\FakeLlm;

/**
 * Unit tests for PagePlanStep: per-section normalization (unique file-safe
 * slugs, art-direction enums, adjacency rule, card-grid cap — unchanged from
 * the landing-page era), the page-tree flattening, the per-page request
 * fan-out, and the end-to-end write of pages.json.
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

/** A siteSpec with a two-page tree (home + menu with one child). */
function plan_spec(array $overrides = []): array
{
    return array_merge([
        'name'     => 'Demo',
        'language' => 'en',
        'sections' => ['Hero', 'About'],
        'pages'    => [
            ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome', 'children' => []],
            ['title' => 'Menu', 'slug' => 'menu', 'purpose' => 'What we bake', 'children' => [
                ['title' => 'Breads', 'slug' => 'breads', 'purpose' => 'Bread list', 'children' => []],
            ]],
        ],
    ], $overrides);
}

test('PagePlanStep::normalize forces unique, file-safe slugs and fills defaults', function () {
    $sections = PagePlanStep::normalize([
        plan_section(['slug' => null]),                  // slug derived from title
        plan_section(['slug' => 'Our Story!', 'title' => 'About', 'layout_archetype' => 'asymmetric-split', 'background' => 'base']),
        plan_section(['title' => 'Another Hero', 'layout_archetype' => 'centered-stack', 'background' => 'contrast']), // duplicate slug -> hero-2
        'not-an-array',                                  // skipped
    ]);

    $slugs = array_column($sections, 'slug');
    assert_eq(['hero', 'our-story', 'hero-2'], $slugs);
    assert_eq('hero', $sections[0]['type'], 'type preserved');
});

test('PagePlanStep::normalize keeps the art-direction fields on a valid plan', function () {
    $sections = PagePlanStep::normalize([
        plan_section(),
        plan_section(['slug' => 'work', 'title' => 'Work', 'type' => 'gallery', 'layout_archetype' => 'offset-grid', 'background' => 'base', 'handoff' => 'Between the image hero above and the contrast CTA below.']),
        plan_section(['slug' => 'cta', 'title' => 'CTA', 'type' => 'cta', 'layout_archetype' => 'centered-stack', 'background' => 'contrast', 'handoff' => 'Between the base offset grid above and the footer below.']),
    ]);

    assert_eq(3, count($sections));
    assert_eq('full-bleed-cover', $sections[0]['layout_archetype']);
    assert_eq('image', $sections[0]['background']);
    assert_contains('site header', $sections[0]['handoff']);
});

test('PagePlanStep::normalize rejects an unknown layout_archetype', function () {
    assert_throws(function () {
        PagePlanStep::normalize([plan_section(['layout_archetype' => 'fancy-mosaic'])]);
    }, 'invalid layout_archetype');
});

test('PagePlanStep::normalize rejects an unknown background', function () {
    assert_throws(function () {
        PagePlanStep::normalize([plan_section(['background' => 'plaid'])]);
    }, 'invalid background');
});

test('PagePlanStep::normalize rejects a missing handoff', function () {
    assert_throws(function () {
        PagePlanStep::normalize([plan_section(['handoff' => '  '])]);
    }, 'handoff');
});

test('PagePlanStep::normalize rejects adjacent duplicate archetypes', function () {
    assert_throws(function () {
        PagePlanStep::normalize([
            plan_section(),
            plan_section(['slug' => 'work', 'layout_archetype' => 'equal-card-grid', 'background' => 'base']),
            plan_section(['slug' => 'team', 'layout_archetype' => 'equal-card-grid', 'background' => 'tinted']),
        ]);
    }, 'adjacent');
});

test('PagePlanStep::normalize allows a repeated archetype when not adjacent', function () {
    $sections = PagePlanStep::normalize([
        plan_section(['layout_archetype' => 'equal-card-grid', 'background' => 'base']),
        plan_section(['slug' => 'story', 'layout_archetype' => 'centered-stack', 'background' => 'tinted']),
        plan_section(['slug' => 'team', 'layout_archetype' => 'equal-card-grid', 'background' => 'base']),
    ]);
    assert_eq(3, count($sections));
});

test('PagePlanStep::normalize reports every violation in one rejection', function () {
    try {
        PagePlanStep::normalize([
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

test('PagePlanStep::normalize does not report adjacency between invalid archetypes', function () {
    try {
        PagePlanStep::normalize([
            plan_section(['layout_archetype' => 'fancy-mosaic']),
            plan_section(['slug' => 'work', 'layout_archetype' => 'fancy-mosaic']),
        ]);
        assert_true(false, 'expected the plan to be rejected');
    } catch (RuntimeException $e) {
        assert_contains('invalid layout_archetype', $e->getMessage());
        assert_true(!str_contains($e->getMessage(), 'adjacent'), 'enum error must not cascade into an adjacency error');
    }
});

test('PagePlanStep::normalize caps equal-card-grid at twice per page', function () {
    assert_throws(function () {
        PagePlanStep::normalize([
            plan_section(['layout_archetype' => 'equal-card-grid']),
            plan_section(['slug' => 'a', 'layout_archetype' => 'centered-stack']),
            plan_section(['slug' => 'b', 'layout_archetype' => 'equal-card-grid']),
            plan_section(['slug' => 'c', 'layout_archetype' => 'offset-grid']),
            plan_section(['slug' => 'd', 'layout_archetype' => 'equal-card-grid']),
        ]);
    }, 'equal-card-grid');
});

test('PagePlanStep::repairVariety reassigns the later section of each adjacent duplicate pair', function () {
    $sections = PagePlanStep::repairVariety([
        plan_section(['slug' => 'a', 'layout_archetype' => 'centered-stack']),
        plan_section(['slug' => 'b', 'layout_archetype' => 'asymmetric-split']),
        plan_section(['slug' => 'c', 'layout_archetype' => 'asymmetric-split']),
    ]);

    assert_eq('centered-stack', $sections[0]['layout_archetype'], 'untouched');
    assert_eq('asymmetric-split', $sections[1]['layout_archetype'], 'first of the pair kept');
    assert_true($sections[2]['layout_archetype'] !== 'asymmetric-split', 'later section reassigned');
    PagePlanStep::normalize($sections); // the result passes validation
});

test('PagePlanStep::repairVariety fixes a run of three duplicates without creating new ones', function () {
    $sections = PagePlanStep::repairVariety([
        plan_section(['slug' => 'a', 'layout_archetype' => 'centered-stack']),
        plan_section(['slug' => 'b', 'layout_archetype' => 'centered-stack']),
        plan_section(['slug' => 'c', 'layout_archetype' => 'centered-stack']),
    ]);

    PagePlanStep::normalize($sections);
    assert_eq('centered-stack', $sections[0]['layout_archetype']);
    assert_eq('centered-stack', $sections[2]['layout_archetype'], 'non-adjacent repeat is allowed and kept');
});

test('PagePlanStep::repairVariety leaves a valid plan unchanged', function () {
    $raw = [
        plan_section(),
        plan_section(['slug' => 'work', 'layout_archetype' => 'offset-grid']),
        plan_section(['slug' => 'cta', 'layout_archetype' => 'centered-stack']),
    ];
    assert_eq(
        array_column($raw, 'layout_archetype'),
        array_column(PagePlanStep::repairVariety($raw), 'layout_archetype')
    );
});

test('PagePlanStep::repairVariety reassigns equal-card-grids beyond the cap to non-grids', function () {
    $sections = PagePlanStep::repairVariety([
        plan_section(['slug' => 'a', 'layout_archetype' => 'equal-card-grid']),
        plan_section(['slug' => 'b', 'layout_archetype' => 'centered-stack']),
        plan_section(['slug' => 'c', 'layout_archetype' => 'equal-card-grid']),
        plan_section(['slug' => 'd', 'layout_archetype' => 'offset-grid']),
        plan_section(['slug' => 'e', 'layout_archetype' => 'equal-card-grid']),
    ]);

    PagePlanStep::normalize($sections);
    $grids = array_filter(array_column($sections, 'layout_archetype'), fn ($a) => $a === 'equal-card-grid');
    assert_eq(2, count($grids), 'first two grids kept, the third reassigned');
    assert_eq('equal-card-grid', $sections[0]['layout_archetype']);
    assert_eq('equal-card-grid', $sections[2]['layout_archetype']);
});

test('PagePlanStep::repairVariety leaves invalid archetypes for normalize to reject', function () {
    $sections = PagePlanStep::repairVariety([
        plan_section(['slug' => 'a', 'layout_archetype' => 'fancy-mosaic']),
        plan_section(['slug' => 'b', 'layout_archetype' => 'fancy-mosaic']),
    ]);
    assert_eq(['fancy-mosaic', 'fancy-mosaic'], array_column($sections, 'layout_archetype'));
});

test('PagePlanStep::flattenPages walks the tree depth-first with paths and menu order', function () {
    $flat = PagePlanStep::flattenPages(plan_spec());

    assert_eq(['home', 'menu', 'breads'], array_column($flat, 'slug'));
    assert_eq('/', $flat[0]['path']);
    assert_eq(true, $flat[0]['front']);
    assert_eq(null, $flat[0]['parent']);
    assert_eq('/menu/', $flat[1]['path']);
    assert_eq(false, $flat[1]['front']);
    assert_eq('/menu/breads/', $flat[2]['path']);
    assert_eq('menu', $flat[2]['parent']);
    assert_eq([0, 10, 20], array_column($flat, 'menu_order'));
});

test('PagePlanStep::flattenPages paths front-page children under the front slug', function () {
    $flat = PagePlanStep::flattenPages(plan_spec(['pages' => [
        ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome', 'children' => [
            ['title' => 'Visit', 'slug' => 'visit', 'purpose' => 'Directions', 'children' => []],
        ]],
    ]]));

    assert_eq('/', $flat[0]['path']);
    // WordPress resolves the child's URI from its parent's post_name even
    // when the parent is the front page — advertising "/visit/" would 404.
    assert_eq('/home/visit/', $flat[1]['path']);
    assert_eq('home', $flat[1]['parent']);
});

test('PagePlanStep::flattenPages degrades a pageless spec to a single homepage', function () {
    $flat = PagePlanStep::flattenPages(['name' => 'Solo', 'description' => 'One thing.']);
    assert_eq(1, count($flat));
    assert_eq('home', $flat[0]['slug']);
    assert_eq(true, $flat[0]['front']);
    assert_eq('One thing.', $flat[0]['purpose']);
});

test('PagePlanStep::sitePagesList renders one line per page with path and front marker', function () {
    $list = PagePlanStep::sitePagesList(PagePlanStep::flattenPages(plan_spec()));
    assert_contains('"Home" — / (front page): Welcome', $list);
    assert_contains('"Menu" — /menu/: What we bake', $list);
    assert_contains('"Breads" — /menu/breads/: Bread list', $list);
});

test('page-plan fans out one request per page with per-page context', function () {
    $tmp = sys_get_temp_dir() . '/builder_ppl_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A bakery']);
    $project->writeJson('siteSpec.json', plan_spec(['language' => 'es-AR']));
    $renderer = new PromptRenderer(repo_path('prompts'));

    $reqs = (new PagePlanStep(new FakeLlm(), $renderer))->requests($project);

    assert_eq(['home', 'menu', 'breads'], array_keys($reqs));
    assert_contains('in es-AR', $reqs['home']['prompt']);
    assert_contains('front page', $reqs['home']['prompt']);          // front emphasis
    assert_contains('interior page', $reqs['menu']['prompt']);       // interior emphasis
    assert_contains('"Menu"', $reqs['menu']['prompt']);              // its own title
    assert_contains('What we bake', $reqs['menu']['prompt']);        // its own purpose
    assert_contains('/menu/breads/', $reqs['menu']['prompt']);       // site pages list

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('page-plan writes pages.json with sections per page', function () {
    $tmp = sys_get_temp_dir() . '/builder_pp_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A bakery']);
    $project->writeJson('siteSpec.json', plan_spec());

    $llm = new FakeLlm();
    // One response per page, in flattened order: home, menu, breads.
    $llm->queueJson(['sections' => [
        plan_section(),
        plan_section(['slug' => 'cta', 'title' => 'CTA', 'type' => 'cta', 'layout_archetype' => 'centered-stack', 'background' => 'contrast', 'handoff' => 'Between the image hero above and the footer below.']),
    ]]);
    $llm->queueJson(['sections' => [
        plan_section(['slug' => 'menu-hero', 'title' => 'Menu Hero', 'layout_archetype' => 'centered-stack', 'background' => 'tinted', 'handoff' => 'Between the site header above and the bread list below.']),
        plan_section(['slug' => 'breads', 'title' => 'Breads', 'type' => 'features', 'layout_archetype' => 'list-with-thumbnails', 'background' => 'base', 'handoff' => 'Between the tinted menu hero above and the footer below.']),
    ]]);
    $llm->queueJson(['sections' => [
        plan_section(['slug' => 'bread-list', 'title' => 'Bread List', 'type' => 'features', 'layout_archetype' => 'list-with-thumbnails', 'background' => 'base', 'handoff' => 'Between the site header above and the footer below.']),
    ]]);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new PagePlanStep($llm, $renderer))->run($project);

    $plan = $project->readJson('pages.json');
    assert_eq(3, count($plan['pages']));
    assert_eq('home', $plan['pages'][0]['slug']);
    assert_eq(true, $plan['pages'][0]['front']);
    assert_eq('hero', $plan['pages'][0]['sections'][0]['slug']);
    assert_eq('menu', $plan['pages'][1]['slug']);
    assert_eq('/menu/', $plan['pages'][1]['path']);
    assert_eq('menu-hero', $plan['pages'][1]['sections'][0]['slug']);
    assert_eq('menu', $plan['pages'][2]['parent']);
    assert_eq(20, $plan['pages'][2]['menu_order']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('page-plan repairs only the invalid page with one follow-up call', function () {
    $tmp = sys_get_temp_dir() . '/builder_ppr_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A bakery']);
    $project->writeJson('siteSpec.json', plan_spec(['pages' => [
        ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome', 'children' => []],
        ['title' => 'Menu', 'slug' => 'menu', 'purpose' => 'What we bake', 'children' => []],
    ]]));

    $llm = new FakeLlm();
    // home plan is valid…
    $llm->queueJson(['sections' => [plan_section()]]);
    // …menu plan violates the adjacency rule…
    $llm->queueJson(['sections' => [
        plan_section(['slug' => 'a', 'layout_archetype' => 'centered-stack', 'background' => 'base']),
        plan_section(['slug' => 'b', 'layout_archetype' => 'centered-stack', 'background' => 'contrast']),
    ]]);
    // …and the repair call returns a fixed menu plan.
    $llm->queueJson(['sections' => [
        plan_section(['slug' => 'a', 'layout_archetype' => 'full-bleed-cover', 'background' => 'image']),
        plan_section(['slug' => 'b', 'layout_archetype' => 'centered-stack', 'background' => 'contrast']),
    ]]);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new PagePlanStep($llm, $renderer))->run($project);

    $plan = $project->readJson('pages.json');
    assert_eq('full-bleed-cover', $plan['pages'][1]['sections'][0]['layout_archetype']);
    // Batch (2 calls) + one repair; the repair prompt carries the rejected
    // plan and the specific error, labeled per page in the LLM logs.
    assert_eq(3, count($llm->calls));
    $repairPrompt = $llm->calls[2]['prompt'];
    assert_contains('IT WAS REJECTED', $repairPrompt);
    assert_contains('adjacent sections', $repairPrompt);
    assert_contains('change only ONE of the two sections', $repairPrompt);
    assert_contains('also update its content_notes', $repairPrompt);
    assert_eq('page-plan-menu-repair', $llm->calls[2]['opts']['log_label'] ?? null);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('page-plan falls back to a mechanical fix when the repair still breaks a variety rule', function () {
    $tmp = sys_get_temp_dir() . '/builder_ppv_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A portfolio']);
    $project->writeJson('siteSpec.json', plan_spec(['pages' => [
        ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome', 'children' => []],
    ]]));

    $llm = new FakeLlm();
    // The plan has adjacent duplicates…
    $llm->queueJson(['sections' => [
        plan_section(['slug' => 'credibility-block', 'layout_archetype' => 'centered-stack', 'background' => 'base']),
        plan_section(['slug' => 'closing-cta', 'layout_archetype' => 'centered-stack', 'background' => 'contrast']),
    ]]);
    // …and the repair fumbles it by moving BOTH sections to the same new archetype.
    $llm->queueJson(['sections' => [
        plan_section(['slug' => 'credibility-block', 'layout_archetype' => 'asymmetric-split', 'background' => 'base']),
        plan_section(['slug' => 'closing-cta', 'layout_archetype' => 'asymmetric-split', 'background' => 'contrast']),
    ]]);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new PagePlanStep($llm, $renderer))->run($project);

    $sections = $project->readJson('pages.json')['pages'][0]['sections'];
    assert_eq(2, count($llm->calls), 'no second LLM repair — the fallback is mechanical');
    assert_eq('asymmetric-split', $sections[0]['layout_archetype'], 'first of the pair kept');
    assert_true($sections[1]['layout_archetype'] !== 'asymmetric-split', 'later section reassigned');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('page-plan throws when the repair is still invalid beyond the variety rules', function () {
    $tmp = sys_get_temp_dir() . '/builder_ppr2_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A bakery']);
    $project->writeJson('siteSpec.json', plan_spec(['pages' => [
        ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome', 'children' => []],
    ]]));

    $bad = ['sections' => [plan_section(['background' => 'plaid'])]];
    $llm = new FakeLlm();
    $llm->queueJson($bad);
    $llm->queueJson($bad);
    $renderer = new PromptRenderer(repo_path('prompts'));

    assert_throws(function () use ($llm, $renderer, $project) {
        (new PagePlanStep($llm, $renderer))->run($project);
    });
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('page-plan repairs an empty page plan and throws when the repair is empty too', function () {
    $tmp = sys_get_temp_dir() . '/builder_pp0_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A bakery']);
    $project->writeJson('siteSpec.json', plan_spec(['pages' => [
        ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome', 'children' => []],
    ]]));

    $llm = new FakeLlm();
    $llm->queueJson(['sections' => []]);
    $llm->queueJson(['sections' => []]);
    $renderer = new PromptRenderer(repo_path('prompts'));

    assert_throws(function () use ($llm, $renderer, $project) {
        (new PagePlanStep($llm, $renderer))->run($project);
    }, 'no sections');
    // The empty plan went through the repair path before aborting.
    assert_eq(2, count($llm->calls));
    assert_contains('has no sections', $llm->calls[1]['prompt']);
    exec('rm -rf ' . escapeshellarg($tmp));
});
