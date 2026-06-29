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

test('design-direction writes a normalized designDirection.json', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson([
        'archetype'      => 'Editorial Magazine', // slugified on the way in
        'mood'           => ['confident', 'warm', 'spacious'],
        'era_reference'  => '1970s print editorial',
        'color_strategy' => 'warm earthy neutrals with one electric accent',
        'type_strategy'  => 'high-contrast serif display + clean grotesque body',
        'shape_language' => 'sharp corners, thin rules, generous whitespace',
        'signature_move' => 'oversized section numbers and asymmetric margins',
        'avoid'          => 'centered hero with all-sans type',
    ]);

    $renderer = new PromptRenderer(repo_path('prompts'));
    (new DesignDirectionStep($llm, $renderer))->run($project);

    $dir = $project->readJson('designDirection.json');
    assert_eq('editorial-magazine', $dir['archetype']);    // slugified
    assert_true(is_array($dir['mood']));
    assert_eq('confident', $dir['mood'][0]);
    assert_eq('1970s print editorial', $dir['era_reference']);

    // The rendered prompt must carry the user's words AND the factual spec.
    assert_contains('cozy neighborhood bakery', $llm->calls[0]['prompt']);
    assert_contains('Hearth & Crumb', $llm->calls[0]['prompt']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction tolerates a string mood and fills missing fields', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['archetype' => 'brutalist', 'mood' => 'stark']);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new DesignDirectionStep($llm, $renderer))->run($project);

    $dir = $project->readJson('designDirection.json');
    assert_eq('brutalist', $dir['archetype']);
    assert_eq(['stark'], $dir['mood']);                    // string wrapped to list
    foreach (['era_reference', 'color_strategy', 'type_strategy', 'shape_language', 'signature_move', 'avoid'] as $key) {
        assert_true(array_key_exists($key, $dir), "{$key} key present");
    }

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-direction throws when archetype missing', function () {
    [$project, $llm, $tmp] = make_designdir_fixture();
    $llm->queueJson(['mood' => ['bold']]); // no archetype
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

test('readFor returns the direction JSON when present, fallback when absent', function () {
    $tmp = sys_get_temp_dir() . '/builder_designdir_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    // Absent → a non-empty fallback that still carries the "avoid defaults" intent.
    $fallback = DesignDirectionStep::readFor($project);
    assert_true(trim($fallback) !== '', 'fallback is non-empty');
    assert_contains('avoid', strtolower($fallback));

    // Present → the verbatim file content is injected.
    $project->writeJson('designDirection.json', ['archetype' => 'dark-luxe']);
    assert_contains('dark-luxe', DesignDirectionStep::readFor($project));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('theme-json injects the design direction into its prompt', function () {
    $tmp = sys_get_temp_dir() . '/builder_designdir_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $project->writeJson('designDirection.json', ['archetype' => 'editorial-magazine']);

    $llm = new FakeLlm();
    $llm->queueJson(valid_theme_payload());
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new ThemeJsonStep($llm, $renderer))->run($project);

    assert_contains('editorial-magazine', $llm->calls[0]['prompt']);
    exec('rm -rf ' . escapeshellarg($tmp));
});
