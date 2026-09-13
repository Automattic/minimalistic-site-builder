<?php
declare(strict_types=1);

use Automattic\SiteBuild\Patterns\ComposeLayoutsStep;
use Automattic\SiteBuild\Patterns\PatternArtifacts;
use Automattic\SiteBuild\Project;

/**
 * @param list<array<string, mixed>> $inventory
 * @param list<array<string, mixed>> $pages
 */
function compose_project(array $inventory, array $pages): Project
{
    $dir = sys_get_temp_dir() . '/compose-layouts-' . bin2hex(random_bytes(4));
    mkdir($dir, 0o777, true);

    $project = new Project($dir, basename($dir));
    $project->writeJson(PatternArtifacts::NORMALIZED, ['inventory' => $inventory]);
    $project->writeJson(PatternArtifacts::PLAN, ['pages' => $pages]);

    return $project;
}

function compose_pattern(string $id, array $categories): array
{
    return [
        'id' => $id,
        'categories' => $categories,
        'content' => '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->',
    ];
}

test('a planned section becomes the pattern its category names', function () {
    $project = compose_project(
        [compose_pattern('theme/hero', ['hero'])],
        [['slug' => 'home', 'sections' => [['intent' => 'Hero', 'category' => 'hero']]]],
    );

    (new ComposeLayoutsStep())->run($project);

    $layouts = $project->readJson(PatternArtifacts::LAYOUTS);
    assert_eq('theme/hero', $layouts['pages'][0]['sections'][0]['pattern']);
});

/**
 * The inventory is the whole vocabulary. A section the theme has no word for
 * has to stop the build: shipping the page without it produces a site that
 * passes every check and is missing a section nobody asked to drop.
 */
test('a section with no approved pattern stops the build', function () {
    $project = compose_project(
        [compose_pattern('theme/hero', ['hero'])],
        [['slug' => 'home', 'sections' => [['intent' => 'Pricing', 'category' => 'pricing']]]],
    );

    $thrown = assert_throws(static fn () => (new ComposeLayoutsStep())->run($project));

    assert_contains('Pricing', $thrown->getMessage());
});

/**
 * Naming every gap at once, rather than the first, is what lets a planner fix
 * the plan in one pass instead of one round trip per hole.
 */
test('every unmatched section is named, not just the first', function () {
    $project = compose_project(
        [compose_pattern('theme/hero', ['hero'])],
        [[
            'slug' => 'home',
            'sections' => [
                ['intent' => 'Pricing', 'category' => 'pricing'],
                ['intent' => 'Team', 'category' => 'team'],
            ],
        ]],
    );

    $thrown = assert_throws(static fn () => (new ComposeLayoutsStep())->run($project));

    assert_contains('Pricing', $thrown->getMessage());
    assert_contains('Team', $thrown->getMessage());
});

/**
 * A plan may name its pattern outright, as a hand-written Blueprint does. That
 * is a request, not an approval: it still has to be in the inventory.
 */
test('a pattern the plan names but the inventory lacks is refused', function () {
    $project = compose_project(
        [compose_pattern('theme/hero', ['hero'])],
        [['slug' => 'home', 'sections' => [['pattern' => 'dotcompatterns/hero']]]],
    );

    assert_throws(static fn () => (new ComposeLayoutsStep())->run($project));
});

test('a pattern the plan names and the inventory has is used as asked', function () {
    $project = compose_project(
        [compose_pattern('theme/hero', ['hero']), compose_pattern('theme/banner', ['hero'])],
        [['slug' => 'home', 'sections' => [['pattern' => 'theme/banner']]]],
    );

    (new ComposeLayoutsStep())->run($project);

    assert_eq('theme/banner', $project->readJson(PatternArtifacts::LAYOUTS)['pages'][0]['sections'][0]['pattern']);
});

/**
 * Two runs that chose differently would compose two different sites before
 * either reached the model, which is exactly what a fixture comparison is
 * meant to rule out.
 */
test('the same plan and inventory choose the same pattern every time', function () {
    $inventory = [
        compose_pattern('theme/zebra', ['hero']),
        compose_pattern('theme/apple', ['hero']),
        compose_pattern('theme/mango', ['hero']),
    ];
    $pages = [['slug' => 'home', 'sections' => [['intent' => 'Hero', 'category' => 'hero']]]];

    $first = compose_project($inventory, $pages);
    $second = compose_project(array_reverse($inventory), $pages);

    (new ComposeLayoutsStep())->run($first);
    (new ComposeLayoutsStep())->run($second);

    assert_eq(
        $first->readText(PatternArtifacts::PROVENANCE),
        $second->readText(PatternArtifacts::PROVENANCE),
    );
});

/**
 * An entry with no markup would be chosen, recorded as the section's
 * provenance, and contribute nothing — a page missing a section while every
 * check reports it was composed from approved patterns.
 */
test('an inventory entry with no markup is not a candidate', function () {
    $empty = compose_pattern('theme/hero', ['hero']);
    $empty['content'] = '';

    $project = compose_project(
        [$empty],
        [['slug' => 'home', 'sections' => [['intent' => 'Hero', 'category' => 'hero']]]],
    );

    assert_throws(static fn () => (new ComposeLayoutsStep())->run($project));
});

test('provenance records the page, intent and pattern of every section', function () {
    $project = compose_project(
        [compose_pattern('theme/hero', ['hero'])],
        [['slug' => 'home', 'sections' => [['intent' => 'Hero', 'category' => 'hero']]]],
    );

    (new ComposeLayoutsStep())->run($project);

    $record = $project->readJson(PatternArtifacts::PROVENANCE)['sections'][0];
    assert_eq('home', $record['page']);
    assert_eq('Hero', $record['intent']);
    assert_eq('theme/hero', $record['pattern']);
});
