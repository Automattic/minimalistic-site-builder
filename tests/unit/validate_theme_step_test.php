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

test('validate-theme fails with a durable report for malformed preset references', function () {
    [$project, $tmp] = final_validation_project();
    $project->writeText(
        'theme/parts/bad.html',
        '<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing--xl"}}}} -->'
        . '<div class="wp-block-group"></div><!-- /wp:group -->'
    );

    try {
        assert_throws(fn () => (new ValidateThemeStep())->run($project));
        $log = $project->readText('logs/validate-theme.log');
        assert_contains('malformed preset reference', $log);
        assert_contains('var:preset|spacing--xl', $log);
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});
