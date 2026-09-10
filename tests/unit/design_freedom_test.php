<?php
declare(strict_types=1);

use Automattic\SiteBuild\DesignFloor;
use Automattic\SiteBuild\HeroCopyBudget;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\PagePlanStep;
use Automattic\SiteBuild\Tests\FakeLlm;

/** @return list<array<string,mixed>> */
function freedom_plan(string $background = 'base'): array
{
    return array_map(static fn (int $i): array => [
        'slug' => 'chapter-' . $i,
        'title' => 'Chapter ' . $i,
        'type' => 'story',
        'purpose' => 'Read the next chapter.',
        'content_notes' => 'A continuous editorial sequence, using repetition deliberately.',
        'layout_archetype' => 'asymmetric-split',
        'background' => $background,
        'vertical_density' => 'spacious',
        'item_pattern' => null,
        'text_placement' => 'centered',
        'handoff' => 'Continue the same reading surface and spatial rhythm.',
        'primary_action' => null,
    ], range(1, 6));
}

test('design freedom preserves intentional repetition, uninterrupted surfaces and spacious sequences', function () {
    foreach (['base', 'tinted', 'contrast'] as $surface) {
        $plan = freedom_plan($surface);
        $warnings = $repairs = [];
        $out = PagePlanStep::normalize($plan, true, null, [], $warnings, 'home', $repairs);
        foreach ($out as &$section) {
            unset($section['role']);
        }
        unset($section);
        assert_eq($plan, $out, 'aesthetic choices and the prose that explains them survive');
        assert_eq([], $warnings);
        assert_eq([], $repairs);
    }
});

test('design freedom still rejects an unsupported layout instead of treating it as a creative choice', function () {
    $plan = freedom_plan();
    $plan[2]['layout_archetype'] = 'unsupported-orbit';
    $error = assert_throws(fn () => PagePlanStep::normalize($plan));
    assert_contains('invalid layout_archetype', $error->getMessage());
});

test('layout compatibility repair preserves surfaces, dense spacing and repeated grids at every page length', function () {
    foreach ([2, 4, 5, 6, 8, 12] as $length) {
        foreach (['base', 'tinted', 'contrast', 'image'] as $surface) {
            $plan = array_fill(0, $length, freedom_plan($surface)[0]);
            foreach ($plan as $i => &$section) {
                $section['slug'] = 'section-' . $i;
                $section['type'] = 'pricing-table';
                $section['layout_archetype'] = 'equal-card-grid';
            }
            unset($section);
            $warnings = $repairs = [];
            $out = PagePlanStep::repairLayoutCompatibility($plan, false, null, $warnings, 'catalog', $repairs);
            assert_eq($plan, $out, 'no forced band, surface cap, layout cap or density demotion');
            assert_eq([], $warnings);
            assert_eq([], $repairs);
            $normalized = PagePlanStep::normalize($out, false);
            assert_eq($normalized, PagePlanStep::normalize($normalized, false));
        }
    }
});

test('a page plan persists mixed item idioms despite a different site preference without retrying', function () {
    $tmp = sys_get_temp_dir() . '/builder_design_freedom_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A field guide']);
    seed_test_design_direction($project);
    $direction = $project->readJson('designDirection.json');
    $direction['item_pattern'] = 'card';
    $project->writeJson('designDirection.json', $direction);
    $project->writeJson('siteSpec.json', [
        'name' => 'Field guide', 'description' => 'Notes from the field',
        'pages' => [['title' => 'Home', 'slug' => 'home', 'purpose' => 'Read the guide', 'children' => []]],
    ]);
    $sections = freedom_plan();
    $patterns = [null, 'rule-row', 'spec-table', 'tag-cluster', 'rule-row', 'card'];
    foreach ($patterns as $i => $pattern) {
        $sections[$i]['item_pattern'] = $pattern;
    }
    $llm = new FakeLlm();
    $llm->queueJson(['sections' => $sections]);
    (new PagePlanStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);
    $out = $project->readJson('pages.json')['pages'][0]['sections'];
    assert_eq($patterns, array_column($out, 'item_pattern'));
    assert_eq(array_column($sections, 'content_notes'), array_column($out, 'content_notes'));
    assert_eq(array_fill(0, 6, 'spacious'), array_column($out, 'vertical_density'));
    assert_eq(1, count($llm->calls), 'valid aesthetic choices cost no repair call');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design freedom preserves different item presentations and unassigned prose within one page', function () {
    $sections = freedom_plan();
    foreach (['rule-row', 'spec-table', 'card', 'tag-cluster', null, 'rule-row'] as $i => $pattern) {
        $sections[$i]['item_pattern'] = $pattern;
        $sections[$i]['type'] = ['menu', 'technical-facts', 'team', 'skills', 'story', 'pricing'][$i];
    }
    $warnings = $repairs = [];
    $delivered = PagePlanStep::normalize($sections, true, null, [], $warnings, 'home', $repairs);
    assert_eq(array_column($sections, 'item_pattern'), array_column($delivered, 'item_pattern'));
    assert_eq(array_column($sections, 'content_notes'), array_column($delivered, 'content_notes'));
    assert_eq([], $repairs, 'valid per-section choices are not commitment drift');
    assert_eq([], $warnings);
});

test('non-card item patterns keep a supported split without contradictory repair notes', function () {
    foreach (['rule-row', 'spec-table', 'tag-cluster'] as $pattern) {
        $sections = freedom_plan();
        foreach ($sections as &$section) {
            $section['type'] = 'services';
            $section['item_pattern'] = $pattern;
        }
        unset($section);
        $pages = [['slug' => 'services', 'front' => false, 'sections' => $sections]];
        $repairs = [];
        $out = PagePlanStep::withListsOffTheSplit($pages, $repairs);
        assert_eq($pages, $out);
        assert_eq([], $repairs);
        assert_eq($out, PagePlanStep::withListsOffTheSplit($out, $repairs));
        assert_eq([], $repairs);
    }
});

test('design freedom keeps one caption label alongside the hero headline and standfirst', function () {
    $label = '<!-- wp:paragraph {"fontSize":"caption"} --><p>Field notes</p><!-- /wp:paragraph -->';
    $heading = '<!-- wp:heading {"level":1} --><h1>Reading the landscape</h1><!-- /wp:heading -->';
    $support = '<!-- wp:paragraph --><p>Stories from the places we walk.</p><!-- /wp:paragraph -->';
    $markup = '<!-- wp:group {"className":"hero-composition__copy"} -->'
        . '<div class="hero-composition__copy">' . $label . $heading . $support . '</div><!-- /wp:group -->';
    $result = HeroCopyBudget::enforce($markup, null, 'home-hero');
    assert_eq($markup, $result['markup']);
    assert_eq([], $result['warnings']);
    assert_eq($result, HeroCopyBudget::enforce($result['markup'], null, 'home-hero'));
    $rules = array_column(DesignFloor::check($markup, []), 'rule');
    assert_true(!in_array('kicker-above-heading', $rules, true), 'a caption is not inherently a design defect');
});

test('scoped HTML-first fallback planning also preserves per-section item choices', function () {
    $tmp = sys_get_temp_dir() . '/builder_design_freedom_subset_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A field guide']);
    seed_test_design_direction($project, overrides: ['item_pattern' => 'card']);
    $project->writeJson('siteSpec.json', [
        'name' => 'Field guide', 'description' => 'Notes from the field',
        'pages' => [
            ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Read the guide', 'children' => []],
            ['title' => 'Catalog', 'slug' => 'catalog', 'purpose' => 'Browse the entries', 'children' => []],
        ],
    ]);
    $sections = freedom_plan();
    $patterns = ['rule-row', 'spec-table', 'card', null, 'tag-cluster', 'rule-row'];
    foreach ($patterns as $i => $pattern) {
        $sections[$i]['item_pattern'] = $pattern;
    }
    $llm = new FakeLlm();
    $llm->queueJson(['sections' => $sections]);
    $out = (new PagePlanStep($llm, new PromptRenderer(repo_path('prompts'))))->runForSlugs($project, ['catalog']);
    assert_eq($patterns, array_column($out[0]['sections'], 'item_pattern'));
    assert_eq(array_column($sections, 'content_notes'), array_column($out[0]['sections'], 'content_notes'));
    assert_eq(1, count($llm->calls));
    assert_true(!$project->exists('pages.json'), 'scoped fallback does not replace the whole site plan');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('hero caption allowance removes only extra or overlong labels and reaches a fixed point', function () {
    $caption = static fn (string $text): string => '<!-- wp:paragraph {"fontSize":"caption"} --><p>' . $text . '</p><!-- /wp:paragraph -->';
    $heading = '<!-- wp:heading {"level":1} --><h1>Field notes</h1><!-- /wp:heading -->';
    foreach ([false, true] as $withSupport) {
        $support = $withSupport ? '<!-- wp:paragraph --><p>The landscape in detail.</p><!-- /wp:paragraph -->' : '';
        $kept = $caption('Walking journal');
        $extra = $caption('An unnecessary second label');
        $long = $caption(str_repeat('A', 81));
        $open = '<!-- wp:group {"className":"hero-composition__copy"} --><div class="hero-composition__copy">';
        $close = '</div><!-- /wp:group -->';
        $result = HeroCopyBudget::enforce($open . $kept . $extra . $long . $heading . $support . $close, null, 'home-hero');
        assert_eq($open . $kept . $heading . $support . $close, $result['markup']);
        assert_eq(2, count($result['warnings']), 'only the two removed labels are reported');
        $again = HeroCopyBudget::enforce($result['markup'], null, 'home-hero');
        assert_eq($result['markup'], $again['markup']);
        assert_eq([], $again['warnings']);
    }
});
