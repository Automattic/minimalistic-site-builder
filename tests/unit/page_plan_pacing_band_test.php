<?php
declare(strict_types=1);

use Automattic\SiteBuild\Steps\PagePlanStep;

/** A varied-archetype page so only the background rule is under test. */
function pacing_plan(int $sections, string $background = 'base'): array
{
    $archetypes = [
        'centered-stack',
        'asymmetric-split',
        'mixed-width-editorial',
        'list-with-thumbnails',
        'full-bleed-cover',
        'offset-grid',
        'centered-stack',
        'asymmetric-split',
    ];
    $plan = [];
    for ($i = 0; $i < $sections; $i++) {
        $plan[] = [
            'slug'             => "s{$i}",
            'title'            => "Section {$i}",
            'type'             => 'content',
            'purpose'          => 'Serve the page.',
            'content_notes'    => 'Concrete notes grounded in the spec.',
            'layout_archetype' => $archetypes[$i % count($archetypes)],
            'background'       => $background,
            'vertical_density' => 'standard',
            'text_placement'   => 'left-column',
            'handoff'          => 'Sits between the hero above and the story below.',
            'primary_action'   => null,
        ];
    }
    return $plan;
}

test('a long page with every section on the page background is rejected', function () {
    // 271 of 371 audited pages came back exactly like this — and the longer the
    // page, the likelier it was. "Mostly base" was read as "all base".
    $warnings = [];
    $repairs = [];
    $rejected = false;
    try {
        PagePlanStep::normalize(pacing_plan(6), true, null, [], $warnings, 'home', $repairs, true);
    } catch (\RuntimeException $e) {
        $rejected = true;
        assert_contains("all 6 sections use background 'base'", $e->getMessage());
        assert_contains('at least one', $e->getMessage());
    }
    assert_true($rejected, 'an all-base 6-section page is a rejection');
});

test('the background floor leaves short pages alone', function () {
    // Every contact page is 2 to 4 sections (BIGR-858). One uniform ground is a
    // fine answer for a page that short, and forcing a dark band onto a contact
    // page would be a worse plan, not a better one.
    foreach ([2, 3, 4] as $sections) {
        $warnings = [];
        $repairs = [];
        $out = PagePlanStep::normalize(
            pacing_plan($sections),
            false,
            null,
            [],
            $warnings,
            'contact',
            $repairs,
            true,
        );
        assert_eq($sections, count($out), "a {$sections}-section page survives");
        assert_eq(
            array_fill(0, $sections, 'base'),
            array_column($out, 'background'),
            "a {$sections}-section page keeps its uniform ground",
        );
    }
});

test('repairVariety gives an all-base long page exactly one mid-page band', function () {
    foreach ([5, 6, 7, 8] as $sections) {
        $warnings = [];
        $repairs = [];
        $out = PagePlanStep::repairVariety(pacing_plan($sections), true, null, $warnings, 'home', $repairs, true);
        $backgrounds = array_column($out, 'background');
        $banded = array_keys(array_filter($backgrounds, fn (string $b) => $b !== 'base'));

        assert_eq(1, count($banded), "a {$sections}-section page gets exactly one band, not stripes");
        $at = $banded[0];
        assert_true($at > 0, 'never the first section — the site header renders over it');
        assert_true(
            $at < $sections - 1,
            'never the last section — withClosingBandOffFooterSurface pins that one against the footer',
        );
        assert_eq('contrast', $backgrounds[$at], 'the promoted band is contrast, never an unbudgeted image');
    }
});

test('a page that already paces itself is left untouched', function () {
    // The floor is a minimum, not an assignment. A plan that placed its own
    // band must come back byte-identical.
    $plan = pacing_plan(6);
    $plan[2]['background'] = 'image';
    $warnings = [];
    $repairs = [];
    $out = PagePlanStep::repairVariety($plan, true, null, $warnings, 'home', $repairs, true);

    assert_eq(array_column($plan, 'background'), array_column($out, 'background'), 'nothing was promoted');
    assert_eq(
        [],
        array_values(array_filter($warnings, fn (string $w) => str_contains($w, '.background'))),
        'and nothing was recorded',
    );
});

test('the promoted band corrects its own seam prose and both neighbours', function () {
    // "handoff" names each neighbour's background, and the section authors on
    // either side read that line. Promoting a band without correcting them
    // hands three authors contradictory briefs.
    $warnings = [];
    $repairs = [];
    $out = PagePlanStep::repairVariety(pacing_plan(5), true, null, $warnings, 'home', $repairs, true);
    $backgrounds = array_column($out, 'background');
    $at = (int) array_search('contrast', $backgrounds, true);

    assert_contains('Build correction', $out[$at]['handoff']);
    assert_contains('supersedes', $out[$at]['handoff']);
    assert_contains(
        'Sits between the hero above',
        $out[$at]['handoff'],
        'the planner\'s own reasoning survives the correction',
    );
    foreach ([$at - 1, $at + 1] as $neighbour) {
        assert_contains('Build correction', $out[$neighbour]['handoff'], "neighbour {$neighbour} was told");
        assert_contains('contrast band', $out[$neighbour]['handoff']);
    }
});

test('the promotion is recorded durably', function () {
    $warnings = [];
    $repairs = [];
    PagePlanStep::repairVariety(pacing_plan(6), true, null, $warnings, 'home', $repairs, true);

    $recorded = array_values(array_filter($warnings, fn (string $w) => str_contains($w, '.background')));
    assert_eq(1, count($recorded), 'one promotion, one record');
    assert_contains('authored="base"', $recorded[0]);
    assert_contains('delivered="contrast"', $recorded[0]);
});

test('the page-plan prompt states the floor, not only the "mostly base" bias', function () {
    // The deterministic promotion is the floor; the prompt is what stops most
    // plans needing it. "Mostly base" alone is what produced 73% all-base.
    $prompt = (string) file_get_contents(repo_path('prompts/page-plan.md'));
    assert_contains('"Mostly base" is not "all base"', $prompt);
    assert_contains('5 or more sections MUST carry at least one', $prompt);
    assert_contains('every section on "base"', $prompt, 'the final re-check covers it too');
});
