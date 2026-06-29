<?php
declare(strict_types=1);

/**
 * Unit tests for SectionsStep: it fires one request per part (header, footer,
 * each section), validates the markup, and writes the part files.
 */

function sections_fixture(): array
{
    $tmp = sys_get_temp_dir() . '/builder_sec_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $project->writeJson('theme/theme.json', ['version' => 3]);
    $project->writeJson('sections.json', ['sections' => [
        ['slug' => 'hero', 'title' => 'Hero', 'type' => 'hero'],
        ['slug' => 'about', 'title' => 'About', 'type' => 'about'],
    ]]);
    return [$project, $tmp];
}

test('sections requests one part per header/footer/section', function () {
    [$project, $tmp] = sections_fixture();
    $renderer = new PromptRenderer(repo_path('prompts'));
    $reqs = (new SectionsStep(new FakeLlm(), $renderer))->requests($project);

    assert_eq(['header', 'footer', 'section-hero', 'section-about'], array_keys($reqs));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections writes header, footer and a part per section', function () {
    [$project, $tmp] = sections_fixture();
    $llm = new FakeLlm();
    $llm->queueJson(['markup' => '<!-- wp:group --><!-- wp:site-title /--><!-- /wp:group -->']);
    $llm->queueJson(['markup' => '<!-- wp:group --><!-- wp:paragraph --><p>(c)</p><!-- /wp:paragraph --><!-- /wp:group -->']);
    $llm->queueJson(['markup' => '<!-- wp:heading --><h2>Hero</h2><!-- /wp:heading -->']);
    $llm->queueJson(['markup' => '<!-- wp:heading --><h2>About</h2><!-- /wp:heading -->']);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SectionsStep($llm, $renderer))->run($project);

    foreach (['parts/header.html', 'parts/footer.html', 'parts/section-hero.html', 'parts/section-about.html'] as $rel) {
        assert_true($project->exists('theme/' . $rel), "{$rel} written");
        assert_contains('wp:', $project->readText('theme/' . $rel));
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections throws when a part has no block markup', function () {
    [$project, $tmp] = sections_fixture();
    $llm = new FakeLlm();
    $llm->queueJson(['markup' => '<!-- wp:group --><!-- /wp:group -->']);
    $llm->queueJson(['markup' => '<!-- wp:group --><!-- /wp:group -->']);
    $llm->queueJson(['markup' => 'just text, no blocks']); // hero — invalid
    $llm->queueJson(['markup' => '<!-- wp:heading --><h2>About</h2><!-- /wp:heading -->']);
    $renderer = new PromptRenderer(repo_path('prompts'));

    assert_throws(function () use ($llm, $renderer, $project) {
        (new SectionsStep($llm, $renderer))->run($project);
    });
    exec('rm -rf ' . escapeshellarg($tmp));
});
