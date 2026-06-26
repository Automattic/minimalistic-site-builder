<?php
declare(strict_types=1);

test('scaffold-theme writes style.css and readme with placeholders', function () {
    $tmp = sys_get_temp_dir() . '/builder_scaffold_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    (new ScaffoldThemeStep())->run($project);

    assert_true($project->exists('theme/style.css'), 'style.css written');
    assert_true($project->exists('theme/readme.txt'), 'readme.txt written');

    $css = $project->readText('theme/style.css');
    assert_contains('Theme Name: {{THEME_NAME}}', $css);
    assert_contains('Text Domain: {{THEME_SLUG}}', $css);
    assert_contains('Description: {{DESCRIPTION}}', $css);

    $readme = $project->readText('theme/readme.txt');
    assert_contains('=== {{THEME_NAME}} ===', $readme);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('scaffold-theme has stable id and label', function () {
    $s = new ScaffoldThemeStep();
    assert_eq('scaffold-theme', $s->id());
    assert_true($s->label() !== '');
});
