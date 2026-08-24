<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;

/**
 * @return array{exit:int, stdout:string, stderr:string, lines:list<string>}
 */
function run_palette_audit(array $args): array
{
    $command = php_child_command(repo_path('bin/palette-audit.php'), $args);
    $spec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($command, $spec, $pipes);
    assert_true(is_resource($proc), 'proc_open failed: ' . $command);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    $stdout = $stdout === false ? '' : $stdout;
    $stderr = $stderr === false ? '' : $stderr;
    $lines = $stdout === '' ? [] : explode("\n", rtrim($stdout, "\n"));

    return [
        'exit' => $exit,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'lines' => $lines,
    ];
}

/** @param callable(\Automattic\SiteBuild\Project):void $fn */
function with_palette_audit_project(callable $fn): void
{
    $store = new ProjectStore(repo_path('projects'));
    $slug = 'palette-audit-cli-' . getmypid() . '-' . str_replace('.', '', uniqid('', true));
    $project = $store->create($slug);
    try {
        $fn($project);
    } finally {
        remove_tree($project->root);
    }
}

/** @param array<string,string> $palette */
function write_theme_palette(\Automattic\SiteBuild\Project $project, array $palette): void
{
    $entries = [];
    foreach ($palette as $slug => $color) {
        $entries[] = ['slug' => $slug, 'name' => $slug, 'color' => $color];
    }
    $project->writeJson('theme/theme.json', [
        'version' => 3,
        'settings' => ['color' => ['palette' => $entries]],
    ]);
}

test('palette-audit --fixtures prints G5 counts and exits 0 after repair', function () {
    $run = run_palette_audit(['--fixtures']);
    $text = $run['stdout'] . $run['stderr'];

    assert_eq(0, $run['exit'], $text);
    foreach ([
        'fixtures: 10',
        'pre-repair violating palettes: 6',
        'pre-repair findings: 13',
        'post-repair remaining: 0',
    ] as $line) {
        assert_true(in_array($line, $run['lines'], true), $text);
    }
    assert_contains("post-repair remaining: 0", $run['stdout']);
});

test('palette-audit with no args prints usage on stderr and exits 1', function () {
    $run = run_palette_audit([]);

    assert_eq(1, $run['exit'], $run['stdout'] . $run['stderr']);
    assert_contains('Usage: php bin/palette-audit.php --fixtures', $run['stderr']);
    assert_contains('Usage: php bin/palette-audit.php --projects [dir]', $run['stderr']);
    assert_contains('Usage: php bin/palette-audit.php <slug>', $run['stderr']);
});

test('palette-audit --projects reports residuals and black/white repairs', function () {
    $dir = sys_get_temp_dir() . '/palette_audit_projects_' . getmypid() . '_' . str_replace('.', '', uniqid('', true));
    assert_true(mkdir($dir . '/mid/theme', 0775, true));
    file_put_contents($dir . '/mid/theme/theme.json', json_encode([
        'version' => 3,
        'settings' => ['color' => ['palette' => [
            ['slug' => 'base', 'color' => '#808080', 'name' => 'Base'],
            ['slug' => 'contrast', 'color' => '#AAAAAA', 'name' => 'Contrast'],
            ['slug' => 'primary', 'color' => '#000000', 'name' => 'Primary'],
            ['slug' => 'secondary', 'color' => '#000000', 'name' => 'Secondary'],
            ['slug' => 'accent', 'color' => '#000000', 'name' => 'Accent'],
        ]]],
    ]));
    try {
        $run = run_palette_audit(['--projects', $dir]);
        $text = $run['stdout'] . $run['stderr'];
        assert_eq(0, $run['exit'], $text);
        assert_contains('projects palettes: 1', $run['stdout']);
        assert_contains('residual palettes: 1', $run['stdout']);
        assert_contains('residuals missing unrepaired warning: 0', $run['stdout']);
        assert_contains('warning=unrepaired', $run['stdout']);
    } finally {
        remove_tree($dir);
    }
});

test('palette-audit unknown slug errors on stderr and exits 1', function () {
    $slug = 'palette-audit-missing-' . getmypid() . '-' . str_replace('.', '', uniqid('', true));
    $run = run_palette_audit([$slug]);

    assert_eq(1, $run['exit'], $run['stdout'] . $run['stderr']);
    assert_contains('Project does not exist:', $run['stderr']);
});

test('palette-audit missing theme.json errors on stderr and exits 1', function () {
    with_palette_audit_project(function ($project): void {
        $run = run_palette_audit([$project->slug()]);
        assert_eq(1, $run['exit'], $run['stdout'] . $run['stderr']);
        assert_contains('Missing theme/theme.json', $run['stderr']);
    });
});

test('palette-audit slug reports delivered findings and does not repair', function () {
    with_palette_audit_project(function ($project): void {
        write_theme_palette($project, [
            'base' => '#131313',
            'contrast' => '#E8DFD0',
            'primary' => '#8E1F26',
            'secondary' => '#C79A3C',
            'accent' => '#E2622A',
        ]);
        $run = run_palette_audit([$project->slug()]);
        $text = $run['stdout'] . $run['stderr'];
        assert_eq(1, $run['exit'], $text);
        assert_contains('findings: 2', $run['stdout']);
        assert_contains('hue-separation', $run['stdout']);
        assert_contains('authored=#8E1F26', $run['stdout']);
    });
});

test('palette-audit slug with a clean delivered palette exits 0', function () {
    with_palette_audit_project(function ($project): void {
        write_theme_palette($project, [
            'base' => '#F7F4EE',
            'contrast' => '#1B1B1B',
            'primary' => '#7B2D26',
            'secondary' => '#3E5C4A',
            'accent' => '#1F6F8B',
        ]);
        $run = run_palette_audit([$project->slug()]);
        assert_eq(0, $run['exit'], $run['stdout'] . $run['stderr']);
        assert_contains('findings: 0', $run['stdout']);
    });
});
