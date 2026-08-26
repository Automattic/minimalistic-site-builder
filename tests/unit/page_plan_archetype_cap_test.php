<?php
declare(strict_types=1);

use Automattic\SiteBuild\Steps\PagePlanStep;

/** One valid planned section, so each test states only what it is about. */
function cap_section(string $slug, string $archetype, string $density = 'standard'): array
{
    return [
        'slug'             => $slug,
        'title'            => ucfirst($slug),
        'type'             => 'content',
        'purpose'          => 'Serve the page.',
        'content_notes'    => 'Concrete notes grounded in the spec.',
        'layout_archetype' => $archetype,
        'background'       => 'base',
        'vertical_density' => $density,
        'handoff'          => 'Sits between its neighbors.',
        'primary_action'   => null,
    ];
}

/** The audited failure: adjacency perfectly satisfied, two archetypes carrying the page. */
function cap_alternating_plan(): array
{
    return [
        cap_section('hero', 'mixed-width-editorial'),
        cap_section('a', 'centered-stack'),
        cap_section('b', 'mixed-width-editorial'),
        cap_section('c', 'centered-stack'),
        cap_section('d', 'mixed-width-editorial'),
        cap_section('e', 'centered-stack'),
    ];
}

test('the archetype cap holds at two for every real page length', function () {
    // Front pages aim 5-8 sections and interior pages 3-6, so the cap is a flat
    // "at most twice" across everything the planner actually produces; it only
    // loosens on pages longer than the prompt ever asks for.
    foreach ([2, 3, 4, 5, 6, 7, 8] as $sections) {
        assert_eq(2, PagePlanStep::archetypeCap($sections), "a {$sections}-section page caps at 2");
    }
    assert_eq(3, PagePlanStep::archetypeCap(9));
    assert_eq(4, PagePlanStep::archetypeCap(12));
    assert_true(PagePlanStep::archetypeCap(1) >= 2, 'the cap is never unsatisfiable on a short page');
});

test('a page that alternates two archetypes is rejected even with no adjacent duplicates', function () {
    // This is the whole point. "No two ADJACENT sections share an archetype" is
    // fully satisfied by A,B,A,B,A,B — and 77% of audited sections were one
    // archetype while that rule held. Only a whole-page count sees it.
    $plan = cap_alternating_plan();
    $adjacent = 0;
    foreach ($plan as $i => $section) {
        if ($i > 0 && $section['layout_archetype'] === $plan[$i - 1]['layout_archetype']) {
            $adjacent++;
        }
    }
    assert_eq(0, $adjacent, 'the fixture breaks no adjacency rule');

    $warnings = [];
    $repairs = [];
    $rejected = false;
    try {
        PagePlanStep::normalize($plan, true, null, [], $warnings, 'home', $repairs, true);
    } catch (\RuntimeException $e) {
        $rejected = true;
        assert_contains("'mixed-width-editorial' is used 3 times across 6 sections", $e->getMessage());
        assert_contains('no archetype may carry more than 2', $e->getMessage());
    }
    assert_true($rejected, 'an alternating page is a rejection, not an accepted plan');
});

test('repairVariety re-homes the excess onto the least-used composition', function () {
    // The deterministic floor. A model that keeps returning the uniform page
    // still ships a varied one, and the excess must spread rather than pile
    // onto whichever archetype happens to come first in the catalog.
    $warnings = [];
    $repairs = [];
    $out = PagePlanStep::repairVariety(cap_alternating_plan(), true, null, $warnings, 'home', $repairs, true);

    $archetypes = array_column($out, 'layout_archetype');
    $counts = array_count_values($archetypes);
    foreach ($counts as $archetype => $used) {
        assert_true($used <= 2, "{$archetype} is within the cap after repair (used {$used})");
    }
    assert_true(count($counts) >= 3, 'a 6-section page ends up with at least three compositions');

    for ($i = 1; $i < count($archetypes); $i++) {
        assert_true(
            $archetypes[$i] !== $archetypes[$i - 1],
            'the dominance pass never reintroduces an adjacent duplicate',
        );
    }
});

test('every mechanical archetype change is recorded durably', function () {
    // A silent rewrite is how a plan and its own prose drift apart. Everything
    // else in this step records authored-vs-delivered; so does this.
    $warnings = [];
    $repairs = [];
    PagePlanStep::repairVariety(cap_alternating_plan(), true, null, $warnings, 'home', $repairs, true);

    assert_true($warnings !== [], 'the rewrite is not silent');
    $recorded = 0;
    foreach ($warnings as $warning) {
        if (str_contains($warning, '.layout_archetype')) {
            assert_contains('authored=', $warning);
            assert_contains('delivered=', $warning);
            $recorded++;
        }
    }
    assert_true($recorded >= 2, 'both over-cap sections are accounted for');
});

test('the cap leaves a legitimate short page alone', function () {
    // A contact page is 2 to 4 sections (BIGR-858). Two compositions twice each
    // is the correct plan for one, and must not be rejected as dominance.
    $plan = [
        cap_section('hero', 'centered-stack'),
        cap_section('form', 'asymmetric-split'),
        cap_section('map', 'centered-stack'),
        cap_section('cta', 'asymmetric-split'),
    ];
    $warnings = [];
    $repairs = [];
    $out = PagePlanStep::normalize($plan, false, null, [], $warnings, 'contact', $repairs, true);
    assert_eq(4, count($out), 'the plan survives intact');
    assert_eq(
        ['centered-stack', 'asymmetric-split', 'centered-stack', 'asymmetric-split'],
        array_column($out, 'layout_archetype'),
        'nothing was reassigned',
    );
});

test('the dominance pass respects the interior page opening rule', function () {
    // pickLeastUsed walks the catalog, and `full-bleed-cover` is first in it —
    // so without the exclusion an interior page would get the second homepage
    // hero normalize() rejects.
    $plan = [
        cap_section('hero', 'mixed-width-editorial'),
        cap_section('a', 'centered-stack'),
        cap_section('b', 'mixed-width-editorial'),
        cap_section('c', 'centered-stack'),
        cap_section('d', 'mixed-width-editorial'),
    ];
    $warnings = [];
    $repairs = [];
    $out = PagePlanStep::repairVariety($plan, false, null, $warnings, 'about', $repairs, true);
    assert_true(
        $out[0]['layout_archetype'] !== 'full-bleed-cover',
        'an interior page never opens on a full-bleed cover',
    );
});

test('the page-plan prompt states the whole-page cap, not only adjacency', function () {
    // The deterministic pass is the floor; the prompt is what stops most plans
    // needing it. A floor with no rule above it repairs every single page.
    $prompt = (string) file_get_contents(repo_path('prompts/page-plan.md'));
    assert_contains('more than TWICE on a page', $prompt);
    assert_contains('Alternating two archetypes', $prompt, 'the prompt names the exact failure mode');
    assert_contains('used more than twice on the page', $prompt, 'the final re-check covers it too');
});
