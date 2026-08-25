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

    quietly(fn () => (new ContrastFixStep())->run($project));

    $theme = $project->readJson('theme/theme.json');
    $hover = $theme['styles']['elements']['link'][':hover']['color']['text'];
    assert_true($hover !== 'var(--wp--preset--color--accent)', 'failing hover must be repaired');
    $report = $project->readText('logs/contrast-report.txt');
    assert_contains('global link hover', $report);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('button repair on a mid-tone background keeps the failure on the record', function () {
    // On #7A7A7A neither base (~4.3) nor contrast (~4.4) reaches 4.5: the
    // improvement is applied but must not read as a clean repair.
    $tmp = sys_get_temp_dir() . '/builder_cfs_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('theme/theme.json', contrast_step_theme_json([
        'elements' => ['button' => ['color' => [
            'background' => '#7A7A7A',
            'text'       => 'var(--wp--preset--color--base)', // ~4.29:1
        ]]],
    ]));

    quietly(fn () => (new ContrastFixStep())->run($project));

    $report = $project->readText('logs/contrast-report.txt');
    assert_contains('still below threshold', $report);
    assert_contains('(repaired) (warning)', $report);
    $joined = implode(' ', $project->readJson('warnings.json')['contrast-fix'] ?? []);
    assert_contains('still below threshold', $joined, 'the residual failure is durable, not log-only');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('an unfixable failing button warns instead of disappearing', function () {
    // contrast (~4.40:1 on #7A7A7A) is already the best candidate — the old
    // code fell through silently; a warning must survive.
    $tmp = sys_get_temp_dir() . '/builder_cfs_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('theme/theme.json', contrast_step_theme_json([
        'elements' => ['button' => ['color' => [
            'background' => '#7A7A7A',
            'text'       => 'var(--wp--preset--color--contrast)',
        ]]],
    ]));

    quietly(fn () => (new ContrastFixStep())->run($project));

    $theme = $project->readJson('theme/theme.json');
    assert_eq('var(--wp--preset--color--contrast)', $theme['styles']['elements']['button']['color']['text'],
        'no improvement available — the authored color stays');
    assert_contains('no palette color improves it (warning)', $project->readText('logs/contrast-report.txt'));

    // Unrepairable pairs are delivered-through defects: durable, not log-only.
    $joined = implode(' ', $project->readJson('warnings.json')['contrast-fix'] ?? []);
    assert_contains('no palette color improves it', $joined);

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

    quietly(fn () => (new ContrastFixStep())->run($project));

    $theme = $project->readJson('theme/theme.json');
    assert_eq('var(--wp--preset--color--contrast)', $theme['styles']['elements']['link'][':hover']['color']['text']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('button interaction states keep normal-text contrast', function () {
    $tmp = sys_get_temp_dir() . '/builder_cfs_button_states_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('theme/theme.json', contrast_step_theme_json([
        'elements' => ['button' => [
            'color' => ['background' => 'var:preset|color|contrast', 'text' => 'var:preset|color|base'],
            ':hover' => ['color' => [
                'background' => 'var:preset|color|accent',
                'text' => 'var:preset|color|base',
            ]],
            ':focus' => ['color' => [
                'background' => 'transparent',
                'text' => 'inherit',
            ]],
        ]],
    ]));

    quietly(fn () => (new ContrastFixStep())->run($project));
    $theme = $project->readJson('theme/theme.json');
    assert_eq(
        'var(--wp--preset--color--contrast)',
        $theme['styles']['elements']['button'][':hover']['color']['text'],
        'failing opaque hover label is repaired',
    );
    assert_eq('inherit', $theme['styles']['elements']['button'][':focus']['color']['text']);
    assert_contains('button hover text', $project->readText('logs/contrast-report.txt'));
    exec('rm -rf ' . escapeshellarg($tmp));
});
