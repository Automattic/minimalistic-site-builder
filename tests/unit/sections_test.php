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
    $reqs = (new SectionsStep(new FakeLlm(), $renderer, new ThemeKnowledge(null)))->requests($project);

    assert_eq(['header', 'footer', 'section-hero', 'section-about'], array_keys($reqs));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections writes header, footer and a part per section', function () {
    [$project, $tmp] = sections_fixture();
    $llm = new FakeLlm();
    $llm->queueText('<!-- wp:group --><!-- wp:site-title /--><!-- /wp:group -->');
    $llm->queueText('<!-- wp:group --><!-- wp:paragraph --><p>(c)</p><!-- /wp:paragraph --><!-- /wp:group -->');
    $llm->queueText('<!-- wp:heading --><h2>Hero</h2><!-- /wp:heading -->');
    $llm->queueText('<!-- wp:heading --><h2>About</h2><!-- /wp:heading -->');
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SectionsStep($llm, $renderer, new ThemeKnowledge(null)))->run($project);

    foreach (['parts/header.html', 'parts/footer.html', 'parts/section-hero.html', 'parts/section-about.html'] as $rel) {
        assert_true($project->exists('theme/' . $rel), "{$rel} written");
        assert_contains('wp:', $project->readText('theme/' . $rel));
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections strips a stray markdown code fence from a part response', function () {
    [$project, $tmp] = sections_fixture();
    $llm = new FakeLlm();
    $llm->queueText("```html\n<!-- wp:group --><!-- wp:site-title /--><!-- /wp:group -->\n```");
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');
    $llm->queueText('<!-- wp:heading --><h2>Hero</h2><!-- /wp:heading -->');
    $llm->queueText('<!-- wp:heading --><h2>About</h2><!-- /wp:heading -->');
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SectionsStep($llm, $renderer, new ThemeKnowledge(null)))->run($project);

    $header = $project->readText('theme/parts/header.html');
    assert_true(!str_contains($header, '```'), 'code fence stripped');
    assert_contains('wp:group', $header);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections throws when a part has no block markup', function () {
    [$project, $tmp] = sections_fixture();
    $llm = new FakeLlm();
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');
    $llm->queueText('just text, no blocks'); // hero — invalid
    $llm->queueText('<!-- wp:heading --><h2>About</h2><!-- /wp:heading -->');
    $renderer = new PromptRenderer(repo_path('prompts'));

    assert_throws(function () use ($llm, $renderer, $project) {
        (new SectionsStep($llm, $renderer, new ThemeKnowledge(null)))->run($project);
    });
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('sections writes nothing when any part is invalid (no partial output)', function () {
    [$project, $tmp] = sections_fixture();
    $llm = new FakeLlm();
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');                 // header — valid
    $llm->queueText('<!-- wp:group --><!-- /wp:group -->');                 // footer — valid
    $llm->queueText('just text, no blocks');                               // section-hero — invalid
    $llm->queueText('<!-- wp:heading --><h2>About</h2><!-- /wp:heading -->');
    $renderer = new PromptRenderer(repo_path('prompts'));

    assert_throws(function () use ($llm, $renderer, $project) {
        (new SectionsStep($llm, $renderer, new ThemeKnowledge(null)))->run($project);
    });
    // The valid header/footer must NOT have been written before the bad part threw.
    assert_true(!$project->exists('theme/parts/header.html'), 'no part written when a sibling is invalid');
    assert_true(!$project->exists('theme/parts/footer.html'), 'no part written when a sibling is invalid');
    exec('rm -rf ' . escapeshellarg($tmp));
});
