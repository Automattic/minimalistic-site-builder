<?php
declare(strict_types=1);

use Automattic\SiteBuild\Steps\PagePlanStep;

/**
 * A varied-archetype page so only the background rule is under test. The list
 * deliberately holds no 'full-bleed-cover': normalize() forces that archetype
 * onto the 'image' background (BIGR-955), which would satisfy the banded floor
 * and hide the rule these tests exercise.
 */
function pacing_plan(int $sections, string $background = 'base'): array
{
    $archetypes = [
        'centered-stack',
        'asymmetric-split',
        'equal-card-grid',
        'list-with-thumbnails',
        'equal-card-grid',
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

test('a long page with more than two bands is rejected', function () {
    // The other edge of the same audit: more content earned more colors, and
    // long pages came back as a succession of bands.
    $warnings = [];
    $repairs = [];
    $rejected = false;
    try {
        PagePlanStep::normalize(pacing_plan(6, 'contrast'), true, null, [], $warnings, 'home', $repairs, true);
    } catch (\RuntimeException $e) {
        $rejected = true;
        assert_contains('6 of 6 sections sit on a non-base background', $e->getMessage());
        assert_contains('at most 2', $e->getMessage());
    }
    assert_true($rejected, 'a 6-section page with six bands is a rejection');
});

test('repairVariety demotes a long page down to two bands', function () {
    $plan = pacing_plan(6);
    foreach ([1 => 'tinted', 2 => 'contrast', 4 => 'contrast'] as $i => $background) {
        $plan[$i]['background'] = $background;
    }
    $warnings = [];
    $repairs = [];
    $out = PagePlanStep::repairVariety($plan, true, null, $warnings, 'home', $repairs, true);
    assert_eq(
        ['base', 'base', 'contrast', 'base', 'contrast', 'base'],
        array_column($out, 'background'),
        'the subtle tint goes first and the two contrast beats stay',
    );
    assert_contains('demoted an excess color band', implode("\n", $warnings));
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
    assert_contains('AT MOST TWO non-base backgrounds', $prompt);
    assert_contains('More content does not earn more colors', $prompt);
});

test('surface restraint demotes excess bands to an absolute two-beat budget', function () {
    $plan = pacing_plan(6);
    foreach (['image', 'base', 'tinted', 'contrast', 'image', 'base'] as $i => $background) {
        $plan[$i]['background'] = $background;
    }
    $warnings = [];
    $out = PagePlanStep::withSurfaceRestraint($plan, 'home', $warnings);

    assert_eq(
        ['image', 'base', 'base', 'base', 'image', 'base'],
        array_column($out, 'background'),
        'subtle tints are demoted before contrast, and ordinary image bands are kept until last',
    );
    assert_eq(2, count($warnings), 'each delivered-value change is recorded once');
    assert_contains('at most two purposeful', implode("\n", $warnings));
    assert_contains('Build correction', $out[2]['handoff']);
    assert_contains('now uses the page base', $out[1]['handoff'], 'neighbor seam facts are corrected');
});

test('surface restraint preserves a locked hero and structural covers', function () {
    $plan = pacing_plan(6);
    foreach (['image', 'tinted', 'image', 'contrast', 'tinted', 'contrast'] as $i => $background) {
        $plan[$i]['background'] = $background;
    }
    $plan[0]['layout_archetype'] = 'full-bleed-cover';
    $plan[2]['layout_archetype'] = 'full-bleed-cover';
    $warnings = [];
    $out = PagePlanStep::withSurfaceRestraint($plan, 'home', $warnings, true);

    assert_eq(
        ['image', 'base', 'image', 'base', 'base', 'base'],
        array_column($out, 'background'),
        'the locked hero and the cover are the two beats that survive; the closing band is not special here',
    );
    assert_true(!str_contains(implode("\n", $warnings), 'could not be met'), 'the budget was met');

    // Three structural covers cannot be demoted, so the page ships over budget
    // and says so.
    $plan = pacing_plan(6);
    foreach (['image', 'image', 'image', 'tinted', 'base', 'base'] as $i => $background) {
        $plan[$i]['background'] = $background;
        if ($background === 'image') {
            $plan[$i]['layout_archetype'] = 'full-bleed-cover';
        }
    }
    $warnings = [];
    $out = PagePlanStep::withSurfaceRestraint($plan, 'home', $warnings, true);
    assert_eq(['image', 'image', 'image', 'base', 'base', 'base'], array_column($out, 'background'));
    $joined = implode("\n", $warnings);
    assert_contains('surface budget could not be met', $joined);
    assert_contains('delivered those sections intact', $joined);
});

test('surface restraint leaves short pages and already restrained pages byte-identical', function () {
    foreach ([pacing_plan(4, 'contrast'), pacing_plan(6)] as $plan) {
        if (count($plan) === 6) {
            $plan[1]['background'] = 'tinted';
            $plan[4]['background'] = 'contrast';
        }
        $warnings = [];
        $out = PagePlanStep::withSurfaceRestraint($plan, 'page', $warnings);
        assert_eq($plan, $out);
        assert_eq([], $warnings);
    }
});
