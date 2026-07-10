<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\ApplyIdentityStep;
use Automattic\SiteBuild\Steps\ScaffoldThemeStep;

test('apply-identity replaces all theme placeholders', function () {
    $tmp = sys_get_temp_dir() . '/builder_identity_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    (new ScaffoldThemeStep())->run($project);
    $project->writeJson('siteSpec.json', [
        'name'        => 'Hearth & Crumb',
        'slug'        => 'hearth-crumb',
        'description' => 'A neighborhood bakery.',
    ]);

    (new ApplyIdentityStep())->run($project);

    $css = $project->readText('theme/style.css');
    assert_contains('Theme Name: Hearth & Crumb', $css);
    assert_contains('Text Domain: hearth-crumb', $css);
    assert_contains('Description: A neighborhood bakery.', $css);
    assert_contains('Author: Builder', $css);            // default author
    assert_true(!str_contains($css, '{{'), 'no placeholders left in css');

    $readme = $project->readText('theme/readme.txt');
    assert_contains('=== Hearth & Crumb ===', $readme);
    assert_true(!str_contains($readme, '{{'), 'no placeholders left in readme');

    exec('rm -rf ' . escapeshellarg($tmp));
});
