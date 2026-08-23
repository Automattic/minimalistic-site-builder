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
