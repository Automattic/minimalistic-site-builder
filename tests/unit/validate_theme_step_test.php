<?php
declare(strict_types=1);

use Automattic\SiteBuild\Pipeline;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\ScaffoldThemeStep;
use Automattic\SiteBuild\Steps\ThemeJsonStep;
use Automattic\SiteBuild\Steps\ValidateThemeStep;

/** @return array{0:Automattic\SiteBuild\Project,1:string} */
function final_validation_project(): array
{
    $tmp = sys_get_temp_dir() . '/builder_final_validation_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeText('theme/style.css', "/*\nTheme Name: Demo\n*/\n");
    $project->writeJson(
        'theme/theme.json',
        ThemeJsonStep::normalizeSpacingSettings(['version' => 3])
    );
    $template = '<!-- wp:template-part {"slug":"header"} /-->'
        . '<!-- wp:paragraph --><p>Content</p><!-- /wp:paragraph -->'
        . '<!-- wp:template-part {"slug":"footer"} /-->';
    $project->writeText('theme/templates/index.html', $template);
    $project->writeText('theme/templates/page.html', $template);
    $project->writeText('theme/parts/header.html', '<!-- wp:site-title /-->');
    $project->writeText('theme/parts/footer.html', '<!-- wp:paragraph --><p>Footer</p><!-- /wp:paragraph -->');
    return [$project, $tmp];
}

test('validate-theme declaration rejects an incomplete theme graph', function () {
    assert_eq([
        'pages.json',
        'theme/style.css',
        'theme/theme.json',
        'theme/templates/index.html',
        'theme/templates/page.html',
        'theme/parts/header.html',
        'theme/parts/footer.html',
        'theme/parts/*',
        'theme/templates/*',
        'plugin/pages/*',
    ], (new ValidateThemeStep())->declaration()->reads);

    assert_throws(
        fn () => new Pipeline(
            [new ScaffoldThemeStep(), new ValidateThemeStep()],
            seeds: ['pages.json'],
        ),
        'a scaffold alone does not provide theme.json, templates, or parts',
    );
});

test('validate-theme passes and logs a completed contract-valid theme', function () {
    [$project, $tmp] = final_validation_project();
    try {
        (new ValidateThemeStep())->run($project);
        assert_contains('passed', $project->readText('logs/validate-theme.log'));
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('validate-theme records problems as warnings and still delivers the theme', function () {
    [$project, $tmp] = final_validation_project();
    $project->writeText(
        'theme/parts/bad.html',
        '<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing--xl"}}}} -->'
        . '<div class="wp-block-group"></div><!-- /wp:group -->'
    );

    try {
        (new ValidateThemeStep())->run($project);

        $log = $project->readText('logs/validate-theme.log');
        assert_contains('theme delivered anyway', $log);
        assert_contains('malformed preset reference', $log);
        assert_contains('var:preset|spacing--xl', $log);

        $warnings = $project->readJson('warnings.json');
        assert_true(isset($warnings['validate-theme']), 'warnings.json groups problems by step id');
        assert_contains('var:preset|spacing--xl', implode("\n", $warnings['validate-theme']));
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('addWarnings accumulates across steps and dedupes within a step', function () {
    [$project, $tmp] = final_validation_project();
    try {
        $project->addWarnings('validate-theme', ['a button link has no href']);
        $project->addWarnings('validate-theme', ['a button link has no href', 'a link has an empty href']);
        $project->addWarnings('fix-blocks', ['dropped vertical rhythm CSS']);
        $project->addWarnings('fix-blocks', []);

        assert_eq([
            'validate-theme' => ['a button link has no href', 'a link has an empty href'],
            'fix-blocks' => ['dropped vertical rhythm CSS'],
        ], $project->readJson('warnings.json'));
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('validate-theme also runs the typography and plan validators', function () {
    [$project, $tmp] = final_validation_project();
    // Hardcoded font size → typographyWarnings; an interior page opening at
    // homepage-hero scale → planWarnings. Both were orphaned in bin/eval.php
    // before — the final gate must run every advisory validator.
    $project->writeText(
        'theme/parts/hardcoded.html',
        '<!-- wp:paragraph {"fontSize":"1.25rem"} --><p>Sized</p><!-- /wp:paragraph -->'
    );
    $project->writeJson('pages.json', ['pages' => [
        ['slug' => 'home', 'front' => true, 'sections' => [
            ['slug' => 'hero', 'layout_archetype' => 'full-bleed-cover'],
        ]],
        ['slug' => 'about', 'front' => false, 'sections' => [
            ['slug' => 'about-hero', 'layout_archetype' => 'full-bleed-cover'],
        ]],
    ]]);

    try {
        (new ValidateThemeStep())->run($project);

        $joined = implode("\n", $project->readJson('warnings.json')['validate-theme'] ?? []);
        assert_contains('hardcoded font-size values bypass the fontSizes scale', $joined);
        assert_contains("interior page 'about' opens with a full-bleed-cover section", $joined);
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('validate-theme records footer interaction residuals and still delivers', function () {
    [$project, $tmp] = final_validation_project();
    $project->writeText(
        'theme/parts/footer.html',
        '<!-- wp:paragraph --><p><a href="#">Social</a></p><!-- /wp:paragraph -->'
        . '<!-- wp:list --><ul class="wp-block-list"></ul><!-- /wp:list -->'
    );

    try {
        (new ValidateThemeStep())->run($project);

        assert_true($project->exists('theme/parts/footer.html'), 'advisory validation never removes the footer');
        $joined = implode("\n", $project->readJson('warnings.json')['validate-theme'] ?? []);
        assert_contains('authored href="#" -> delivered href="#"', $joined);
        assert_contains('wp:list[1]', $joined);
        assert_contains('disposition:', $joined);
        assert_contains('theme delivered anyway', $project->readText('logs/validate-theme.log'));
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});
