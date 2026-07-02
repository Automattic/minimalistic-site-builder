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

test('design-direction picks one of the four directions and writes it to designDirection.md', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['directions' => [
        ['title' => 'Hearth & Grain',  'description' => 'Editorial-magazine warmth, 1970s print feel.'],
        ['title' => 'Flour & Steel',   'description' => 'Industrial-utilitarian bakery, raw concrete tones.'],
        ['title' => 'Sugar Bloom',     'description' => 'Playful-pop pastels with oversized display type.'],
        ['title' => 'Midnight Levain', 'description' => 'Dark-luxe patisserie, gold on near-black.'],
    ]]);

    $renderer = new PromptRenderer(repo_path('prompts'));
    (new DesignDirectionStep($llm, $renderer))->run($project);

    assert_true($project->exists('designDirection.md'), 'designDirection.md written');
    $written = $project->readText('designDirection.md');
    // Exactly one of the four directions is persisted (title heading + description).
    $titles = ['Hearth & Grain', 'Flour & Steel', 'Sugar Bloom', 'Midnight Levain'];
    $matched = array_values(array_filter($titles, fn ($t) => str_contains($written, $t)));
    assert_true(count($matched) === 1, 'exactly one direction is chosen');
    assert_contains('# ', $written); // the title is rendered as a heading

    // The rendered prompt must carry the user's words AND the factual spec.
    assert_contains('cozy neighborhood bakery', $llm->calls[0]['prompt']);
    assert_contains('Hearth & Crumb', $llm->calls[0]['prompt']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction persists the chosen direction\'s image grade', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    // Same grade on all four so the random choice doesn't matter to the assertion.
    $grade = 'warm kodachrome color, soft golden light, shallow depth of field';
    $llm->queueJson(['directions' => array_map(fn (int $i) => [
        'title'       => "Direction {$i}",
        'description' => "Vision {$i}.",
        'image_grade' => $grade,
    ], range(1, 4))]);

    $renderer = new PromptRenderer(repo_path('prompts'));
    (new DesignDirectionStep($llm, $renderer))->run($project);

    assert_true($project->exists('imageGrade.txt'), 'imageGrade.txt written');
    assert_eq($grade, DesignDirectionStep::imageGradeFor($project));

    // The prompt asks the model for the grade field.
    assert_contains('image_grade', $llm->calls[0]['prompt']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction tolerates a direction without an image grade', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    // A stale grade from a previous run must not survive a re-run whose chosen
    // direction carries no grade.
    $project->writeText('imageGrade.txt', "stale grade from an earlier run\n");
    $llm->queueJson(['directions' => [
        ['title' => 'No Grade', 'description' => 'A direction with no image_grade field.'],
    ]]);

    $renderer = new PromptRenderer(repo_path('prompts'));
    (new DesignDirectionStep($llm, $renderer))->run($project);

    assert_eq('', DesignDirectionStep::imageGradeFor($project));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction throws when the model returns no usable directions', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['directions' => [['title' => 'Empty', 'description' => '   ']]]);
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
