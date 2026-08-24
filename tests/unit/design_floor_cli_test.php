<?php
declare(strict_types=1);

use Automattic\SiteBuild\DesignFloor;
use Automattic\SiteBuild\ProjectStore;

test('design-floor CLI prints usage and exits 1 when the slug is missing', function () {
    $command = php_child_command(repo_path('bin/design-floor.php'));
    $output = [];
    $exit = 0;
    exec($command . ' 2>&1', $output, $exit);

    assert_eq(1, $exit);
    assert_eq('Usage: php bin/design-floor.php <slug>', implode("\n", $output));
});

test('design-floor CLI prints DesignFloor warning rows for planted plugin page markup', function () {
    $slug = 'zz-design-floor-cli-' . getmypid() . '-' . uniqid();
    $project = (new ProjectStore(repo_path('projects')))->create($slug);

    try {
        $markup = <<<'HTML'
<!-- wp:group {"className":"card-style--flush"} -->
<div class="wp-block-group card-style--flush">
<!-- wp:group {"className":"card-style--framed"} -->
<div class="wp-block-group card-style--framed"></div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->
HTML;
        $project->writeText('plugin/pages/home.html', $markup);
        // Theme chrome must stay out of this CLI: Project::markupFiles() is the wrong lens.
        $project->writeText('theme/parts/header.html', $markup);
        $theme = [
            'settings' => [
                'typography' => [
                    'fontSizes' => [
                        ['slug' => 'body', 'size' => '0.5rem'],
                        ['slug' => 'huge', 'size' => '3rem'],
                    ],
                ],
            ],
        ];
        $project->writeJson('theme/theme.json', $theme);

        $command = php_child_command(repo_path('bin/design-floor.php'), [$slug]);
        $output = [];
        $exit = 0;
        exec($command . ' 2>&1', $output, $exit);

        $expected = [];
        foreach (DesignFloor::check($markup, []) as $finding) {
            $expected[] = DesignFloor::warningRow('plugin/pages/home.html', $finding);
        }
        foreach (DesignFloor::check('', $theme) as $finding) {
            $expected[] = DesignFloor::warningRow('theme/theme.json', $finding);
        }

        assert_eq(0, $exit, implode("\n", $output));
        assert_eq($expected, $output);
        assert_true($expected !== [], 'planted markup and theme.json must produce findings');
        foreach ($output as $line) {
            assert_true(!str_contains($line, 'theme/parts/header.html'), $line);
        }
    } finally {
        remove_tree($project->root);
    }
});

test('design-floor CLI prints nothing when a project has no findings', function () {
    $slug = 'zz-design-floor-cli-empty-' . getmypid() . '-' . uniqid();
    $project = (new ProjectStore(repo_path('projects')))->create($slug);

    try {
        $project->writeText('plugin/pages/home.html', "<!-- wp:paragraph -->\n<p>Hello.</p>\n<!-- /wp:paragraph -->\n");

        $command = php_child_command(repo_path('bin/design-floor.php'), [$slug]);
        $output = [];
        $exit = 0;
        exec($command . ' 2>&1', $output, $exit);

        assert_eq(0, $exit, implode("\n", $output));
        assert_eq([], $output);
    } finally {
        remove_tree($project->root);
    }
});
