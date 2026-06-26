<?php
declare(strict_types=1);

test('design-doc writes design.md from spec + direction', function () {
    $tmp = sys_get_temp_dir() . '/builder_doc_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('siteSpec.json', ['name' => 'Hearth & Crumb', 'slug' => 'hearth-crumb']);
    $project->writeJson('designDirection.json', ['concept' => 'warm bakery']);

    $llm = new FakeLlm();
    $body = "# Hearth & Crumb — Design Document\n\n## Overview\n" . str_repeat('Warm and inviting bakery brand. ', 20);
    $llm->queueText("```markdown\n{$body}\n```"); // fenced — must be stripped
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new DesignDocStep($llm, $renderer))->run($project);

    $md = $project->readText('design.md');
    assert_contains('# Hearth & Crumb — Design Document', $md);
    assert_true(!str_starts_with($md, '```'), 'fence stripped');
    // Both inputs injected.
    assert_contains('Hearth & Crumb', $llm->calls[0]['prompt']);
    assert_contains('warm bakery', $llm->calls[0]['prompt']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-doc throws on too-short output', function () {
    $tmp = sys_get_temp_dir() . '/builder_doc_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('siteSpec.json', ['name' => 'X']);
    $project->writeJson('designDirection.json', ['concept' => 'y']);
    $llm = new FakeLlm();
    $llm->queueText('too short');
    $renderer = new PromptRenderer(repo_path('prompts'));
    assert_throws(function () use ($llm, $renderer, $project) {
        (new DesignDocStep($llm, $renderer))->run($project);
    });
    exec('rm -rf ' . escapeshellarg($tmp));
});
