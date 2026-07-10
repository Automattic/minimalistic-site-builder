<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\ContrastFixStep;

function contrast_step_theme_json(array $styles): array
{
    return [
        'version'  => 3,
        'settings' => ['color' => ['palette' => [
            ['slug' => 'base', 'color' => '#FFFFFF', 'name' => 'Base'],
            ['slug' => 'contrast', 'color' => '#111111', 'name' => 'Contrast'],
            ['slug' => 'primary', 'color' => '#1D4ED8', 'name' => 'Primary'],
            ['slug' => 'secondary', 'color' => '#666666', 'name' => 'Secondary'],
            ['slug' => 'accent', 'color' => '#F2B8B5', 'name' => 'Accent'], // ~1.8:1 on base
        ]]],
        'styles'   => $styles,
    ];
}

test('theme.json global link hover below 4.5:1 on base is repaired', function () {
    $tmp = sys_get_temp_dir() . '/builder_cfs_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('theme/theme.json', contrast_step_theme_json([
        'elements' => ['link' => [
            'color'  => ['text' => 'var(--wp--preset--color--primary)'],
            ':hover' => ['color' => ['text' => 'var(--wp--preset--color--accent)']],
        ]],
    ]));

    ob_start();
    (new ContrastFixStep())->run($project);
    ob_end_clean();

    $theme = $project->readJson('theme/theme.json');
    $hover = $theme['styles']['elements']['link'][':hover']['color']['text'];
    assert_true($hover !== 'var(--wp--preset--color--accent)', 'failing hover must be repaired');
    $report = $project->readText('logs/contrast-report.txt');
    assert_contains('global link hover', $report);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme.json passing global link hover is left alone', function () {
    $tmp = sys_get_temp_dir() . '/builder_cfs_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('theme/theme.json', contrast_step_theme_json([
        'elements' => ['link' => [
            'color'  => ['text' => 'var(--wp--preset--color--primary)'],
            ':hover' => ['color' => ['text' => 'var(--wp--preset--color--contrast)']],
        ]],
    ]));

    ob_start();
    (new ContrastFixStep())->run($project);
    ob_end_clean();

    $theme = $project->readJson('theme/theme.json');
    assert_eq('var(--wp--preset--color--contrast)', $theme['styles']['elements']['link'][':hover']['color']['text']);

    exec('rm -rf ' . escapeshellarg($tmp));
});
