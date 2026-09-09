<?php
declare(strict_types=1);

use Automattic\SiteBuild\PlaygroundArtifact;
use Automattic\SiteBuild\SitePreset;

test('sharedSteps carries the site options and the offline guard', function () {
    with_project('sitepreset_', function ($project) {
        $project->writeJson('siteSpec.json', ['name' => 'Corner Bakery']);
        $steps = SitePreset::sharedSteps($project);

        $kinds = array_column($steps, 'step');
        assert_true(in_array('setSiteOptions', $kinds, true), 'has setSiteOptions');
        assert_true(in_array('writeFile', $kinds, true), 'has the offline guard writeFile');

        foreach ($steps as $step) {
            if ($step['step'] === 'setSiteOptions') {
                assert_eq('Corner Bakery', $step['options']['blogname']);
                assert_eq('/%postname%/', $step['options']['permalink_structure']);
            }
        }
    });
});

test('wrapBlueprint produces a schema-bearing blueprint around the steps given', function () {
    $bp = SitePreset::wrapBlueprint([['step' => 'setSiteOptions', 'options' => []]]);
    assert_eq('/', $bp['landingPage']);
    assert_true($bp['login'], 'logs in');
    assert_contains('blueprint-schema.json', $bp['$schema']);
    assert_eq(1, count($bp['steps']));
});

test('PlaygroundArtifact still answers siteOptions after the move', function () {
    with_project('sitepreset_delegate_', function ($project) {
        $project->writeJson('siteSpec.json', ['name' => 'Corner Bakery']);
        assert_eq(
            SitePreset::siteOptions($project),
            PlaygroundArtifact::siteOptions($project)
        );
    });
});

test('siteDescription prefers the stated tagline and never invents one', function () {
    // The header's site-tagline block renders this exact option whenever the
    // header kept one, and a header keeps one only when a tagline was stated —
    // so the tagline has to win, or the two would disagree on the page.
    assert_eq('Bread, daily', SitePreset::siteDescription([
        'tagline' => 'Bread, daily',
        'description' => 'A bakery on the corner.',
        'topic' => 'sourdough',
    ]));
    // With no tagline the header carries no block, so the option is free to
    // describe the site for anyone who shares a link to it.
    assert_eq('A bakery on the corner.', SitePreset::siteDescription([
        'description' => 'A bakery on the corner.',
        'topic' => 'sourdough',
    ]));
    // WordPress appends this option to the front page's <title>, so a bare
    // topic phrase would read as "Corner Bakery – sourdough" in the tab.
    assert_eq('', SitePreset::siteDescription(['topic' => 'sourdough']), 'the topic is not a description');
    assert_eq('', SitePreset::siteDescription(['name' => 'Corner Bakery']), 'the name is not a description');
    // What the header reads to decide is untouched (BIGR-773).
    assert_eq('', SitePreset::blogDescription(['description' => 'A bakery on the corner.']));
});

test('the blueprint carries the same description the seeder applies', function () {
    with_project('sitepreset_description_', function ($project) {
        $spec = ['name' => 'Corner Bakery', 'description' => 'A bakery on the corner.'];
        $project->writeJson('siteSpec.json', $spec);
        assert_eq(
            SitePreset::siteDescription($spec),
            SitePreset::siteOptions($project)['blogdescription'],
        );
    });
});
