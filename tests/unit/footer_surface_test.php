<?php
declare(strict_types=1);

use Automattic\SiteBuild\FooterComposition;
use Automattic\SiteBuild\Steps\PagePlanStep;

/** A planned page whose sections carry only the fields the seam repair reads. */
function footer_seam_page(string $slug, array $backgrounds): array
{
    $sections = [];
    foreach ($backgrounds as $i => $background) {
        $sections[] = ['slug' => "{$slug}-{$i}", 'title' => "S{$i}", 'background' => $background];
    }
    return ['slug' => $slug, 'title' => ucfirst($slug), 'front' => $slug === 'home', 'sections' => $sections];
}

/** @return list<string> */
function footer_seam_closings(array $pages): array
{
    return array_map(
        static fn (array $page): string => (string) (end($page['sections'])['background'] ?? '-'),
        $pages
    );
}

test('footer surface avoids the background every page closes on', function () {
    // typographic-billboard prefers base. A site whose pages all close on base
    // would give that footer no boundary on any page, so the preference loses.
    assert_eq(
        'contrast',
        FooterComposition::resolveSurface('typographic-billboard', ['base', 'base', 'base', 'base']),
        'a footer may not take the surface every page already closes on'
    );
});

test('footer surface keeps the archetype preference when nothing collides', function () {
    assert_eq(
        'base',
        FooterComposition::resolveSurface('typographic-billboard', ['contrast', 'image', 'tinted']),
        'no collision means the assigned composition keeps its surface'
    );
});

test('footer surface takes the fewest collisions when both surfaces collide', function () {
    // split-ledger prefers contrast, but three pages close on contrast and only
    // one on base, so base leaves the fewest merged seams.
    assert_eq(
        'base',
        FooterComposition::resolveSurface('split-ledger', ['contrast', 'contrast', 'contrast', 'base']),
        'the surface with fewer merged seams wins when neither is clean'
    );
});

test('footer surface prefers the archetype on an equal collision count', function () {
    assert_eq(
        'contrast',
        FooterComposition::resolveSurface('split-ledger', ['contrast', 'base']),
        'a tie leaves the assigned composition its surface'
    );
});

test('tinted and image closings never collide with a solid footer surface', function () {
    // Only base and contrast are exact solid surfaces (SectionRhythm's
    // COLLAPSIBLE_SURFACES); a tint or a photo band always reads as an edge.
    assert_eq(
        'base',
        FooterComposition::resolveSurface('typographic-billboard', ['tinted', 'image', 'tinted']),
    );
    assert_eq(
        'contrast',
        FooterComposition::resolveSurface('split-ledger', ['tinted', 'image']),
    );
});

test('footer surface resolves with no planned pages', function () {
    assert_eq('base', FooterComposition::resolveSurface('typographic-billboard', []));
});

test('footer surface rejects an unknown archetype', function () {
    // Assert the type, not merely "it threw": a missing method throws Error and
    // would let this pass against code that does not exist yet.
    $error = assert_throws(
        static fn () => FooterComposition::resolveSurface('not-an-archetype', ['base']),
        'unknown archetypes must not silently resolve a surface'
    );
    assert_true($error instanceof InvalidArgumentException, get_class($error));
});

test('a persisted footer_archetype survives later design-direction rewrites', function () {
    with_project('builder_footer_persist_', function ($project): void {
        $project->writeText('siteSpec.json', '{"name":"Seed"}');
        seed_test_design_direction($project);
        $hashed = FooterComposition::archetypeFor(
            $project->readText('siteSpec.json'),
            \Automattic\SiteBuild\Steps\DesignDirectionStep::readFor($project),
        );
        $pinned = $hashed === 'split-ledger' ? 'typographic-billboard' : 'split-ledger';
        $project->writeJson('pages.json', [
            'pages' => [footer_seam_page('home', ['image', 'contrast'])],
            'footer_archetype' => $pinned,
        ]);

        assert_eq($pinned, FooterComposition::archetypeForProject($project));

        $direction = $project->readJson('designDirection.json');
        $direction['description'] .= ' Retinted to #ABCDEF so a rehash would move.';
        $project->writeJson('designDirection.json', $direction);

        assert_eq(
            $pinned,
            FooterComposition::archetypeForProject($project),
            'sections must keep the plan-time footer after direction prose changes',
        );
    });
});

test('the footer archetype is decided from the site, not from any page plan', function () {
    // The surface has to be known before page-plan fires so every page can be
    // told what to avoid; seeding on planned sections made that impossible.
    $archetype = FooterComposition::archetypeFor('{"name":"Seed"}', 'DIRECTION');
    assert_true(in_array($archetype, FooterComposition::ARCHETYPES, true), $archetype);
    assert_eq($archetype, FooterComposition::archetypeFor('{"name":"Seed"}', 'DIRECTION'));
});

test('the footer archetype varies with the site it is seeded on', function () {
    // Guards against a constant masquerading as a hash.
    $seen = [];
    for ($i = 0; $i < 60; $i++) {
        $seen[FooterComposition::archetypeFor('{"name":"Site' . $i . '"}', "DIRECTION {$i}")] = true;
    }
    assert_true(count($seen) > 1, 'archetype selection collapsed to ' . implode(',', array_keys($seen)));
});

test('a closing band planned on the footer surface is moved off it', function () {
    // The plan prompt carries the rule, but page requests fan out concurrently
    // and the model still lands on the footer's surface often enough that half
    // the seams merged in a 7-site build. This is the deterministic floor.
    $warnings = [];
    $pages = PagePlanStep::withClosingBandOffFooterSurface(
        [footer_seam_page('home', ['image', 'base', 'contrast']), footer_seam_page('menu', ['base', 'contrast'])],
        'contrast',
        $warnings
    );
    assert_eq(['base', 'base'], footer_seam_closings($pages));
    assert_eq(2, count($warnings), 'each moved band is recorded');
});

test('a compliant closing band is left exactly as planned', function () {
    $warnings = [];
    $pages = PagePlanStep::withClosingBandOffFooterSurface(
        [footer_seam_page('home', ['base', 'tinted']), footer_seam_page('menu', ['contrast', 'image'])],
        'contrast',
        $warnings
    );
    assert_eq(['tinted', 'image'], footer_seam_closings($pages));
    assert_eq([], $warnings);
});

test('only the closing band is steered, never the bands above it', function () {
    // A contrast band mid-page is deliberate rhythm; only the seam the footer
    // actually touches is the build's business.
    $warnings = [];
    $pages = PagePlanStep::withClosingBandOffFooterSurface(
        [footer_seam_page('home', ['contrast', 'contrast', 'contrast'])],
        'contrast',
        $warnings
    );
    assert_eq(['contrast', 'contrast', 'base'], array_column($pages[0]['sections'], 'background'));
});

test('a moved closing band does not leave stale handoff prose behind', function () {
    // The plan writes handoff prose naming the background it chose, and both
    // the section author and the footer author read that line — an uncorrected
    // move hands them "Background: tinted" beside "this base-background section".
    $pages = [footer_seam_page('bread', ['contrast', 'base'])];
    $pages[0]['sections'][1]['handoff'] =
        'Sits below the bread list with a shared base ground. Closes the page before the footer.';

    $warnings = [];
    $out = PagePlanStep::withClosingBandOffFooterSurface($pages, 'base', $warnings);

    $handoff = $out[0]['sections'][1]['handoff'];
    assert_contains('Sits below the bread list', $handoff, 'the planned rhythm intent survives');
    assert_contains('tinted', $handoff);
    assert_contains('supersedes any background named earlier', $handoff);
});

test('a base footer takes a tint above it rather than forcing every page dark', function () {
    $warnings = [];
    $pages = PagePlanStep::withClosingBandOffFooterSurface(
        [footer_seam_page('home', ['base', 'base'])],
        'base',
        $warnings
    );
    assert_eq(['tinted'], footer_seam_closings($pages));
});

test('the footer prompt requires utility links to sit inside a navigation', function () {
    // The CSS reset kills the bullets deterministically; this keeps the prompt
    // from steering the model back to a bare page-list, which still stacks
    // vertically where the composition asked for a baseline rail.
    $prompt = (string) file_get_contents(repo_path('prompts') . '/footer.md');
    assert_contains('bare `wp:page-list` outside `wp:navigation`', $prompt);
    assert_true(
        !str_contains($prompt, 'Prefer wp:navigation/navigation-link/page-list over wp:list'),
        'the old "prefer" wording permitted the bare page-list that shipped bullets'
    );
});

test('the page-plan closing rule names the surface pages must avoid', function () {
    $rule = FooterComposition::closingSectionRule('contrast');
    assert_contains('contrast', $rule);
    assert_contains('LAST section', $rule);
});

test('the page-plan closing rule rejects a non-surface value', function () {
    $error = assert_throws(
        static fn () => FooterComposition::closingSectionRule('tinted'),
        'only the exact solid surfaces are enforceable footer backgrounds'
    );
    assert_true($error instanceof InvalidArgumentException, get_class($error));
});
