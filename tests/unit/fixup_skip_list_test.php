<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockFixer;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\ContrastFixStep;
use Automattic\SiteBuild\Steps\FixBlocksStep;
use Automattic\SiteBuild\Steps\MotionSanityStep;
use Automattic\SiteBuild\Steps\NormalizeLayoutStep;

/** @return array<mixed> */
function fixup_skip_theme_json(): array
{
    return [
        'version' => 3,
        'settings' => [
            'layout' => ['contentSize' => '860px'],
            'color' => ['palette' => [
                ['slug' => 'base', 'color' => '#FFFFFF', 'name' => 'Base'],
                ['slug' => 'contrast', 'color' => '#111111', 'name' => 'Contrast'],
                ['slug' => 'primary', 'color' => '#1D4ED8', 'name' => 'Primary'],
                ['slug' => 'accent', 'color' => '#F2B8B5', 'name' => 'Accent'],
            ]],
        ],
        'styles' => ['elements' => ['link' => [
            'color' => ['text' => 'var(--wp--preset--color--primary)'],
            ':hover' => ['color' => ['text' => 'var(--wp--preset--color--accent)']],
        ]]],
    ];
}

function fixup_skip_motion_markup(): string
{
    return '<!-- wp:group {"className":"reveal-up","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group reveal-up"><!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph --></div>'
        . '<!-- /wp:group -->';
}

test('new CSS path skips legacy contrast fix before generated artifacts change', function () {
    $tmp = sys_get_temp_dir() . '/builder_fixup_skip_contrast_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeText('design/site.css', ':root { --generated: true; }');
    $project->writeJson('theme/theme.json', fixup_skip_theme_json());
    $before = $project->readText('theme/theme.json');

    try {
        (new ContrastFixStep())->run($project);

        assert_eq($before, $project->readText('theme/theme.json'));
        assert_true(!$project->exists('logs/contrast-report.txt'), 'skip returns before legacy report generation');
        $warnings = $project->readJson('warnings.json')['fixup_skipped'] ?? [];
        assert_eq(1, count($warnings));
        assert_contains('step=contrast-fix', $warnings[0]);
        assert_contains('signal=design/site.css', $warnings[0]);
        assert_contains('disposition=skipped', $warnings[0]);
        assert_contains('reason=', $warnings[0]);
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('new CSS path skips legacy motion sanity before generated artifacts change', function () {
    $tmp = sys_get_temp_dir() . '/builder_fixup_skip_motion_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeText('design/site.css', ':root { --generated: true; }');
    $project->writeJson('designDirection.json', ['motion' => 'none']);
    $project->writeText('theme/parts/section.html', fixup_skip_motion_markup());
    $before = $project->readText('theme/parts/section.html');

    try {
        (new MotionSanityStep())->run($project);

        assert_eq($before, $project->readText('theme/parts/section.html'));
        assert_true(!$project->exists('logs/motion-sanity.txt'), 'skip returns before legacy report generation');
        $warnings = $project->readJson('warnings.json')['fixup_skipped'] ?? [];
        assert_eq(1, count($warnings));
        assert_contains('step=motion-sanity', $warnings[0]);
        assert_contains('signal=design/site.css', $warnings[0]);
        assert_contains('disposition=skipped', $warnings[0]);
        assert_contains('reason=', $warnings[0]);
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('legacy path still runs contrast and motion fixups when generated CSS is absent', function () {
    $tmp = sys_get_temp_dir() . '/builder_fixup_skip_legacy_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('theme/theme.json', fixup_skip_theme_json());
    $project->writeJson('designDirection.json', ['motion' => 'none']);
    $project->writeText('theme/parts/section.html', fixup_skip_motion_markup());
    $themeBefore = $project->readText('theme/theme.json');
    $motionBefore = $project->readText('theme/parts/section.html');

    try {
        (new ContrastFixStep())->run($project);
        (new MotionSanityStep())->run($project);

        assert_true($project->readText('theme/theme.json') !== $themeBefore, 'legacy contrast fix still mutates');
        assert_true($project->readText('theme/parts/section.html') !== $motionBefore, 'legacy motion fix still mutates');
        assert_true($project->exists('logs/contrast-report.txt'));
        assert_true($project->exists('logs/motion-sanity.txt'));
        $warnings = $project->readJson('warnings.json');
        assert_true(!isset($warnings['fixup_skipped']), 'legacy fixups are not labeled skipped');
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('new CSS path keeps normalize-layout and fix-blocks active', function () {
    $tmp = sys_get_temp_dir() . '/builder_fixup_skip_active_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeText('design/site.css', ':root { --generated: true; }');
    $project->writeJson('theme/theme.json', fixup_skip_theme_json());
    $project->writeText(
        'theme/parts/section.html',
        '<!-- wp:group {"backgroundColor":"contrast"}} -->'
            . '<div class="wp-block-group has-contrast-background-color has-background"></div>'
            . '<!-- /wp:group -->',
    );
    $fixer = new class implements BlockFixer {
        /** @var list<string> */
        public array $calls = [];

        public function fix(string $themeDir): string
        {
            $this->calls[] = $themeDir;
            return '[fix-templates] 0/1 file(s) re-serialized';
        }
    };

    try {
        (new NormalizeLayoutStep())->run($project);
        assert_contains(
            'wp:group {"backgroundColor":"contrast","layout":{"type":"constrained"}}',
            $project->readText('theme/parts/section.html'),
            'normalize-layout stays active when design/site.css exists',
        );

        (new FixBlocksStep($fixer))->run($project);
        assert_eq([$project->themePath()], $fixer->calls, 'fix-blocks stays active when design/site.css exists');
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});
