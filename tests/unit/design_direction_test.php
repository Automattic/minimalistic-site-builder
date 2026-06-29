<?php
declare(strict_types=1);

/** @return array{0:Project,1:FakeLlm,2:string} */
function make_designdir_fixture(): array
{
    $tmp = sys_get_temp_dir() . '/builder_designdir_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy neighborhood bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Hearth & Crumb', 'visual_vibe' => 'warm and rustic']);
    return [$project, new FakeLlm(), $tmp];
}

test('design-direction writes the freeform brief to designDirection.md', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $brief = "Editorial-magazine direction: warm, rustic, 1970s print feel. "
        . "Earthy neutrals with one electric accent; high-contrast serif display "
        . "over a clean grotesque body. Sharp corners, generous whitespace, oversized "
        . "section numbers. Avoid the centered all-sans hero.";
    $llm->queueText($brief);

    $renderer = new PromptRenderer(repo_path('prompts'));
    (new DesignDirectionStep($llm, $renderer))->run($project);

    assert_true($project->exists('designDirection.md'), 'designDirection.md written');
    $written = $project->readText('designDirection.md');
    assert_contains('Editorial-magazine', $written);

    // The rendered prompt must carry the user's words AND the factual spec.
    assert_contains('cozy neighborhood bakery', $llm->calls[0]['prompt']);
    assert_contains('Hearth & Crumb', $llm->calls[0]['prompt']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction throws when the model returns an empty brief', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueText("   \n  ");
    $renderer = new PromptRenderer(repo_path('prompts'));
    assert_throws(function () use ($llm, $renderer, $project) {
        (new DesignDirectionStep($llm, $renderer))->run($project);
    });
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction throws when meta prompt missing', function () {
    $tmp = sys_get_temp_dir() . '/builder_designdir_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => '']);
    $project->writeJson('siteSpec.json', ['name' => 'X']);
    $renderer = new PromptRenderer(repo_path('prompts'));
    assert_throws(function () use ($renderer, $project) {
        (new DesignDirectionStep(new FakeLlm(), $renderer))->run($project);
    });
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('readFor returns the brief when present, fallback when absent', function () {
    $tmp = sys_get_temp_dir() . '/builder_designdir_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    // Absent → a non-empty fallback that still carries the "avoid defaults" intent.
    $fallback = DesignDirectionStep::readFor($project);
    assert_true(trim($fallback) !== '', 'fallback is non-empty');
    assert_contains('avoid', strtolower($fallback));

    // Present → the verbatim brief is injected.
    $project->writeText('designDirection.md', "Dark-luxe direction with gold accents.\n");
    assert_contains('Dark-luxe', DesignDirectionStep::readFor($project));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json injects the design direction into its prompt', function () {
    $tmp = sys_get_temp_dir() . '/builder_designdir_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $project->writeText('designDirection.md', "Editorial-magazine direction.\n");

    $llm = new FakeLlm();
    $llm->queueJson(valid_theme_payload());
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new ThemeJsonStep($llm, $renderer))->run($project);

    assert_contains('Editorial-magazine', $llm->calls[0]['prompt']);
    exec('rm -rf ' . escapeshellarg($tmp));
});
