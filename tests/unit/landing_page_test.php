<?php
declare(strict_types=1);

function landing_fixture(): array
{
    $tmp = sys_get_temp_dir() . '/builder_lp_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('siteSpec.json', ['name' => 'Demo', 'sections' => ['Hero', 'About']]);
    $project->writeText('design.md', '# Demo design');
    $project->writeJson('theme/theme.json', ['version' => 3]);
    return [$project, $tmp];
}

function landing_files(): array
{
    $header = '<!-- wp:group --><!-- wp:site-title /--><!-- /wp:group -->';
    $footer = '<!-- wp:group --><!-- wp:paragraph --><p>(c) Demo</p><!-- /wp:paragraph --><!-- /wp:group -->';
    $front = '<!-- wp:template-part {"slug":"header"} /--><!-- wp:heading --><h2>Hero</h2><!-- /wp:heading --><!-- wp:template-part {"slug":"footer"} /-->';
    $index = '<!-- wp:template-part {"slug":"header"} /--><!-- wp:post-content /--><!-- wp:template-part {"slug":"footer"} /-->';
    return [
        'parts/header.html' => $header,
        'parts/footer.html' => $footer,
        'templates/index.html' => $index,
        'templates/front-page.html' => $front,
    ];
}

test('landing-page writes all four block files', function () {
    [$project, $tmp] = landing_fixture();
    $llm = new FakeLlm();
    $llm->queueJson(landing_files());
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new LandingPageStep($llm, $renderer))->run($project);

    foreach (['theme/parts/header.html', 'theme/parts/footer.html', 'theme/templates/index.html', 'theme/templates/front-page.html'] as $f) {
        assert_true($project->exists($f), "{$f} written");
        assert_contains('wp:', $project->readText($f));
    }
    assert_contains('wp:template-part', $project->readText('theme/templates/front-page.html'));
    // Large output budget requested.
    assert_eq(32000, $llm->calls[0]['opts']['max_tokens']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('landing-page throws when a file is missing', function () {
    [$project, $tmp] = landing_fixture();
    $files = landing_files();
    unset($files['parts/footer.html']);
    $llm = new FakeLlm();
    $llm->queueJson($files);
    $renderer = new PromptRenderer(repo_path('prompts'));
    assert_throws(function () use ($llm, $renderer, $project) {
        (new LandingPageStep($llm, $renderer))->run($project);
    });
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('landing-page throws when template lacks template parts', function () {
    [$project, $tmp] = landing_fixture();
    $files = landing_files();
    $files['templates/front-page.html'] = '<!-- wp:heading --><h2>No parts</h2><!-- /wp:heading -->';
    $llm = new FakeLlm();
    $llm->queueJson($files);
    $renderer = new PromptRenderer(repo_path('prompts'));
    assert_throws(function () use ($llm, $renderer, $project) {
        (new LandingPageStep($llm, $renderer))->run($project);
    });
    exec('rm -rf ' . escapeshellarg($tmp));
});
